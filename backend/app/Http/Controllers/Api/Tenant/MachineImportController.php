<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Imports\MachineImporter;
use App\Imports\SpreadsheetFile;
use App\Models\DealerLink;
use App\Models\MachineImport;
use App\Models\ProductModel;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importing machines from a CSV or Excel file: a template, a preview that
 * writes nothing, the import itself, and the history with an audit report
 * for each import.
 */
class MachineImportController extends Controller
{
    /** A CSV with the columns and one example row from the manufacturer's own data. */
    public function template(): StreamedResponse
    {
        $model = ProductModel::orderBy('name')->value('name') ?? 'S-400';
        $dealer = DealerLink::whereNotNull('dealer_code')->orderBy('dealer_code')->value('dealer_code') ?? '';
        $name = Tenant::current()->manufacturer()->slug.'-machine-import-template.csv';

        return response()->streamDownload(function () use ($model, $dealer) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column(MachineImporter::COLUMNS, 'label'));
            fputcsv($out, ['EXAMPLE-0001', $model, 'PO-1234', $dealer, 'inventory', '2026-03-15', '2026-04-02']);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** What the file would do. Writes nothing. */
    public function preview(Request $request): JsonResponse
    {
        [$importer, $file] = $this->importer($request);
        $results = $importer->analyse();

        return response()->json([
            'file_name' => $file->getClientOriginalName(),
            'columns' => $importer->columns(),
            'summary' => self::summary($results),
            'rows' => MachineImporter::public($results),
        ]);
    }

    /** Imports the file: every row without a problem is written; the rest are reported. */
    public function store(Request $request): JsonResponse
    {
        [$importer, $file] = $this->importer($request);
        $import = $importer->apply($request->user(), self::fileName($file), hash_file('sha256', $file->getRealPath()));

        return response()->json(self::history($import->load('user')) + ['rows' => $import->results], 201);
    }

    public function index(): JsonResponse
    {
        $imports = MachineImport::with('user')->orderByDesc('id')->limit(50)->get();

        return response()->json($imports->map(fn (MachineImport $i) => self::history($i)));
    }

    /** One import's audit report: every row and what happened to it. */
    public function report(int $import): BinaryFileResponse
    {
        $i = MachineImport::with('user')->findOrFail($import);
        $path = tempnam(sys_get_temp_dir(), 'import');

        $writer = new Writer(tap(new Options, function (Options $o) {
            $o->setColumnWidth(8, 1);
            $o->setColumnWidth(20, 2);
            $o->setColumnWidth(14, 3);
            $o->setColumnWidth(90, 4);
        }));
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Import '.$i->id);
        $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(6));
        $bold = (new Style)->setFontBold();

        // What, who, when - then one line per row.
        $writer->addRow(new Row([self::text('Import #'.$i->id.' of '.$i->file_name)], $bold));
        $writer->addRow(new Row([self::text('By '.($i->user?->name ?? 'a former user').', '.$i->created_at->format('Y-m-d H:i').' UTC, '.($i->mode === MachineImporter::ADD ? 'adding new machines only' : 'adding new and updating existing machines'))]));
        $writer->addRow(new Row([self::text("{$i->created_count} added, {$i->updated_count} updated, {$i->unchanged_count} unchanged, {$i->skipped_count} skipped, {$i->failed_count} with problems (not imported)")]));
        $writer->addRow(new Row([self::text('File SHA-256: '.$i->sha256)]));
        $writer->addRow(new Row([]));
        $writer->addRow(Row::fromValues(['Row', 'Serial number', 'Result', 'Details'], $bold));

        $words = ['create' => 'Added', 'update' => 'Updated', 'unchanged' => 'Unchanged', 'skip' => 'Skipped', 'error' => 'Not imported'];
        foreach ($i->results as $r) {
            $details = array_merge(
                array_map(fn ($c) => "{$c['field']}: ".($c['from'] ?? '(blank)').' -> '.($c['to'] ?? '(blank)'), $r['changes']),
                $r['messages'],
            );
            $writer->addRow(new Row([
                new NumericCell($r['line'], null),
                self::text($r['serial']),
                self::text($words[$r['action']] ?? $r['action']),
                self::text(implode(' ', $details)),
            ]));
        }
        $writer->close();

        $name = Tenant::current()->manufacturer()->slug.'-import-'.$i->id.'-report.xlsx';

        return response()->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** @return array{0: MachineImporter, 1: UploadedFile} */
    private function importer(Request $request): array
    {
        $data = $request->validate([
            // By extension: a CSV's content just looks like text, so content sniffing can't tell.
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:5120'],
            'mode' => ['required', Rule::in([MachineImporter::ADD, MachineImporter::ADD_UPDATE])],
        ], [
            'file.extensions' => 'Upload a CSV or an Excel (.xlsx) file.',
            'file.max' => 'That file is over 5 MB. Split it into smaller files.',
        ]);

        try {
            $sheet = SpreadsheetFile::read($data['file'], MachineImporter::MAX_ROWS);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'We couldn\'t read this file. Save it as CSV or Excel (.xlsx) and try again.']);
        }

        return [new MachineImporter($sheet, $data['mode']), $data['file']];
    }

    private static function summary(array $results): array
    {
        $count = fn (string $a) => count(array_filter($results, fn ($r) => $r['action'] === $a));

        return [
            'rows' => count($results),
            'create' => $count('create'),
            'update' => $count('update'),
            'unchanged' => $count('unchanged'),
            'skip' => $count('skip'),
            'error' => $count('error'),
        ];
    }

    private static function history(MachineImport $i): array
    {
        return [
            'id' => $i->id,
            'file_name' => $i->file_name,
            'mode' => $i->mode,
            'by' => $i->user?->name,
            'at' => $i->created_at?->toIso8601String(),
            'summary' => [
                'rows' => $i->rows_total,
                'create' => $i->created_count,
                'update' => $i->updated_count,
                'unchanged' => $i->unchanged_count,
                'skip' => $i->skipped_count,
                'error' => $i->failed_count,
            ],
        ];
    }

    private static function fileName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))) ?? '';

        return mb_substr(trim($name), 0, 200) ?: 'import';
    }

    /** Always text - never a formula, whatever the cell starts with. */
    private static function text(?string $value): StringCell|EmptyCell
    {
        return $value === null || $value === '' ? new EmptyCell(null, null) : new StringCell($value, null);
    }
}
