<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AssignTierRequest;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Services\Pricing\PriceResolver;
use Illuminate\Http\JsonResponse;

class CustomerTierController extends Controller
{
    /**
     * POST /api/customers/{customer}/tier — add the customer to a tier, or update the
     * validity window if they are already in it. Other tier memberships are kept.
     */
    public function store(AssignTierRequest $request, B2bCustomer $customer, PriceResolver $prices): JsonResponse
    {
        $tier = B2bPriceTier::where('slug', $request->validated('tier'))->firstOrFail();
        $alreadyAssigned = $customer->priceTiers()->whereKey($tier->id)->exists();

        $customer->priceTiers()->syncWithoutDetaching([
            $tier->id => [
                'valid_from' => $request->validated('valid_from'),
                'valid_until' => $request->validated('valid_until'),
            ],
        ]);

        $effective = $prices->effectiveTier($customer, now());

        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'company_name' => $customer->company_name,
            'tiers' => $customer->priceTiers()->orderByDesc('priority')->get()->map(fn (B2bPriceTier $t) => [
                'slug' => $t->slug,
                'name' => $t->name,
                'discount_percent' => $t->discount_percent,
                'valid_from' => $t->pivot->valid_from,
                'valid_until' => $t->pivot->valid_until,
            ]),
            'effective_tier' => $effective?->slug,
        ]], $alreadyAssigned ? 200 : 201);
    }
}
