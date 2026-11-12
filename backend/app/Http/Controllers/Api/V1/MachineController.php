<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\ProductModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Their ERP sends machines (`api-key:machines:write`) - typically when one
 * is built, or shipped to a dealer. Matched by serial number: a new serial
 * is added, a known one updated. Up to 500 at a time; each machine is its
 * own transaction and gets its own result, so one bad row doesn't hold up
 * the rest.
 *
 * A machine's model is found by the ERP's item number (`model`, matched to a
 * model's ERP number) or its name (`model_name`). Its dealer by the ERP's
 * customer number (`dealer_code`); null sends it back to the factory, and
 * leaving dealer_code out leaves the dealer as it is. Every change lands in
 * the machine's history as "From your ERP", like a change made on its page.
 */
class MachineController extends Controller
{
    private const NOTE = 'From your ERP';

    public function upsert(Request $request): JsonResponse
    {
        $request->validate(['machines' => ['required', 'array', 'min:1', 'max:500']]);

        $results = [];
        foreach ($request->input('machines') as $i => $row) {
            $results[] = ['index' => $i, 'serial_no' => is_array($row) ? ($row['serial_no'] ?? null) : null] + $this->one(is_array($row) ? $row : []);
        }

        return response()->json([
            'results' => $results,
            'summary' => collect($results)->countBy('result')->all(),
        ]);
    }

    /** @return array{result: string, id?: int, errors?: array<string, string>} */
    private function one(array $row): array
    {
        $v = Validator::make($row, [
            'serial_no' => ['required', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:100'],
            'model_name' => ['nullable', 'string', 'max:120'],
            'dealer_code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(Machine::STATUSES)],
            'production_no' => ['nullable', 'string', 'max:80'],
            'built_at' => ['nullable', 'date'],
            'shipped_at' => ['nullable', 'date'],
        ]);
        if ($v->fails()) {
            return ['result' => 'error', 'errors' => array_map(fn (array $messages) => $messages[0], $v->errors()->toArray())];
        }
        $data = $v->validated();
        $serial = trim($data['serial_no']);

        // The dealer, if one was named. Only an active dealer - like choosing one on the machine's page.
        $link = null;
        $movesDealer = array_key_exists('dealer_code', $row);
        if ($movesDealer && ($data['dealer_code'] ?? null) !== null) {
            $link = DealerLink::where('dealer_code', $data['dealer_code'])->where('status', DealerLink::ACTIVE)->first();
            if (! $link) {
                return ['result' => 'error', 'errors' => ['dealer_code' => "No active dealer has customer number \"{$data['dealer_code']}\". Add it under Integrations → Match your ERP's numbers."]];
            }
        }

        $machine = Machine::where('serial_no', $serial)->first();

        if (! $machine) {
            $model = $this->model($data);
            if (is_string($model)) {
                return ['result' => 'error', 'errors' => ['model' => $model]];
            }
            $machine = Machine::add([
                'product_model_id' => $model->id,
                'serial_no' => $serial,
                'production_no' => $data['production_no'] ?? null,
                'built_at' => $data['built_at'] ?? null,
                'shipped_at' => $data['shipped_at'] ?? null,
                'status' => $data['status'] ?? 'inventory',
            ], $link, null, self::NOTE);

            return ['result' => 'created', 'id' => $machine->id];
        }

        $changed = DB::transaction(function () use ($machine, $data, $row, $link, $movesDealer) {
            $before = [$machine->dealer_link_id, $machine->status];
            $machine->fill(array_intersect_key($data, array_flip(array_intersect(['production_no', 'built_at', 'shipped_at'], array_keys($row)))));
            $edited = $machine->isDirty();
            $machine->save();
            if ($movesDealer && $link?->id !== $machine->dealer_link_id) {
                $machine->transferTo($link, null, self::NOTE);
            }
            if (isset($data['status'])) {
                $machine->changeStatus($data['status'], null, self::NOTE);
            }

            return $edited || $before !== [$machine->dealer_link_id, $machine->status];
        });

        return ['result' => $changed ? 'updated' : 'unchanged', 'id' => $machine->id];
    }

    /** The model by ERP item number, else by name; or why it couldn't be found. */
    private function model(array $data): ProductModel|string
    {
        if (! empty($data['model'])) {
            return ProductModel::where('erp_ref', $data['model'])->first()
                ?? "No model has item number \"{$data['model']}\". Add it under Integrations → Match your ERP's numbers.";
        }
        if (! empty($data['model_name'])) {
            return ProductModel::where('name', $data['model_name'])->first() ?? "No model is called \"{$data['model_name']}\".";
        }

        return 'A new machine needs its model: "model" (your item number) or "model_name".';
    }
}
