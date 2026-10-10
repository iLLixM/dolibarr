<?php
/* Copyright (C) 2026 Marcel
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Run the PHPUnit scenarios without installing PHPUnit or booting Dolibarr. */
if (PHP_SAPI !== 'cli') {
	exit(1);
}
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
	throw new ErrorException($message, 0, $severity, $file, $line);
});
define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 2).'/htdocs');
require __DIR__.'/../phpunit/fixtures/membersubscriptioninvoice.php';
memberInvoiceTestEnvironment();
$failed = 0;
$scenarios = memberInvoiceScenarios();
foreach ($scenarios as $name => $scenario) {
	try {
		if (!$scenario()) {
			throw new RuntimeException('Assertion failed');
		}
		print 'PASS '.$name."\n";
	} catch (Throwable $e) {
		$failed++;
		print 'FAIL '.$name.': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n";
	}
}
print count($scenarios).' scenarios, '.$failed." failures. No application database or mail transport used.\n";
exit($failed ? 1 : 0);
