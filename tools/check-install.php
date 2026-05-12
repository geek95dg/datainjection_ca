<?php

/**
 * Datainjection (CA) install diagnostic.
 *
 * Run from the GLPI root, e.g.
 *
 *     sudo -u www-data php plugins/datainjectionca/tools/check-install.php
 *
 * Reports exactly what GLPI sees: which plugin directories are scanned,
 * whether `datainjectionca` is present, and whether its setup.php exposes
 * the required `plugin_version_datainjectionca()` function.
 */

const EXPECTED_KEY = 'datainjectionca';

$glpiRoot = dirname(__DIR__, 3);
$inc      = $glpiRoot . '/inc/includes.php';
if (!is_file($inc)) {
    fwrite(STDERR, "Cannot find GLPI at {$glpiRoot}/inc/includes.php\n");
    fwrite(STDERR, "Run this script from inside <glpi_root>/plugins/datainjectionca/tools/\n");
    exit(1);
}
include_once $inc;

echo "GLPI root:        {$glpiRoot}\n";

if (!defined('GLPI_PLUGINS_DIRECTORIES')) {
    echo "GLPI_PLUGINS_DIRECTORIES not defined — GLPI bootstrap incomplete.\n";
    exit(1);
}

echo "Plugin scan dirs:\n";
foreach (GLPI_PLUGINS_DIRECTORIES as $dir) {
    $marker = is_dir($dir) ? '[ok] ' : '[missing] ';
    echo "  {$marker}{$dir}\n";
}

$foundAt = null;
foreach (GLPI_PLUGINS_DIRECTORIES as $dir) {
    $candidate = $dir . '/' . EXPECTED_KEY;
    if (is_dir($candidate)) {
        $foundAt = $candidate;
        break;
    }
}

if ($foundAt === null) {
    echo "\nFAIL: no directory named '" . EXPECTED_KEY . "' found in any plugin dir.\n";
    echo "      Rename or symlink the repo so the folder name is exactly '" . EXPECTED_KEY . "'.\n";
    exit(1);
}

echo "\nFolder:           {$foundAt}\n";

$setup = $foundAt . '/setup.php';
if (!is_file($setup)) {
    echo "FAIL: setup.php missing.\n";
    exit(1);
}
echo "setup.php:        present\n";

include_once $setup;

$versionFn = 'plugin_version_' . EXPECTED_KEY;
$initFn    = 'plugin_init_' . EXPECTED_KEY;
$installFn = 'plugin_' . EXPECTED_KEY . '_install';

foreach ([$versionFn, $initFn, $installFn] as $fn) {
    $status = function_exists($fn) ? '[ok]' : '[MISSING]';
    echo "{$status} {$fn}()\n";
}

if (function_exists($versionFn)) {
    $info = $versionFn();
    echo "\nReported metadata:\n";
    echo "  name:    " . ($info['name']    ?? '?') . "\n";
    echo "  version: " . ($info['version'] ?? '?') . "\n";
    echo "  author:  " . ($info['author']  ?? '?') . "\n";
}

echo "\nIf all rows are [ok], run:\n";
echo "  sudo -u www-data php bin/console glpi:plugin:install " . EXPECTED_KEY . "\n";
