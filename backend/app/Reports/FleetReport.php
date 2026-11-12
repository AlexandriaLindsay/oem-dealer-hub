<?php

namespace App\Reports;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\ProductModel;
use App\Tenancy\Tenant;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The fleet report: every machine at the current manufacturer, across all its
 * dealers, counted by status, dealer and model - and the same machines as an
 * Excel file.
 *
 * Takes the machine list's filters (Machine::filter), so the report, the
 * export and the list always agree on what "these machines" means.
 */
final class FleetReport
{
    public function __construct(private readonly array $filters = []) {}

    /**
     * @return array{
     *   total: int,
     *   by_status: array<string, int>,
     *   by_dealer: list<array{id: int|null, name: string, total: int, statuses: array<string, int>}>,
     *   by_model: list<array{id: int, name: string, line: string, total: int, statuses: array<string, int>}>
     * }
     */
    public function summary(): array
    {
        $byStatus = $this->countBy(null);
        $byDealer = $this->countBy('dealer_link_id');
        $byModel = $this->countBy('product_model_id');

        $dealerNames = DealerLink::with('dealer')->whereIn('id', array_filter(array_keys($byDealer)))->get()
            ->mapWithKeys(fn (DealerLink $l) => [$l->id => $l->dealer->name]);
        $models = ProductModel::with('productLine')->whereIn('id', array_keys($byModel))->get()->keyBy('id');
        $factory = Tenant::current()->manufacturer()->name;

        $dealers = collect($byDealer)->map(fn ($statuses, $id) => [
            'id' => $id === '' ? null : (int) $id,
            // Machines with no dealer are still at the manufacturer.
            'name' => $id === '' ? 'At the factory ('.$factory.')' : ($dealerNames[(int) $id] ?? 'Unknown dealer'),
            'total' => array_sum($statuses),
            'statuses' => $statuses,
        ]);

        $modelRows = collect($byModel)->map(fn ($statuses, $id) => [
            'id' => (int) $id,
            'name' => $models->get((int) $id)?->name ?? 'Unknown model',
            'line' => $models->get((int) $id)?->productLine?->name ?? '',
            'total' => array_sum($statuses),
            'statuses' => $statuses,
        ]);

        return [
            'total' => array_sum($byStatus['']),
            'by_status' => $byStatus[''],
            // Largest first, then by name, so the order is stable between visits.
            'by_dealer' => $dealers->sortBy([['total', 'desc'], ['name', 'asc']])->values()->all(),
            'by_model' => $modelRows->sortBy([['total', 'desc'], ['name', 'asc']])->values()->all(),
            'aging' => $this->aging($dealerNames->all(), $factory),
        ];
    }

    /**
     * How long unsold machines have been sitting (Machine::UNSOLD,
     * Machine::AGE_BANDS), per dealer: a count per band and the oldest. The
     * dealers holding the oldest stock first - they're the ones to call.
     *
     * @param  array<int, string>  $dealerNames
     * @return array{bands: list<string>, totals: list<int>, by_dealer: list<array{id: int|null, name: string, counts: list<int>, total: int, oldest_days: int}>}
     */
    private function aging(array $dealerNames, string $factory): array
    {
        $today = now()->startOfDay();
        $bandOf = function (int $days): int {
            foreach (Machine::AGE_BANDS as $i => [$from, $to]) {
                if ($days >= $from && ($to === null || $days <= $to)) {
                    return $i;
                }
            }

            return count(Machine::AGE_BANDS) - 1;
        };
        $empty = array_fill(0, count(Machine::AGE_BANDS), 0);

        $rows = Machine::filter($this->filters)->whereIn('status', Machine::UNSOLD)
            ->selectRaw('dealer_link_id, '.Machine::stockSinceSql().' as since')->toBase()->get();

        $byDealer = [];
        $totals = $empty;
        foreach ($rows as $row) {
            $days = $row->since ? max(0, (int) now()->parse($row->since)->startOfDay()->diffInDays($today)) : 0;
            $key = (string) ($row->dealer_link_id ?? '');
            $byDealer[$key] ??= ['counts' => $empty, 'oldest_days' => 0];
            $byDealer[$key]['counts'][$bandOf($days)]++;
            $byDealer[$key]['oldest_days'] = max($byDealer[$key]['oldest_days'], $days);
            $totals[$bandOf($days)]++;
        }

        // Names for dealers that only have unsold stock in this result.
        $missing = array_diff(array_filter(array_keys($byDealer)), array_keys($dealerNames));
        if ($missing) {
            $dealerNames += DealerLink::with('dealer')->whereIn('id', $missing)->get()->mapWithKeys(fn (DealerLink $l) => [$l->id => $l->dealer->name])->all();
        }

        $dealers = collect($byDealer)->map(fn ($d, $id) => [
            'id' => $id === '' ? null : (int) $id,
            'name' => $id === '' ? 'At the factory ('.$factory.')' : ($dealerNames[(int) $id] ?? 'Unknown dealer'),
            'counts' => $d['counts'],
            'total' => array_sum($d['counts']),
            'oldest_days' => $d['oldest_days'],
            // Over 180 days: what the list is sorted by.
            'aged' => $d['counts'][2] + $d['counts'][3],
        ]);

        return [
            'bands' => array_column(Machine::AGE_BANDS, 2),
            'totals' => $totals,
            'by_dealer' => $dealers->sortBy([['aged', 'desc'], ['oldest_days', 'desc'], ['name', 'asc']])->values()->all(),
        ];
    }

    /**
     * Writes every matching machine to an .xlsx at $path, one row each, read
     * from the database in chunks so a big fleet doesn't fill memory.
     */
    public function writeXlsx(string $path): int
    {
        $options = new Options;
        $options->setColumnWidth(18, 1, 2);
        $options->setColumnWidth(16, 3, 4);
        $options->setColumnWidth(12, 5);
        $options->setColumnWidth(28, 6);
        $options->setColumnWidth(12, 7, 8, 9);
        $options->setColumnWidth(20, 10);
        $options->setColumnWidth(14, 11);

        $writer = new Writer($options);
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Fleet');
        // Keep the header row in view while scrolling.
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        $bold = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues([
            'Serial number', 'Production number', 'Product line', 'Model', 'Status',
            'Dealer', 'Dealer code', 'Built', 'Shipped', 'Last changed (UTC)', 'Days in stock',
        ], $bold));

        $date = (new Style)->setFormat('yyyy-mm-dd');
        $dateTime = (new Style)->setFormat('yyyy-mm-dd h:mm AM/PM');
        $factory = 'At the factory';
        $rows = 0;

        $machines = Machine::with(['productModel.productLine', 'dealerLink.dealer'])
            ->filter($this->filters)
            ->orderBy('serial_no')
            ->lazy(500);

        foreach ($machines as $m) {
            $writer->addRow(new Row([
                self::text($m->serial_no),
                self::text($m->production_no),
                self::text($m->productModel->productLine->name),
                self::text($m->productModel->name),
                self::text(ucfirst($m->status)),
                self::text($m->dealerLink?->dealer->name ?? $factory),
                self::text($m->dealerLink?->dealer_code),
                self::date($m->built_at, $date),
                self::date($m->shipped_at, $date),
                self::date($m->updated_at, $dateTime),
                // A number, so it sorts and filters in Excel; empty once sold or rented.
                $m->daysInStock() === null ? new EmptyCell(null, null) : new NumericCell($m->daysInStock(), null),
            ]));
            $rows++;
        }

        $writer->close();

        return $rows;
    }

    /**
     * Status counts, zeros included, grouped by $column (null: one group, keyed '').
     *
     * @return array<string, array<string, int>>
     */
    private function countBy(?string $column): array
    {
        $query = Machine::filter($this->filters)->selectRaw('status, count(*) as total')->groupBy('status');
        if ($column) {
            $query->addSelect($column)->groupBy($column);
        }

        $empty = array_fill_keys(Machine::STATUSES, 0);
        $groups = $column ? [] : ['' => $empty];

        foreach ($query->toBase()->get() as $row) {
            $key = $column ? (string) ($row->{$column} ?? '') : '';
            $groups[$key] ??= $empty;
            $groups[$key][$row->status] = (int) $row->total;
        }

        return $groups;
    }

    /**
     * Always a string cell - never Cell::fromValue, which turns text starting
     * with "=" into a live formula. A serial number or name typed as
     * =HYPERLINK(...) stays harmless text in the export.
     */
    private static function text(?string $value): Cell
    {
        return $value === null || $value === '' ? new EmptyCell(null, null) : new StringCell($value, null);
    }

    private static function date(?DateTimeInterface $value, Style $style): Cell
    {
        return $value ? new DateTimeCell($value, $style) : new EmptyCell(null, null);
    }
}
