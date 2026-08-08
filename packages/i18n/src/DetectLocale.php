<?php

declare(strict_types=1);

namespace LombokClarion\I18n;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\RequestContext;
use LombokClarion\Http\Response;

/**
 * Per-route locale negotiation (never a hidden global): precedence is
 * ?lang= query param, then Accept-Language header, then the translator's
 * current (default) locale — restricted to an explicit allow-list.
 * The chosen locale is set on the Translator and stored in RequestContext.
 */
final class DetectLocale implements Middleware
{
    /** @param list<string> $supported */
    public function __construct(
        private readonly Translator $translator,
        private readonly RequestContext $context,
        private readonly array $supported,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $candidate = (string) ($request->query['lang'] ?? '');

        if (!in_array($candidate, $this->supported, true)) {
            $candidate = $this->fromAcceptLanguage($request->header('accept-language') ?? '');
        }

        if ($candidate !== '') {
            $this->translator->setLocale($candidate);
        }

        $this->context->set('locale', $this->translator->locale());

        return $next($request);
    }

    private function fromAcceptLanguage(string $header): string
    {
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            $primary = explode('-', $tag)[0];
            if (in_array($tag, $this->supported, true)) {
                return $tag;
            }
            if (in_array($primary, $this->supported, true)) {
                return $primary;
            }
        }
        return '';
    }
}
