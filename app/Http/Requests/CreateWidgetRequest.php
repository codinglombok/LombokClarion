<?php

declare(strict_types=1);

namespace App\Http\Requests;

use LombokClarion\Security\FormRequest;

final class CreateWidgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'price_cents' => ['required', 'int'],
        ];
    }
}
