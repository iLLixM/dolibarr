<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Membership invoice wizard. Each write step requires its own confirmation.
 */
define('CSRFCHECK_WITH_TOKEN', 1);
require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/membersubscriptioninvoice.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/membersubscriptioninvoicemail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/** @var DoliDB $db */
/** @var User $user */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var Form $form */
'@phan-var-force DoliDB $db';
'@phan-var-force User $user';
'@phan-var-force Conf $conf';
'@phan-var-force Translate $langs';
$langs->loadLangs(array('members', 'bills', 'companies', 'mails', 'errors'));
if (!MemberSubscriptionInvoice::canRead($user)) {
	accessforbidden();
}
$run = GETPOST('run', 'aZ09');
if (!preg_match('/^[a-f0-9]{32}$/D', $run) || empty($_SESSION['member_invoice_runs'][$run])) {
	accessforbidden($langs->trans('MemberInvoiceExpired'));
}
$context = &$_SESSION['member_invoice_runs'][$run];
if ((int) $context['owner'] !== (int) $user->id || (int) $context['entity'] !== (int) $conf->entity || $context['created'] < dol_now() - 7200) {
	accessforbidden($langs->trans('MemberInvoiceExpired'));
}
$context['ids'] = MemberSubscriptionInvoice::selection($context['ids'], getDolGlobalInt('MAIN_LIMIT_FOR_MASS_ACTIONS', 1000));
$service = new MemberSubscriptionInvoice($db);
$mailer = new MemberSubscriptionInvoiceMail($db);
$formmail = new FormMail($db);
$form = new Form($db);
$action = GETPOST('action', 'aZ09');
$url = DOL_URL_ROOT.'/adherents/subscriptioninvoices.php?run='.$run;
if (!isset($context['start'])) {
	$context['start'] = MemberSubscriptionInvoice::startDate((int) dol_print_date(dol_now(), '%Y', 'tzuser'), (int) dol_print_date(dol_now(), '%m', 'tzuser'), (int) dol_print_date(dol_now(), '%d', 'tzuser'));
	$context['template'] = $langs->transnoentities('MemberInvoiceDescription');
}

/*
 * Actions
 */
if ($action) {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($context['nonce'], GETPOST('nonce', 'aZ09'))) {
		accessforbidden($langs->trans('MemberInvoiceExpired'));
	}
	$context['nonce'] = bin2hex(random_bytes(16));
	try {
		if ($action === 'edit' && $context['phase'] === 'preview') {
			$context['phase'] = 'configure';
		} elseif ($action === 'preview' && $context['phase'] === 'configure') {
			$context['start'] = MemberSubscriptionInvoice::startDate(GETPOSTINT('startyear'), GETPOSTINT('startmonth'), GETPOSTINT('startday'));
			$context['template'] = GETPOST('description', 'alphanohtml');
			$context['createcustomers'] = (bool) GETPOSTINT('createcustomers');
			if ($context['createcustomers'] && !MemberSubscriptionInvoice::canCreateCustomer($user)) {
				throw new RuntimeException('NotEnoughPermissions');
			}
			foreach ($context['ids'] as $memberid) {
				if (GETPOSTISSET('amount_'.$memberid)) {
					$context['amounts'][$memberid] = GETPOST('amount_'.$memberid, 'alphanohtml');
				}
			}
			$context['preview'] = $service->preview($context['ids'], $context['start'], $context['template'], $context['amounts'], $user, !empty($context['createcustomers']));
			$context['phase'] = 'preview';
		} elseif ($action === 'create' && $context['phase'] === 'preview' && GETPOSTINT('confirm')) {
			$context['phase'] = 'results';
			foreach ($context['preview'] as $memberid => $row) {
				$context['results'][$memberid] = array('invoiceid' => 0, 'error' => $row['error'], 'pdf' => '', 'mail' => '');
				if ($row['error']) {
					continue;
				}
				try {
					$invoice = $service->create($row, $context['template'], $context['amounts'], $user, !empty($context['createcustomers']));
					$context['results'][$memberid]['invoiceid'] = $invoice->id;
				} catch (Throwable $e) {
					$context['results'][$memberid]['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'MemberInvoiceCreateFailed';
				}
			}
		} elseif (in_array($action, array('preparevalidate', 'preparemail', 'preparepdf'), true) && $context['phase'] === 'results') {
			$selected = MemberSubscriptionInvoice::selection(GETPOST('invoices', 'array:int'), getDolGlobalInt('MAIN_LIMIT_FOR_MASS_ACTIONS', 1000));
			$available = array();
			foreach ($context['results'] as $memberid => $result) {
				if ($result['invoiceid']) {
					$available[$result['invoiceid']] = $memberid;
				}
			}
			foreach ($selected as $invoiceid) {
				if (!isset($available[$invoiceid])) {
					throw new RuntimeException('NotEnoughPermissions');
				}
				$service->invoice($invoiceid, $available[$invoiceid], $user);
			}
			$operation = $action === 'preparemail' ? 'send' : 'validate';
			if (!MemberSubscriptionInvoice::canProcess($user, $operation)) {
				throw new RuntimeException('NotEnoughPermissions');
			}
			$context['selected'] = array_intersect_key($available, array_flip($selected));
			$context['phase'] = $action === 'preparemail' ? 'mail' : ($action === 'preparepdf' ? 'pdf' : 'validate');
		} elseif (in_array($action, array('validate', 'pdf'), true) && $context['phase'] === $action && GETPOSTINT('confirm')) {
			if (!MemberSubscriptionInvoice::canProcess($user, 'validate')) {
				throw new RuntimeException('NotEnoughPermissions');
			}
			$context['phase'] = 'results';
			foreach ($context['selected'] as $invoiceid => $memberid) {
				$pdfAttempted = false;
				try {
					if ($action === 'validate') {
						$service->validate($invoiceid, $memberid, $user);
					}
					$invoice = $service->invoice($invoiceid, $memberid, $user);
					if (!in_array((int) $invoice->status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
						throw new RuntimeException('MemberInvoiceNotValidated');
					}
					$pdfAttempted = true;
					$mailer->generate($invoice, $user);
					$context['results'][$memberid]['pdf'] = 'MemberInvoicePdfReady';
					$context['results'][$memberid]['error'] = '';
				} catch (Throwable $e) {
					$context['results'][$memberid]['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'MemberInvoicePdfFailed';
					if ($pdfAttempted) {
						$context['results'][$memberid]['pdf'] = 'MemberInvoicePdfFailed';
					}
				}
			}
		} elseif ($action === 'loadtemplate' && $context['phase'] === 'mail') {
			$template = $formmail->getEMailTemplate($db, 'facture_send', $user, $langs, GETPOSTINT('modelmailselected'));
			if (!is_object($template) || empty($template->id)) {
				throw new RuntimeException('MemberInvoiceTemplateMissing');
			}
			$context['subject'] = $template->topic;
			$context['body'] = $template->content;
		} elseif ($action === 'mailpreview' && $context['phase'] === 'mail') {
			if (!MemberSubscriptionInvoice::canProcess($user, 'send') || !isValidEmail($user->email)) {
				throw new RuntimeException('MemberInvoiceMailRequired');
			}
			$context['subject'] = GETPOST('subject', 'alphanohtml');
			$context['body'] = GETPOST('message', 'restricthtml');
			$context['resend'] = (bool) GETPOSTINT('resend');
			if (trim($context['subject']) === '' || trim($context['body']) === '') {
				throw new RuntimeException('MemberInvoiceMailRequired');
			}
			$context['mailpreview'] = array();
			foreach ($context['selected'] as $invoiceid => $memberid) {
				try {
					$invoice = $service->invoice($invoiceid, $memberid, $user);
					$status = $mailer->lastStatus($invoiceid);
					if ($status === 'MEMBER_INVOICE_SEND_PENDING' || ($status && !$context['resend'])) {
						throw new RuntimeException($status === 'MEMBER_INVOICE_SEND_PENDING' ? 'MemberInvoiceMailUnknown' : 'MemberInvoiceConfirmResend');
					}
					$context['mailpreview'][$invoiceid] = $mailer->prepare($invoice, $user, $context['subject'], $context['body']);
					$context['mailpreview'][$invoiceid]['error'] = '';
				} catch (RuntimeException $e) {
					$context['mailpreview'][$invoiceid] = array('error' => $e->getMessage());
				}
			}
			$context['phase'] = 'mailpreview';
		} elseif ($action === 'send' && $context['phase'] === 'mailpreview' && GETPOSTINT('confirm')) {
			if (!MemberSubscriptionInvoice::canProcess($user, 'send')) {
				throw new RuntimeException('NotEnoughPermissions');
			}
			$context['phase'] = 'results';
			foreach ($context['selected'] as $invoiceid => $memberid) {
				try {
					if ($context['mailpreview'][$invoiceid]['error']) {
						throw new RuntimeException($context['mailpreview'][$invoiceid]['error']);
					}
					$invoice = $service->invoice($invoiceid, $memberid, $user);
					$context['results'][$memberid]['mail'] = $mailer->send($invoice, $user, $context['subject'], $context['body'], $context['mailpreview'][$invoiceid]['fingerprint'], $context['resend']);
				} catch (Throwable $e) {
					$context['results'][$memberid]['mail'] = $e instanceof RuntimeException ? $e->getMessage() : 'MemberInvoiceMailUnknown';
				}
			}
		} elseif ($action === 'back' && in_array($context['phase'], array('validate', 'pdf', 'mail', 'mailpreview'), true)) {
			$context['phase'] = $context['phase'] === 'mailpreview' ? 'mail' : 'results';
		} else {
			throw new RuntimeException('MemberInvoiceExpired');
		}
	} catch (RuntimeException $e) {
		setEventMessages($langs->trans($e->getMessage()), null, 'errors');
	}
	header('Location: '.$url);
	exit;
}

/*
 * View
 */
llxHeader('', $langs->trans('MemberInvoiceCreate'));
print load_fiche_titre($langs->trans('MemberInvoiceCreate'), '', 'bill');
print '<p>'.$langs->trans('MemberInvoiceExplanation').'</p>';
print '<a href="'.DOL_URL_ROOT.'/adherents/list.php">'.$langs->trans('BackToList').'</a>';

/**
 * @param string $url Form target
 * @param string $nonce Single-use workflow token
 * @param string $action Action, or empty for named buttons
 * @return void
 */
function memberInvoiceFormStart($url, $nonce, $action = '')
{
	print '<form method="POST" action="'.dolPrintHTMLForAttribute($url).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="nonce" value="'.dolPrintHTMLForAttribute($nonce).'">';
	if ($action !== '') {
		print '<input type="hidden" name="action" value="'.dolPrintHTMLForAttribute($action).'">';
	}
}

if (in_array($context['phase'], array('configure', 'preview'), true)) {
	try {
		$rows = $service->preview($context['ids'], $context['start'], $context['template'], $context['amounts'], $user, !empty($context['createcustomers']));
		if ($context['phase'] === 'preview') {
			$context['preview'] = $rows;
		}
		memberInvoiceFormStart($url, $context['nonce'], $context['phase'] === 'configure' ? 'preview' : 'create');
		if ($context['phase'] === 'configure') {
			print '<p>'.$langs->trans('DateSubscription').' ';
			print $form->selectDate($context['start'], 'start', 0, 0, 0, '', 1, 1, 0, '', '', '', '', 1, '', '', 'gmt');
			print '</p><p>'.$langs->trans('Description').' <input class="minwidth300" name="description" value="'.dolPrintHTMLForAttribute($context['template']).'"></p>';
			print '<p class="opacitymedium">'.$langs->trans('MemberInvoicePlaceholders').'</p>';
			if (MemberSubscriptionInvoice::canCreateCustomer($user)) {
				print '<p><label><input type="checkbox" name="createcustomers" value="1"'.(!empty($context['createcustomers']) ? ' checked' : '').'> '.$langs->trans('MemberInvoiceCreateCustomers').'</label></p>';
				print '<p class="opacitymedium">'.$langs->trans('MemberInvoiceCreateCustomersHelp').'</p>';
			}
		}
		if ($context['phase'] === 'preview' && !empty($context['createcustomers'])) {
			print '<p class="warning">'.$langs->trans('MemberInvoiceCreateCustomersHelp').'</p>';
		}
		if (getDolGlobalInt('MEMBER_SUBSCRIPTION_SUGGEST_END_OF_MONTH') || getDolGlobalInt('MEMBER_SUBSCRIPTION_SUGGEST_END_OF_YEAR')) {
			print '<p class="warning">'.$langs->trans('MemberInvoiceCalendarOverride').'</p>';
		}
		print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
		foreach (array('Member', 'Type', 'Amount', 'DateSubscription', 'DateEndSubscription', 'ThirdParty', 'Description', 'Status') as $key) {
			print '<th>'.$langs->trans($key).'</th>';
		}
		print '</tr>';
		$valid = 0;
		foreach ($rows as $memberid => $row) {
			print '<tr class="oddeven"><td>'.dolPrintText($row['name']).'</td><td>'.dolPrintText($row['type']);
			if ($row['type'] !== '') {
				print '<br><span class="opacitymedium">'.$langs->trans('Duration').': '.($row['duration'] === '' ? $langs->trans('MemberInvoiceDurationNotSet') : dolPrintText($row['duration'])).'</span>';
			}
			if ($row['formula']) {
				print '<br>'.dolPrintHTML($row['formula']);
			}
			print '</td><td>';
			if ($context['phase'] === 'configure' && $row['editable']) {
				$value = isset($context['amounts'][$memberid]) ? $context['amounts'][$memberid] : ($row['amount'] === null ? '' : price($row['amount']));
				print '<input class="width75" name="amount_'.$memberid.'" value="'.dolPrintHTMLForAttribute($value).'">';
			} else {
				print $row['amount'] === null ? '' : price($row['amount'], 0, $langs, 1, -1, -1, $conf->currency);
			}
			print '</td><td>'.dol_print_date($row['start'], 'day', 'gmt').'</td><td>'.($row['end'] === null ? '' : dol_print_date($row['end'], 'day', 'gmt')).'</td>';
			print '<td>'.dolPrintText($row['customer']);
			if (!empty($row['newcustomer'])) {
				print '<br><span class="warning">'.$langs->trans('MemberInvoiceCustomerPlanned').'</span>';
			}
			print '</td><td>'.dolPrintText($row['description']).'</td><td>';
			print $row['error'] ? '<span class="error">'.$langs->trans($row['error']).'</span>' : $langs->trans('MemberInvoiceReady');
			if (!$row['error'] && !isValidEmail($row['email'])) {
				print '<br><span class="warning">'.$langs->trans('MemberInvoiceRecipientError').'</span>';
			}
			print '</td></tr>';
			$valid += $row['error'] ? 0 : 1;
		}
		print '</table></div>';
		if ($context['phase'] === 'configure') {
			print '<p><button class="button" type="submit">'.$langs->trans('Preview').'</button></p>';
		} elseif ($valid) {
			print '<p><label><input type="checkbox" name="confirm" value="1" required> '.$langs->trans('MemberInvoiceConfirmCreate', $valid, count($rows) - $valid).'</label></p>';
			print '<button class="button" type="submit">'.$langs->trans('MemberInvoiceCreate').'</button>';
		}
		print '</form>';
		if ($context['phase'] === 'preview') {
			memberInvoiceFormStart($url, $context['nonce'], 'edit');
			print '<button class="button" type="submit">'.$langs->trans('Modify').'</button></form>';
		}
	} catch (RuntimeException $e) {
		print '<p class="error">'.$langs->trans($e->getMessage()).'</p>';
	}
} else {
	$created = count(array_filter($context['results'], static function ($result) { return !empty($result['invoiceid']); }));
	print '<p>'.$langs->trans('MemberInvoiceSummary', count($context['ids']), $created, count($context['ids']) - $created).'</p>';
	memberInvoiceFormStart($url, $context['nonce']);
	print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><th></th>';
	foreach (array('Member', 'Invoice', 'Status', 'PDF', 'EMail', 'Error') as $key) {
		print '<th>'.$langs->trans($key).'</th>';
	}
	print '</tr>';
	foreach ($context['results'] as $memberid => $result) {
		print '<tr class="oddeven"><td>';
		try {
			if ($result['invoiceid']) {
				$invoice = $service->invoice($result['invoiceid'], $memberid, $user);
				if ($context['phase'] === 'results') {
					print '<input type="checkbox" name="invoices[]" value="'.((int) $invoice->id).'">';
				} elseif (isset($context['selected'][$invoice->id])) {
					print '<input type="checkbox" checked disabled>';
				}
				print '</td><td>'.dolPrintText($context['preview'][$memberid]['name']).'</td><td>'.$invoice->getNomUrl(1).'</td><td>'.$invoice->getLibStatut(0).'</td>';
			} else {
				if (restrictedArea($user, 'adherent', $memberid, '', '', 'socid', 'rowid', 0, 1) <= 0) {
					throw new RuntimeException('NotEnoughPermissions');
				}
				print '</td><td>'.dolPrintText($context['preview'][$memberid]['name']).'</td><td></td><td>'.$langs->trans('MemberInvoiceSkipped').'</td>';
			}
			print '<td>'.($result['pdf'] ? $langs->trans($result['pdf']) : $langs->trans('MemberInvoiceNotGenerated')).'</td>';
			print '<td>'.($result['mail'] ? $langs->trans($result['mail']) : $langs->trans('MemberInvoiceNotSent')).'</td>';
			print '<td>'.($result['error'] ? $langs->trans($result['error']) : '').'</td>';
		} catch (RuntimeException $e) {
			print '</td><td colspan="6">'.$langs->trans($e->getMessage()).'</td>';
		}
		print '</tr>';
	}
	print '</table></div>';
	if ($context['phase'] === 'results') {
		if (MemberSubscriptionInvoice::canProcess($user, 'validate')) {
			print '<button class="button" name="action" value="preparevalidate">'.$langs->trans('MemberInvoiceValidatePdf').'</button>';
			print '<button class="button" name="action" value="preparepdf">'.$langs->trans('ReGeneratePDF').'</button>';
		}
		if (MemberSubscriptionInvoice::canProcess($user, 'send')) {
			print '<button class="button" name="action" value="preparemail">'.$langs->trans('SendByMail').'</button>';
		}
	}
	print '</form>';

	if (in_array($context['phase'], array('validate', 'pdf'), true)) {
		memberInvoiceFormStart($url, $context['nonce'], $context['phase']);
		print '<p>'.$langs->trans('MemberInvoiceConfirmProcessing', count($context['selected'])).'</p>';
		print '<label><input type="checkbox" name="confirm" value="1" required> '.$langs->trans($context['phase'] === 'validate' ? 'MemberInvoiceValidatePdf' : 'ReGeneratePDF').'</label>';
		print '<button class="button">'.$langs->trans('Confirm').'</button></form>';
	}
	if ($context['phase'] === 'mail') {
		$templateCount = $formmail->fetchAllEMailTemplate('facture_send', $user, $langs);
		memberInvoiceFormStart($url, $context['nonce'], 'loadtemplate');
		print '<select name="modelmailselected">';
		if ($templateCount > 0) {
			foreach ($formmail->lines_model as $template) {
				print '<option value="'.((int) $template->id).'">'.dolPrintText($template->label).'</option>';
			}
		}
		print '</select><button class="button">'.$langs->trans('MemberInvoiceLoadTemplate').'</button></form>';
		memberInvoiceFormStart($url, $context['nonce'], 'mailpreview');
		print '<p>'.$langs->trans('MailFrom').': '.dolPrintText($user->email).'</p>';
		print '<p>'.$langs->trans('MailTopic').' <input class="minwidth300" name="subject" value="'.dolPrintHTMLForAttribute(isset($context['subject']) ? $context['subject'] : $langs->transnoentities('SendBillRef', '__REF__')).'"></p>';
		$editor = new DolEditor('message', isset($context['body']) ? $context['body'] : $langs->transnoentities('MemberInvoiceDefaultMail'), '', 200, 'dolibarr_mailings', 'In', false, true, isModEnabled('fckeditor'), 10, '90%');
		$editor->Create();
		print '<p><label><input type="checkbox" name="resend" value="1"> '.$langs->trans('MemberInvoiceConfirmResend').'</label></p>';
		print '<button class="button">'.$langs->trans('Preview').'</button></form>';
	}
	if ($context['phase'] === 'mailpreview') {
		$sendable = 0;
		foreach ($context['selected'] as $invoiceid => $memberid) {
			try {
				$invoice = $service->invoice($invoiceid, $memberid, $user);
				print '<p>'.$invoice->getNomUrl(1).'</p>';
				if ($context['mailpreview'][$invoiceid]['error']) {
					throw new RuntimeException($context['mailpreview'][$invoiceid]['error']);
				}
				$message = $context['mailpreview'][$invoiceid];
				print '<p>'.$langs->trans('MailFrom').': '.dolPrintText($message['from']).'</p>';
				print '<p>'.$langs->trans('MailRecipient').': '.dolPrintText(implode(', ', $message['to'])).'</p>';
				if ($message['bcc']) {
					print '<p>'.$langs->trans('MemberInvoiceBcc').': '.dolPrintText($message['bcc']).'</p>';
				}
				print '<p>'.dolPrintText($message['subject']).'</p>';
				print '<div class="border">'.dolPrintHTML($message['body']).'</div>';
				print '<p>'.$langs->trans('AttachedFiles').': '.dolPrintText(dol_basename($message['path'])).'</p>';
				$sendable++;
			} catch (RuntimeException $e) {
				print '<p class="error">'.$langs->trans($e->getMessage()).'</p>';
			}
		}
		if ($sendable) {
			memberInvoiceFormStart($url, $context['nonce'], 'send');
			print '<p><label><input type="checkbox" name="confirm" value="1" required> '.$langs->trans('MemberInvoiceConfirmSend', $sendable).'</label></p>';
			print '<button class="button">'.$langs->trans('SendMail').'</button></form>';
		}
	}
	if ($context['phase'] !== 'results') {
		memberInvoiceFormStart($url, $context['nonce'], 'back');
		print '<button class="button">'.$langs->trans('Cancel').'</button></form>';
	}
}
llxFooter();
$db->close();
