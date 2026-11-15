<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a B2B customer, including its WooCommerce and Fortnox links. */
class CustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'woo_customer_id' => $this->filled('woo_customer_id') ? trim((string) $this->input('woo_customer_id')) : null,
            'fortnox_customer_number' => $this->filled('fortnox_customer_number') ? trim((string) $this->input('fortnox_customer_number')) : null,
            'country_code' => strtoupper((string) ($this->input('country_code') ?: 'SE')),
            'currency' => strtoupper((string) ($this->input('currency') ?: 'SEK')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $customer = $this->route('customer');

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'org_number' => ['nullable', 'string', 'max:20'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'woo_customer_id' => ['nullable', 'integer', 'min:1', Rule::unique('b2b_customers', 'woo_customer_id')->ignore($customer)],
            'fortnox_customer_number' => ['nullable', 'string', 'max:50', Rule::unique('b2b_customers', 'fortnox_customer_number')->ignore($customer)],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'woo_customer_id.unique' => 'Another customer is already linked to WooCommerce customer :input.',
            'fortnox_customer_number.unique' => 'Another customer is already linked to Fortnox customer :input.',
        ];
    }
}
