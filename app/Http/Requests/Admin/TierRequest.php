<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Create or edit a price tier from the admin pages. */
class TierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
            'discount_percent' => str_replace(',', '.', (string) $this->input('discount_percent')), // "12,5" from a Swedish keyboard
            'is_active' => $this->boolean('is_active'),
            'priority' => $this->input('priority') ?? 0,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('b2b_price_tiers', 'slug')->ignore($this->route('tier'))],
            'description' => ['nullable', 'string', 'max:1000'],
            'discount_percent' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'priority' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['slug.unique' => 'Another tier already uses the short name ":input".'];
    }
}
