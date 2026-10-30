<?php

namespace App\Models;

use Database\Factories\B2bPriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'description', 'discount_percent', 'priority', 'is_active'])]
class B2bPriceTier extends Model
{
    /** @use HasFactory<B2bPriceTierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<B2bCustomer, $this> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(B2bCustomer::class, 'b2b_customer_tier_assignments')
            ->withPivot(['valid_from', 'valid_until'])
            ->withTimestamps();
    }
}
