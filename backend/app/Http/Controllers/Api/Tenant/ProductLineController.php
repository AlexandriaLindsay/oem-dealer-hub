<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ProductLine;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductLineController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ProductLine::orderBy('name')->get(['id', 'name']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Unique within this manufacturer only - another can have the same name.
            'name' => ['required', 'string', 'max:120', Rule::unique('product_lines')->where('manufacturer_id', Tenant::current()->id())],
        ]);

        $line = ProductLine::create($data);

        return response()->json(['id' => $line->id, 'name' => $line->name], 201);
    }
}
