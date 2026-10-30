<?php

namespace App\Models;

use Database\Factories\B2bCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'woo_customer_id', 'fortnox_customer_number', 'company_name', 'org_number',
    'vat_number', 'email', 'country_code', 'currency', 'is_active',
])]
class B2bCustomer extends Model
{
    /** @use HasFactory<B2bCustomerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<B2bPriceTier, $this> */
    public function priceTiers(): BelongsToMany
    {
        return $this->belongsToMany(B2bPriceTier::class, 'b2b_customer_tier_assignments')
            ->withPivot(['valid_from', 'valid_until'])
            ->withTimestamps();
    }

    /** @return HasMany<OrderLog, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(OrderLog::class);
    }
}
