# lombokclarion/validation

**Fluent rule builder, 24-language messages, FormRequest for pre-controller validation.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/validation
```

## Namespace

```php
LombokClarion\Validation
```

## What's Inside

| Class | Role |
|-------|------|
| `Rule` | Fluent rule builder: `string()`, `integer()`, `email()`, `min()`, `max()`, `in()`, `file()`, etc. |
| `Validator` | Validates `array<string, mixed>` against `array<string, Rule>` |
| `Violation` | Single validation error (field, rule name, message) |
| `ValidationException` | Carries `Violation[]` array |
| `FormRequest` | Base class for controller-level auto-validation |
| `Lang` | Loads validation message translations |

**Supported languages:** ar, bn, de, en, es, fa, fr, hi, id, it, ja, ko, ms, nl, pl, pt, ru, sw, th, tr, uk, ur, vi, zh

## Usage

```php
use LombokClarion\Validation\Rule;
use LombokClarion\Validation\Validator;

$rules = [
    'name'  => Rule::required()->string()->min(2)->max(100),
    'email' => Rule::required()->email(),
    'age'   => Rule::integer()->min(18),
    'role'  => Rule::required()->in(['admin', 'editor', 'viewer']),
];

$validator = new Validator($rules);
$violations = $validator->validate($data);

if (!empty($violations)) {
    // $violations[0]->field, ->rule, ->message
}
```

### FormRequest

```php
use LombokClarion\Validation\FormRequest;
use LombokClarion\Validation\Rule;

class CreateWidgetRequest extends FormRequest {
    protected function rules(): array {
        return [
            'name' => Rule::required()->string()->min(1)->max(255),
        ];
    }
}
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
