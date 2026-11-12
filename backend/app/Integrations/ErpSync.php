<?php

namespace App\Integrations;

use App\Jobs\PushSalesOrderToErp;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\Machine;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\SalesOrder;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ERP connection: OEM Dealer Hub calling the manufacturer's ERP API
 * itself, for an ERP that can't (or won't) call ours. Works with any REST API
 * that speaks JSON. For each sync they tell us the path, where the list is in
 * the reply, and where each of our fields is in an item (dot paths). It
 * always runs inside one manufacturer (Tenant::run).
 *
 * - orders_push: each new sales order is POSTed to their ERP (the same shape as
 *   the API and webhooks), and the ERP's order number is read from the reply.
 * - order_status: fetches their orders and moves ours to match.
 * - prices: fetches their prices onto one price list (all or nothing).
 * - machines: fetches machines by serial number (each row on its own).
 *
 * The pulls go through exactly the same rules as the integration API
 * (SalesOrder::moveTo, PriceSync, MachineSync), and webhooks they fire say
 * "source": "erp". A pull asks for changes since its last run ({since} in the
 * path) and remembers where it got to.
 */
class ErpSync
{
    /** Never from the start of time for a sync that has run: the overlap covers clocks that disagree a little. */
    private const OVERLAP_SECONDS = 120;

    public function __construct(private UrlGuard $guard) {}

    public static function connection(): ?ErpConnection
    {
        if (! Plan::includes(Tenant::current()->manufacturer(), 'erp_integration')) {
            return null;
        }

        return ErpConnection::first();
    }

    /**
     * A new sales order: queue sending it to their ERP once the transaction
     * commits, if they've turned that on. Called by SalesOrder::fromQuote.
     */
    public static function queuePush(SalesOrder $order): void
    {
        $connection = self::connection();
        if (! $connection?->syncOn('orders_push')) {
            return;
        }

        $run = new ErpSyncRun;
        $run->forceFill(['sync' => 'orders_push', 'sales_order_id' => $order->id, 'status' => ErpSyncRun::RUNNING])->save();
        PushSalesOrderToErp::dispatch(Tenant::current()->id(), $run->id)->afterCommit();
    }

    // ---- Sending an order ----

    /** @throws ErpException */
    public function push(ErpConnection $connection, ErpSyncRun $run): void
    {
        $order = $run->salesOrder;
        if (! $order) {
            throw new ErpException('The sales order is gone.', 0);
        }

        $settings = $connection->sync('orders_push');
        $client = new ErpClient($connection, $this->guard);
        $response = $client->post($client->url($settings['path'] ?? ''), SalesOrderPayload::make($order), [
            // The same on every try, so an ERP that understands it never makes the order twice.
            'Idempotency-Key' => 'oemdh-sales-order-'.$order->id,
        ]);

        $ref = ErpClient::pick($response->json(), $settings['ref_field'] ?? null);
        $ref = is_scalar($ref) ? trim((string) $ref) : '';
        if ($ref !== '' && $order->erp_ref !== $ref) {
            Webhooks::asErp(fn () => $order->updateDetails(['erp_ref' => mb_substr($ref, 0, 100)]));
        }

        $run->forceFill([
            'status' => ErpSyncRun::OK,
            'counts' => ['sent' => 1],
            'message' => 'Sent '.$order->label().($ref !== '' ? ". Your ERP's number for it is {$ref}." : '.'),
            'finished_at' => now(),
        ])->save();
    }

    // ---- Fetching ----

    /** Runs one pull to the end, recording it. Never throws: a problem is the run's message. */
    public function run(ErpConnection $connection, string $sync, bool $full = false): ErpSyncRun
    {
        $run = new ErpSyncRun;
        $run->forceFill(['sync' => $sync, 'status' => ErpSyncRun::RUNNING, 'attempts' => 1])->save();
        $startedAt = now();

        try {
            $items = $this->fetch($connection, $sync, $full ? null : $connection->since($sync));
            [$counts, $problems, $message] = Webhooks::asErp(fn () => match ($sync) {
                'order_status' => $this->orderStatus($connection, $items),
                'prices' => $this->prices($connection, $items),
                'machines' => $this->machines($connection, $items),
            });
        } catch (ErpException $e) {
            $run->forceFill(['status' => ErpSyncRun::FAILED, 'message' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()])->save();

            return $run;
        }

        $failed = $counts['failed'] ?? false;
        unset($counts['failed']);
        $run->forceFill([
            'status' => $failed ? ErpSyncRun::FAILED : ($problems ? ErpSyncRun::PARTIAL : ErpSyncRun::OK),
            'counts' => $counts,
            'problems' => array_slice($problems, 0, ErpSyncRun::MAX_PROBLEMS),
            'message' => mb_substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();

        // Where it got to, so next time asks only for what changed. Not after a failure: that's tried again.
        if (! $failed) {
            $connection->setSince($sync, $startedAt->copy()->subSeconds(self::OVERLAP_SECONDS)->utc()->format('Y-m-d\TH:i:s\Z'));
        }

        return $run;
    }

    /**
     * "Try it": the first page only, with what each of the first items would
     * do - so they can check their settings before anything changes. Writes nothing.
     *
     * @return array{found: int, sample: string|null, rows: list<array{fields: array<string, mixed>, outcome: string}>}
     *
     * @throws ErpException
     */
    public function preview(ErpConnection $connection, string $sync): array
    {
        $settings = $connection->sync($sync);
        $client = new ErpClient($connection, $this->guard);
        $items = ErpClient::listIn($client->get($this->pathFor($client, $settings, null)), $settings['list'] ?? null);

        $sample = $items === [] ? null : json_encode($items[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($sample !== null && mb_strlen($sample) > 4000) {
            $sample = mb_substr($sample, 0, 4000)."\n…";
        }

        $rows = [];
        foreach (array_slice($items, 0, 10) as $item) {
            $fields = self::fields($item, $settings, $sync);
            $rows[] = ['fields' => $fields, 'outcome' => $this->describe($connection, $sync, $fields)];
        }

        return ['found' => count($items), 'sample' => $sample, 'rows' => $rows];
    }

    /** @throws ErpException */
    private function fetch(ErpConnection $connection, string $sync, ?string $since): array
    {
        $settings = $connection->sync($sync);
        $client = new ErpClient($connection, $this->guard);

        return $client->all($this->pathFor($client, $settings, $since), $settings['list'] ?? null, $settings['next'] ?? null);
    }

    private function pathFor(ErpClient $client, array $settings, ?string $since): string
    {
        return $client->url($settings['path'] ?? '', ['since' => $since ?? '1970-01-01T00:00:00Z']);
    }

    /**
     * Our fields from one of their items, by the paths they gave. A field
     * that's mapped but missing from the item is left out (not null), so a
     * missing dealer doesn't send a machine back to the factory.
     *
     * @return array<string, mixed>
     */
    public static function fields(mixed $item, array $settings, string $sync): array
    {
        $out = [];
        foreach (array_keys(ErpConnection::FIELDS[$sync]) as $field) {
            $path = trim((string) ($settings['fields'][$field] ?? ''));
            if ($path === '' || ! is_array($item) || ! self::has($item, $path)) {
                continue;
            }
            $value = ErpClient::pick($item, $path);
            $out[$field] = match (true) {
                $value === null => null,
                is_float($value) => number_format($value, 2, '.', ''),
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => trim((string) $value),
                default => json_encode($value),
            };
        }

        return $out;
    }

    private static function has(array $item, string $path): bool
    {
        return array_key_exists($path, $item) || data_get($item, $path, $missing = new \stdClass) !== $missing;
    }

    /** Their status as ours, through the matches they set (any case), or ours if they already use our words. */
    private static function status(array $settings, ?string $theirs, array $ours): ?string
    {
        if ($theirs === null || $theirs === '') {
            return null;
        }
        foreach ($settings['statuses'] ?? [] as $pair) {
            if (mb_strtolower(trim((string) $pair['erp'])) === mb_strtolower($theirs)) {
                return $pair['ours'];
            }
        }

        return in_array(mb_strtolower($theirs), $ours, true) ? mb_strtolower($theirs) : null;
    }

    // ---- The three pulls: [counts, problems, message] ----

    private function orderStatus(ErpConnection $connection, array $items): array
    {
        $settings = $connection->sync('order_status');
        $counts = ['moved' => 0, 'unchanged' => 0, 'not_ours' => 0, 'errors' => 0];
        $problems = [];
        $unmatched = [];

        foreach ($items as $item) {
            $f = self::fields($item, $settings, 'order_status');
            $ref = $f['order'] ?? '';
            $order = $ref === '' ? null : $this->findOrder($settings, $ref);
            if (! $order) {
                // An order that didn't start here (or isn't numbered yet): not ours to move.
                $counts['not_ours']++;

                continue;
            }

            $to = self::status($settings, $f['status'] ?? null, SalesOrder::STATUSES);
            if (($f['status'] ?? '') !== '' && $to === null) {
                $unmatched[$f['status']] = true;
            }

            try {
                $moved = DB::transaction(function () use ($order, $f, $to, $settings) {
                    $changed = false;
                    if (($settings['match'] ?? 'erp_ref') === 'number' && ($f['erp_ref'] ?? '') !== '' && $order->erp_ref !== $f['erp_ref']) {
                        $order->updateDetails(['erp_ref' => mb_substr($f['erp_ref'], 0, 100)]);
                        $changed = true;
                    }
                    if ($to && $to !== $order->status) {
                        $steps = self::pathTo($order->status, $to);
                        if ($steps === null) {
                            throw ValidationException::withMessages(['status' => $order->label().' is '.str_replace('_', ' ', $order->status).' here, so it can\'t be marked '.str_replace('_', ' ', $to).'.']);
                        }
                        foreach ($steps as $step) {
                            $order->moveTo($step, null, $step === SalesOrder::CANCELLED ? (($f['cancel_reason'] ?? '') ?: 'Cancelled in your ERP.') : null);
                        }
                        $changed = true;
                    }

                    return $changed;
                });
                $counts[$moved ? 'moved' : 'unchanged']++;
            } catch (ValidationException $e) {
                $counts['errors']++;
                $problems[] = $order->label().': '.collect($e->errors())->flatten()->first();
            }
        }

        foreach (array_keys($unmatched) as $status) {
            $problems[] = "Your ERP's status \"{$status}\" isn't matched to one of ours, so those orders were left as they are.";
        }

        return [$counts, $problems, "Checked {$this->plural(count($items), 'order')} from your ERP: {$counts['moved']} changed here."];
    }

    private function findOrder(array $settings, string $ref): ?SalesOrder
    {
        if (($settings['match'] ?? 'erp_ref') === 'number') {
            return preg_match('/^(?:SO-)?(\d+)$/i', $ref, $m) ? SalesOrder::where('number', (int) $m[1])->first() : null;
        }

        return SalesOrder::where('erp_ref', $ref)->first();
    }

    /**
     * The moves from one status to another, following MOVES - so an ERP that
     * jumps straight to "delivered" still passes through "shipped", stamping
     * each step. Null when there's no way there (e.g. back from shipped).
     *
     * @return list<string>|null
     */
    public static function pathTo(string $from, string $to): ?array
    {
        $queue = [[$from, []]];
        $seen = [$from => true];
        while ($queue) {
            [$at, $steps] = array_shift($queue);
            foreach (SalesOrder::MOVES[$at] ?? [] as $next) {
                // Cancelling is only ever a direct step, never a way through.
                if (isset($seen[$next]) || ($next === SalesOrder::CANCELLED && $to !== SalesOrder::CANCELLED)) {
                    continue;
                }
                if ($next === $to) {
                    return [...$steps, $next];
                }
                $seen[$next] = true;
                $queue[] = [$next, [...$steps, $next]];
            }
        }

        return null;
    }

    private function prices(ErpConnection $connection, array $items): array
    {
        $settings = $connection->sync('prices');
        $list = PriceList::find($settings['price_list_id'] ?? 0);
        if (! $list) {
            throw new ErpException('Choose the price list your ERP\'s prices go on.', 0);
        }

        $rows = [];
        $problems = [];
        foreach ($items as $i => $item) {
            $f = self::fields($item, $settings, 'prices');
            if (($f['item'] ?? '') === '') {
                $problems[] = 'Item '.($i + 1).' in your ERP\'s list has no item number.';

                continue;
            }
            $rows[] = ['item' => $f['item'], 'price' => $f['price'] ?? null];
        }

        $outcome = $rows ? PriceSync::apply($list, $rows, 'items', skipUnknown: true) : ['errors' => [], 'updated' => 0, 'unknown' => []];
        $unknown = count(array_unique($outcome['unknown']));
        if ($outcome['errors']) {
            $problems = [...array_values($outcome['errors']), ...$problems];

            return [['updated' => 0, 'not_ours' => $unknown, 'failed' => true], $problems, 'Nothing was saved, because some of your ERP\'s prices couldn\'t be used.'];
        }

        return [
            ['updated' => $outcome['updated'], 'not_ours' => $unknown],
            $problems,
            "Set {$this->plural($outcome['updated'], 'price')} on {$list->name}".($unknown ? ", and skipped {$this->plural($unknown, 'item')} with no matching model or option." : '.'),
        ];
    }

    private function machines(ErpConnection $connection, array $items): array
    {
        $settings = $connection->sync('machines');
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
        $problems = [];
        $newModels = [];

        foreach ($items as $item) {
            $f = self::fields($item, $settings, 'machines');
            if (array_key_exists('status', $f)) {
                $theirs = $f['status'];
                $f['status'] = self::status($settings, $theirs, Machine::STATUSES);
                if ($f['status'] === null) {
                    unset($f['status']);
                    if (($theirs ?? '') !== '') {
                        $problems[] = 'Serial '.($f['serial_no'] ?? '?').": your ERP's status \"{$theirs}\" isn't matched to one of ours, so its status was left as it is.";
                    }
                }
            }

            $result = MachineSync::one($f, (bool) ($settings['add_models'] ?? false));
            if (isset($result['new_model'])) {
                $newModels[] = $result['new_model'];
            }
            if ($result['result'] === 'error') {
                $counts['errors']++;
                $problems[] = 'Serial '.(($f['serial_no'] ?? '') ?: '(none)').': '.implode(' ', $result['errors'] ?? []);
            } else {
                $counts[$result['result']]++;
            }
        }

        $message = "Checked {$this->plural(count($items), 'machine')} from your ERP: {$counts['created']} added, {$counts['updated']} updated.";
        if ($newModels) {
            $counts['new_models'] = count($newModels);
            $message .= ' Added '.$this->plural(count($newModels), 'model').' to the "'.MachineSync::NEW_MODELS_LINE.'" product line.';
        }

        return [$counts, $problems, $message];
    }

    /** What one previewed item would do, in words. */
    private function describe(ErpConnection $connection, string $sync, array $f): string
    {
        $settings = $connection->sync($sync);

        return match ($sync) {
            'order_status' => (function () use ($settings, $f) {
                $order = ($f['order'] ?? '') === '' ? null : $this->findOrder($settings, $f['order']);
                if (! $order) {
                    return 'No matching sales order here, so it would be left alone.';
                }
                $to = self::status($settings, $f['status'] ?? null, SalesOrder::STATUSES);
                if (! $to) {
                    return $order->label().': status "'.($f['status'] ?? '').'" isn\'t matched to one of ours.';
                }
                if ($to === $order->status) {
                    return $order->label().' is already '.str_replace('_', ' ', $to).'.';
                }

                return self::pathTo($order->status, $to) === null
                    ? $order->label().' can\'t go from '.str_replace('_', ' ', $order->status).' to '.str_replace('_', ' ', $to).'.'
                    : $order->label().' would move to '.str_replace('_', ' ', $to).'.';
            })(),
            'prices' => (function () use ($f) {
                $match = PriceSync::match((string) ($f['item'] ?? ''));

                return $match ? 'Would set '.$match.' to '.(($f['price'] ?? '') === '' || $f['price'] === null ? 'price on request' : $f['price']).'.' : 'No model or option has this item number, so it would be skipped.';
            })(),
            'machines' => (function () use ($f, $settings) {
                $serial = $f['serial_no'] ?? '';
                if ($serial === '') {
                    return 'Has no serial number, so it can\'t be used.';
                }
                if (Machine::where('serial_no', $serial)->exists()) {
                    return 'Would update '.$serial.'.';
                }
                // A new machine needs a model we know, the same check MachineSync makes.
                $byName = ($f['model_name'] ?? '') !== '' ? ProductModel::where('name', $f['model_name'])->first() : null;
                $found = match (true) {
                    ($f['model'] ?? '') !== '' => ProductModel::forItemCode($f['model']) ?? $byName ?? "no model has item number \"{$f['model']}\" (or the start of it)",
                    ($f['model_name'] ?? '') !== '' => $byName ?? "no model is called \"{$f['model_name']}\"",
                    default => 'it has no model',
                };
                if (! is_string($found)) {
                    return "Would add {$serial} as {$found->name}.";
                }
                if (! empty($settings['add_models']) && ($name = MachineSync::newModelName($f)) !== null) {
                    return ProductModel::where('name', $name)->exists()
                        ? "Would add {$serial} as {$name}."
                        : "Would add {$serial}, and a new model \"{$name}\".";
                }

                return "Can't add {$serial}: {$found}.";
            })(),
        };
    }

    private function plural(int $n, string $word): string
    {
        return $n.' '.$word.($n === 1 ? '' : 's');
    }
}
