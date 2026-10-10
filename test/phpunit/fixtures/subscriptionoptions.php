<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/html.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';

/** Synthetic permissions, without an application session. */
class SubscriptionOptionsTestUser
{
	/** @var int External third party */
	public $socid = 0;
	/** @var string[] Denied permissions */
	public $denied = array();
	/** @param string ...$parts Permission path @return bool */
	public function hasRight(...$parts)
	{
		return !in_array(implode('.', $parts), $this->denied, true);
	}
}

/** Fail closed on any unexpected SQL; no connection exists. */
class SubscriptionOptionsTestDatabase
{
	/** @var int Nested transaction level */
	public $transaction_opened = 0;
	/** @var string[] Operations */
	public $calls = array();
	/** @var string Failure injection */
	public $failure = '';
	/** @var mixed Latest synthetic subscription amount */
	public $latest = null;
	/** @return string */
	public function prefix()
	{
		return 'test_';
	}
	/** @param int $limit Limit @return string */
	public function plimit($limit)
	{
		return ' LIMIT '.((int) $limit);
	}
	/** @return int */
	public function begin()
	{
		$this->transaction_opened++;
		$this->calls[] = 'begin';
		return 1;
	}
	/** @return int */
	public function commit()
	{
		$this->calls[] = 'commit';
		if ($this->failure !== 'commit') {
			$this->transaction_opened--;
		}
		return $this->failure === 'commit' ? 0 : 1;
	}
	/** @return int */
	public function rollback()
	{
		$this->transaction_opened = max(0, $this->transaction_opened - 1);
		$this->calls[] = 'rollback';
		return 1;
	}
	/** @param string $sql Query @return object|false */
	public function query($sql)
	{
		if (preg_match('/^SELECT rowid FROM test_adherent WHERE rowid = [0-9]+ FOR UPDATE$/D', $sql)) {
			$this->calls[] = 'lock';
			return $this->failure === 'lock' ? false : (object) array('row' => (object) array('rowid' => 1));
		}
		if ($sql === 'SELECT subscription FROM test_subscription WHERE fk_adherent = 1 ORDER BY datec DESC, rowid DESC LIMIT 1') {
			$this->calls[] = 'history';
			return (object) array('row' => $this->latest === null ? null : (object) array('subscription' => $this->latest));
		}
		throw new LogicException('Unexpected query in isolated test');
	}
	/** @param object $res Result @return object|null */
	public function fetch_object($res)
	{
		return $res->row;
	}
	/** @param object $res Result @return void */
	public function free($res)
	{
	}
}

/** Member type without a database connection. */
class SubscriptionOptionsTestType extends AdherentType
{
	public function __construct()
	{
	}
}

/** Exercises the real orchestration with synthetic core operations. */
class SubscriptionOptionsTestMember extends Adherent
{
	/** @var AdherentType Test type */
	public $testType;
	/** @var string Failure injection */
	public $failure = '';
	/** @var array<string,mixed> Captured arguments */
	public $observed = array();
	/** @param User $user User @return AdherentType */
	protected function subscriptionTypeForBatch($user)
	{
		$this->db->calls[] = 'authorize';
		if ($this->failure === 'access') {
			throw new RuntimeException('NotEnoughPermissions');
		}
		return $this->testType;
	}
	/** @param int $force_thirdparty_id ID @return int */
	public function fetch_thirdparty($force_thirdparty_id = 0)
	{
		$this->thirdparty = (object) array('id' => 42, 'entity' => $this->failure === 'thirdparty_entity' ? 2 : 1, 'element' => 'societe');
		return $this->failure === 'thirdparty' ? -1 : 1;
	}
	/** @param mixed ...$args Core subscription arguments @return int */
	public function subscription(...$args)
	{
		if ($this->failure === 'nested_exception') {
			$this->db->begin();
			throw new RuntimeException('Synthetic trigger exception');
		}
		$this->db->calls[] = 'subscription';
		$this->observed['subscription'] = $args;
		return $this->failure === 'subscription' ? -1 : 10;
	}
	/** @param mixed ...$args Core complementary action arguments @return int */
	public function subscriptionComplementaryActions(...$args)
	{
		$this->db->calls[] = 'complementary';
		$this->observed['complementary'] = $args;
		$this->invoice = $args[1] === 'invoiceonly' ? (object) array('id' => 20) : null;
		return $this->failure === 'invoice' ? -1 : 1;
	}
	/** @param AdherentType $type Type @param bool $requireInvoicePdf Attach invoice @return int */
	public function sendSubscriptionConfirmation(AdherentType $type, $requireInvoicePdf = false)
	{
		if (end($this->db->calls) !== 'commit') {
			throw new LogicException('Mail must follow commit');
		}
		$this->db->calls[] = 'mail';
		$this->observed['attachment'] = $requireInvoicePdf;
		if ($this->failure === 'pdf') {
			$this->error = 'SubscriptionOptionsPdfMissing';
			return -1;
		}
		if ($this->failure === 'mail_exception') {
			throw new RuntimeException('Simulated mail exception');
		}
		return $this->failure === 'mail' ? -1 : 1;
	}
}

/** @return array{0:SubscriptionOptionsTestDatabase,1:SubscriptionOptionsTestMember,2:SubscriptionOptionsTestUser} */
function subscriptionOptionsTestContext()
{
	$db = new SubscriptionOptionsTestDatabase();
	$member = new SubscriptionOptionsTestMember($db);
	$member->id = 1;
	$member->email = 'member@example.invalid';
	$member->testType = new SubscriptionOptionsTestType();
	$member->testType->amount = '24';
	$member->testType->duration_value = 1;
	$member->testType->duration_unit = 'y';
	return array($db, $member, new SubscriptionOptionsTestUser());
}

/** @return void Set up synthetic configuration only. */
function subscriptionOptionsTestEnvironment()
{
	global $conf, $langs, $hookmanager, $mc;
	$mc = null;
	$hookmanager = new class {
		public function executeHooks(...$args)
		{
			return 0;
		}
	};
	$conf = (object) array('entity' => 1, 'global' => new stdClass(), 'modules' => array('member' => 1, 'invoice' => 1, 'societe' => 1), 'cache' => array());
	$conf->tzuserinputkey = 'gmt';
	$conf->tzuserdisplaykey = 'gmt';
	$langs = new Translate(DOL_DOCUMENT_ROOT, $conf);
	$langs->setDefaultLang('en_US');
	$langs->tab_translate = array('Subscription' => 'Subscription');
}

/** @return array<string,callable():bool> Isolated regression scenarios */
function subscriptionOptionsScenarios()
{
	$scenarios = array();
	$date = dol_mktime(0, 0, 0, 1, 1, 2027);
	foreach (array(false, true) as $invoice) {
		foreach (array(false, true) as $mail) {
			$scenarios['options_invoice_'.(int) $invoice.'_mail_'.(int) $mail] = static function () use ($date, $invoice, $mail) {
				list($db, $member, $user) = subscriptionOptionsTestContext();
				$result = $member->createSubscriptionWithOptions($user, $date, $invoice, $mail);
				return $result === array('subscription' => 10, 'invoice' => $invoice ? 20 : 0, 'sent' => $mail ? 1 : 0, 'error' => '', 'warning' => '')
					&& $member->observed['subscription'][1] === 24.0 && $member->observed['subscription'][2] === 0
					&& $member->observed['complementary'][1] === ($invoice ? 'invoiceonly' : 'none') && $member->observed['complementary'][2] === 0
					&& (!$mail || $member->observed['attachment'] === $invoice);
			};
		}
	}
	foreach (array('lock', 'access', 'subscription', 'invoice', 'commit', 'thirdparty', 'thirdparty_entity', 'nested_exception') as $failure) {
		$scenarios['rollback_'.$failure] = static function () use ($date, $failure) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$db->failure = $member->failure = $failure;
			$result = $member->createSubscriptionWithOptions($user, $date, true, true);
			return $result['error'] !== '' && !$result['subscription'] && !$result['invoice'] && !$result['sent'] && end($db->calls) === 'rollback' && !$db->transaction_opened && !in_array('mail', $db->calls, true);
		};
	}
	foreach (array('pdf', 'mail', 'mail_exception', 'missing_email') as $failure) {
		$scenarios['mail_warning_preserves_records_'.$failure] = static function () use ($date, $failure) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$member->failure = $failure;
			if ($failure === 'missing_email') {
				$member->email = '';
			}
			$result = $member->createSubscriptionWithOptions($user, $date, true, true);
			return $result['warning'] !== '' && $result['subscription'] === 10 && $result['invoice'] === 20 && !$result['sent'] && !$result['error'] && !in_array('rollback', $db->calls, true);
		};
	}
	foreach (array('24', null, '', '0', '-1', 'invalid') as $configured) {
		$scenarios['amount_'.var_export($configured, true)] = static function () use ($configured) {
			list($db, $member) = subscriptionOptionsTestContext();
			$db->latest = '17.5';
			try {
				$amount = $member->getSubscriptionAmountForBatch($configured);
			} catch (RuntimeException $e) {
				return in_array($configured, array('0', '-1', 'invalid'), true) && !$db->calls;
			}
			return $configured === '24' ? $amount === 24.0 && !$db->calls : $amount === 17.5 && $db->calls === array('history');
		};
	}
	$scenarios['missing_history'] = static function () {
		list($db, $member) = subscriptionOptionsTestContext();
		try {
			$member->getSubscriptionAmountForBatch(null);
		} catch (RuntimeException $e) {
			return $e->getMessage() === 'SubscriptionOptionsAmountMissing';
		}
		return false;
	};
	foreach (array(array(1, 'y', 12, 31), array(0, 'y', 12, 31), array(6, 'm', 6, 30), array(3, 'w', 1, 21)) as $case) {
		$scenarios['duration_'.$case[0].$case[1]] = static function () use ($date, $case) {
			$type = (object) array('duration_value' => $case[0], 'duration_unit' => $case[1]);
			return Adherent::subscriptionEndDateForBatch($date, $type) === dol_mktime(0, 0, 0, $case[2], $case[3], 2027);
		};
	}
	foreach (array('adherent.cotisation.creer', 'facture.creer', 'facture.invoice_advance.validate', 'facture.invoice_advance.send') as $right) {
		$scenarios['denied_'.$right] = static function () use ($date, $right) {
			global $conf;
			$conf->global->MAIN_USE_ADVANCED_PERMS = 1;
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$user->denied = array($right);
			$result = $member->createSubscriptionWithOptions($user, $date, true, true);
			return $result['error'] === 'NotEnoughPermissions' && !$db->calls;
		};
	}
	$scenarios['multiple_members_keep_individual_amounts_and_isolate_failures'] = static function () use ($date) {
		$outcomes = array();
		foreach (array('24', '12', '0', '8') as $amount) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$member->testType->amount = $amount;
			$result = $member->createSubscriptionWithOptions($user, $date, true, false);
			$outcomes[] = $result['subscription'] ? $member->observed['subscription'][1] : 0;
		}
		return $outcomes === array(24.0, 12.0, 0, 8.0);
	};
	$scenarios['real_mail_helper_refuses_missing_invoice_pdf'] = static function () {
		list($db) = subscriptionOptionsTestContext();
		$member = new Adherent($db);
		return $member->sendSubscriptionConfirmation(new SubscriptionOptionsTestType(), true) < 0 && $member->error === 'SubscriptionOptionsPdfMissing' && !$db->calls;
	};
	foreach (array('MEMBER_SUBSCRIPTION_SUGGEST_END_OF_MONTH' => array(2, 28), 'MEMBER_SUBSCRIPTION_SUGGEST_END_OF_YEAR' => array(12, 31)) as $setting => $expected) {
		$scenarios['calendar_'.$setting] = static function () use ($setting, $expected) {
			global $conf;
			$conf->global->$setting = 1;
			$type = (object) array('duration_value' => 3, 'duration_unit' => 'w');
			return Adherent::subscriptionEndDateForBatch(dol_mktime(0, 0, 0, 2, 10, 2027), $type) === dol_mktime(23, 59, 59, $expected[0], $expected[1], 2027);
		};
	}
	$scenarios['fallback_amount_used_by_core_subscription'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$member->testType->amount = null;
		$db->latest = '17.5';
		$result = $member->createSubscriptionWithOptions($user, $date, false, false);
		return $result['subscription'] === 10 && $member->observed['subscription'][1] === 17.5;
	};
	$scenarios['mail_disabled_keeps_records'] = static function () use ($date) {
		global $conf;
		$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$result = $member->createSubscriptionWithOptions($user, $date, true, true);
		return $result['warning'] === 'SubscriptionOptionsEmailDisabled' && $result['subscription'] === 10 && !$result['sent'] && !in_array('mail', $db->calls, true);
	};
	foreach (array('external', 'module_disabled', 'existing_transaction', 'invalid_date') as $case) {
		$scenarios['reject_'.$case] = static function () use ($date, $case) {
			global $conf;
			list($db, $member, $user) = subscriptionOptionsTestContext();
			if ($case === 'external') {
				$user->socid = 42;
			} elseif ($case === 'module_disabled') {
				unset($conf->modules['member']);
			} elseif ($case === 'existing_transaction') {
				$db->transaction_opened = 1;
			}
			$result = $member->createSubscriptionWithOptions($user, $case === 'invalid_date' ? 0 : $date, true, true);
			return $result['error'] !== '' && !$result['subscription'] && !in_array('subscription', $db->calls, true) && !in_array('mail', $db->calls, true);
		};
	}
	$scenarios['no_invoice_needs_no_invoice_rights'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$user->denied = array('facture.creer', 'facture.invoice_advance.send');
		$result = $member->createSubscriptionWithOptions($user, $date, false, true);
		return $result['subscription'] === 10 && $result['sent'] === 1 && !$result['invoice'];
	};
	$scenarios['real_authorization_rejects_member_in_other_entity'] = static function () use ($date) {
		list($db, $unused, $user) = subscriptionOptionsTestContext();
		$member = new class($db) extends Adherent {
			/** @param mixed ...$args Fetch arguments @return int */
			public function fetch(...$args)
			{
				$this->entity = 2;
				return 1;
			}
		};
		$member->id = 1;
		$result = $member->createSubscriptionWithOptions($user, $date, false, false);
		return $result['error'] === 'NotEnoughPermissions' && !$result['subscription'] && $db->calls === array('begin', 'lock', 'rollback');
	};
	return $scenarios;
}
