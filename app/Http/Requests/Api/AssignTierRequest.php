<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AssignTierRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tier' => ['required', 'string', 'exists:b2b_price_tiers,slug'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
        ];
    }

    public function messages(): array
    {
        return ['tier.exists' => 'No price tier with slug ":input".'];
    }
}
