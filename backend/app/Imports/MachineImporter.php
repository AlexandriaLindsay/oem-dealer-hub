<?php

namespace App\Imports;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\MachineImport;
use App\Models\ProductModel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings a manufacturer's existing machines in from a spreadsheet - the
 * onboarding step, and the no-ERP way to keep DealerDock current
 * (docs/PLAN.md section 5).
 *
 * analyse() works out what every row would do, writing nothing - the preview.
 * apply() analyses again (the file is re-sent, never trusted from before),
 * then writes every row that has no problem, in one transaction, and records
 * the import with every row's outcome for the audit report.
 *
 * Rules that keep a re-import safe:
 * - A blank cell never clears anything on an existing machine. To move one
 *   back to the factory, write "factory" in the dealer column.
 * - A machine's model is never changed by an import; a different model is a
 *   problem to look at, not an update.
 *
 * Runs inside the current tenant (the request's manufacturer).
 */
final class MachineImporter
{
    public const MAX_ROWS = 5000;

    public const ADD = 'add';

    public const ADD_UPDATE = 'add_update';

    /** Every column it understands, and the header spellings that mean it. */
    public const COLUMNS = [
        'serial_no' => ['label' => 'Serial number', 'required' => true, 'aliases' => ['serial number', 'serial', 'serial no', 'serial #', 's/n', 'sn', 'serialnumber']],
        'model' => ['label' => 'Model', 'required' => true, 'aliases' => ['model', 'model name', 'model number', 'model no']],
        'production_no' => ['label' => 'Production number', 'required' => false, 'aliases' => ['production number', 'production no', 'production #', 'work order', 'work order number', 'po', 'po number']],
        'dealer' => ['label' => 'Dealer', 'required' => false, 'aliases' => ['dealer', 'dealer code', 'dealer name', 'dealer #', 'dealer no']],
        'status' => ['label' => 'Status', 'required' => false, 'aliases' => ['status', 'machine status']],
        'built_at' => ['label' => 'Built on', 'required' => false, 'aliases' => ['built on', 'built', 'build date', 'date built', 'built at']],
        'shipped_at' => ['label' => 'Shipped on', 'required' => false, 'aliases' => ['shipped on', 'shipped', 'ship date', 'date shipped', 'shipped at']],
    ];

    /** Words for "still at the factory" in the dealer column. */
    private const FACTORY = ['factory', 'at factory', 'at the factory', 'none'];

    private const STATUS_WORDS = ['stock' => 'inventory', 'in stock' => 'inventory', 'rented' => 'rental', 'demonstrator' => 'demo'];

    /** @var array<string, int> column key => index in each row */
    private array $columnIndex = [];

    /** @var list<string> headers it didn't recognise (ignored) */
    private array $ignored = [];

    /** @var array<int, DealerLink> every dealer link at the manufacturer, by id (loaded by analyse) */
    private array $linksById = [];

    public function __construct(
        private readonly SpreadsheetFile $file,
        private readonly string $mode,
    ) {
        $this->mapHeaders();
    }

    /** The headers it ignored, and which column it read each field from - for the preview. */
    public function columns(): array
    {
        return [
            'used' => collect($this->columnIndex)->map(fn ($i, $key) => ['field' => self::COLUMNS[$key]['label'], 'header' => $this->file->headers[$i]])->values()->all(),
            'ignored' => $this->ignored,
        ];
    }

    /**
     * What each row would do. Actions: create, update, unchanged, skip (already
     * there, in add-only mode), error (with messages; never written).
     *
     * @return list<array{line: int, serial: string, action: string, messages: list<string>, changes: list<array{field: string, from: ?string, to: ?string}>, plan: array}>
     */
    public function analyse(): array
    {
        $models = ProductModel::get()->keyBy(fn ($m) => mb_strtolower($m->name));
        [$byCode, $byName, $ambiguousNames] = $this->dealerLookups();
        $existing = $this->existingMachines();
        $seen = [];
        $results = [];

        foreach ($this->file->rows as $row) {
            $get = fn (string $key) => isset($this->columnIndex[$key]) ? trim($row['cells'][$this->columnIndex[$key]] ?? '') : '';
            $serial = $get('serial_no');
            $messages = [];
            $plan = [];

            if ($serial === '') {
                $messages[] = 'No serial number.';
            } elseif (mb_strlen($serial) > 80) {
                $messages[] = 'The serial number is longer than 80 characters.';
            } elseif (isset($seen[mb_strtolower($serial)])) {
                $messages[] = 'This serial number is also on row '.$seen[mb_strtolower($serial)].'. Each machine should be in the file once.';
            } else {
                $seen[mb_strtolower($serial)] = $row['line'];
            }

            $modelName = $get('model');
            $model = $models[mb_strtolower($modelName)] ?? null;
            if ($modelName === '') {
                $messages[] = 'No model.';
            } elseif (! $model) {
                $messages[] = "There's no model called \"$modelName\". Add it under Products first, or check the spelling.";
            }

            // Dealer: blank = no change (or the factory, for a new machine).
            $dealerText = $get('dealer');
            $linkGiven = $dealerText !== '';
            $link = null;
            if ($linkGiven && ! in_array(mb_strtolower($dealerText), self::FACTORY, true)) {
                $key = mb_strtolower($dealerText);
                $link = $byCode[$key] ?? null;
                if (! $link && isset($ambiguousNames[$key])) {
                    $messages[] = "More than one dealer is called \"$dealerText\". Use their dealer code instead.";
                } else {
                    $link ??= $byName[$key] ?? null;
                    if (! $link) {
                        $messages[] = "No dealer with the code or name \"$dealerText\". Check it against your Dealers page.";
                    } elseif ($link->status !== DealerLink::ACTIVE) {
                        $messages[] = "{$link->dealer->name} is suspended, so machines can't be sent to them.";
                    }
                }
            }

            $statusText = $get('status');
            $status = null;
            if ($statusText !== '') {
                $status = self::STATUS_WORDS[mb_strtolower($statusText)] ?? mb_strtolower($statusText);
                if (! in_array($status, Machine::STATUSES, true)) {
                    $messages[] = "\"$statusText\" isn't a status. Use one of: ".implode(', ', Machine::STATUSES).'.';
                }
            }

            $productionNo = $get('production_no');
            if (mb_strlen($productionNo) > 80) {
                $messages[] = 'The production number is longer than 80 characters.';
            }

            $dates = [];
            foreach (['built_at' => 'built', 'shipped_at' => 'shipped'] as $key => $word) {
                $text = $get($key);
                if ($text === '') {
                    continue;
                }
                $date = self::parseDate($text);
                if ($date === null) {
                    $messages[] = "\"$text\" isn't a date we can read for when it was $word. Use 2026-03-15 (or 3/15/2026).";
                } else {
                    $dates[$key] = $date;
                }
            }

            $machine = $existing[mb_strtolower($serial)] ?? null;
            $action = 'create';
            $changes = [];

            if (! $messages && $machine) {
                if ($this->mode === self::ADD) {
                    $action = 'skip';
                    $messages[] = 'Already in DealerDock, so it was left as it is. Choose "add new and update existing" to update it.';
                } elseif ((int) $machine->product_model_id !== $model->id) {
                    $messages[] = "In DealerDock this machine's model is {$machine->productModel->name}, but the file says {$model->name}. An import doesn't change a machine's model.";
                } else {
                    $changes = $this->changes($machine, $linkGiven, $link, $status, $productionNo, $dates);
                    $action = $changes ? 'update' : 'unchanged';
                    $plan = ['machine_id' => $machine->id, 'link_given' => $linkGiven, 'link_id' => $link?->id, 'status' => $status, 'production_no' => $productionNo, 'dates' => $dates];
                }
            } elseif (! $messages) {
                $plan = ['model_id' => $model->id, 'link_id' => $link?->id, 'status' => $status ?? 'inventory', 'production_no' => $productionNo, 'dates' => $dates];
            }

            if ($messages && $action !== 'skip') {
                $action = 'error';
            }

            $results[] = ['line' => $row['line'], 'serial' => $serial, 'action' => $action, 'messages' => $messages, 'changes' => $changes, 'plan' => $plan];
        }

        return $results;
    }

    /** Analyses again and writes every row without a problem, in one transaction. */
    public function apply(User $by, string $fileName, string $sha256): MachineImport
    {
        $results = $this->analyse();

        return DB::transaction(function () use ($results, $by, $fileName, $sha256) {
            $import = MachineImport::create([
                'user_id' => $by->id, 'file_name' => $fileName, 'sha256' => $sha256, 'mode' => $this->mode,
                'rows_total' => count($results), 'created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0,
                'skipped_count' => 0, 'failed_count' => 0, 'results' => [],
            ]);
            $note = "Imported from {$fileName} (import #{$import->id})";

            foreach ($results as $r) {
                $p = $r['plan'];
                if ($r['action'] === 'create') {
                    Machine::add(
                        ['product_model_id' => $p['model_id'], 'serial_no' => $r['serial'], 'production_no' => $p['production_no'] ?: null, 'status' => $p['status']] + $p['dates'],
                        $p['link_id'] ? $this->linksById[$p['link_id']] : null,
                        $by,
                        $note,
                        stampShipped: false,
                    );
                } elseif ($r['action'] === 'update') {
                    $machine = Machine::findOrFail($p['machine_id']);
                    $fields = $p['dates'] + ($p['production_no'] !== '' ? ['production_no' => $p['production_no']] : []);
                    $machine->fill($fields)->save();
                    if ($p['link_given']) {
                        $machine->transferTo($p['link_id'] ? $this->linksById[$p['link_id']] : null, $by, $note);
                    }
                    if ($p['status']) {
                        $machine->changeStatus($p['status'], $by, $note);
                    }
                }
            }

            $count = fn (string $action) => count(array_filter($results, fn ($r) => $r['action'] === $action));
            $import->update([
                'created_count' => $count('create'),
                'updated_count' => $count('update'),
                'unchanged_count' => $count('unchanged'),
                'skipped_count' => $count('skip'),
                'failed_count' => $count('error'),
                'results' => self::public($results),
            ]);

            return $import;
        });
    }

    /** Results without the internal plan - what the preview and the report show. */
    public static function public(array $results): array
    {
        return array_map(fn ($r) => array_diff_key($r, ['plan' => true]), $results);
    }

    /**
     * "2026-03-15", "2026/03/15", "3/15/2026" (US order) -> "2026-03-15";
     * anything else null. Also Excel's own day numbers (45838 = 2025-06-30),
     * which is what a date cell becomes when a sheet loses its date format.
     */
    public static function parseDate(string $text): ?string
    {
        $text = trim($text);
        // Excel counts days from 1899-12-30; 18264..73050 is 1950..2099.
        if (preg_match('/^\d{5}$/', $text) && (int) $text >= 18264 && (int) $text <= 73050) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) $text.' days')->format('Y-m-d');
        }
        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $text, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m)) {
            [$mo, $d, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return $y >= 1950 && $y <= 2100 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private function mapHeaders(): void
    {
        $lookup = [];
        foreach (self::COLUMNS as $key => $column) {
            foreach ($column['aliases'] as $alias) {
                $lookup[self::normalise($alias)] = $key;
            }
        }

        foreach ($this->file->headers as $i => $header) {
            $key = $lookup[self::normalise($header)] ?? null;
            if ($key === null) {
                if ($header !== '') {
                    $this->ignored[] = $header;
                }

                continue;
            }
            if (isset($this->columnIndex[$key])) {
                throw ValidationException::withMessages(['file' => 'Two columns both look like "'.self::COLUMNS[$key]['label'].'" ("'.$this->file->headers[$this->columnIndex[$key]]."\" and \"$header\"). Remove or rename one."]);
            }
            $this->columnIndex[$key] = $i;
        }

        $missing = collect(self::COLUMNS)->filter(fn ($c, $key) => $c['required'] && ! isset($this->columnIndex[$key]))->pluck('label');
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'The file needs a column for: '.$missing->implode(', ').'. Download the template to see the columns.']);
        }
    }

    private static function normalise(string $header): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['_', '-', '.'], ' ', mb_strtolower(ltrim($header, "\xEF\xBB\xBF")))));
    }

    /**
     * Dealer lookups by code and by name (both lowercased). Codes win over
     * names; a name two dealers share can't be used.
     *
     * @return array{0: array<string, DealerLink>, 1: array<string, DealerLink>, 2: array<string, true>}
     */
    private function dealerLookups(): array
    {
        $links = DealerLink::with('dealer')->get();
        $this->linksById = $links->keyBy('id')->all();
        $byCode = $links->filter(fn ($l) => $l->dealer_code)->keyBy(fn ($l) => mb_strtolower($l->dealer_code))->all();
        $names = $links->groupBy(fn ($l) => mb_strtolower($l->dealer->name));
        $byName = $names->filter(fn ($g) => $g->count() === 1)->map(fn ($g) => $g->first())->all();
        $ambiguous = $names->filter(fn ($g) => $g->count() > 1)->map(fn () => true)->all();

        return [$byCode, $byName, $ambiguous];
    }

    /** @return Collection<string, Machine> the file's serials already at this manufacturer, keyed lowercase */
    private function existingMachines(): Collection
    {
        $serials = collect($this->file->rows)
            ->map(fn ($r) => trim($r['cells'][$this->columnIndex['serial_no']] ?? ''))
            ->filter()->unique()->values();

        return $serials->chunk(500)->flatMap(fn ($chunk) => Machine::with('productModel')->whereIn('serial_no', $chunk->all())->get())
            ->keyBy(fn ($m) => mb_strtolower($m->serial_no));
    }

    /** What an update would change, in words. Blank in the file = leave it. */
    private function changes(Machine $machine, bool $linkGiven, ?DealerLink $link, ?string $status, string $productionNo, array $dates): array
    {
        $changes = [];
        $place = fn (?int $linkId) => $linkId ? (($this->linksById[$linkId] ?? null)?->dealer->name ?? 'a dealer') : 'the factory';

        if ($linkGiven && (int) $machine->dealer_link_id !== (int) $link?->id) {
            $changes[] = ['field' => 'Dealer', 'from' => $place($machine->dealer_link_id), 'to' => $link?->dealer->name ?? 'the factory'];
        }
        if ($status && $status !== $machine->status) {
            $changes[] = ['field' => 'Status', 'from' => $machine->status, 'to' => $status];
        }
        if ($productionNo !== '' && $productionNo !== (string) $machine->production_no) {
            $changes[] = ['field' => 'Production number', 'from' => $machine->production_no, 'to' => $productionNo];
        }
        foreach (['built_at' => 'Built on', 'shipped_at' => 'Shipped on'] as $key => $label) {
            if (isset($dates[$key]) && $dates[$key] !== $machine->{$key}?->toDateString()) {
                $changes[] = ['field' => $label, 'from' => $machine->{$key}?->toDateString(), 'to' => $dates[$key]];
            }
        }

        return $changes;
    }
}
