<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\ProductSku;
use App\Services\Pricing\PriceResolver;
use Illuminate\Http\JsonResponse;

class CustomerPriceController extends Controller
{
    /** GET /api/customers/{customer}/price/{sku} */
    public function show(B2bCustomer $customer, string $sku, PriceResolver $prices): JsonResponse
    {
        $productSku = ProductSku::where('sku', $sku)->first();

        if ($productSku === null) {
            return response()->json(['message' => "Unknown SKU [{$sku}]."], 404);
        }

        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'customer_active' => $customer->is_active,
            'sku_active' => $productSku->is_active,
        ] + $prices->quote($customer, $productSku)->toArray()]);
    }
}
