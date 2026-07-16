<?php

declare(strict_types=1);

namespace LombokClarion\View;

/**
 * Wrap a value with Safe::mark() before outputting it with `{!! !!}` to
 * signal to `lombokclarion audit:sql`'s sibling XSS rule that this
 * particular raw-output site was reviewed and is fed from a pre-sanitized
 * source (e.g. a markdown-to-HTML renderer already escaping user input).
 * The wrapper itself does no sanitization — it is a marker, not a filter.
 */
final class Safe
{
    private function __construct(public readonly string $value)
    {
    }

    public static function mark(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
