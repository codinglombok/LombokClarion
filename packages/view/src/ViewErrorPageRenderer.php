<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\Http\Errors\ErrorPageRenderer;
use LombokClarion\Http\Errors\PlainErrorPageRenderer;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use Throwable;

/**
 * Renders error pages from templates, one per status code:
 * `errors/404`, `errors/500`, and so on.
 *
 * RESOLUTION IS A THREE-STEP LADDER, and each rung exists because the one
 * below it is not good enough on its own:
 *
 *   1. `errors/{status}` — the specific page, when the app has authored one.
 *   2. `errors/generic` — one template covering the statuses nobody bothered
 *      to design, so adding a 429 to the app does not require a new file just
 *      to avoid an ugly page.
 *   3. {@see PlainErrorPageRenderer} — no templates involved at all.
 *
 * Step 3 is the one that matters. The most likely reason an error page fails
 * to render is that the view layer itself is what broke — a compile error in a
 * template, an unwritable cache directory. A renderer that could only produce
 * output when the template system was healthy would go blank in exactly the
 * situation it exists for. So a template failure here is caught, and the
 * dependency-free renderer answers instead.
 *
 * JSON clients skip templates entirely: an API asking for JSON gets JSON, not
 * a rendered HTML page with a JSON content type bolted on.
 */
final class ViewErrorPageRenderer implements ErrorPageRenderer
{
    private readonly ErrorPageRenderer $fallback;

    public function __construct(
        private readonly ViewEngine $views,
        ?ErrorPageRenderer $fallback = null,
        private readonly string $templatePrefix = 'errors/',
    ) {
        $this->fallback = $fallback ?? new PlainErrorPageRenderer();
    }

    public function render(int $status, Request $request, array $details = []): Response
    {
        if ($this->wantsJson($request)) {
            return $this->fallback->render($status, $request, $details);
        }

        foreach ([$this->templatePrefix . $status, $this->templatePrefix . 'generic'] as $template) {
            try {
                $html = $this->views->render($template, [
                    'status' => $status,
                    'details' => $details,
                    'path' => $request->path,
                ]);

                return Response::html($html, $status);
            } catch (Throwable) {
                // Missing template, or a broken one. Try the next rung; the
                // last rung cannot fail. Deliberately not logged here — the
                // common case is "this app has no errors/429 template", which
                // is normal operation, not a fault worth a line in the log.
                continue;
            }
        }

        return $this->fallback->render($status, $request, $details);
    }

    private function wantsJson(Request $request): bool
    {
        if (strtolower((string) $request->header('x-requested-with')) === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) $request->header('accept'));

        return str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }
}
