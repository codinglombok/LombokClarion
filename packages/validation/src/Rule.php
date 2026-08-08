<?php

declare(strict_types=1);

namespace LombokClarion\Validation;

/**
 * A validation rule: typed object, fluent constraints, one `check()`.
 *
 *   Rule::string()->min(3)->max(255)
 *   Rule::int()->min(1)
 *   Rule::email()
 *   Rule::in(['draft', 'published'])
 *   Rule::date('Y-m-d')
 *   Rule::bool()
 *
 * Deliberately NOT the pipe-string DSL (`'required|max:255'`): a string
 * DSL is a second language that cannot be grepped for usages of one rule,
 * typos into silence instead of an error, and is opaque to PHPStan. Here a
 * misspelled rule is an undefined method at parse time, and "who uses
 * max()" is a grep.
 *
 * Presence semantics: every field named in a rules() array is REQUIRED
 * unless marked ->nullable(). There is no `required` rule to forget — the
 * common case is the default and the exception is the annotation, same
 * direction as $fillable in ActiveRecord.
 *
 * check() returns the CANONICALIZED value on success (ints from numeric
 * strings, bools from checkbox-shaped input, DateTimeImmutable from date
 * strings) — validation and casting are one step for the same reason
 * typed route params narrow and cast at once: there is no "valid but
 * still a string" limbo for the DTO to inherit.
 *
 * The vocabulary is intentionally small. A constraint this set cannot
 * express is a typed callable via ->also(), written next to the rule that
 * needs it — not a global rule registry that grows by accretion.
 */
final class Rule
{
    /** @var 'string'|'int'|'email'|'bool'|'date'|'in'|'file' */
    private string $type;

    private bool $nullable = false;

    private ?int $min = null;

    private ?int $max = null;

    /** @var list<string> for type=in */
    private array $allowed = [];

    /** @var list<string> for type=file: content-sniffed mime types accepted */
    private array $mimes = [];

    private ?int $maxBytes = null;

    private string $dateFormat = 'Y-m-d';

    /** @var list<callable(mixed): ?Violation> */
    private array $extra = [];

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function int(): self
    {
        return new self('int');
    }

    public static function email(): self
    {
        return new self('email');
    }

    public static function bool(): self
    {
        return new self('bool');
    }

    public static function date(string $format = 'Y-m-d'): self
    {
        $rule = new self('date');
        $rule->dateFormat = $format;

        return $rule;
    }

    /**
     * @param list<string> $allowed
     */
    public static function in(array $allowed): self
    {
        if ($allowed === []) {
            throw new \InvalidArgumentException('Rule::in() needs at least one allowed value — an empty set rejects everything, which is a bug, not a rule.');
        }

        $rule = new self('in');
        $rule->allowed = $allowed;

        return $rule;
    }

    /**
     * An uploaded file whose REAL content type — sniffed from the bytes via
     * finfo, never read from the client's Content-Type header — is one of
     * $mimes. The client header and filename are display data (see
     * UploadedFile's docblock); a .png that is actually PHP source fails
     * here regardless of what the request claimed.
     *
     * Like in(): an empty allow-list rejects everything, which is a bug in
     * the rules, not a rule.
     *
     * @param non-empty-list<string> $mimes e.g. ['image/png', 'image/jpeg']
     */
    public static function file(array $mimes): self
    {
        if ($mimes === []) {
            throw new \InvalidArgumentException('Rule::file() needs at least one allowed mime type — an empty set rejects everything, which is a bug, not a rule.');
        }

        $rule = new self('file');
        $rule->mimes = array_values($mimes);

        return $rule;
    }

    /** Files: maximum size in bytes, measured from the tmp file on disk. */
    public function maxBytes(int $maxBytes): self
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be positive.');
        }

        $clone = clone $this;
        $clone->maxBytes = $maxBytes;

        return $clone;
    }

    /** Absent or null is acceptable; when present, the rule still applies. */
    public function nullable(): self
    {
        $clone = clone $this;
        $clone->nullable = true;

        return $clone;
    }

    /** Strings: minimum length. Ints: minimum value. */
    public function min(int $min): self
    {
        $clone = clone $this;
        $clone->min = $min;

        return $clone;
    }

    /** Strings: maximum length. Ints: maximum value. */
    public function max(int $max): self
    {
        $clone = clone $this;
        $clone->max = $max;

        return $clone;
    }

    /**
     * A typed escape hatch for what the vocabulary cannot say. The callable
     * receives the already-canonicalized value and returns a Violation or
     * null — it lives next to the rule that needs it, greppable, not in a
     * registry.
     *
     * @param callable(mixed): ?Violation $check
     */
    public function also(callable $check): self
    {
        $clone = clone $this;
        $clone->extra[] = $check;

        return $clone;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * @return array{0: mixed, 1: ?Violation} [canonical value, violation]
     */
    public function check(mixed $value): array
    {
        $result = match ($this->type) {
            'string' => $this->checkString($value),
            'int' => $this->checkInt($value),
            'email' => $this->checkEmail($value),
            'bool' => $this->checkBool($value),
            'date' => $this->checkDate($value),
            'in' => $this->checkIn($value),
            'file' => $this->checkFile($value),
        };

        [$canonical, $violation] = $result;

        if ($violation === null) {
            foreach ($this->extra as $check) {
                $violation = $check($canonical);
                if ($violation !== null) {
                    break;
                }
            }
        }

        return [$canonical, $violation];
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkString(mixed $value): array
    {
        if (!is_string($value)) {
            return [null, new Violation('validation.string.type', [])];
        }

        $length = mb_strlen($value);

        if ($this->min !== null && $length < $this->min) {
            return [null, new Violation('validation.string.min', ['min' => $this->min])];
        }

        if ($this->max !== null && $length > $this->max) {
            return [null, new Violation('validation.string.max', ['max' => $this->max])];
        }

        return [$value, null];
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkInt(mixed $value): array
    {
        // Form input arrives as strings; "42" is an int in intent. "42.5",
        // "", "abc" are not — filter_var draws exactly that line.
        $int = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false || (!is_int($value) && !is_string($value))) {
            return [null, new Violation('validation.int.type', [])];
        }

        if ($this->min !== null && $int < $this->min) {
            return [null, new Violation('validation.int.min', ['min' => $this->min])];
        }

        if ($this->max !== null && $int > $this->max) {
            return [null, new Violation('validation.int.max', ['max' => $this->max])];
        }

        return [$int, null];
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkEmail(mixed $value): array
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return [null, new Violation('validation.email', [])];
        }

        if ($this->max !== null && mb_strlen($value) > $this->max) {
            return [null, new Violation('validation.string.max', ['max' => $this->max])];
        }

        return [$value, null];
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkBool(mixed $value): array
    {
        // Checkbox-shaped input: browsers send "1"/"on" for checked and
        // omit the field for unchecked; APIs send true/false. All of these
        // are bools in intent; anything else is a type error, not falsy.
        // A match, not a lookup array: PHP array keys coerce (true, 1 and
        // '1' collide into one key), which PHPStan rightly flagged.
        return match (true) {
            is_bool($value) => [$value, null],
            $value === 1, $value === '1', $value === 'true', $value === 'on' => [true, null],
            $value === 0, $value === '0', $value === 'false' => [false, null],
            default => [null, new Violation('validation.bool', [])],
        };
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkDate(mixed $value): array
    {
        if (!is_string($value)) {
            return [null, new Violation('validation.date', ['format' => $this->dateFormat])];
        }

        $date = \DateTimeImmutable::createFromFormat('!' . $this->dateFormat, $value);

        // createFromFormat "helpfully" accepts 2026-02-31 by rolling it to
        // March 3rd. A birthdate that silently becomes a different day is
        // corrupted data, so round-trip equality is part of validity.
        if ($date === false || $date->format($this->dateFormat) !== $value) {
            return [null, new Violation('validation.date', ['format' => $this->dateFormat])];
        }

        return [$date, null];
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkFile(mixed $value): array
    {
        if (!$value instanceof \LombokClarion\Http\UploadedFile) {
            return [null, new Violation('validation.file.type', [])];
        }

        // isValid() covers both the PHP error code AND the tmp file's
        // existence/provenance — a crafted UploadedFile pointing at a path
        // that is not really there fails here, before finfo touches it.
        if (!$value->isValid()) {
            return [null, new Violation('validation.file.upload', [])];
        }

        $size = filesize($value->tmpPath);
        if ($this->maxBytes !== null && ($size === false || $size > $this->maxBytes)) {
            return [null, new Violation('validation.file.max', ['max' => $this->maxBytes])];
        }

        // The line this rule exists to hold: the mime comes from the BYTES.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $sniffed = $finfo->file($value->tmpPath);

        if ($sniffed === false || !in_array($sniffed, $this->mimes, true)) {
            return [null, new Violation('validation.file.mime', ['allowed' => implode(', ', $this->mimes)])];
        }

        return [$value, null];
    }

    /** @return array{0: mixed, 1: ?Violation} */
    private function checkIn(mixed $value): array
    {
        if (!is_string($value) || !in_array($value, $this->allowed, true)) {
            return [null, new Violation('validation.in', ['allowed' => implode(', ', $this->allowed)])];
        }

        return [$value, null];
    }
}
