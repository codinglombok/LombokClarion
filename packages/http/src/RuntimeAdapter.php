<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * The single seam between the framework and its deployment target.
 * FpmAdapter, SwooleAdapter, and FunctionAdapter all implement this and sit
 * behind the same Kernel/Router/Container/domain code — only the adapter
 * changes per deployment target (master prompt §5).
 */
interface RuntimeAdapter
{
    public function handle(Request $request): Response;
}
