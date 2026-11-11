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

        $writer = new Writer($options);
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Fleet');
        // Keep the header row in view while scrolling.
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        $bold = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues([
            'Serial number', 'Production number', 'Product line', 'Model', 'Status',
            'Dealer', 'Dealer code', 'Built', 'Shipped', 'Last changed (UTC)',
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
