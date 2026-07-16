<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

use LombokClarion\Console\ConsoleKernel;

final class ConsoleTestCase
{
    public function __construct(private readonly ConsoleKernel $console)
    {
    }

    /**
     * @param list<string> $argv
     * @return array{exitCode: int, output: string}
     */
    public function run(array $argv): array
    {
        ob_start();
        $exitCode = $this->console->handle($argv);
        $output = ob_get_clean();

        return ['exitCode' => $exitCode, 'output' => $output];
    }
}
