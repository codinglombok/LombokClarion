<?php

declare(strict_types=1);

namespace LombokClarion\Validation;

use LombokClarion\Http\RendersResponse;
use LombokClarion\Http\Response;
use LombokClarion\I18n\Translator;
use RuntimeException;

/**
 * A failed validation IS an HTTP outcome — 422 with per-field errors — so
 * this exception implements RendersResponse and the Kernel renders it
 * without any route-side try/catch boilerplate.
 *
 * Violations stay structural (key + params) until the moment of
 * rendering. setTranslator() is how FormRequest injects the app's
 * Translator so field errors come out in the request's locale; without
 * one, messages fall back to the raw keys — ugly and OBVIOUS, which is
 * the correct failure mode for "forgot to wire the translator" (a silent
 * English fallback would hide the wiring gap from an Indonesian-only
 * deployment until a user complained).
 */
final class ValidationException extends RuntimeException implements RendersResponse
{
    private ?Translator $translator = null;

    /**
     * @param array<string, list<Violation>> $violations field => violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Validation failed for: ' . implode(', ', array_keys($violations)));
    }

    public function setTranslator(Translator $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * @return array<string, list<string>> field => rendered messages
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->violations as $field => $violations) {
            foreach ($violations as $violation) {
                $params = ['field' => $field] + $violation->params;
                $errors[$field][] = $this->translator?->trans($violation->key, $params)
                    ?? $violation->key;
            }
        }

        return $errors;
    }

    public function toResponse(): Response
    {
        return Response::json(['errors' => $this->errors()], 422);
    }
}
