<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$files = glob(__DIR__ . '/*Test.php');
sort($files);
foreach ($files as $file) {
    echo "== " . basename($file) . " ==\n";
    runTests($file);
    echo "\n";
}
echo "ALL TEST FILES PASSED\n";
