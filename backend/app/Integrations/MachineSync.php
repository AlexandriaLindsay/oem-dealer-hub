<?php

namespace App\Integrations;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\ProductLine;
use App\Models\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One machine from a manufacturer's ERP, matched by serial number: a new
 * serial is added, a known one updated. Shared by the integration API
 * (POST /api/v1/machines, the ERP sends) and the ERP connection (we fetch),
 * so both follow exactly the same rules.
 *
 * A machine's model is found by the ERP's item number (`model`, matched to a
 * model's ERP number) or its name (`model_name`). Its dealer by the ERP's
 * customer number (`dealer_code`); null sends it back to the factory, and
 * leaving dealer_code out leaves the dealer as it is. Every change lands in
 * the machine's history as "From your ERP", like a change made on its page.
 */
class MachineSync
{
    public const NOTE = 'From your ERP';

    /** The product line models added from the ERP go into, until staff move them. */
    public const NEW_MODELS_LINE = 'From your ERP';

    /**
     * @param  bool  $addModels  a model we don't have is added from the item number
     *                           (the ERP connection's "Add models we don't have yet")
     * @return array{result: string, id?: int, errors?: array<string, string>, new_model?: string}
     */
    public static function one(array $row, bool $addModels = false): array
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
            $model = self::model($data);
            $newModel = null;
            if (is_string($model) && $addModels && self::newModelName($data) !== null) {
                [$model, $newModel] = self::addModel($data);
            }
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

            return ['result' => 'created', 'id' => $machine->id] + ($newModel ? ['new_model' => $newModel] : []);
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

    /**
     * The name a model added from this row would get: the ERP's model name if
     * it sent one, else its item number. Null when there's neither.
     */
    public static function newModelName(array $data): ?string
    {
        $name = trim((string) (($data['model_name'] ?? '') ?: ($data['model'] ?? '')));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    /**
     * Adds the model a machine from the ERP needs, in the "From your ERP"
     * product line, with the item number as its ERP number so the next
     * machine finds it. If a model already has that name (but another ERP
     * number), that one is used instead of making a twin.
     *
     * @return array{0: ProductModel, 1: string|null} the model, and its name if it was added
     */
    private static function addModel(array $data): array
    {
        $name = self::newModelName($data);
        if ($existing = ProductModel::where('name', $name)->first()) {
            return [$existing, null];
        }

        $line = ProductLine::firstOrCreate(['name' => self::NEW_MODELS_LINE]);
        $model = $line->models()->create([
            'name' => $name,
            'erp_ref' => ($data['model'] ?? '') !== '' ? mb_substr(trim($data['model']), 0, 100) : null,
        ]);

        return [$model, $name];
    }

    /** The model by ERP item number, else by name; or why it couldn't be found. */
    private static function model(array $data): ProductModel|string
    {
        if (! empty($data['model'])) {
            // With a name too, a model by that name will do (the item number may be one build of it).
            return ProductModel::forItemCode($data['model'])
                ?? (! empty($data['model_name']) ? ProductModel::where('name', $data['model_name'])->first() : null)
                ?? "No model has item number \"{$data['model']}\". Add it under Integrations → Match your ERP's numbers.";
        }
        if (! empty($data['model_name'])) {
            return ProductModel::where('name', $data['model_name'])->first() ?? "No model is called \"{$data['model_name']}\".";
        }

        return 'A new machine needs its model: "model" (your item number) or "model_name".';
    }
}
