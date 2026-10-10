<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Isolated scenarios shared by PHPUnit and the dependency-free CLI runner. */
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/html.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/membersubscriptioninvoice.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/membersubscriptioninvoicemail.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/** @return void Initialize only synthetic configuration and language data. */
function memberInvoiceTestEnvironment()
{
	global $conf, $langs;
	$conf = (object) array('global' => (object) array('MAIN_MAX_DECIMALS_TOT' => 2, 'MAIN_MAX_DECIMALS_UNIT' => 5), 'modules' => array('member' => 1, 'invoice' => 1, 'societe' => 1), 'cache' => array());
	$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
	$langs->setDefaultLang('en_US');
	$langs->tab_translate = array('FormatDateShort' => '%m/%d/%Y', 'SeparatorDecimal' => '.', 'SeparatorThousand' => ',');
}

/** User double: deny only explicitly listed permissions. */
class MemberInvoiceTestUser
{
	/** @var int User ID */
	public $id = 1;
	/** @var string Synthetic sender */
	public $email = 'sender@example.invalid';
	/** @var int External user third party */
	public $socid = 0;
	/** @var string[] Denied rights */
	public $denied = array();
	/**
	 * @param string ...$parts Permission path
	 * @return bool
	 */
	public function hasRight(...$parts)
	{
		return !in_array(implode('.', $parts), $this->denied, true);
	}
}

/** No connection: records transaction and locking calls only. */
class MemberInvoiceTestDatabase
{
	/** @var string[] Recorded operations */
	public $calls = array();
	/** @var string Failure injection */
	public $fail = '';
	/** @return int */
	public function begin()
	{
		$this->calls[] = 'begin';
		return $this->fail === 'begin' ? 0 : 1;
	}
	/** @return int */
	public function commit()
	{
		$this->calls[] = 'commit';
		return $this->fail === 'commit' ? 0 : 1;
	}
	/** @return int */
	public function rollback()
	{
		$this->calls[] = 'rollback';
		return 1;
	}
	/** @return string */
	public function prefix()
	{
		return 'test_';
	}
	/**
	 * @param string $sql Query
	 * @return bool
	 */
	public function query($sql)
	{
		if (!preg_match('/^SELECT rowid FROM test_(adherent|facture) WHERE rowid = [0-9]+ FOR UPDATE$/D', $sql)) {
			throw new LogicException('Unexpected query: '.$sql);
		}
		$this->calls[] = 'lock';
		return $this->fail !== 'lock';
	}
	/**
	 * @param mixed $res Result
	 * @return object
	 */
	public function fetch_object($res)
	{
		return (object) array('rowid' => 1);
	}
}

/** Invoice double fails closed if any payment/subscription API is attempted. */
class MemberInvoiceTestInvoice extends stdClass
{
	/** @var int Invoice type */
	public $type = 0;
	/** @var string PDF model */
	public $model_pdf = 'test';
	/** @var int Invoice status */
	public $status = 0;
	/** @var string Invoice reference */
	public $ref = 'TEST-17';
	/** @var int Third party ID */
	public $socid = 2;
	/** @var array<int,array{email:string}> Invoice billing contacts */
	public $contacts = array();
	/** @var object Third party */
	public $thirdparty;
	/** @var int Invoice ID */
	public $id = 17;
	/** @var string Failure injection */
	public $fail = '';
	/** @var array<string,mixed> Recorded calls */
	public $calls = array();
	/**
	 * @param object $user User
	 * @return int
	 */
	public function create($user)
	{
		$this->calls['create'] = true;
		return $this->fail === 'create' ? -1 : $this->id;
	}
	/**
	 * @param mixed ...$args Line arguments
	 * @return int
	 */
	public function addline(...$args)
	{
		$this->calls['line'] = $args;
		return $this->fail === 'line' ? -1 : 1;
	}
	/**
	 * @param string $origin Source element
	 * @param int $id Member ID
	 * @return int
	 */
	public function add_object_linked($origin, $id)
	{
		$this->calls['link'] = array($origin, $id);
		return $this->fail === 'link' ? -1 : 1;
	}
	/** @param int $id Invoice ID @return int */
	public function fetch($id)
	{
		return 1;
	}
	/** @return int */
	public function fetch_thirdparty()
	{
		return 1;
	}
	/** @param User $user User @return int */
	public function validate($user)
	{
		$this->calls['validate'] = true;
		if ($this->fail === 'validate') {
			return -1;
		}
		$this->status = Facture::STATUS_VALIDATED;
		return 1;
	}
	/** @param mixed ...$args Contact filter @return array */
	public function liste_contact(...$args)
	{
		return $this->contacts;
	}
	/** @param string $name Trigger @param User $user User @return int */
	public function call_trigger($name, $user)
	{
		$this->calls['trigger'] = $name;
		return $this->fail === 'trigger' ? -1 : 1;
	}
	/** @param string $model Model @param Translate $langs Language @return int */
	public function generateDocument($model, $langs)
	{
		$this->calls['pdf'] = true;
		return $this->fail === 'pdf' ? -1 : 1;
	}
}

/** Reuses the real transaction orchestration with synthetic preview/invoice. */
class MemberInvoiceTestService extends MemberSubscriptionInvoice
{
	/** @var array<string,mixed> Refreshed preview */
	public $row;
	/** @var MemberInvoiceTestInvoice Invoice double */
	public $draft;
	/** @var MemberInvoiceTestDatabase Database double */
	public $connection;
	/**
	 * @param int[] $ids IDs
	 * @param int $start Start
	 * @param string $template Template
	 * @param array<int,string> $amounts Overrides
	 * @param object $user User
	 * @return array<int,array<string,mixed>>
	 */
	public function preview(array $ids, $start, $template, array $amounts, $user, $createCustomers = false)
	{
		$this->connection->calls[] = 'refresh';
		return array($this->row['id'] => $this->row);
	}
	/** @return MemberInvoiceTestInvoice */
	protected function newInvoice()
	{
		return $this->draft;
	}
	/** @param int $id Invoice ID @param int $member Member ID @param User $user User @return object */
	public function invoice($id, $member, $user)
	{
		return $this->draft;
	}
}

/** Durable log double, with no database access. */
class MemberInvoiceTestCustomerService extends MemberInvoiceTestService
{
	/** @var bool Synthetic customer was created */
	public $customerCreated = false;
	/** @var string Failure injection */
	public $failure = '';
	/** @param int $id Member ID @param User $user User @param bool $persist Create @return object */
	protected function memberCustomer($id, $user, $persist = false)
	{
		$this->connection->calls[] = 'customer';
		if (!$persist || $this->failure === 'customer') {
			throw new RuntimeException('MemberInvoiceCustomerCreateFailed');
		}
		$this->customerCreated = true;
		return (object) array('id' => 42);
	}
	/** @param int[] $ids IDs @param int $start Date @param string $template Template @param array $amounts Amounts @param User $user User @param bool $createCustomers Consent @return array */
	public function preview(array $ids, $start, $template, array $amounts, $user, $createCustomers = false)
	{
		$rows = parent::preview($ids, $start, $template, $amounts, $user, $createCustomers);
		if ($this->customerCreated) {
			$rows[1]['socid'] = 42;
			$rows[1]['newcustomer'] = false;
			if ($this->failure === 'defaults') {
				$rows[1]['terms'] = 7;
			}
		}
		return $rows;
	}
}

/** Durable log double, with no database access. */
class MemberInvoiceTestEvent extends stdClass
{
	/** @var string Durable attempt status */
	public $saved = '';
	/** @var string Failure injection */
	public $fail = '';
	/** @param User $user User @return int */
	public function create($user)
	{
		if ($this->fail === 'logcreate') {
			return -1;
		}
		$this->saved = $this->code;
		return 1;
	}
	/** @param User $user User @return int */
	public function update($user)
	{
		if ($this->fail === 'logupdate') {
			return -1;
		}
		$this->saved = $this->code;
		return 1;
	}
}

/** No transport is constructed: records only a send attempt. */
class MemberInvoiceTestTransport
{
	/** @var string Constructor error */
	public $error = '';
	/** @var string Failure injection */
	public $fail = '';
	/** @var bool Transport was called */
	public $called = false;
	/** @return int */
	public function sendfile()
	{
		if (getDolGlobalInt('SYSLOG_LEVEL') !== -1 || getDolGlobalInt('MAIN_MAIL_DEBUG') !== 0) {
			throw new LogicException('Transport debug output could disclose recipients');
		}
		$this->called = true;
		if ($this->fail === 'timeout') {
			throw new RuntimeException('SimulatedTimeout');
		}
		return $this->fail === 'send' ? 0 : 1;
	}
}

/** Real send orchestration, with synthetic messages and transport. */
class MemberInvoiceTestMailer extends MemberSubscriptionInvoiceMail
{
	/** @var MemberInvoiceTestEvent Log */
	public $event;
	/** @var MemberInvoiceTestTransport Transport */
	public $transport;
	/** @var MemberInvoiceTestDatabase Database double */
	public $connection;
	/** @var array<string,mixed> Captured mail arguments */
	public $message = array();
	/** @param Facture $invoice Invoice @return Translate */
	public function outputLangs($invoice)
	{
		global $langs;
		return $langs;
	}
	/** @param Facture $invoice Invoice @return string */
	public function document($invoice)
	{
		if ($invoice->fail === 'missingpdf') {
			throw new RuntimeException('MemberInvoiceMissingPdf');
		}
		return '/synthetic/'.$invoice->ref.'.pdf';
	}
	/** @param Facture $invoice Invoice @param User $user User @param string $subject Subject @param string $body Body @return array */
	public function prepare($invoice, $user, $subject, $body)
	{
		return array('fingerprint' => 'confirmed', 'subject' => $subject, 'body' => $body, 'from' => $user->email, 'to' => $this->recipients($invoice), 'bcc' => '', 'path' => '/synthetic/'.$invoice->ref.'.pdf');
	}
	/** @param int $id Invoice ID @return string */
	public function lastStatus($id)
	{
		return $this->event->saved;
	}
	/** @return MemberInvoiceTestEvent */
	protected function newEvent()
	{
		return $this->event;
	}
	/** @param Facture $invoice Invoice @param array $message Message @return MemberInvoiceTestTransport */
	protected function newMail($invoice, array $message)
	{
		if (end($this->connection->calls) !== 'commit' || $this->event->saved !== 'MEMBER_INVOICE_SEND_PENDING') {
			throw new LogicException('Sending before durable commit');
		}
		$this->message = $message;
		return $this->transport;
	}
}

/** Feeds synthetic linked periods into the real duplicate detector. */
class MemberInvoiceTestDuplicateDatabase extends MemberInvoiceTestDatabase
{
	/** @var object[] Rows */
	public $rows = array();
	/** @param string $sql Query @return bool */
	public function query($sql)
	{
		if (strpos($sql, 'UNION ALL') === false || strpos($sql, "e.sourcetype = 'subscription'") === false || substr_count($sql, 'f.entity = 1') !== 2 || strpos($sql, 'f.fk_statut') !== false) {
			throw new LogicException('Duplicate query lost its scope or legacy/cancelled invoices');
		}
		return true;
	}
	/** @param mixed $res Result @return object|null */
	public function fetch_object($res)
	{
		return array_shift($this->rows);
	}
	/** @param int $date Synthetic timestamp @return int */
	public function jdate($date)
	{
		return $date;
	}
}

/** Synthetic member selection and empty duplicate result. */
class MemberInvoiceTestPreviewDatabase extends MemberInvoiceTestDatabase
{
	/** @var object[] Member rows */
	public $rows = array();
	/** @param string $sql Query @return object */
	public function query($sql)
	{
		if (strpos($sql, 'SELECT m.rowid,') === 0) {
			return (object) array('rows' => $this->rows);
		}
		if (strpos($sql, 'UNION ALL') !== false) {
			return (object) array('rows' => array());
		}
		throw new LogicException('Unexpected preview query');
	}
	/** @param object $res Result @return object|null */
	public function fetch_object($res)
	{
		return array_shift($res->rows);
	}
	/** @param object $res Result @return void */
	public function free($res)
	{
	}
}

/** Exercises real preview assembly with an accessible synthetic customer. */
class MemberInvoiceTestPreviewService extends MemberSubscriptionInvoice
{
	/** @param User $user User @param int $id Member ID @return bool */
	protected function canReadMember($user, $id)
	{
		return true;
	}
	/** @param int $id Customer ID @param User $user User @return object */
	protected function customer($id, $user)
	{
		return (object) array('id' => $id, 'name' => 'Synthetic customer', 'email' => 'customer@example.invalid');
	}
}

/**
 * @param callable $operation Operation expected to fail
 * @param string $key Expected error
 * @return bool
 */
function memberInvoiceExpectError($operation, $key)
{
	try {
		$operation();
	} catch (RuntimeException $e) {
		return $e->getMessage() === $key;
	}
	return false;
}

/**
 * No scenario connects to a DB, boots Dolibarr, creates files or sends mail.
 *
 * @return array<string,callable():bool>
 */
function memberInvoiceScenarios()
{
	$start = MemberSubscriptionInvoice::startDate(2027, 1, 1);
	$scenarios = array();
	foreach (array('d', 'w', 'm', 'y') as $unit) {
		$scenarios['implicit_one_'.$unit] = static function () use ($start, $unit) {
			return MemberSubscriptionInvoice::endDate($start, $unit, false, false) === MemberSubscriptionInvoice::endDate($start, '1'.$unit, false, false);
		};
	}
	foreach (array('12m' => array(2027, 12, 31), '6m' => array(2027, 6, 30), '1y' => array(2027, 12, 31), '1d' => array(2027, 1, 1)) as $duration => $date) {
		$scenarios['period_'.$duration] = static function () use ($start, $duration, $date) {
			return MemberSubscriptionInvoice::endDate($start, $duration, false, false) === MemberSubscriptionInvoice::startDate($date[0], $date[1], $date[2]);
		};
	}
	$scenarios['leap_year'] = static function () {
		return MemberSubscriptionInvoice::endDate(MemberSubscriptionInvoice::startDate(2028, 2, 1), '1m', false, false) === MemberSubscriptionInvoice::startDate(2028, 2, 29);
	};
	$scenarios['calendar_override_precedence'] = static function () use ($start) {
		return MemberSubscriptionInvoice::endDate($start, '6m', true, true) === MemberSubscriptionInvoice::startDate(2027, 1, 31)
			&& MemberSubscriptionInvoice::endDate($start, '6m', false, true) === MemberSubscriptionInvoice::startDate(2027, 12, 31);
	};
	$scenarios['invalid_calendar_date'] = static function () {
		return memberInvoiceExpectError(static function () { MemberSubscriptionInvoice::startDate(2027, 2, 29); }, 'MemberInvoiceInvalidPeriod');
	};
	foreach (array('', '0m', '-1y', '2x', '1m garbage') as $duration) {
		$scenarios['invalid_duration_'.$duration] = static function () use ($start, $duration) {
			return memberInvoiceExpectError(static function () use ($start, $duration) { MemberSubscriptionInvoice::endDate($start, $duration, false, false); }, 'MemberInvoiceInvalidDuration');
		};
	}
	$scenarios['individual_fixed_amounts'] = static function () {
		return MemberSubscriptionInvoice::amount('120', '999', false, 10, 0) === 120.0
			&& MemberSubscriptionInvoice::amount('60', null, false, 10, 0) === 60.0;
	};
	$scenarios['editable_amount'] = static function () {
		return MemberSubscriptionInvoice::amount(null, '125.50', true, 100, 0) === 125.5;
	};
	foreach (array(null, '', '-1', '0', 'invalid', 'INF') as $amount) {
		$scenarios['invalid_amount_'.var_export($amount, true)] = static function () use ($amount) {
			return memberInvoiceExpectError(static function () use ($amount) { MemberSubscriptionInvoice::amount($amount, null, false, null, 0); }, 'MemberInvoiceInvalidAmount');
		};
	}
	$scenarios['invalid_override_is_not_sanitized_into_price'] = static function () {
		return memberInvoiceExpectError(static function () { MemberSubscriptionInvoice::amount('100', 'garbage100', true, 0, 0); }, 'MemberInvoiceInvalidAmount');
	};
	foreach (array('1+2', '1,,2', '12,34', '1 2') as $input) {
		$scenarios['malformed_localized_amount_'.$input] = static function () use ($input) {
			return memberInvoiceExpectError(static function () use ($input) { MemberSubscriptionInvoice::amount('100', $input, true, 0, 0); }, 'MemberInvoiceInvalidAmount');
		};
	}
	$scenarios['german_localized_amount'] = static function () {
		global $langs;
		$saved = $langs->tab_translate;
		try {
			$langs->tab_translate['SeparatorDecimal'] = ',';
			$langs->tab_translate['SeparatorThousand'] = '.';
			return MemberSubscriptionInvoice::amount('100', '1.234,56', true, 0, 0) === 1234.56;
		} finally {
			$langs->tab_translate = $saved;
		}
	};
	$scenarios['rounded_amount_respects_minimum'] = static function () {
		return memberInvoiceExpectError(static function () { MemberSubscriptionInvoice::amount('0.014', null, false, '0.014', 0); }, 'MemberInvoiceBelowMinimum');
	};
	$scenarios['minimum_not_silently_increased'] = static function () {
		return memberInvoiceExpectError(static function () { MemberSubscriptionInvoice::amount('10', null, false, 20, 0); }, 'MemberInvoiceBelowMinimum');
	};
	$scenarios['global_minimum'] = static function () {
		return memberInvoiceExpectError(static function () { MemberSubscriptionInvoice::amount('30', null, false, 20, 40); }, 'MemberInvoiceBelowMinimum');
	};
	$scenarios['selection_deduplicated'] = static function () {
		return MemberSubscriptionInvoice::selection(array(2, 1, 2), 3) === array(1, 2);
	};
	foreach (array(array(), array(0), array(-1), array(1, 2, 3), array('1 OR 1=1')) as $index => $ids) {
		$scenarios['selection_refused_'.$index] = static function () use ($ids) {
			return memberInvoiceExpectError(static function () use ($ids) { MemberSubscriptionInvoice::selection($ids, 2); }, 'MemberInvoiceInvalidSelection');
		};
	}
	$scenarios['description_is_individual'] = static function () use ($start) {
		return strpos(MemberSubscriptionInvoice::description('Fee {member_type} {start_date} {end_date}', 'Junior', $start, $start), 'Junior') !== false;
	};
	$scenarios['unsupported_placeholder'] = static function () use ($start) {
		return memberInvoiceExpectError(static function () use ($start) { MemberSubscriptionInvoice::description('{execute}', 'Type', $start, $start); }, 'MemberInvoiceInvalidDescription');
	};
	$scenarios['billing_addresses_are_deduplicated'] = static function () {
		return MemberSubscriptionInvoiceMail::checkedRecipients(array('a@example.invalid', 'a@example.invalid')) === array('a@example.invalid');
	};
	foreach (array(array(), array(''), array('not-an-email'), array('a@example.invalid', '')) as $index => $addresses) {
		$scenarios['invalid_recipient_'.$index] = static function () use ($addresses) {
			return memberInvoiceExpectError(static function () use ($addresses) { MemberSubscriptionInvoiceMail::checkedRecipients($addresses); }, 'MemberInvoiceRecipientError');
		};
	}
	$scenarios['permissions_basic_and_advanced'] = static function () {
		global $conf;
		$user = new MemberInvoiceTestUser();
		$conf->global->MAIN_USE_ADVANCED_PERMS = 0;
		$user->denied = array('facture.creer');
		$basic = !MemberSubscriptionInvoice::canCreate($user) && !MemberSubscriptionInvoice::canProcess($user, 'validate') && MemberSubscriptionInvoice::canProcess($user, 'send');
		$conf->global->MAIN_USE_ADVANCED_PERMS = 1;
		$advanced = MemberSubscriptionInvoice::canProcess($user, 'validate');
		$user->denied[] = 'facture.invoice_advance.send';
		$advanced = $advanced && !MemberSubscriptionInvoice::canProcess($user, 'send');
		$user->socid = 1;
		return $basic && $advanced && !MemberSubscriptionInvoice::canRead($user);
	};
	foreach (array('', 'create', 'line', 'link', 'lock', 'commit', 'changed', 'duplicate') as $failure) {
		$scenarios['transaction_'.($failure ?: 'success_no_subscription_payment_or_validation')] = static function () use ($failure, $start) {
			$connection = new MemberInvoiceTestDatabase();
			$connection->fail = $failure;
			$service = new MemberInvoiceTestService($connection);
			$service->connection = $connection;
			$service->draft = new MemberInvoiceTestInvoice();
			$service->draft->fail = $failure;
			$row = array('id' => 1, 'start' => $start, 'end' => $start, 'entity' => 1, 'socid' => 2, 'terms' => 1, 'paymentmode' => 0, 'bank' => 0, 'product' => 0, 'producttype' => 1, 'description' => 'Membership', 'vat' => 0, 'localtax1' => 0, 'localtax2' => 0, 'amount' => 100, 'error' => '');
			$service->row = $row;
			if ($failure === 'changed') {
				$service->row['amount'] = 200;
			}
			if ($failure === 'duplicate') {
				$service->row['error'] = 'MemberInvoiceDuplicate';
			}
			try {
				$service->create($row, 'Membership', array(), new MemberInvoiceTestUser());
			} catch (RuntimeException $e) {
				return $failure !== '' && end($connection->calls) === 'rollback'
					&& ($failure !== 'duplicate' || $e->getMessage() === 'MemberInvoiceDuplicate')
					&& ($failure !== 'changed' || $e->getMessage() === 'MemberInvoicePreviewChanged');
			}
			return $failure === '' && $connection->calls === array('begin', 'lock', 'refresh', 'commit')
				&& array_keys($service->draft->calls) === array('create', 'line', 'link')
				&& $service->draft->calls['line'][8] === $start && $service->draft->calls['line'][9] === $start
				&& $service->draft->calls['line'][13] === 'TTC' && $service->draft->calls['line'][14] === 100
				&& $service->draft->calls['link'] === array('member', 1);
		};
	}
	foreach (array('', 'validate', 'lock', 'commit', 'alreadyvalidated', 'denied') as $failure) {
		$scenarios['validation_'.($failure ?: 'success')] = static function () use ($failure) {
			$connection = new MemberInvoiceTestDatabase();
			$connection->fail = $failure;
			$service = new MemberInvoiceTestService($connection);
			$service->draft = new MemberInvoiceTestInvoice();
			$service->draft->fail = $failure;
			$user = new MemberInvoiceTestUser();
			if ($failure === 'alreadyvalidated') {
				$service->draft->status = Facture::STATUS_VALIDATED;
			}
			if ($failure === 'denied') {
				$user->denied = array('facture.creer', 'facture.invoice_advance.validate');
			}
			try {
				$service->validate(17, 1, $user);
			} catch (RuntimeException $e) {
				return $failure === 'denied' ? !$connection->calls && $e->getMessage() === 'NotEnoughPermissions' : in_array($failure, array('validate', 'lock', 'commit'), true) && end($connection->calls) === 'rollback';
			}
			return in_array($failure, array('', 'alreadyvalidated'), true) && end($connection->calls) === 'commit'
				&& $service->draft->status === Facture::STATUS_VALIDATED && ($failure !== 'alreadyvalidated' || !$service->draft->calls);
		};
	}
	foreach (array('billing', 'fallback', 'invalidbilling', 'missing') as $case) {
		$scenarios['recipient_resolution_'.$case] = static function () use ($case) {
			$invoice = new MemberInvoiceTestInvoice();
			$invoice->thirdparty = (object) array('email' => $case === 'missing' ? '' : 'customer@example.invalid');
			if ($case === 'billing' || $case === 'invalidbilling') {
				$invoice->contacts = array(array('email' => $case === 'billing' ? 'billing@example.invalid' : ''));
			}
			$mailer = new MemberSubscriptionInvoiceMail(new MemberInvoiceTestDatabase());
			if ($case === 'invalidbilling' || $case === 'missing') {
				return memberInvoiceExpectError(static function () use ($mailer, $invoice) { $mailer->recipients($invoice); }, 'MemberInvoiceRecipientError');
			}
			return $mailer->recipients($invoice) === array($case === 'billing' ? 'billing@example.invalid' : 'customer@example.invalid');
		};
	}
	foreach (array('', 'send', 'timeout', 'logcreate', 'logupdate', 'trigger', 'changed', 'pending', 'repeat', 'resend', 'denied') as $failure) {
		$scenarios['mail_'.($failure ?: 'success')] = static function () use ($failure) {
			global $conf;
			$conf->global->SYSLOG_LEVEL = 7;
			$conf->global->MAIN_MAIL_DEBUG = 1;
			$connection = new MemberInvoiceTestDatabase();
			$mailer = new MemberInvoiceTestMailer($connection);
			$mailer->connection = $connection;
			$mailer->event = new MemberInvoiceTestEvent();
			$mailer->event->fail = $failure;
			$mailer->transport = new MemberInvoiceTestTransport();
			$mailer->transport->fail = $failure;
			$invoice = new MemberInvoiceTestInvoice();
			$invoice->fail = $failure;
			$invoice->thirdparty = (object) array('email' => 'customer@example.invalid');
			$user = new MemberInvoiceTestUser();
			if ($failure === 'pending') {
				$mailer->event->saved = 'MEMBER_INVOICE_SEND_PENDING';
			} elseif ($failure === 'repeat' || $failure === 'resend') {
				$mailer->event->saved = 'MEMBER_INVOICE_SEND_SENT';
			} elseif ($failure === 'denied') {
				$user->denied = array('facture.lire');
			}
			try {
				$result = $mailer->send($invoice, $user, 'Subject', 'Body', $failure === 'changed' ? 'stale' : 'confirmed', $failure === 'resend');
			} catch (RuntimeException $e) {
				if (getDolGlobalInt('SYSLOG_LEVEL') !== 7 || getDolGlobalInt('MAIN_MAIL_DEBUG') !== 1) {
					return false;
				}
				$errors = array('pending' => 'MemberInvoiceMailUnknown', 'repeat' => 'MemberInvoiceConfirmResend', 'changed' => 'MemberInvoicePreviewChanged', 'logcreate' => 'MemberInvoiceDatabaseError', 'denied' => 'NotEnoughPermissions');
				if ($failure === 'timeout') {
					return $mailer->transport->called && $mailer->event->saved === 'MEMBER_INVOICE_SEND_PENDING' && end($connection->calls) === 'commit';
				}
				return isset($errors[$failure]) && $e->getMessage() === $errors[$failure] && !$mailer->transport->called;
			}
			$expected = array('' => 'MemberInvoiceMailSent', 'resend' => 'MemberInvoiceMailSent', 'send' => 'MemberInvoiceMailFailed', 'logupdate' => 'MemberInvoiceMailSentLogFailed', 'trigger' => 'MemberInvoiceMailSentLogFailed');
			return getDolGlobalInt('SYSLOG_LEVEL') === 7 && getDolGlobalInt('MAIN_MAIL_DEBUG') === 1 && isset($expected[$failure]) && $result === $expected[$failure] && $mailer->transport->called
				&& $mailer->message['path'] === '/synthetic/TEST-17.pdf' && $mailer->message['to'] === array('customer@example.invalid')
				&& ($failure !== 'send' || $mailer->event->saved === 'MEMBER_INVOICE_SEND_FAILED')
				&& ($failure !== 'logupdate' || $mailer->event->saved === 'MEMBER_INVOICE_SEND_PENDING');
		};
	}
	foreach (array('', 'pdf', 'missingpdf', 'draft', 'denied') as $failure) {
		$scenarios['pdf_'.($failure ?: 'success')] = static function () use ($failure) {
			$connection = new MemberInvoiceTestDatabase();
			$mailer = new MemberInvoiceTestMailer($connection);
			$invoice = new MemberInvoiceTestInvoice();
			$invoice->status = $failure === 'draft' ? Facture::STATUS_DRAFT : Facture::STATUS_VALIDATED;
			$invoice->fail = $failure;
			$user = new MemberInvoiceTestUser();
			if ($failure === 'denied') {
				$user->denied = array('facture.lire');
			}
			$errors = array('pdf' => 'MemberInvoicePdfFailed', 'missingpdf' => 'MemberInvoiceMissingPdf', 'draft' => 'MemberInvoiceNotValidated', 'denied' => 'NotEnoughPermissions');
			try {
				$mailer->generate($invoice, $user);
			} catch (RuntimeException $e) {
				return isset($errors[$failure]) && $errors[$failure] === $e->getMessage() && !$connection->calls
					&& ($failure === 'draft' || $invoice->status === Facture::STATUS_VALIDATED);
			}
			return $failure === '' && !$connection->calls && $invoice->calls === array('pdf' => true);
		};
	}
	foreach (array('overlap', 'adjacent', 'missing', 'reversed', 'othermember') as $case) {
		$scenarios['duplicate_'.$case] = static function () use ($case, $start) {
			global $conf;
			$conf->entity = 1;
			$connection = new MemberInvoiceTestDuplicateDatabase();
			$end = MemberSubscriptionInvoice::endDate($start, '1y', false, false);
			$connection->rows = array((object) array('rowid' => 17, 'memberid' => $case === 'othermember' ? 2 : 1, 'date_start' => $case === 'missing' ? 0 : $start, 'date_end' => $case === 'reversed' ? $start - 86400 : $end));
			$service = new MemberSubscriptionInvoice($connection);
			$rows = array();
			foreach (array(1, 2) as $id) {
				$rows[$id] = array('start' => $case === 'adjacent' ? $end + 86400 : $start, 'end' => $end + 86400, 'error' => '', 'duplicates' => array());
			}
			$method = new ReflectionMethod(MemberSubscriptionInvoice::class, 'withDuplicates');
			$method->setAccessible(true);
			$result = $method->invoke($service, $rows);
			return $result[1]['error'] === (in_array($case, array('adjacent', 'othermember'), true) ? '' : 'MemberInvoiceDuplicate')
				&& $result[2]['error'] === ($case === 'othermember' ? 'MemberInvoiceDuplicate' : '');
		};
	}
	$scenarios['missing_thirdparty_blocks_creation'] = static function () {
		$service = new MemberSubscriptionInvoice(new MemberInvoiceTestDatabase());
		$method = new ReflectionMethod(MemberSubscriptionInvoice::class, 'customer');
		$method->setAccessible(true);
		return memberInvoiceExpectError(static function () use ($service, $method) { $method->invoke($service, 0, new MemberInvoiceTestUser()); }, 'MemberInvoiceMissingCustomer');
	};
	foreach (array('', '0y', '1x') as $duration) {
		$scenarios['preview_preserves_customer_on_invalid_duration_'.$duration] = static function () use ($start, $duration) {
			global $conf, $hookmanager, $mc;
			$savedHookmanager = $hookmanager;
			$savedMc = $mc;
			$conf->entity = 1;
			$mc = null;
			$hookmanager = new class {
				/** @param mixed ...$args Hook arguments @return int */
				public function executeHooks(...$args)
				{
					return 0;
				}
			};
			try {
				$connection = new MemberInvoiceTestPreviewDatabase();
				$connection->rows = array((object) array('rowid' => 1, 'firstname' => 'Synthetic', 'lastname' => 'Member', 'libelle' => 'Annual member', 'duration' => $duration, 'caneditamount' => 0, 'amountformuladescription' => '', 'fk_soc' => 2, 'statut' => 1, 'typestatus' => 1, 'subscription' => 1, 'morphy' => 'phy', 'amount' => '24', 'minimumamount' => '0'));
				$service = new MemberInvoiceTestPreviewService($connection);
				$rows = $service->preview(array(1), $start, 'Fee {member_type}', array(), new MemberInvoiceTestUser());
				return $rows[1]['customer'] === 'Synthetic customer' && $rows[1]['socid'] === 2 && $rows[1]['amount'] === 24.0
					&& $rows[1]['end'] === null && $rows[1]['duration'] === $duration && $rows[1]['error'] === 'MemberInvoiceInvalidDuration';
			} finally {
				$hookmanager = $savedHookmanager;
				$mc = $savedMc;
			}
		};
	}
	foreach (array('', 'customer', 'line', 'link', 'defaults', 'denied', 'no_consent', 'ineligible') as $failure) {
		$scenarios['customer_and_invoice_'.($failure ?: 'success')] = static function () use ($failure, $start) {
			$connection = new MemberInvoiceTestDatabase();
			$service = new MemberInvoiceTestCustomerService($connection);
			$service->connection = $connection;
			$service->failure = $failure;
			$service->draft = new MemberInvoiceTestInvoice();
			$service->draft->fail = $failure;
			$row = array('id' => 1, 'start' => $start, 'end' => $start, 'entity' => 1, 'socid' => 0, 'newcustomer' => true, 'terms' => 1, 'paymentmode' => 0, 'bank' => 0, 'product' => 0, 'producttype' => 1, 'description' => 'Membership', 'vat' => 0, 'localtax1' => 0, 'localtax2' => 0, 'amount' => 100, 'error' => '');
			$service->row = $row;
			$user = new MemberInvoiceTestUser();
			if ($failure === 'denied') {
				$user->denied = array('societe.creer');
			}
			if ($failure === 'ineligible') {
				$service->row['error'] = 'MemberInvoiceIneligibleMember';
			}
			try {
				$service->create($row, 'Membership', array(), $user, $failure !== 'no_consent');
			} catch (RuntimeException $e) {
				return $failure !== '' && end($connection->calls) === 'rollback'
					&& (!in_array($failure, array('denied', 'no_consent', 'ineligible'), true) || !$service->customerCreated)
					&& ($failure !== 'defaults' || $e->getMessage() === 'MemberInvoicePreviewChanged');
			}
			return $failure === '' && $service->draft->socid === 42
				&& $connection->calls === array('begin', 'lock', 'refresh', 'customer', 'refresh', 'commit');
		};
	}
	return $scenarios;
}
