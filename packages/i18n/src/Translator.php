<?php

declare(strict_types=1);

namespace LombokClarion\I18n;

use RuntimeException;

/**
 * Explicit-over-magic i18n. Catalogs are plain PHP files registered by hand:
 *
 *   $t = new Translator('id', 'en');
 *   $t->addCatalog('id', require 'resources/lang/id.php');
 *   $t->addCatalog('en', require 'resources/lang/en.php');
 *   $t->trans('welcome', ['name' => 'Ana']);       // interpolation :name
 *   $t->choice('apples', 3);                        // "one|many" pluralization
 *
 * No directory scanning, no locale auto-negotiation hidden in the framework —
 * DetectLocale middleware does negotiation explicitly, per route, and the chosen
 * locale flows through RequestContext like every other request-scoped value.
 * Missing key => falls back to the fallback locale; missing there too => the key
 * itself is returned AND recorded in missingKeys() so tests/CI can assert
 * catalogs are complete (translation gaps are findable, not silent).
 */
final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    /** @var list<string> */
    private array $missing = [];

    public function __construct(
        private string $locale,
        private readonly string $fallbackLocale = 'en',
    ) {
    }

    /** @param array<string, string> $messages */
    public function addCatalog(string $locale, array $messages): void
    {
        $this->catalogs[$locale] = [...($this->catalogs[$locale] ?? []), ...$messages];
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, string|int|float> $params */
    public function trans(string $key, array $params = []): string
    {
        $message = $this->catalogs[$this->locale][$key]
            ?? $this->catalogs[$this->fallbackLocale][$key]
            ?? null;

        if ($message === null) {
            $this->missing[] = "{$this->locale}.{$key}";
            return $key;
        }

        foreach ($params as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }

        return $message;
    }

    /**
     * Pluralization: catalog value "satu apel|:count apel" — segment 0 for
     * count === 1, segment 1 otherwise; :count is always available.
     *
     * @param array<string, string|int|float> $params
     */
    public function choice(string $key, int $count, array $params = []): string
    {
        $raw = $this->catalogs[$this->locale][$key]
            ?? $this->catalogs[$this->fallbackLocale][$key]
            ?? null;

        if ($raw === null) {
            $this->missing[] = "{$this->locale}.{$key}";
            return $key;
        }

        $segments = explode('|', $raw);
        $message = $count === 1 ? $segments[0] : ($segments[1] ?? $segments[0]);

        return $this->interp($message, ['count' => $count, ...$params]);
    }

    private function interp(string $message, array $params): string
    {
        foreach ($params as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }
        return $message;
    }

    /** @return list<string> locale-qualified keys that resolved nowhere */
    public function missingKeys(): array
    {
        return $this->missing;
    }

    public function assertNoMissingKeys(): void
    {
        if ($this->missing !== []) {
            throw new RuntimeException('Missing translations: ' . implode(', ', array_unique($this->missing)));
        }
    }
}
