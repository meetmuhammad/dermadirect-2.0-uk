<?php
/**
 * Standalone tests for barcode scanner S3 object keys and storage source resolution.
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/barcode_scanner_storage_test.php
 *
 * Only pure static helpers are called, so no WordPress functions or AWS SDK classes are needed.
 * No real credentials are used; every value below is a placeholder.
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

/* ---------------- Object keys ---------------- */

check('empty folder keeps bare id at bucket root', BarcodeScannerManager::buildObjectKey('6745f0a1b2c3d') === '6745f0a1b2c3d');
check('default folder argument keeps bare id', BarcodeScannerManager::buildObjectKey('abc123') === 'abc123');
check('whitespace folder keeps bare id', BarcodeScannerManager::buildObjectKey('abc123', "  \t ") === 'abc123');
check('slash-only folder keeps bare id', BarcodeScannerManager::buildObjectKey('abc123', '///') === 'abc123');
check('normal prefix', BarcodeScannerManager::buildObjectKey('abc123', 'dd20-staging') === 'dd20-staging/abc123');
check('leading and trailing slashes trimmed', BarcodeScannerManager::buildObjectKey('abc123', '/dd20-staging/') === 'dd20-staging/abc123');
check('surrounding whitespace and slashes trimmed', BarcodeScannerManager::buildObjectKey('abc123', ' //dd20-staging// ') === 'dd20-staging/abc123');
check('nested prefix kept', BarcodeScannerManager::buildObjectKey('abc123', '/photos/dd20-staging/') === 'photos/dd20-staging/abc123');

/* ---------------- Source resolution ---------------- */

$complete = ['key' => 'k-theme', 'secret' => 's-theme', 'region' => 'eu-west-1', 'bucket' => 'b-theme', 'folder' => '/dd20-staging/'];
$option = ['key' => 'k-option', 'secret' => 's-option', 'region' => 'eu-west-2', 'bucket' => 'b-option'];
$env = ['key' => 'k-env', 'secret' => 's-env', 'region' => null, 'bucket' => 'b-env', 'folder' => null];

$config = BarcodeScannerManager::pickStorageConfig(['theme_settings' => $complete, 'option' => $option, 'env' => $env]);
check('theme settings win when complete', $config['source'] === 'theme_settings' && $config['bucket'] === 'b-theme');
check('theme settings folder normalised', $config['folder'] === 'dd20-staging');
check('theme settings region kept', $config['region'] === 'eu-west-1');

$partial = ['key' => 'k-theme', 'secret' => '   ', 'bucket' => 'b-theme'];
$config = BarcodeScannerManager::pickStorageConfig(['theme_settings' => $partial, 'option' => $option, 'env' => $env]);
check('incomplete theme settings fall through to option', $config['source'] === 'option' && $config['key'] === 'k-option');
check('option without folder stores at bucket root', $config['folder'] === '');

$config = BarcodeScannerManager::pickStorageConfig(['theme_settings' => [], 'option' => false, 'env' => $env]);
check('missing option falls through to env', $config['source'] === 'env' && $config['bucket'] === 'b-env');
check('env region defaults to eu-west-2', $config['region'] === 'eu-west-2');

$config = BarcodeScannerManager::pickStorageConfig(['theme_settings' => [], 'option' => ['key' => 'k', 'secret' => 's', 'bucket' => '']]);
check('nothing complete reports not configured', $config['source'] === '' && $config['key'] === '' && $config['bucket'] === '');
check('not configured still reports default region', $config['region'] === 'eu-west-2');

$config = BarcodeScannerManager::pickStorageConfig(['option' => [' key ' => 'x', 'key' => ' k ', 'secret' => ' s ', 'bucket' => ' b ', 'region' => '  ']]);
check('values are trimmed', $config['key'] === 'k' && $config['secret'] === 's' && $config['bucket'] === 'b');
check('blank region defaults to eu-west-2', $config['region'] === 'eu-west-2');

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
