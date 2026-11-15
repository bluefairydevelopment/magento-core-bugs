<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * Static-analysis bootstrap (phpunit uses Test/Unit/bootstrap.php) for bluefairy/module-core-bugs.
 *
 * This package is only ever installed into vendor/, so Magento's own unit-test
 * bootstrap (`dev/tests/unit/framework/bootstrap.php`, which exists only for a module
 * under app/code/) is not used. The numbered rules below are what this one enforces:
 *
 * 1. LOAD vendor/autoload.php, NEVER app/bootstrap.php. Magento's
 *    app/bootstrap.php calls stream_wrapper_unregister('phar'), and phpstan runs
 *    FROM a phar - it dies exit 255 with zero findings, reading like an opinion.
 *
 * 2. PREPEND the source tree. Composer maps BlueFairy\CoreBugs\ to the INSTALLED
 *    package in the Magento vendor/ directory, so without this a run reads this
 *    repository's tests and exercises the last published release.
 *
 * 3. REFUSE to run without compiled DI rather than guess.
 *
 * 4. Synthesise a Factory/Extension class ONLY when composer cannot find a real
 *    file for it, and REFUSE to analyse if one in this package's OWN namespace
 *    would be synthesised - phpstan stops checking anything an empty stand-in
 *    returns, and exits 0 over its own blind spot.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: '';

if ($magentoRoot === '' || !is_file($magentoRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "MAGENTO_ROOT is not set to a Magento installation.\n"
        . "These tests mock Magento classes, so the framework has to be loadable:\n"
        . "  MAGENTO_ROOT=/path/to/magento /path/to/magento/vendor/bin/phpunit -c phpunit.xml.dist\n"
    );
    // phpcs:ignore Magento2.Security.LanguageConstruct -- a test bootstrap that refuses to run exits non-zero; the exit is the refusal
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $composerLoader */
$composerLoader = require $magentoRoot . '/vendor/autoload.php';

$src = dirname(__DIR__);
$ownNamespace = 'BlueFairy\\CoreBugs\\';

spl_autoload_register(static function (string $class) use ($src, $ownNamespace): void {
    if (!str_starts_with($class, $ownNamespace)) {
        return;
    }
    $file = $src . '/' . str_replace('\\', '/', substr($class, strlen($ownNamespace))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

$generated = $magentoRoot . '/generated/code/';

// The compiled-DI proxy. It proves SOMETHING was compiled on this install, not
// that this module was - named as a proxy rather than left to read like a guarantee.
foreach (['Magento/Catalog/Api/Data/ProductExtensionInterface.php'] as $sentinel) {
    if (!is_file($generated . $sentinel)) {
        fwrite(STDERR, sprintf(
            "\nREFUSING to run: %s is missing.\n"
            . "The Magento install at %s has no compiled DI AT ALL, so extension-attribute\n"
            . "interfaces would be synthesised empty and this run would measure phantoms.\n"
            . "Run:  cd %s && php -d memory_limit=-1 bin/magento setup:di:compile\n\n",
            $generated . $sentinel,
            $magentoRoot,
            $magentoRoot
        ));
        // phpcs:ignore Magento2.Security.LanguageConstruct -- a test bootstrap that refuses to run exits non-zero; the exit is the refusal
        exit(1);
    }
}

$ownFactoryImports = [];
$unresolved = [];

foreach (['Api', 'App', 'Block', 'Console', 'Controller', 'Cron', 'Helper', 'Model', 'Observer', 'Plugin', 'Setup', 'Ui', 'ViewModel'] as $dir) {
    if (!is_dir($src . '/' . $dir)) {
        continue;
    }
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $entry) {
        if (!$entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }
        preg_match_all(
            '/^use\s+([A-Za-z0-9_\\\\]+(?:Factory|ExtensionInterface|Extension))\s*;/m',
            (string) file_get_contents($entry->getPathname()),
            $matches
        );
        foreach ($matches[1] as $name) {
            $ownFactoryImports[$name] = true;
            if ($composerLoader->findFile($name) === false && !is_file($generated . str_replace('\\', '/', $name) . '.php')) {
                $unresolved[$name] = true;
            }
        }
    }
}

$underAnalyser = str_contains(strtolower((string) ($_SERVER['argv'][0] ?? '')), 'phpstan')
    || class_exists('PHPStan\Analyser\Analyser', false);

// Print the denominator on EVERY run, pass or fail, so a green carries it.
fwrite(STDERR, sprintf(
    "core-bugs-bootstrap: %d Factory/Extension import(s) in shipped code, %d unresolved%s\n",
    count($ownFactoryImports),
    count($unresolved),
    $underAnalyser ? ' [static analysis]' : ''
));

$ownUnresolved = array_filter(
    array_keys($unresolved),
    static fn (string $name): bool => str_starts_with($name, $ownNamespace)
);

if ($ownUnresolved !== [] && $underAnalyser) {
    fwrite(STDERR, sprintf(
        "\nREFUSING to analyse: %d class(es) in this package's OWN namespace cannot be resolved\n"
        . "and would be SYNTHESISED as empty stand-ins:\n  %s\n"
        . "Install this package on %s and compile, then analyse.\n\n",
        count($ownUnresolved),
        implode("\n  ", $ownUnresolved),
        $magentoRoot
    ));
    // phpcs:ignore Magento2.Security.LanguageConstruct -- a test bootstrap that refuses to run exits non-zero; the exit is the refusal
    exit(1);
}

spl_autoload_register(static function (string $class) use ($generated): void {
    $file = $generated . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

spl_autoload_register(static function (string $class) use ($composerLoader): void {
    if (class_exists($class, false) || interface_exists($class, false)) {
        return;
    }

    $isFactory   = str_ends_with($class, 'Factory');
    $isExtension = str_ends_with($class, 'ExtensionInterface');
    $isExtData   = str_ends_with($class, 'Extension') && !$isExtension;

    if (!$isFactory && !$isExtension && !$isExtData) {
        return;
    }
    $realFile = $composerLoader->findFile($class);
    if ($realFile !== false) {
        require $realFile;
        return;
    }

    $pos       = strrpos($class, '\\');
    $namespace = $pos === false ? '' : substr($class, 0, $pos);
    $shortName = $pos === false ? $class : substr($class, $pos + 1);
    $nsDecl    = $namespace === '' ? '' : sprintf('namespace %s;', $namespace);

    if ($isExtension) {
        // phpcs:ignore Magento2.Security.InsecureFunction,Squiz.PHP.Eval -- test bootstrap only: declares an empty stand-in for a generated class the Magento root lacks; the code is built from the class name alone
        eval(sprintf('%s interface %s {}', $nsDecl, $shortName));
        return;
    }

    if ($isExtData) {
        $iface      = $class . 'Interface';
        $implements = (interface_exists($iface) || class_exists($iface)) ? ' implements \\' . $iface : '';
        // phpcs:ignore Magento2.Security.InsecureFunction,Squiz.PHP.Eval -- test bootstrap only: declares an empty stand-in for a generated class the Magento root lacks; the code is built from the class name alone
        eval(sprintf('%s class %s%s {}', $nsDecl, $shortName, $implements));
        return;
    }

    // phpcs:ignore Magento2.Security.InsecureFunction,Squiz.PHP.Eval -- test bootstrap only: declares an empty stand-in for a generated class the Magento root lacks; the code is built from the class name alone
    eval(sprintf(
        '%s class %s { public function create(array $data = []) { return null; } }',
        $nsDecl,
        $shortName
    ));
});
