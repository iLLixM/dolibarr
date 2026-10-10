<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Invoice-specific documents and confirmed, individually addressed mail. */
class MemberSubscriptionInvoiceMail
{
	/** @var DoliDB Database handler */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param Facture $invoice Invoice with its third party loaded
	 * @return Translate
	 */
	public function outputLangs($invoice)
	{
		global $conf, $langs;
		$outputlangs = new Translate('', $conf);
		$outputlangs->setDefaultLang(getDolGlobalInt('MAIN_MULTILANGS') && $invoice->thirdparty->default_lang ? $invoice->thirdparty->default_lang : $langs->defaultlang);
		$outputlangs->loadLangs(array('main', 'bills', 'members', 'companies', 'products', 'mails'));
		return $outputlangs;
	}

	/**
	 * @param Facture $invoice Invoice
	 * @return string[] Valid billing addresses
	 */
	public function recipients($invoice)
	{
		$contacts = $invoice->liste_contact(1, 'external', 0, 'BILLING', 1);
		if (!is_array($contacts)) {
			throw new RuntimeException('MemberInvoiceRecipientError');
		}
		$addresses = array();
		foreach ($contacts as $contact) {
			$addresses[] = trim((string) $contact['email']);
		}
		if (!$contacts) {
			$addresses[] = trim((string) $invoice->thirdparty->email);
		}
		return self::checkedRecipients($addresses);
	}

	/**
	 * @param string[] $addresses Individual mailbox addresses
	 * @return string[]
	 */
	public static function checkedRecipients(array $addresses)
	{
		if (!$addresses) {
			throw new RuntimeException('MemberInvoiceRecipientError');
		}
		foreach ($addresses as $address) {
			if (!isValidEmail($address)) {
				throw new RuntimeException('MemberInvoiceRecipientError');
			}
		}
		return array_values(array_unique($addresses));
	}

	/**
	 * Resolve only a PDF inside this invoice's own entity/reference directory.
	 *
	 * @param Facture $invoice Invoice
	 * @return string
	 */
	public function document($invoice)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		if ($invoice->type != Facture::TYPE_STANDARD || !in_array((int) $invoice->status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
			throw new RuntimeException('MemberInvoiceNotValidated');
		}
		$root = isset($conf->invoice->multidir_output[$invoice->entity]) ? $conf->invoice->multidir_output[$invoice->entity] : '';
		if (!$root || !$invoice->ref) {
			throw new RuntimeException('MemberInvoiceMissingPdf');
		}
		$directory = $root.'/'.dol_sanitizeFileName($invoice->ref);
		$path = $directory.'/'.dol_sanitizeFileName($invoice->ref).'.pdf';
		if (!dol_is_file($path) && !empty($invoice->last_main_doc)) {
			$path = DOL_DATA_ROOT.'/'.$invoice->last_main_doc;
		}
		$realDirectory = realpath($directory);
		$realPath = realpath($path);
		if (!$realDirectory || dirname($realDirectory) !== realpath($root) || !$realPath || dirname($realPath) !== $realDirectory || !preg_match('/\.pdf$/i', $realPath) || !dol_is_file($realPath) || !is_readable($realPath)) {
			throw new RuntimeException('MemberInvoiceMissingPdf');
		}
		return $realPath;
	}

	/**
	 * Generate the document without changing the invoice validation transaction.
	 *
	 * @param Facture $invoice Accessible invoice
	 * @param User $user User
	 * @return void
	 */
	public function generate($invoice, $user)
	{
		if (!MemberSubscriptionInvoice::canProcess($user, 'validate')) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		if ($invoice->type != Facture::TYPE_STANDARD || !in_array((int) $invoice->status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
			throw new RuntimeException('MemberInvoiceNotValidated');
		}
		if ($invoice->generateDocument($invoice->model_pdf, $this->outputLangs($invoice)) <= 0) {
			throw new RuntimeException('MemberInvoicePdfFailed');
		}
		if ($invoice->fetch($invoice->id) <= 0) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		$this->document($invoice);
	}

	/**
	 * Resolve the exact message shown in the confirmation, including automatic BCC.
	 *
	 * @param Facture $invoice Invoice
	 * @param User $user Sender
	 * @param string $subject Subject template
	 * @param string $body Message template
	 * @return array<string,mixed> Message and confirmation fingerprint
	 */
	public function prepare($invoice, $user, $subject, $body)
	{
		$outputlangs = $this->outputLangs($invoice);
		$substitutions = getCommonSubstitutionArray($outputlangs, 0, null, $invoice);
		complete_substitutions_array($substitutions, $outputlangs, $invoice);
		$path = $this->document($invoice);
		$hash = $this->documentHash($path);
		if (!$hash) {
			throw new RuntimeException('MemberInvoiceMissingPdf');
		}
		$message = array('from' => $user->email, 'to' => $this->recipients($invoice), 'bcc' => getDolGlobalString('MAIN_MAIL_AUTOCOPY_INVOICE_TO'),
			'subject' => make_substitutions($subject, $substitutions, $outputlangs), 'body' => make_substitutions($body, $substitutions, $outputlangs), 'path' => $path);
		$message['fingerprint'] = hash('sha256', json_encode(array($invoice->id, $invoice->ref, $invoice->socid, $invoice->status, $invoice->total_ttc, $invoice->date_modification, $message, $hash)));
		return $message;
	}

	/**
	 * @param string $path PDF path
	 * @return string|false
	 */
	protected function documentHash($path)
	{
		return hash_file('sha256', $path);
	}

	/**
	 * @param int $invoiceid Invoice ID
	 * @return string Last recorded attempt status
	 */
	public function lastStatus($invoiceid)
	{
		$sql = "SELECT code FROM ".$this->db->prefix()."actioncomm WHERE elementtype = 'facture' AND fk_element = ".((int) $invoiceid);
		$sql .= " AND code IN ('MEMBER_INVOICE_SEND_PENDING', 'MEMBER_INVOICE_SEND_SENT', 'MEMBER_INVOICE_SEND_FAILED', 'AC_BILL_SENTBYMAIL') ORDER BY id DESC";
		$sql .= $this->db->plimit(1);
		$res = $this->db->query($sql);
		if (!$res) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		$found = $this->db->fetch_object($res);
		return $found ? $found->code : '';
	}

	/**
	 * @param Facture $invoice Validated invoice
	 * @param User $user Sender
	 * @param string $subject Subject with Dolibarr substitutions
	 * @param string $body Body with Dolibarr substitutions
	 * @param string $expected Confirmed fingerprint
	 * @param bool $resend Explicitly confirmed repeat
	 * @return string Result translation key
	 */
	public function send($invoice, $user, $subject, $body, $expected, $resend = false)
	{
		global $langs;
		if (!MemberSubscriptionInvoice::canProcess($user, 'send')) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		if (getDolGlobalInt('MAIN_DISABLE_ALL_MAILS')) {
			throw new RuntimeException('MemberInvoiceMailDisabled');
		}
		if (!isValidEmail($user->email) || trim($subject) === '' || trim($body) === '') {
			throw new RuntimeException('MemberInvoiceMailRequired');
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		if (!$this->db->begin()) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		try {
			$lock = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'facture WHERE rowid = '.((int) $invoice->id).' FOR UPDATE');
			if (!$lock || !$this->db->fetch_object($lock) || $invoice->fetch($invoice->id) <= 0 || $invoice->fetch_thirdparty() <= 0) {
				throw new RuntimeException('MemberInvoiceDatabaseError');
			}
			$message = $this->prepare($invoice, $user, $subject, $body);
			if (!hash_equals($expected, $message['fingerprint'])) {
				throw new RuntimeException('MemberInvoicePreviewChanged');
			}
			$status = $this->lastStatus($invoice->id);
			if ($status === 'MEMBER_INVOICE_SEND_PENDING' || ($status && !$resend)) {
				throw new RuntimeException($status === 'MEMBER_INVOICE_SEND_PENDING' ? 'MemberInvoiceMailUnknown' : 'MemberInvoiceConfirmResend');
			}
			$event = $this->newEvent();
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = 'MEMBER_INVOICE_SEND_PENDING';
			$event->label = $langs->transnoentities('MemberInvoiceMailPending', $invoice->ref);
			$event->datep = dol_now();
			$event->datef = $event->datep;
			$event->percentage = 0;
			$event->userownerid = $user->id;
			$event->socid = $invoice->socid;
			$event->fk_element = $invoice->id;
			$event->elementtype = 'facture';
			$event->note_private = $langs->transnoentities('MemberInvoiceMailUnknown');
			if ($event->create($user) <= 0 || !$this->db->commit()) {
				throw new RuntimeException('MemberInvoiceDatabaseError');
			}
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}

		// The committed attempt survives a timeout; SMTP must never run in a DB transaction.
		$sent = $this->deliver($invoice, $message);
		$event->code = $sent ? 'MEMBER_INVOICE_SEND_SENT' : 'MEMBER_INVOICE_SEND_FAILED';
		$event->percentage = $sent ? 100 : 0;
		$resultkey = $sent ? 'MemberInvoiceMailSent' : 'MemberInvoiceMailFailed';
		$event->label = $langs->transnoentities($resultkey);
		$event->note_private = $langs->transnoentities($resultkey);
		if ($event->update($user) <= 0) {
			return $sent ? 'MemberInvoiceMailSentLogFailed' : 'MemberInvoiceMailUnknown';
		}
		if ($sent) {
			$invoice->actionmsg = $langs->transnoentities('InvoiceSentByEMail', $invoice->ref);
			$invoice->actionmsg2 = $invoice->actionmsg;
			$invoice->sendtoid = array();
			if ($invoice->call_trigger('BILL_SENTBYMAIL', $user) < 0) {
				return 'MemberInvoiceMailSentLogFailed';
			}
		}
		return $resultkey;
	}

	/** @return ActionComm Durable attempt log */
	protected function newEvent()
	{
		return new ActionComm($this->db);
	}

	/**
	 * @param Facture $invoice Invoice
	 * @param array<string,mixed> $message Confirmed message
	 * @return CMailFile
	 */
	protected function newMail($invoice, array $message)
	{
		return new CMailFile($message['subject'], implode(',', $message['to']), $message['from'], $message['body'], array($message['path']), array('application/pdf'), array(dol_basename($message['path'])), '', $message['bcc'], 0, -1, '', '', 'inv'.$invoice->id);
	}

	/**
	 * Keep addresses and message contents out of transport debug logs.
	 *
	 * @param Facture $invoice Invoice
	 * @param array<string,mixed> $message Confirmed message
	 * @return bool
	 */
	private function deliver($invoice, array $message)
	{
		global $conf;
		$saved = array();
		foreach (array('SYSLOG_LEVEL' => -1, 'MAIN_MAIL_DEBUG' => 0) as $key => $value) {
			$saved[$key] = property_exists($conf->global, $key) ? getDolGlobalString($key) : null;
			$conf->global->$key = $value;
		}
		try {
			$mail = $this->newMail($invoice, $message);
			return !$mail->error && $mail->sendfile() > 0;
		} finally {
			// Request-local overrides only; never persist configuration changes.
			foreach ($saved as $key => $value) {
				if ($value === null) {
					unset($conf->global->$key);
				} else {
					$conf->global->$key = $value;
				}
			}
		}
	}
}
