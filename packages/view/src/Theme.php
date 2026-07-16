<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\View\Exceptions\ViewException;

/**
 * The `data-style` value applied to <html>. Constructed once at boot from
 * the typed config ($config->theme->style, sourced from the THEME_STYLE
 * env var) and passed into the layout as data — layouts render
 * {{ $theme->style }} and never hardcode a preset name (§8).
 *
 * STARTER_KIT_PRESETS are the 4 presets the LombokClarion spec ships
 * (§13); UPSTREAM_PRESETS are the additional ones LombokCSS itself
 * defines. Both are accepted; anything else fails at boot, not silently
 * at render time.
 */
final class Theme
{
    public const STARTER_KIT_PRESETS = [
        'resonant-stark',   // default
        'neo-brutalism',
        'glassmorphism',
        'quiet-editorial',  // LombokClarion extension, see quiet-editorial.css
    ];

    public const UPSTREAM_PRESETS = [
        'modern-corporate-flat',
        'semantic-minimalist',
    ];

    public function __construct(public readonly string $style)
    {
        $valid = [...self::STARTER_KIT_PRESETS, ...self::UPSTREAM_PRESETS];
        if (!in_array($style, $valid, true)) {
            throw new ViewException(sprintf(
                'Unknown theme "%s". Valid THEME_STYLE values: %s.',
                $style,
                implode(', ', $valid)
            ));
        }
    }
}
