<?php

declare(strict_types=1);

namespace LombokClarion\Http\Errors;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Error bodies without a view layer: JSON for clients that asked for it, a
 * small self-contained HTML page otherwise.
 *
 * This is the renderer the framework can always fall back to, which is why it
 * has no dependency on the view package and no template files. An error
 * renderer that needs the template system to be working is useless in the case
 * where the template system is what broke.
 *
 * CONTENT NEGOTIATION IS ON ACCEPT AND X-REQUESTED-WITH, and it exists because
 * returning an HTML error page to a fetch() call produces a parse error in the
 * client that hides the real status. An API client that asked for JSON gets
 * JSON even when the thing that failed was the server.
 *
 * Everything interpolated into the HTML goes through htmlspecialchars —
 * including the debug details, which contain exception messages that can
 * echo back attacker-supplied input. A debug-only XSS is still an XSS, and
 * "only on staging" is not a boundary anyone should be relying on.
 */
final class PlainErrorPageRenderer implements ErrorPageRenderer
{
    public function render(int $status, Request $request, array $details = []): Response
    {
        if ($this->wantsJson($request)) {
            $payload = ['error' => $this->reasonPhrase($status), 'status' => $status];

            // $details is empty in production — ErrorHandler never passes it.
            if ($details !== []) {
                $payload['debug'] = $details;
            }

            return Response::json($payload, $status);
        }

        return Response::html($this->html($status, $details), $status);
    }

    private function wantsJson(Request $request): bool
    {
        if (strtolower((string) $request->header('x-requested-with')) === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) $request->header('accept'));

        // `*/*` is not a request for JSON — curl and every browser send it.
        // Only an explicit json token counts.
        return str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }

    /**
     * @param array<string, mixed> $details
     */
    private function html(int $status, array $details): string
    {
        $phrase = htmlspecialchars($this->reasonPhrase($status), ENT_QUOTES, 'UTF-8');
        $body = '';

        if ($details !== []) {
            $body .= '<h2>Debug</h2><dl>';

            foreach ($details as $key => $value) {
                $body .= '<dt>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</dt>';
                $body .= '<dd><pre>' . htmlspecialchars(
                    is_scalar($value) ? (string) $value : var_export($value, true),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</pre></dd>';
            }

            $body .= '</dl>';
        }

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>$status $phrase</title>
            <style>
            body { font: 16px/1.6 system-ui, sans-serif; margin: 0; display: grid; place-items: center; min-height: 100vh; color: #1a1a1a; background: #fafafa; }
            main { max-width: 46rem; padding: 2rem; }
            h1 { font-size: 3rem; margin: 0 0 .25rem; letter-spacing: -.02em; }
            p { color: #555; margin: 0; }
            dt { font-weight: 600; margin-top: 1rem; }
            pre { overflow-x: auto; background: #f0f0f0; padding: .75rem; border-radius: .25rem; font-size: .8125rem; }
            </style>
            </head>
            <body>
            <main>
            <h1>$status</h1>
            <p>$phrase</p>
            $body
            </main>
            </body>
            </html>
            HTML;
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => $status >= 500 ? 'Server Error' : 'Request Error',
        };
    }
}
