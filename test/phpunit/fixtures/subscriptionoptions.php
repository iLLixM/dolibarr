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
require_once DOL_DOCUMENT_ROOT.'/adherents/lib/subscription_options.lib.php';

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
	/** @var bool Simulated overlapping subscription */
	public $overlap = false;
	/** @var string[] Synthetic persisted objects */
	public $records = array();
	/** @var string[] Transaction snapshot */
	private $before = array();
	/** @param int $timestamp Timestamp @return string SQL date */
	public function idate($timestamp)
	{
		return dol_print_date($timestamp, '%Y-%m-%d %H:%M:%S');
	}
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
		if (!$this->transaction_opened) {
			$this->before = $this->records;
		}
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
		if (!$this->transaction_opened) {
			$this->records = $this->before;
		}
		$this->calls[] = 'rollback';
		return 1;
	}
	/** @param string $sql Query @return object|false */
	public function query($sql)
	{
		if (strpos($sql, "SELECT rowid FROM test_subscription WHERE fk_adherent = 1 AND (dateadh IS NULL OR dateadh <= '") === 0) {
			$this->calls[] = 'overlap';
			return (object) array('row' => $this->overlap ? (object) array('rowid' => 50) : null);
		}
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
		$this->thirdparty = (object) array('id' => 42, 'entity' => $this->failure === 'thirdparty_entity' ? 2 : 1, 'element' => 'societe', 'name' => 'Synthetic third party');
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
		$this->db->records[] = 'subscription';
		return $this->failure === 'subscription' ? -1 : 10;
	}
	/** @param mixed ...$args Core complementary action arguments @return int */
	public function subscriptionComplementaryActions(...$args)
	{
		$this->db->calls[] = 'complementary';
		$this->observed['complementary'] = $args;
		if (!empty($args[11]) && !$this->socid) {
			$this->db->records[] = 'thirdparty';
			$this->socid = 99;
			if (in_array($this->failure, array('thirdparty_create', 'invoice_after_thirdparty'), true)) {
				return -1;
			}
		}
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
	$member->socid = 42;
	$member->entity = 1;
	$member->email = 'member@example.invalid';
	$member->firstname = 'Test';
	$member->lastname = 'Member';
	$member->testType = new SubscriptionOptionsTestType();
	$member->testType->amount = '24';
	$member->testType->label = 'Synthetic type';
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
	$conf->currency = 'EUR';
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
	foreach (array('MEMBER_SUBSCRIPTION_SUGGEST_END_OF_MONTH', 'MEMBER_SUBSCRIPTION_SUGGEST_END_OF_YEAR') as $setting) {
		$scenarios['calendar_'.$setting] = static function () use ($setting) {
			global $conf;
			$conf->global->$setting = 1;
			$type = (object) array('duration_value' => 3, 'duration_unit' => 'w');
			return Adherent::subscriptionEndDateForBatch(dol_mktime(0, 0, 0, 2, 10, 2027), $type) === dol_mktime(0, 0, 0, 3, 2, 2027);
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
	$scenarios['preview_matches_creation_without_writes'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$row = $member->previewSubscriptionWithOptions($user, $date, true, true);
		$readonly = $db->calls === array('authorize', 'overlap');
		$result = $member->createSubscriptionWithOptions($user, $date, true, true);
		return $readonly && $row['member'] !== '' && $row['type'] === 'Synthetic type' && $row['thirdparty'] === 'Synthetic third party'
			&& !$row['notices'] && $result['subscription'] === 10 && $row['amount'] === $member->observed['subscription'][1]
			&& $row['end'] === $member->observed['subscription'][8] && $row['description'] === $member->observed['complementary'][6];
	};
	$scenarios['preview_retains_details_on_amount_error'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$member->testType->amount = '0';
		$row = $member->previewSubscriptionWithOptions($user, $date, true, false);
		return $row['amount'] === null && $row['end'] > $date && $row['thirdparty'] === 'Synthetic third party'
			&& $row['notices'] === array('SubscriptionOptionsAmountMissing') && $db->calls === array('authorize', 'overlap');
	};
	foreach (array(false, true) as $invoice) {
		$scenarios['preview_missing_thirdparty_invoice_'.(int) $invoice] = static function () use ($date, $invoice) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$member->failure = 'thirdparty';
			$row = $member->previewSubscriptionWithOptions($user, $date, $invoice, false);
			return $row['thirdparty'] === '' && $row['amount'] === 24.0 && $row['notices'] === ($invoice ? array('SubscriptionOptionsThirdPartyMissing') : array());
		};
	}
	$scenarios['preview_denied_access_discloses_no_details'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$member->failure = 'access';
		$row = $member->previewSubscriptionWithOptions($user, $date, true, false);
		return $row['member'] === '' && $row['type'] === '' && $row['thirdparty'] === '' && $row['amount'] === null && $row['notices'] === array('NotEnoughPermissions');
	};
	foreach (array(array(0, 0), array(1, 0), array(0, 1), array(1, 1)) as $flags) {
		foreach (array(array(2, 'd', 2026, 10, 11), array(3, 'w', 2026, 10, 30), array(1, 'm', 2026, 11, 9), array(4, 'm', 2027, 2, 9), array(0, 'y', 2027, 10, 9), array(1, 'y', 2027, 10, 9), array(5, 'y', 2031, 10, 9)) as $case) {
			$scenarios['duration_flags_'.implode('', $flags).'_'.$case[0].$case[1]] = static function () use ($flags, $case) {
				global $conf;
				$conf->global->MEMBER_SUBSCRIPTION_SUGGEST_END_OF_MONTH = $flags[0];
				$conf->global->MEMBER_SUBSCRIPTION_SUGGEST_END_OF_YEAR = $flags[1];
				$type = (object) array('duration_value' => $case[0], 'duration_unit' => $case[1]);
				return Adherent::subscriptionEndDateForBatch(dol_mktime(0, 0, 0, 10, 10, 2026), $type) === dol_mktime(0, 0, 0, $case[3], $case[4], $case[2]);
			};
		}
	}
	$scenarios['duration_leap_year_and_year_boundary'] = static function () {
		$type = (object) array('duration_value' => 2, 'duration_unit' => 'd');
		return Adherent::subscriptionEndDateForBatch(dol_mktime(0, 0, 0, 2, 28, 2028), $type) === dol_mktime(0, 0, 0, 2, 29, 2028)
			&& Adherent::subscriptionEndDateForBatch(dol_mktime(0, 0, 0, 12, 31, 2027), $type) === dol_mktime(0, 0, 0, 1, 1, 2028);
	};
	$scenarios['individual_start_history_validation_today'] = static function () use ($date) {
		list($db, $member) = subscriptionOptionsTestContext();
		$member->datefin = dol_mktime(23, 59, 59, 12, 31, 2026);
		$a = $member->subscriptionStartDateForBatch($date);
		$member->datefin = dol_mktime(0, 0, 0, 3, 31, 2027);
		$b = $member->subscriptionStartDateForBatch($date);
		$member->datefin = null;
		$member->datevalid = dol_mktime(15, 30, 0, 4, 2, 2026);
		$c = $member->subscriptionStartDateForBatch($date);
		$member->datevalid = null;
		return $a === $date && $b === dol_mktime(0, 0, 0, 4, 1, 2027)
			&& $c === dol_mktime(0, 0, 0, 4, 2, 2026) && $member->subscriptionStartDateForBatch($date) === $date;
	};
	foreach (array('m', 'Y', '', '3m') as $correction) {
		$scenarios['start_global_priority_'.$correction] = static function () use ($correction) {
			global $conf;
			list($db, $member) = subscriptionOptionsTestContext();
			$member->datefin = dol_mktime(0, 0, 0, 10, 31, 2026);
			$conf->global->MEMBER_SUBSCRIPTION_START_AFTER = '+1Y';
			$conf->global->MEMBER_SUBSCRIPTION_START_FIRST_DAY_OF = $correction;
			$expected = array('m' => array(2028, 2, 1), 'Y' => array(2028, 1, 1), '' => array(2028, 2, 10), '3m' => array(2026, 7, 1));
			$parts = $expected[$correction];
			return $member->subscriptionStartDateForBatch(dol_mktime(0, 0, 0, 2, 10, 2027)) === dol_mktime(0, 0, 0, $parts[1], $parts[2], $parts[0]);
		};
	}
	$scenarios['manual_period_preservation_and_reset'] = static function () use ($date) {
		$previous = array('start' => $date, 'end' => $date + 86400, 'manualend' => false);
		$auto = subscriptionOptionsPeriodInput($previous, $date + 86400, $previous['end']);
		$manual = subscriptionOptionsPeriodInput($previous, $date + 86400, $date + 20 * 86400);
		$previous['manualend'] = true;
		$kept = subscriptionOptionsPeriodInput($previous, $date + 86400, $previous['end']);
		$reset = subscriptionOptionsPeriodInput($previous, $date, $previous['end'], true);
		$common = subscriptionOptionsPeriodInput($previous, $date, $previous['end'], false, $date + 86400);
		return $auto['end'] === null && !$auto['manualend'] && $manual['end'] === $date + 20 * 86400 && $manual['manualend']
			&& $kept['end'] === $previous['end'] && $reset['end'] === null && !$reset['manualend'] && $common['start'] === $date + 86400 && $common['end'] === $previous['end'];
	};
	$scenarios['preview_automatic_start_and_explicit_end_reach_creation'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$member->datefin = $date - 86400;
		$row = $member->previewSubscriptionWithOptions($user, null, false, false, $date + 5 * 86400);
		$result = $member->createSubscriptionWithOptions($user, $row['start'], false, false, $row['end'], false, $row['fingerprint']);
		return !$row['errors'] && $row['start'] === $date && $result['subscription'] === 10 && $member->observed['subscription'][8] === $date + 5 * 86400;
	};
	$scenarios['amount_or_thirdparty_changes_require_review'] = static function () use ($date) {
		foreach (array('amount', 'socid') as $change) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$row = $member->previewSubscriptionWithOptions($user, $date, true, false);
			if ($change === 'amount') {
				$member->testType->amount = '25';
			} else {
				$member->socid = 84;
			}
			$result = $member->createSubscriptionWithOptions($user, $date, true, false, $row['end'], false, $row['fingerprint']);
			if ($result['error'] !== 'SubscriptionOptionsDataChanged' || $db->records) {
				return false;
			}
		}
		return true;
	};
	foreach (array('overlap', 'reversed', 'missing') as $case) {
		$scenarios['invalid_period_'.$case] = static function () use ($date, $case) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$db->overlap = $case === 'overlap';
			$end = $case === 'reversed' ? $date - 86400 : ($case === 'missing' ? 0 : $date + 86400);
			$row = $member->previewSubscriptionWithOptions($user, $date, false, false, $end);
			$result = $member->createSubscriptionWithOptions($user, $date, false, false, $end);
			return $row['status'] === 'error' && $result['error'] !== '' && !$db->records;
		};
	}
	$scenarios['overlap_appearing_after_preview_blocks_creation'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$row = $member->previewSubscriptionWithOptions($user, $date, true, false);
		$db->overlap = true;
		$result = $member->createSubscriptionWithOptions($user, $date, true, false, $row['end'], false, $row['fingerprint']);
		return $result['error'] === 'SubscriptionOptionsOverlap' && !$db->records;
	};
	$scenarios['preview_period_and_amount_warnings_are_independent'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$a = $member->previewSubscriptionWithOptions($user, $date, false, false);
		$b = $a;
		$b['type'] = 'A different label alone';
		if (subscriptionOptionsPreviewWarnings(array($a, $b))) {
			return false;
		}
		$b['amount'] = 50.0;
		if (subscriptionOptionsPreviewWarnings(array($a, $b)) !== array('SubscriptionOptionsDifferentAmounts')) {
			return false;
		}
		$b['end'] += 86400;
		return subscriptionOptionsPreviewWarnings(array($a, $b)) === array('SubscriptionOptionsDifferentPeriods', 'SubscriptionOptionsDifferentAmounts');
	};
	foreach (array('existing', 'missing_off', 'missing_on', 'no_invoice', 'denied', 'inaccessible', 'thirdparty_create', 'invoice_after_thirdparty') as $case) {
		$scenarios['autothirdparty_'.$case] = static function () use ($date, $case) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$member->socid = in_array($case, array('existing', 'inaccessible'), true) ? 42 : 0;
			$invoice = $case !== 'no_invoice';
			$auto = $case !== 'missing_off';
			if ($case === 'denied') {
				$user->denied = array('societe.creer');
			}
			$member->failure = $case === 'inaccessible' ? 'thirdparty_entity' : $case;
			$row = $member->previewSubscriptionWithOptions($user, $date, $invoice, false, null, $auto);
			if ($db->records) {
				return false;
			}
			$result = $member->createSubscriptionWithOptions($user, $date, $invoice, false, $row['end'], $auto, $row['fingerprint']);
			if (in_array($case, array('missing_off', 'denied', 'inaccessible', 'thirdparty_create', 'invoice_after_thirdparty'), true)) {
				return $result['error'] !== '' && !$result['subscription'] && !$db->records;
			}
			return $result['subscription'] === 10 && in_array('thirdparty', $db->records, true) === ($case === 'missing_on')
				&& $member->observed['complementary'][11] === ($case === 'missing_on' ? 1 : 0)
				&& $row['status'] === ($case === 'missing_on' ? 'warning' : 'ready');
		};
	}
	$scenarios['mail_warning_does_not_block_ready_period'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$member->email = '';
		$row = $member->previewSubscriptionWithOptions($user, $date, false, true);
		return $row['status'] === 'warning' && !$row['errors'] && $row['warnings'] === array('SubscriptionOptionsEmailMissing');
	};
	$scenarios['strict_calendar_input_rejects_impossible_dates'] = static function () {
		$savedPost = $_POST;
		$savedGet = $_GET;
		try {
			$_GET = array();
			$_POST = array('testyear' => '2027', 'testmonth' => '2', 'testday' => '29');
			if (subscriptionOptionsReadDate('test') !== 0) {
				return false;
			}
			$_POST['testyear'] = '2028';
			return subscriptionOptionsReadDate('test') === dol_mktime(0, 0, 0, 2, 29, 2028);
		} finally {
			$_POST = $savedPost;
			$_GET = $savedGet;
		}
	};
	$scenarios['render_editable_individual_fields_and_escape_names'] = static function () use ($date) {
		list($db, $member, $user) = subscriptionOptionsTestContext();
		$member->firstname = '<script>alert(1)</script>';
		$row = $member->previewSubscriptionWithOptions($user, $date, false, false);
		$form = new class {
			/** @param int $timestamp Date @param string $prefix Prefix @param mixed ...$args Options @return string */
			public function selectDate($timestamp, $prefix, ...$args)
			{
				return '<input name="'.$prefix.'" value="'.$timestamp.'">';
			}
		};
		$html = subscriptionOptionsRenderPreview($form, array(1 => $row, 2 => $row));
		return substr_count($html, '<th>') === 8 && strpos($html, '<script>') === false
			&& strpos($html, 'name="substart1"') !== false && strpos($html, 'name="subend1"') !== false
			&& strpos($html, 'name="substart2"') !== false && strpos($html, 'name="subend2"') !== false;
	};
	$scenarios['mixed_members_create_only_authorized_thirdparties'] = static function () use ($date) {
		$outcomes = array();
		foreach (array(42, 0, 0) as $socid) {
			list($db, $member, $user) = subscriptionOptionsTestContext();
			$member->socid = $socid;
			$row = $member->previewSubscriptionWithOptions($user, $date, true, false, null, true);
			$result = $member->createSubscriptionWithOptions($user, $date, true, false, $row['end'], true, $row['fingerprint']);
			$outcomes[] = array($result['subscription'], in_array('thirdparty', $db->records, true));
		}
		return $outcomes === array(array(10, false), array(10, true), array(10, true));
	};
	return $scenarios;
}
