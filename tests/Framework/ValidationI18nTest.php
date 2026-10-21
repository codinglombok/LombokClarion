<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\I18n\Translator;
use LombokClarion\Validation\Rule;
use LombokClarion\Validation\ValidationException;
use LombokClarion\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidationI18nTest extends TestCase
{
    private function validator(): Validator
    {
        return new Validator();
    }

    public function testValidateCanonicalizesAndWhitelists(): void
    {
        $data = $this->validator()->validate(
            ['name' => '  Desk  ', 'price' => '1200', 'evil' => 'DROP TABLE'],
            ['name' => Rule::string()->min(2)->max(10), 'price' => Rule::int()->min(1)->max(9999)]
        );

        $this->assertSame('  Desk  ', $data['name']); // strings pass through
        $this->assertSame(1200, $data['price']);       // ints canonicalized
        $this->assertArrayNotHasKey('evil', $data);    // mass-assignment proof
    }

    public function testNullableFieldMissingBecomesNull(): void
    {
        $data = $this->validator()->validate(
            ['a' => 'x'],
            ['a' => Rule::string(), 'b' => Rule::string()->nullable()]
        );

        $this->assertNull($data['b']);
    }

    public function testMissingRequiredFieldThrowsWithViolations(): void
    {
        try {
            $this->validator()->validate([], ['name' => Rule::string()->min(2)]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertNotEmpty($errors['name']);
        }
    }

    public function testInRuleRejectsUnknownValues(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator()->validate(['plan' => 'gold'], ['plan' => Rule::in(['free', 'pro'])]);
    }

    public function testInRuleAcceptsAllowedValues(): void
    {
        $data = $this->validator()->validate(['plan' => 'pro'], ['plan' => Rule::in(['free', 'pro'])]);

        $this->assertSame('pro', $data['plan']);
    }

    public function testBoolRuleCanonicalizes(): void
    {
        $data = $this->validator()->validate(
            ['a' => '1', 'b' => 'on'],
            ['a' => Rule::bool(), 'b' => Rule::bool()]
        );

        $this->assertTrue($data['a']);
        $this->assertTrue($data['b']);
    }

    public function testAlsoRunsCustomCheck(): void
    {
        $rule = Rule::string()->also(static function (mixed $v): ?object {
            return $v === 'banned' ? new \LombokClarion\Validation\Violation('custom.banned', []) : null;
        });

        $this->expectException(ValidationException::class);
        $this->validator()->validate(['w' => 'banned'], ['w' => $rule]);
    }

    public function testDateRuleReturnsImmutable(): void
    {
        $data = $this->validator()->validate(['d' => '2025-01-31'], ['d' => Rule::date()]);

        $this->assertInstanceOf(\DateTimeImmutable::class, $data['d']);
    }

    // ── i18n ──────────────────────────────────────────────────────────────

    public function testTranslatorInterpolatesParams(): void
    {
        $t = new Translator('en');
        $t->addCatalog('en', ['greet' => 'Hello, :name!']);

        $this->assertSame('Hello, Lombok!', $t->trans('greet', ['name' => 'Lombok']));
    }

    public function testTranslatorFallsBackToFallbackLocale(): void
    {
        $t = new Translator('de');
        $t->addCatalog('en', ['welcome' => 'Welcome']);

        $this->assertSame('Welcome', $t->trans('welcome'));
    }

    public function testMissingKeyIsReturnedAndRecorded(): void
    {
        $t = new Translator('en');

        $this->assertSame('missing.key', $t->trans('missing.key'));
        $this->assertContains('en.missing.key', $t->missingKeys());
    }

    public function testChoiceHandlesPluralSegments(): void
    {
        $t = new Translator('en');
        $t->addCatalog('en', ['items' => ':count item|:count items']);

        $this->assertSame('1 item', $t->choice('items', 1));
        $this->assertSame('5 items', $t->choice('items', 5));
    }

    public function testIndonesianCatalogIsLoadableAndComplete(): void
    {
        $messages = require __DIR__ . '/../../resources/lang/id.php';
        $this->assertIsArray($messages);
        $this->assertNotEmpty($messages);

        $t = new Translator('id');
        $t->addCatalog('id', $messages);
        foreach ($messages as $key => $text) {
            $this->assertSame($text, $t->trans($key));
        }
        $this->assertSame([], $t->missingKeys());
    }

    public function testAllShippedLocalesAreValidPhpArrays(): void
    {
        $locales = glob(__DIR__ . '/../../resources/lang/*.php') ?: [];
        $this->assertGreaterThanOrEqual(24, count($locales));

        foreach ($locales as $file) {
            $messages = require $file;
            $this->assertIsArray($messages, basename($file) . ' must return an array');
            $this->assertNotEmpty($messages, basename($file) . ' must not be empty');
        }
    }
}
