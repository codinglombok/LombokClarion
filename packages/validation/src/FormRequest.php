<?php

declare(strict_types=1);

namespace LombokClarion\Validation;

use LombokClarion\Http\Request;
use LombokClarion\I18n\Translator;

/**
 * Declares WHAT a request must contain and WHAT TYPE it becomes — nothing
 * else. No auto-resolution by type-hint, no auto-redirect-back, no
 * authorize() mixed into the same class (authorization is middleware and
 * Gate, §Stage 9; a validator that also gatekeeps is two responsibilities
 * wearing one name).
 *
 *   /** @extends FormRequest<RegisterMemberInput> *​/
 *   final class RegisterMemberRequest extends FormRequest
 *   {
 *       public function rules(): array
 *       {
 *           return [
 *               'name' => Rule::string()->min(3)->max(255),
 *               'email' => Rule::email()->max(255),
 *               'birthdate' => Rule::date('Y-m-d'),
 *           ];
 *       }
 *
 *       protected function map(array $validated): RegisterMemberInput
 *       {
 *           return new RegisterMemberInput(
 *               name: $validated['name'],
 *               email: $validated['email'],
 *               birthdate: $validated['birthdate'],
 *           );
 *       }
 *   }
 *
 *   // controller:
 *   $input = $this->registerRequest->validate($request); // RegisterMemberInput
 *
 * map() is an EXPLICIT hand-written constructor call, not reflection
 * mapping array keys to constructor parameters. Three lines of "boilerplate"
 * buy: zero reflection (the compiled-boot promise holds), a rename of a
 * DTO property that fails at PHPStan instead of at runtime, and a place
 * to compose ('Y-m-d' string + parsed date into one value object) that a
 * mapper would forbid. The DTO itself is a plain readonly class the
 * domain can own — it needs no framework import, so it can live in
 * app/Domain and cross the §3 boundary carrying data, not dependencies.
 *
 * @template TDto of object
 */
abstract class FormRequest
{
    public function __construct(protected readonly ?Translator $translator = null)
    {
    }

    /** @return array<string, Rule> */
    abstract public function rules(): array;

    /**
     * @param array<string, mixed> $validated canonicalized, whitelisted
     * @return TDto
     */
    abstract protected function map(array $validated): object;

    /**
     * Which part of the request is validated. Body by default — rules
     * describe payloads. Override to merge query for GET-with-filters
     * endpoints; the override is one visible method, not a guess.
     *
     * @return array<string, mixed>
     */
    protected function source(Request $request): array
    {
        return $request->body;
    }

    /**
     * @return TDto
     * @throws ValidationException rendered by the Kernel as 422
     */
    public function validate(Request $request): object
    {
        // Multipart requests split one payload across $request->body and
        // $request->files (PHP's shape, not ours) — a Rule::file() field
        // lives in the latter. Rejoining them here is not a merge policy,
        // it is undoing that split: PHP never puts the same field in both.
        // One normalization applies: an empty <input type="file"> arrives
        // as UPLOAD_ERR_NO_FILE, which MEANS absent — it is dropped so
        // ->nullable() sees absence, not a broken upload.
        $files = array_filter(
            $request->files,
            static fn ($f) => !($f instanceof \LombokClarion\Http\UploadedFile && $f->error === UPLOAD_ERR_NO_FILE),
        );

        try {
            $validated = (new Validator())->validate([...$this->source($request), ...$files], $this->rules());
        } catch (ValidationException $e) {
            if ($this->translator !== null) {
                $e->setTranslator($this->translator);
            }
            throw $e;
        }

        return $this->map($validated);
    }
}
