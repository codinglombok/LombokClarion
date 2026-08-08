<?php

declare(strict_types=1);

namespace LombokClarion\Http\Errors;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Renders the body for an error response.
 *
 * THE SIGNATURE IS THE DESIGN DECISION. It takes a STATUS CODE, not a
 * Throwable. That is what keeps this from becoming the thing
 * {@see \LombokClarion\Http\RendersResponse} explicitly rejects: a registry
 * mapping exception classes to status codes at a distance.
 *
 * The rule stays exactly what it was. An exception either IS an HTTP outcome
 * and says so itself by implementing RendersResponse, or it is a bug and the
 * status is 500 — nothing here can promote a random exception into a 403 by
 * being registered somewhere. Statuses reach this renderer from only three
 * places, all of them explicit: the router (404/405), a RendersResponse
 * exception, or "something crashed" (500).
 *
 * What this interface adds is presentation only: given that we ARE returning
 * 404, what should the body look like.
 */
interface ErrorPageRenderer
{
    /**
     * @param int $status the HTTP status being returned — chosen before this
     *        is called, never by this
     * @param array<string, mixed> $details extra information the renderer may
     *        show. In production this is empty or near-empty; in debug mode it
     *        carries the exception details. The renderer decides what to show,
     *        the caller decides what to hand over — see
     *        {@see ErrorHandler} for why that split matters.
     */
    public function render(int $status, Request $request, array $details = []): Response;
}
