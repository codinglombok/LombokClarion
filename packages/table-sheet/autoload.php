<?php

declare(strict_types=1);

/**
 * Local test bootstrap: eagerly requires every source file (classmap-style)
 * rather than a strict PSR-4 file-per-class mapping, since several small,
 * tightly-related classes intentionally share a file (Cell/Sheet/Workbook in
 * Model.php, the whole formula AST in Formula.php). Composer consumers use
 * the "classmap" autoload strategy declared in composer.json, which handles
 * this the same way — this file exists only so tests can run without
 * reaching Packagist in a network-restricted environment.
 */
function lombok_require_tree(string $dir): void
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            lombok_require_tree($path);
        } elseif (str_ends_with($entry, '.php')) {
            require_once $path;
        }
    }
}

lombok_require_tree(__DIR__ . '/src');
