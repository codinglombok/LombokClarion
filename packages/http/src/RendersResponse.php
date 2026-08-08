<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * An exception that knows its own HTTP shape. The Kernel catches ONLY
 * exceptions implementing this and asks them to render; everything else
 * keeps bubbling to the SAPI as the 500 it is.
 *
 * This is the deliberate opposite of a global exception-to-response
 * mapper: there is no registry translating exception classes to status
 * codes at a distance. An exception either is an HTTP outcome (a failed
 * validation IS a 422) and says so itself, or it is a bug and must not
 * be dressed up as a response.
 */
interface RendersResponse
{
    public function toResponse(): Response;
}
