<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Models within product lines, and the options each can be ordered with. */
class ProductModelController extends Controller
{
    public function index(): JsonResponse
    {
        $models = ProductModel::with(['productLine', 'options'])
            ->withCount('machines')
            ->orderBy('name')
            ->get();

        return response()->json($models->map(fn (ProductModel $m) => self::row($m)));
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = Tenant::current()->id();

        $data = $request->validate([
            // Exists *at this manufacturer* - an id from another one is just "not found".
            'product_line_id' => ['required', 'integer', Rule::exists('product_lines', 'id')->where('manufacturer_id', $tenantId)],
            'name' => ['required', 'string', 'max:120', Rule::unique('product_models')->where('manufacturer_id', $tenantId)],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        $model = ProductModel::create($data);

        return response()->json(self::row($model->load(['productLine', 'options'])->loadCount('machines')), 201);
    }

    public function storeOption(Request $request, int $model): JsonResponse
    {
        $productModel = ProductModel::findOrFail($model);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('product_options')->where('product_model_id', $productModel->id)],
        ]);

        $option = ProductOption::create(['product_model_id' => $productModel->id, 'name' => $data['name']]);

        return response()->json(['id' => $option->id, 'name' => $option->name], 201);
    }

    private static function row(ProductModel $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'category' => $m->category,
            'product_line' => ['id' => $m->productLine->id, 'name' => $m->productLine->name],
            'options' => $m->options->map(fn (ProductOption $o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'machines' => $m->machines_count,
        ];
    }
}
