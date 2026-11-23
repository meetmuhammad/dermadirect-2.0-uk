<?php
/**
 * Standalone tests for barcode scanner upload de-duplication.
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/barcode_scanner_upload_test.php
 *
 * Only pure static helpers are called, so no WordPress functions or AWS SDK classes are needed.
 * The hashes below are placeholders standing in for md5_file() results.
 */

use App\DermadirectToolsMenu\BarcodeScanner\BarcodeScannerManager;

$root = dirname(__DIR__, 2);
require $root . '/web/app/themes/derma-direct/app/DermadirectToolsMenu/BarcodeScanner/BarcodeScannerManager.php';

$failures = 0;
$passes = 0;

function check(string $name, bool $ok): void
{
    global $failures, $passes;

    if ($ok) {
        $passes++;
        echo "PASS  {$name}\n";
    } else {
        $failures++;
        echo "FAIL  {$name}\n";
    }
}

$a = md5('photo-a');
$b = md5('photo-b');
$c = md5('photo-c');

/* ---------------- Duplicates ---------------- */

check('two identical hashes keep one', BarcodeScannerManager::uniqueUploadIndexes([$a, $a]) === [0]);
check('two different hashes keep two', BarcodeScannerManager::uniqueUploadIndexes([$a, $b]) === [0, 1]);
check('duplicate between distinct photos is dropped', BarcodeScannerManager::uniqueUploadIndexes([$a, $a, $b]) === [0, 2]);
check('originals plus compressed copies keep one of each', BarcodeScannerManager::uniqueUploadIndexes([$a, $b, $a, $b]) === [0, 1]);

/* ---------------- Max photos ---------------- */

check('three different keep the first two', BarcodeScannerManager::uniqueUploadIndexes([$a, $b, $c]) === [0, 1]);
check('default max matches MAX_PHOTOS', BarcodeScannerManager::MAX_PHOTOS === 2);
check('custom max is respected', BarcodeScannerManager::uniqueUploadIndexes([$a, $b, $c], 3) === [0, 1, 2]);
check('zero max keeps nothing', BarcodeScannerManager::uniqueUploadIndexes([$a, $b], 0) === []);

/* ---------------- Failed reads ---------------- */

check('empty hash is skipped', BarcodeScannerManager::uniqueUploadIndexes(['', $a]) === [1]);
check('false hash is skipped', BarcodeScannerManager::uniqueUploadIndexes([false, $a, $b]) === [1, 2]);
check('null hash is skipped', BarcodeScannerManager::uniqueUploadIndexes([null, $a]) === [1]);
check('all empty hashes keep nothing', BarcodeScannerManager::uniqueUploadIndexes(['', '']) === []);
check('skipped files do not count toward max', BarcodeScannerManager::uniqueUploadIndexes(['', $a, $a, $b, $c]) === [1, 3]);

/* ---------------- Empty input ---------------- */

check('empty input keeps nothing', BarcodeScannerManager::uniqueUploadIndexes([]) === []);

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
