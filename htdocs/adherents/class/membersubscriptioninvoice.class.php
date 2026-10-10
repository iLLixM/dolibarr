<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Prepare membership invoices without recording subscriptions or payments.
 */
class MemberSubscriptionInvoice
{
	/** @var DoliDB Database handler */
	private $db;
	/** @var array<int,Societe> Customers loaded during this request */
	private $customers = array();
	/** @var array<string,mixed> Shared configuration resolved for this preview */
	private $settings = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param User $user User
	 * @return bool
	 */
	public static function canCreate($user)
	{
		return self::canRead($user) && $user->hasRight('facture', 'creer');
	}

	/**
	 * @param User $user User
	 * @return bool
	 */
	public static function canRead($user)
	{
		return empty($user->socid) && isModEnabled('member') && isModEnabled('invoice') && isModEnabled('societe')
			&& $user->hasRight('adherent', 'lire') && $user->hasRight('adherent', 'cotisation', 'lire')
			&& $user->hasRight('facture', 'lire');
	}

	/**
	 * @param User $user User
	 * @param string $operation validate or send
	 * @return bool
	 */
	public static function canProcess($user, $operation)
	{
		if (!in_array($operation, array('validate', 'send'), true) || !self::canRead($user)) {
			return false;
		}
		if (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS')) {
			return $user->hasRight('facture', 'invoice_advance', $operation);
		}
		return $operation === 'send' || $user->hasRight('facture', 'creer');
	}

	/**
	 * Normalize a bounded selection before loading any objects.
	 *
	 * @param int[] $ids Selected IDs
	 * @param int $limit Maximum selection size
	 * @return int[]
	 */
	public static function selection(array $ids, $limit)
	{
		if (!$ids || count($ids) > $limit) {
			throw new RuntimeException('MemberInvoiceInvalidSelection');
		}
		foreach ($ids as $id) {
			if (!is_numeric($id) || (int) $id <= 0 || (string) (int) $id !== (string) $id) {
				throw new RuntimeException('MemberInvoiceInvalidSelection');
			}
		}
		$ids = array_values(array_unique(array_map('intval', $ids)));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	/**
	 * Calendar dates are represented at midnight UTC, independently of DST.
	 *
	 * @param int $year Year
	 * @param int $month Month
	 * @param int $day Day
	 * @return int
	 */
	public static function startDate($year, $month, $day)
	{
		if ($year < 1970 || $year > 9998 || !checkdate($month, $day, $year)) {
			throw new RuntimeException('MemberInvoiceInvalidPeriod');
		}
		return dol_mktime(0, 0, 0, $month, $day, $year, 'gmt');
	}

	/**
	 * @param int $start Start date
	 * @param string $duration Type duration
	 * @param bool $monthEnd Respect end-of-month setting
	 * @param bool $yearEnd Respect end-of-year setting
	 * @return int
	 */
	public static function endDate($start, $duration, $monthEnd, $yearEnd)
	{
		if (preg_match('/^[dwmy]$/D', $duration)) {
			$duration = '1'.$duration;
		}
		if ($start <= 0) {
			throw new RuntimeException('MemberInvoiceInvalidPeriod');
		}
		if (!preg_match('/^([1-9][0-9]{0,3})([dwmy])$/D', $duration, $matches)) {
			throw new RuntimeException('MemberInvoiceInvalidDuration');
		}
		if ($monthEnd || $yearEnd) {
			$year = (int) dol_print_date($start, '%Y', 'gmt');
			$month = $monthEnd ? (int) dol_print_date($start, '%m', 'gmt') : 12;
			$end = dol_get_last_day($year, $month, 'gmt') - 86399;
		} else {
			$end = dol_time_plus_duree(dol_time_plus_duree($start, (int) $matches[1], $matches[2]), -1, 'd');
		}
		if ($end < $start || (int) dol_print_date($end, '%Y', 'gmt') > 9999) {
			throw new RuntimeException('MemberInvoiceInvalidPeriod');
		}
		return $end;
	}

	/**
	 * @param string|null $configured Configured gross amount
	 * @param string|null $override Explicit amount, or null
	 * @param bool $editable Whether the type allows a different amount
	 * @param string|null $minimum Minimum amount
	 * @param int $globalMinimum Global minimum
	 * @return float
	 */
	public static function amount($configured, $override, $editable, $minimum, $globalMinimum)
	{
		global $langs;
		if ($editable && $override !== null) {
			$override = trim(str_replace(array("\xc2\xa0", "\xe2\x80\xaf"), ' ', $override));
			$decimal = $langs ? $langs->transnoentitiesnoconv('SeparatorDecimal') : '.';
			$thousand = $langs ? $langs->transnoentitiesnoconv('SeparatorThousand') : ',';
			$thousand = $thousand === 'Space' ? ' ' : ($thousand === 'None' ? '' : $thousand);
			$integer = $thousand !== '' ? '(?:[0-9]+|[0-9]{1,3}(?:'.preg_quote($thousand, '/').'[0-9]{3})+)' : '[0-9]+';
			$localized = '/^[+]?'.$integer.'(?:'.preg_quote($decimal, '/').'[0-9]+)?$/D';
			if (!preg_match($localized, $override) && !preg_match('/^[+]?[0-9]+(?:\.[0-9]+)?$/D', $override)) {
				throw new RuntimeException('MemberInvoiceInvalidAmount');
			}
		}
		$value = $editable && $override !== null ? price2num($override, '', 2) : $configured;
		if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
			throw new RuntimeException('MemberInvoiceInvalidAmount');
		}
		if ($minimum !== null && $minimum !== '' && (!is_numeric($minimum) || (float) $minimum < 0)) {
			throw new RuntimeException('MemberInvoiceInvalidAmount');
		}
		if ((float) $value < max((float) $minimum, $globalMinimum)) {
			throw new RuntimeException('MemberInvoiceBelowMinimum');
		}
		$value = (float) price2num($value, 'MT');
		if ($value <= 0) {
			throw new RuntimeException('MemberInvoiceInvalidAmount');
		}
		if ($value < max((float) $minimum, $globalMinimum)) {
			throw new RuntimeException('MemberInvoiceBelowMinimum');
		}
		return $value;
	}

	/**
	 * @param string $template Plain text template
	 * @param string $type Member type label
	 * @param int $start Start date
	 * @param int $end End date
	 * @return string
	 */
	public static function description($template, $type, $start, $end)
	{
		if (trim($template) === '' || preg_match('/\{(?!member_type\}|start_date\}|end_date\})[^}]*\}/', $template)) {
			throw new RuntimeException('MemberInvoiceInvalidDescription');
		}
		return strtr($template, array('{member_type}' => $type, '{start_date}' => dol_print_date($start, 'day', 'gmt'), '{end_date}' => dol_print_date($end, 'day', 'gmt')));
	}

	/**
	 * @param int $id Customer ID
	 * @param User $user User
	 * @return Societe
	 */
	protected function customer($id, $user)
	{
		if ($id <= 0) {
			throw new RuntimeException('MemberInvoiceMissingCustomer');
		}
		if (!isset($this->customers[$id])) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$customer = new Societe($this->db);
			if ($customer->fetch($id) <= 0 || !checkUserAccessToObject($user, array('societe'), $customer)) {
				throw new RuntimeException('MemberInvoiceCustomerAccess');
			}
			$this->customers[$id] = $customer;
		}
		return $this->customers[$id];
	}

	/**
	 * @param User $user User
	 * @param int $id Member ID
	 * @return bool
	 */
	protected function canReadMember($user, $id)
	{
		return restrictedArea($user, 'adherent', $id, '', '', 'socid', 'rowid', 0, 1) > 0;
	}

	/** @param User $user User @return bool */
	public static function canCreateCustomer($user)
	{
		return self::canCreate($user) && $user->hasRight('societe', 'creer');
	}

	/**
	 * Build a read-only proposal using the fields copied by create_from_member().
	 * @param int $id Member ID
	 * @param User $user User
	 * @param bool $persist Explicit creation inside the invoice transaction
	 * @return Societe
	 */
	protected function memberCustomer($id, $user, $persist = false)
	{
		global $conf, $langs;
		if (!self::canCreateCustomer($user)) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$member = new Adherent($this->db);
		if ($member->fetch($id) <= 0 || (int) $member->entity !== (int) $conf->entity || !$this->canReadMember($user, $id)) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		if ($member->socid > 0 || $member->fk_soc > 0) {
			throw new RuntimeException('MemberInvoicePreviewChanged');
		}
		$customer = new Societe($this->db);
		$customer->name = $member->morphy === 'mor' ? ($member->company ?: $member->societe) : $member->getFullName($langs);
		$customer->name_alias = $member->morphy === 'mor' ? $member->getFullName($langs) : $member->company;
		foreach (array('address', 'zip', 'town', 'country_code', 'country_id', 'phone', 'email', 'socialnetworks', 'entity') as $field) {
			$customer->$field = $member->$field;
		}
		$customer->client = 1;
		$customer->code_client = getDolGlobalString('THIRDPARTY_CUSTOMERCODE_EQUALS_MEMBERREF') ? $member->ref : '-1';
		if (trim($customer->name) === '') {
			throw new RuntimeException('MemberInvoiceCustomerCreateFailed');
		}
		if ($persist && $customer->create_from_member($member, $customer->name, (string) $customer->name_alias) <= 0) {
			throw new RuntimeException('MemberInvoiceCustomerCreateFailed');
		}
		return $customer;
	}

	/**
	 * Load member/type data in one query; never trust submitted prices or names.
	 *
	 * @param int[] $ids Selected members
	 * @param int $start Start date
	 * @param string $template Description template
	 * @param array<int,string> $amounts Explicit individual amounts
	 * @param User $user User
	 * @param bool $createCustomers Allow proposing missing third parties
	 * @return array<int,array<string,mixed>>
	 */
	public function preview(array $ids, $start, $template, array $amounts, $user, $createCustomers = false)
	{
		global $conf, $mysoc;
		if (!self::canCreate($user)) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		$ids = self::selection($ids, getDolGlobalInt('MAIN_LIMIT_FOR_MASS_ACTIONS', 1000));
		$this->settings = array();
		$sql = 'SELECT m.rowid, m.firstname, m.lastname, m.statut, m.fk_soc, m.fk_adherent_type, m.morphy,';
		$sql .= ' t.libelle, t.statut as typestatus, t.subscription, t.amount, t.minimumamount, t.caneditamount, t.duration, t.amountformuladescription';
		$sql .= ' FROM '.$this->db->prefix().'adherent as m LEFT JOIN '.$this->db->prefix().'adherent_type as t';
		$sql .= ' ON t.rowid = m.fk_adherent_type AND t.entity IN ('.getEntity('adherent_type').')';
		$sql .= ' WHERE m.rowid IN ('.implode(',', $ids).') AND m.entity IN ('.getEntity('adherent').')';
		$res = $this->db->query($sql);
		if (!$res) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		$members = array();
		while ($member = $this->db->fetch_object($res)) {
			$members[(int) $member->rowid] = $member;
		}
		$this->db->free($res);
		$rows = array();
		foreach ($ids as $id) {
			$row = array('id' => $id, 'name' => '', 'type' => '', 'duration' => '', 'socid' => 0, 'customer' => '', 'email' => '', 'amount' => null, 'editable' => false, 'formula' => '', 'start' => $start, 'end' => null, 'description' => '', 'error' => '', 'duplicates' => array());
			try {
				if (!isset($members[$id]) || !$this->canReadMember($user, $id)) {
					throw new RuntimeException('NotEnoughPermissions');
				}
				$member = $members[$id];
				$row['name'] = trim($member->firstname.' '.$member->lastname);
				$row['type'] = $member->libelle;
				$row['duration'] = (string) $member->duration;
				$row['editable'] = (bool) $member->caneditamount;
				$row['formula'] = $member->amountformuladescription;
				// Resolve the linked customer before validating contribution settings.
				$row['newcustomer'] = empty($member->fk_soc) && $createCustomers;
				$customer = $row['newcustomer'] ? $this->memberCustomer($id, $user) : $this->customer((int) $member->fk_soc, $user);
				if ($row['newcustomer']) {
					$data = array();
					foreach (array('name', 'name_alias', 'address', 'zip', 'town', 'country_code', 'country_id', 'phone', 'email', 'socialnetworks', 'entity', 'code_client') as $field) {
						$data[$field] = $customer->$field;
					}
					$row['customerdigest'] = self::fingerprint($data);
				}
				$row['socid'] = $customer->id;
				$row['customer'] = $customer->name;
				$row['email'] = $customer->email;
				if ((int) $member->statut !== 1 || (int) $member->typestatus !== 1 || !$member->subscription) {
					throw new RuntimeException('MemberInvoiceIneligibleMember');
				}
				if (getDolGlobalString('MEMBER_NEWFORM_DOLIBARRTURNOVER') && $member->morphy === 'mor') {
					throw new RuntimeException('MemberInvoiceSpecialAmount');
				}
				$row['amount'] = self::amount($member->amount, isset($amounts[$id]) ? $amounts[$id] : null, $row['editable'], $member->minimumamount, getDolGlobalInt('MEMBER_MIN_AMOUNT'));
				$row['end'] = self::endDate($start, (string) $member->duration, getDolGlobalInt('MEMBER_SUBSCRIPTION_SUGGEST_END_OF_MONTH') > 0, getDolGlobalInt('MEMBER_SUBSCRIPTION_SUGGEST_END_OF_YEAR') > 0);
				$row['description'] = self::description($template, $row['type'], $start, $row['end']);
				$row['terms'] = (int) $customer->cond_reglement_id;
				if (!$row['terms']) {
					require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/paymentterm.class.php';
					if (!isset($this->settings['terms'])) {
						$term = new PaymentTerm($this->db);
						$this->settings['terms'] = $term->getDefaultId();
					}
					$row['terms'] = $this->settings['terms'];
				}
				if ($row['terms'] <= 0) {
					throw new RuntimeException('MemberInvoicePaymentTermsMissing');
				}
				$row['paymentmode'] = (int) $customer->mode_reglement_id;
				$row['bank'] = $customer->fk_account ? (int) $customer->fk_account : getDolGlobalInt('FACTURE_RIB_NUMBER');
				$row['product'] = getDolGlobalInt('ADHERENT_PRODUCT_ID_FOR_SUBSCRIPTIONS');
				$row['producttype'] = 1;
				if ($row['product']) {
					require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
					if (!isset($this->settings['product'])) {
						$product = new Product($this->db);
						if ((!isModEnabled('product') && !isModEnabled('service')) || $product->fetch($row['product']) <= 0 || !in_array((int) $product->entity, array_map('intval', explode(',', getEntity('product'))), true)) {
							throw new RuntimeException('MemberInvoiceInvalidProduct');
						}
						$this->settings['product'] = $product;
					}
					$row['producttype'] = (int) $this->settings['product']->type;
				}
				$row['vat'] = $this->vat($row['product']);
				$row['localtax1'] = get_localtax($row['vat'], 1, $customer, $mysoc);
				$row['localtax2'] = get_localtax($row['vat'], 2, $customer, $mysoc);
				$row['entity'] = (int) $conf->entity;
			} catch (RuntimeException $e) {
				$row['error'] = $e->getMessage();
			}
			$rows[$id] = $row;
		}
		return $this->withDuplicates($rows);
	}

	/**
	 * @param int $product Product ID
	 * @return string|float
	 */
	private function vat($product)
	{
		global $mysoc;
		if (isset($this->settings['vat'])) {
			return $this->settings['vat'];
		}
		$setting = getDolGlobalString('ADHERENT_VAT_FOR_SUBSCRIPTIONS');
		if ($setting === 'defaultforfoundationcountry') {
			$this->settings['vat'] = get_default_tva($mysoc, $mysoc, $product);
			return $this->settings['vat'];
		}
		if ($setting === '' || $setting === '0') {
			return 0; // Membership configuration explicitly selects no VAT.
		}
		$res = $this->db->query('SELECT taux, code FROM '.$this->db->prefix().'c_tva WHERE active > 0 AND rowid = '.((int) $setting).' AND entity IN ('.getEntity('c_tva').')');
		if (!$res || !($vat = $this->db->fetch_object($res))) {
			throw new RuntimeException('MemberInvoiceInvalidVat');
		}
		$this->settings['vat'] = $vat->taux.($vat->code ? ' ('.$vat->code.')' : '');
		return $this->settings['vat'];
	}

	/**
	 * Include cancelled invoices and legacy subscription-to-invoice links.
	 *
	 * @param array<int,array<string,mixed>> $rows Preview rows
	 * @return array<int,array<string,mixed>>
	 */
	private function withDuplicates(array $rows)
	{
		global $conf;
		$ids = implode(',', array_keys($rows));
		$prefix = $this->db->prefix();
		$sql = "SELECT e.fk_source as memberid, f.rowid, d.date_start, d.date_end FROM ".$prefix."element_element e";
		$sql .= " JOIN ".$prefix."facture f ON f.rowid = e.fk_target LEFT JOIN ".$prefix."facturedet d ON d.fk_facture = f.rowid";
		$sql .= " WHERE e.sourcetype = 'member' AND e.targettype = 'facture' AND e.fk_source IN (".$ids.")";
		$sql .= ' AND f.type = 0 AND f.entity = '.((int) $conf->entity);
		$sql .= " UNION ALL SELECT s.fk_adherent as memberid, f.rowid, s.dateadh as date_start, s.datef as date_end FROM ".$prefix."subscription s";
		$sql .= " JOIN ".$prefix."element_element e ON e.fk_source = s.rowid AND e.sourcetype = 'subscription' AND e.targettype = 'facture'";
		$sql .= " JOIN ".$prefix."facture f ON f.rowid = e.fk_target WHERE s.fk_adherent IN (".$ids.")";
		$sql .= ' AND f.type = 0 AND f.entity = '.((int) $conf->entity);
		$res = $this->db->query($sql);
		if (!$res) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		while ($found = $this->db->fetch_object($res)) {
			$row = &$rows[(int) $found->memberid];
			$start = $found->date_start ? $this->db->jdate($found->date_start) : 0;
			$end = $found->date_end ? $this->db->jdate($found->date_end) : 0;
			if (!$start || !$end || $end < $start || ($start <= $row['end'] && $end >= $row['start'])) {
				$row['duplicates'][(int) $found->rowid] = (int) $found->rowid;
				if (!$row['error']) {
					$row['error'] = 'MemberInvoiceDuplicate';
				}
			}
			unset($row);
		}
		return $rows;
	}

	/**
	 * @param array<string,mixed> $row Preview row
	 * @return string
	 */
	public static function fingerprint(array $row)
	{
		return hash('sha256', json_encode($row));
	}

	/**
	 * Atomically create one invoice after locking and refreshing the preview.
	 *
	 * @param array<string,mixed> $expected Confirmed row
	 * @param string $template Description template
	 * @param array<int,string> $amounts Individual amounts
	 * @param User $user User
	 * @param bool $createCustomers Explicit consent to create missing third parties
	 * @return Facture
	 */
	public function create(array $expected, $template, array $amounts, $user, $createCustomers = false)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		if (!self::canCreate($user)) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		if (!$this->db->begin()) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		try {
			$res = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'adherent WHERE rowid = '.((int) $expected['id']).' FOR UPDATE');
			if (!$res || !$this->db->fetch_object($res)) {
				throw new RuntimeException('MemberInvoiceDatabaseError');
			}
			$this->customers = array();
			$rows = $this->preview(array($expected['id']), $expected['start'], $template, $amounts, $user, $createCustomers);
			$row = $rows[$expected['id']];
			if ($row['error']) {
				throw new RuntimeException($row['error']);
			}
			if (self::fingerprint($row) !== self::fingerprint($expected)) {
				throw new RuntimeException('MemberInvoicePreviewChanged');
			}
			if (!empty($row['newcustomer'])) {
				if (!$createCustomers || !self::canCreateCustomer($user)) {
					throw new RuntimeException('NotEnoughPermissions');
				}
				$customer = $this->memberCustomer($row['id'], $user, true);
				$actualRows = $this->preview(array($row['id']), $row['start'], $template, $amounts, $user);
				$actual = $actualRows[$row['id']];
				if ($actual['error']) {
					throw new RuntimeException($actual['error']);
				}
				if ((int) $actual['socid'] !== (int) $customer->id) {
					throw new RuntimeException('MemberInvoicePreviewChanged');
				}
				foreach (array('amount', 'start', 'end', 'description', 'terms', 'paymentmode', 'bank', 'product', 'producttype', 'vat', 'localtax1', 'localtax2', 'entity') as $field) {
					if ($actual[$field] != $row[$field]) {
						throw new RuntimeException('MemberInvoicePreviewChanged');
					}
				}
				$row = $actual;
			}
			$invoice = $this->newInvoice();
			$invoice->entity = $row['entity'];
			$invoice->type = Facture::TYPE_STANDARD;
			$invoice->socid = $row['socid'];
			$invoice->date = dol_now();
			$invoice->cond_reglement_id = $row['terms'];
			$invoice->mode_reglement_id = $row['paymentmode'];
			$invoice->fk_account = $row['bank'];
			if ($invoice->create($user) <= 0) {
				throw new RuntimeException('MemberInvoiceCreateFailed');
			}
			$result = $invoice->addline(dol_escape_htmltag($row['description']), 0, 1, $row['vat'], $row['localtax1'], $row['localtax2'], $row['product'], 0, $row['start'], $row['end'], 0, 0, 0, 'TTC', $row['amount'], $row['producttype']);
			if ($result <= 0 || $invoice->add_object_linked('member', $row['id']) <= 0) {
				throw new RuntimeException('MemberInvoiceCreateFailed');
			}
			if (!$this->db->commit()) {
				throw new RuntimeException('MemberInvoiceDatabaseError');
			}
			return $invoice;
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * @return Facture New invoice; separate factory for isolated transaction tests
	 */
	protected function newInvoice()
	{
		return new Facture($this->db);
	}

	/**
	 * Serialize validation and keep it independent of document generation.
	 *
	 * @param int $invoiceid Invoice ID
	 * @param int $memberid Linked member ID
	 * @param User $user User
	 * @return Facture Validated invoice
	 */
	public function validate($invoiceid, $memberid, $user)
	{
		if (!self::canProcess($user, 'validate')) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		if (!$this->db->begin()) {
			throw new RuntimeException('MemberInvoiceDatabaseError');
		}
		try {
			$res = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'facture WHERE rowid = '.((int) $invoiceid).' FOR UPDATE');
			if (!$res || !$this->db->fetch_object($res)) {
				throw new RuntimeException('MemberInvoiceDatabaseError');
			}
			$invoice = $this->invoice($invoiceid, $memberid, $user);
			if ($invoice->status == Facture::STATUS_DRAFT) {
				if (isModEnabled('stock') && getDolGlobalInt('STOCK_CALCULATE_ON_BILL')) {
					throw new RuntimeException('ErrorMassValidationNotAllowedWhenStockIncreaseOnAction');
				}
				if ($invoice->validate($user) <= 0) {
					throw new RuntimeException('MemberInvoiceValidateFailed');
				}
			}
			if (!in_array((int) $invoice->status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true)) {
				throw new RuntimeException('MemberInvoiceNotValidated');
			}
			if (!$this->db->commit()) {
				throw new RuntimeException('MemberInvoiceDatabaseError');
			}
			return $invoice;
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Load only an accessible invoice still linked to the expected member.
	 *
	 * @param int $invoiceid Invoice ID
	 * @param int $memberid Member ID
	 * @param User $user User
	 * @return Facture
	 */
	public function invoice($invoiceid, $memberid, $user)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$invoice = new Facture($this->db);
		if (!self::canRead($user) || $invoice->fetch($invoiceid) <= 0 || (int) $invoice->entity !== (int) $conf->entity
			|| !checkUserAccessToObject($user, array('facture'), $invoice)
			|| restrictedArea($user, 'adherent', $memberid, '', '', 'socid', 'rowid', 0, 1) <= 0) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		$sql = "SELECT e.rowid FROM ".$this->db->prefix()."element_element e JOIN ".$this->db->prefix()."adherent m ON m.rowid = e.fk_source WHERE e.sourcetype = 'member' AND e.targettype = 'facture'";
		$sql .= ' AND e.fk_source = '.((int) $memberid).' AND e.fk_target = '.((int) $invoiceid).' AND m.entity IN ('.getEntity('adherent').')';
		$res = $this->db->query($sql);
		if (!$res || !$this->db->fetch_object($res)) {
			throw new RuntimeException('MemberInvoiceLinkChanged');
		}
		if ($invoice->fetch_thirdparty() <= 0) {
			throw new RuntimeException('MemberInvoiceMissingCustomer');
		}
		if ($invoice->type != Facture::TYPE_STANDARD || !checkUserAccessToObject($user, array('societe'), $invoice->thirdparty)) {
			throw new RuntimeException('NotEnoughPermissions');
		}
		return $invoice;
	}
}
