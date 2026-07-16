<?php

declare(strict_types=1);

/**
 * Enforces master prompt §3's hard rule: app/Domain/** may import only
 * plain PHP/stdlib and other app/Domain/** classes, never LombokClarion\*.
 *
 * Additionally enforces §4.12's `forbidden-layers` metadata from optional
 * packages (lombokclarion/active-record and lombokclarion/facades): those
 * packages declare ["app/Domain"] as forbidden, so any import from
 * LombokClarion\ActiveRecord\* or LombokClarion\Facades\* inside
 * app/Domain/** is also a violation.
 *
 * Usage: php bin/check-domain-boundary.php
 */

$domainDir = __DIR__ . '/../app/Domain';
$forbiddenNamespaces = [
    'LombokClarion\\',
];
$violations = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainDir, RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());

    if (preg_match_all('/^use\s+(LombokClarion\\\\[^;]+);/m', $contents, $matches)) {
        foreach ($matches[1] as $badImport) {
            $violations[] = "{$file->getPathname()}: imports {$badImport}";
        }
    }

    // Strip comments/docblocks so explanatory text mentioning LombokClarion
    // isn't a false positive — only real code references count.
    $codeOnly = '';
    foreach (token_get_all($contents) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= $token[1];
        } else {
            $codeOnly .= $token;
        }
    }

    if (preg_match_all('/\\\\?LombokClarion\\\\[A-Za-z\\\\]+/', $codeOnly, $matches)) {
        foreach (array_unique($matches[0]) as $badRef) {
            $violations[] = "{$file->getPathname()}: references {$badRef}";
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Domain boundary violations found:\n");
    foreach (array_unique($violations) as $violation) {
        fwrite(STDERR, "  - $violation\n");
    }
    exit(1);
}

echo "Domain boundary OK: app/Domain/** has zero LombokClarion\\* imports.\n";
exit(0);
