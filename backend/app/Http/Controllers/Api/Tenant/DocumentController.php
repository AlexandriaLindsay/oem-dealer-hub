<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentModel;
use App\Models\Machine;
use App\Support\Uploads;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The document library: manuals, parts books, bulletins. Everyone at the
 * manufacturer can read what's meant for them (dealers never see staff-only
 * documents - Document's audience scope); staff with manage_documents add,
 * change and remove them.
 */
class DocumentController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', Rule::in(Document::KINDS)],
            'model' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Document::with('models.productModel')
            ->when($filters['q'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['model'] ?? null, fn ($q, $model) => $q->whereHas('models', fn ($m) => $m->where('product_model_id', $model)))
            ->orderBy('title')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Document $d) => self::row($d)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /**
     * "What manuals go with this serial number?" Any serial at this
     * manufacturer, even a machine at another dealer - a service shop works on
     * whatever comes in. So the answer is only the model and its documents:
     * never which dealer has it, its status, or its customer.
     */
    public function forSerial(Request $request): JsonResponse
    {
        $serial = trim($request->validate(['serial' => ['required', 'string', 'max:80']])['serial']);

        // On purpose without the dealer scope - see above. Nothing about the
        // machine beyond its model leaves this method.
        $machine = Machine::withoutGlobalScope('dealer')->with('productModel.productLine')->where('serial_no', $serial)->first();

        if (! $machine) {
            return response()->json(['message' => 'No '.Tenant::current()->manufacturer()->name.' machine has serial number '.$serial.'. Check it against the machine\'s serial plate.'], 404);
        }

        return response()->json([
            'machine' => [
                'serial_no' => $machine->serial_no,
                'model' => $machine->productModel->name,
                'product_line' => $machine->productModel->productLine->name,
            ],
            'documents' => Document::forMachine($machine)->map(fn (Document $d) => self::row($d)),
        ]);
    }

    /** The documents for one machine the user can see (its page). */
    public function forMachine(int $machine): JsonResponse
    {
        $m = Machine::findOrFail($machine);

        return response()->json(Document::forMachine($m)->map(fn (Document $d) => self::row($d)));
    }

    public function store(Request $request, Uploads $uploads): JsonResponse
    {
        $data = $this->validated($request, fileRequired: true);
        $scans = $uploads->check(['file' => $data['file']]);

        $stored = null;
        try {
            $document = DB::transaction(function () use ($data, $scans, $uploads, $request, &$stored) {
                $meta = $uploads->put($data['file'], 'library', $scans['file']);
                $stored = $meta['path'];
                $document = Document::create(self::fields($data) + $meta + ['uploaded_by' => $request->user()->id]);
                self::syncModels($document, $data['models'] ?? []);

                return $document;
            });
        } catch (\Throwable $e) {
            Uploads::forget(array_filter([$stored]));
            throw $e;
        }

        return response()->json(self::row($document->load('models.productModel')), 201);
    }

    /** Changes the details and what it covers; a new file replaces the old one. */
    public function update(Request $request, Uploads $uploads, int $document): JsonResponse
    {
        $doc = Document::findOrFail($document);
        $data = $this->validated($request, fileRequired: false);
        $file = $data['file'] ?? null;
        $scans = $file ? $uploads->check(['file' => $file]) : [];

        $oldPath = $doc->path;
        $stored = null;
        try {
            DB::transaction(function () use ($doc, $data, $file, $scans, $uploads, &$stored) {
                $changes = self::fields($data);
                if ($file) {
                    $meta = $uploads->put($file, 'library', $scans['file']);
                    $stored = $meta['path'];
                    $changes += $meta;
                }
                $doc->update($changes);
                self::syncModels($doc, $data['models'] ?? []);
            });
        } catch (\Throwable $e) {
            Uploads::forget(array_filter([$stored]));
            throw $e;
        }

        // Only once the new one is safely saved.
        if ($stored) {
            Uploads::forget([$oldPath]);
        }

        return response()->json(self::row($doc->fresh('models.productModel')));
    }

    public function destroy(int $document): JsonResponse
    {
        $doc = Document::findOrFail($document);
        $path = $doc->path;
        $doc->delete();
        Uploads::forget([$path]);

        return response()->json(null, 204);
    }

    /**
     * The file. ?inline=1 opens a PDF or image in the browser tab; anything
     * else, or without it, downloads. Inline files are sandboxed (no scripts,
     * no same-origin access), so a booby-trapped PDF can't act as the user.
     */
    public function file(Request $request, int $document): StreamedResponse
    {
        $doc = Document::findOrFail($document);
        Uploads::abortIfMissing($doc->path);
        $inline = $request->boolean('inline') && in_array($doc->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);

        $headers = [
            'Content-Type' => $doc->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => 'sandbox',
        ];

        return Uploads::disk()->response($doc->path, $doc->original_name, $headers, $inline ? 'inline' : 'attachment');
    }

    private function validated(Request $request, bool $fileRequired): array
    {
        $tenantId = Tenant::current()->id();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'kind' => ['required', Rule::in(Document::KINDS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience' => ['required', Rule::in([Document::EVERYONE, Document::STAFF])],
            'file' => [$fileRequired ? 'required' : 'nullable', ...Uploads::rule('library_max_kb')],
            'models' => ['nullable', 'array', 'max:200'],
            'models.*.id' => ['required', 'integer', 'distinct', Rule::exists('product_models', 'id')->where('manufacturer_id', $tenantId)],
            'models.*.serial_from' => ['nullable', 'string', 'max:80'],
            'models.*.serial_to' => ['nullable', 'string', 'max:80'],
        ], [
            'file.mimes' => 'Upload a PDF, JPG or PNG.',
            'file.max' => 'That file is over the '.(int) (config('dealerdock.documents.library_max_kb') / 1024).' MB limit.',
            'models.*.id.exists' => 'That model isn\'t one of yours.',
        ], [
            'models.*.serial_from' => 'first serial number',
            'models.*.serial_to' => 'last serial number',
        ]);

        foreach ($data['models'] ?? [] as $i => $link) {
            $from = trim((string) ($link['serial_from'] ?? '')) ?: null;
            $to = trim((string) ($link['serial_to'] ?? '')) ?: null;
            if ($from !== null && $to !== null && strnatcasecmp($from, $to) > 0) {
                throw ValidationException::withMessages(["models.$i.serial_to" => 'The last serial number comes before the first.']);
            }
            $data['models'][$i]['serial_from'] = $from;
            $data['models'][$i]['serial_to'] = $to;
        }

        return $data;
    }

    private static function fields(array $data): array
    {
        return [
            'title' => $data['title'],
            'kind' => $data['kind'],
            'description' => $data['description'] ?? null,
            'audience' => $data['audience'],
        ];
    }

    /** Replaces what the document covers with $links. */
    private static function syncModels(Document $document, array $links): void
    {
        $document->models()->delete();
        foreach ($links as $link) {
            $document->models()->create([
                'product_model_id' => $link['id'],
                'serial_from' => $link['serial_from'],
                'serial_to' => $link['serial_to'],
            ]);
        }
    }

    private static function row(Document $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'kind' => $d->kind,
            'description' => $d->description,
            'audience' => $d->audience,
            'name' => $d->original_name,
            'mime' => $d->mime,
            'size' => $d->size,
            'scan_status' => $d->scan_status,
            'updated_at' => $d->updated_at?->toIso8601String(),
            // Empty: covers every machine.
            'covers' => $d->models->map(fn (DocumentModel $l) => [
                'model_id' => $l->product_model_id,
                'model' => $l->productModel->name,
                'serial_from' => $l->serial_from,
                'serial_to' => $l->serial_to,
                'range' => $l->rangeLabel(),
            ])->sortBy('model')->values(),
        ];
    }
}
