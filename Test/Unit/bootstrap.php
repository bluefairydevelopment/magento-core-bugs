<?php
/**
 * Unit-test bootstrap: a vanilla Magento install's own unit-test framework, plus this module's classes.
 * MAGENTO_ROOT must point at that install. It is never guessed: an unset root once resolved
 * to a shared box.
 */
declare(strict_types=1);

$root = getenv('MAGENTO_ROOT');
if (!$root || !is_file($root . '/dev/tests/unit/framework/bootstrap.php')) {
    throw new \RuntimeException(
        'Set MAGENTO_ROOT to a vanilla Magento install (with dev/tests/unit), e.g. ~/Sites/magento-vanilla'
    );
}
require $root . '/dev/tests/unit/framework/bootstrap.php';
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('BlueFairy\\CoreBugs\\', dirname(__DIR__, 2) . '/');
