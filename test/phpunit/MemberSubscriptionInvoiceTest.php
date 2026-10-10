<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Tests deliberately avoid master.inc.php and the application database. */
if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 2).'/htdocs');
}
require_once __DIR__.'/fixtures/membersubscriptioninvoice.php';

/** Database-free membership invoice regression tests. */
class MemberSubscriptionInvoiceTest extends PHPUnit\Framework\TestCase
{
	/** @return void */
	public function testIsolatedScenarios()
	{
		global $conf, $langs;
		$savedConf = $conf;
		$savedLangs = $langs;
		try {
			memberInvoiceTestEnvironment();
			foreach (memberInvoiceScenarios() as $name => $scenario) {
				$this->assertTrue($scenario(), $name);
			}
		} finally {
			$conf = $savedConf;
			$langs = $savedLangs;
		}
	}
}
