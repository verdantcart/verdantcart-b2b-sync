<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTierRequest;
use App\Models\B2bPriceTier;
use Illuminate\Http\JsonResponse;

class TierController extends Controller
{
    /** GET /api/tiers */
    public function index(): JsonResponse
    {
        $tiers = B2bPriceTier::withCount('customers')->orderByDesc('priority')->get();

        return response()->json(['data' => $tiers->map(fn (B2bPriceTier $t) => self::present($t))]);
    }

    /** POST /api/tiers */
    public function store(StoreTierRequest $request): JsonResponse
    {
        $tier = B2bPriceTier::create($request->validated() + ['priority' => 0, 'is_active' => true]);

        return response()->json(['data' => self::present($tier->loadCount('customers'))], 201);
    }

    public static function present(B2bPriceTier $tier): array
    {
        return [
            'id' => $tier->id,
            'slug' => $tier->slug,
            'name' => $tier->name,
            'description' => $tier->description,
            'discount_percent' => $tier->discount_percent,
            'priority' => $tier->priority,
            'is_active' => $tier->is_active,
            'customers_count' => $tier->customers_count,
        ];
    }
}
