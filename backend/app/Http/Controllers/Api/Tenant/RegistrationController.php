<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\Membership;
use App\Models\Registration;
use App\Models\RegistrationDocument;
use App\Notifications\RegistrationReviewed;
use App\Notifications\RegistrationSubmitted;
use App\Support\Features;
use App\Support\Uploads;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sale and rental registrations. Dealers submit them for their own machines
 * and see only their own (Registration's dealer scope); the manufacturer's
 * reviewers approve or reject them.
 */
class RegistrationController extends Controller
{
    private const PER_PAGE = 50;

    private const MAX_DOCUMENTS = 10;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Registration::PENDING, Registration::APPROVED, Registration::REJECTED])],
            'machine' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Registration::with(['machine.productModel', 'dealerLink.dealer', 'submitter'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['machine'] ?? null, fn ($q, $m) => $q->where('machine_id', $m))
            // Oldest waiting first in the queue; newest first otherwise.
            ->when(($filters['status'] ?? null) === Registration::PENDING, fn ($q) => $q->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Registration $r) => self::row($r)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function show(int $registration): JsonResponse
    {
        $r = Registration::with(['machine.productModel.productLine', 'dealerLink.dealer', 'submitter', 'reviewer', 'documents'])->findOrFail($registration);

        return response()->json(self::detail($r));
    }

    public function store(Request $request, Uploads $uploads): JsonResponse
    {
        $data = $request->validate([
            'machine_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(Registration::TYPES)],
            'delivered_on' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'customer' => ['required', 'array'],
            'customer.company' => ['nullable', 'string', 'max:191'],
            'customer.contact_name' => ['required', 'string', 'max:191'],
            // A way to reach them: email, phone, or both.
            'customer.email' => ['nullable', 'required_without:customer.phone', 'email', 'max:191'],
            'customer.phone' => ['nullable', 'required_without:customer.email', 'string', 'max:40'],
            'customer.address' => ['nullable', 'string', 'max:191'],
            'customer.city' => ['nullable', 'string', 'max:120'],
            'customer.region' => ['nullable', 'string', 'max:120'],
            'customer.postal_code' => ['nullable', 'string', 'max:20'],
            'customer.country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:'.self::MAX_DOCUMENTS],
            'documents.*.kind' => ['required', Rule::in(RegistrationDocument::KINDS)],
            'documents.*.file' => ['required', ...Uploads::rule()],
        ], [
            'customer.email.required_without' => 'Give an email or a phone number so the customer can be reached.',
            'customer.phone.required_without' => 'Give an email or a phone number so the customer can be reached.',
            'delivered_on.before_or_equal' => 'The delivery date can\'t be in the future.',
            'documents.*.file.mimes' => 'Upload a PDF, JPG or PNG.',
        ], [
            // Names people read in error messages, not the field keys.
            'delivered_on' => 'delivery date',
            'customer.company' => 'company',
            'customer.contact_name' => 'contact name',
            'customer.email' => 'email',
            'customer.phone' => 'phone',
            'customer.address' => 'street address',
            'customer.city' => 'city',
            'customer.region' => 'state or province',
            'customer.postal_code' => 'postal code',
            'customer.country' => 'country',
            'documents.*.file' => 'file',
            'documents.*.kind' => 'document type',
        ]);

        // A dealer only finds their own machines (Machine's dealer scope) - another's is "not found".
        $machine = Machine::findOrFail($data['machine_id']);
        $documents = $data['documents'] ?? [];
        $this->assertCanRegister($machine, $data['type'], $documents);

        // Check every file before storing any.
        $files = collect($documents)->mapWithKeys(fn ($d, $i) => ["documents.$i.file" => $d['file']])->all();
        $scans = $uploads->check($files);

        $stored = [];
        try {
            $registration = DB::transaction(function () use ($data, $machine, $documents, $scans, $uploads, $request, &$stored) {
                $registration = Registration::create([
                    'machine_id' => $machine->id,
                    'dealer_link_id' => $machine->dealer_link_id,
                    'type' => $data['type'],
                    'delivered_on' => $data['delivered_on'],
                    'customer' => array_filter($data['customer'], fn ($v) => $v !== null && $v !== ''),
                    'notes' => $data['notes'] ?? null,
                    'submitted_by' => $request->user()->id,
                ]);

                foreach ($documents as $i => $doc) {
                    $meta = $uploads->put($doc['file'], 'registrations/'.$registration->id, $scans["documents.$i.file"]);
                    $stored[] = $meta['path'];
                    $registration->documents()->create($meta + ['kind' => $doc['kind'], 'uploaded_by' => $request->user()->id]);
                }

                return $registration;
            });
        } catch (\Throwable $e) {
            Uploads::forget($stored);
            throw $e;
        }

        $registration->load(['machine.productModel.productLine', 'dealerLink.dealer', 'submitter', 'reviewer', 'documents', 'manufacturer']);
        $this->notifyReviewers($registration, $request->user()->id);

        return response()->json(self::detail($registration), 201);
    }

    public function approve(Request $request, int $registration): JsonResponse
    {
        $r = Registration::findOrFail($registration);
        $r->approve($request->user());

        return $this->afterReview($r);
    }

    public function reject(Request $request, int $registration): JsonResponse
    {
        $r = Registration::findOrFail($registration);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], [
            'reason.required' => 'Tell the dealer what needs fixing, so they can register it again.',
        ]);
        $r->reject($request->user(), $data['reason']);

        return $this->afterReview($r);
    }

    /**
     * One document, sent through here so the access checks apply: the
     * registration must be visible (tenant and dealer scopes), and the file
     * always downloads - it's never shown inline in the browser.
     */
    public function document(int $registration, int $document): StreamedResponse
    {
        $doc = Registration::findOrFail($registration)->documents()->findOrFail($document);
        Uploads::abortIfMissing($doc->path);

        return Uploads::disk()->download($doc->path, $doc->original_name, [
            'Content-Type' => $doc->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function assertCanRegister(Machine $machine, string $type, array $documents): void
    {
        if ($machine->dealer_link_id === null) {
            throw ValidationException::withMessages(['machine_id' => 'This machine is still at the factory. Move it to the dealer that sold it first.']);
        }
        if ($machine->status === 'sold') {
            throw ValidationException::withMessages(['machine_id' => 'This machine is already registered as sold. If it\'s been traded back in, set its status to used first.']);
        }
        if (Registration::where('machine_id', $machine->id)->where('status', Registration::PENDING)->exists()) {
            throw ValidationException::withMessages(['machine_id' => 'This machine already has a registration waiting for review.']);
        }
        // A sale needs its bill of sale - the record of who bought it.
        if ($type === 'sale' && ! collect($documents)->contains(fn ($d) => $d['kind'] === 'bill_of_sale')) {
            throw ValidationException::withMessages(['documents' => 'Attach the bill of sale.']);
        }
    }

    private function afterReview(Registration $r): JsonResponse
    {
        $r->load(['machine.productModel.productLine', 'dealerLink.dealer', 'submitter', 'reviewer', 'documents', 'manufacturer']);

        if ($r->submitter && $r->submitter->id !== (int) $r->reviewed_by) {
            // A mail problem is logged, never undoes the review.
            rescue(fn () => $r->submitter->notify(new RegistrationReviewed($r)), report: true);
        }

        return response()->json(self::detail($r));
    }

    /** Everyone at the manufacturer who can review registrations, except whoever submitted it. */
    private function notifyReviewers(Registration $r, int $exceptUserId): void
    {
        $manufacturer = Tenant::current()->manufacturer();
        $reviewers = $manufacturer->staff()->with('user')->get()
            ->filter(fn (Membership $m) => (int) $m->user_id !== $exceptUserId && Features::allows($manufacturer, $m, 'review_registrations'))
            ->map(fn (Membership $m) => $m->user);

        if ($reviewers->isNotEmpty()) {
            rescue(fn () => Notification::send($reviewers, new RegistrationSubmitted($r)), report: true);
        }
    }

    private static function customerName(array $customer): string
    {
        return trim(($customer['company'] ?? '') !== '' ? $customer['company'].' ('.$customer['contact_name'].')' : $customer['contact_name']);
    }

    private static function row(Registration $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'delivered_on' => $r->delivered_on->toDateString(),
            'machine' => ['id' => $r->machine->id, 'serial_no' => $r->machine->serial_no, 'model' => $r->machine->productModel->name],
            'dealer' => $r->dealerLink->dealer->name,
            'customer' => self::customerName($r->customer),
            'submitted_by' => $r->submitter?->name,
            'submitted_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private static function detail(Registration $r): array
    {
        return self::row($r) + [
            'product_line' => $r->machine->productModel->productLine->name,
            'customer_details' => $r->customer,
            'notes' => $r->notes,
            'reviewed_by' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'rejection_reason' => $r->rejection_reason,
            'documents' => $r->documents->map(fn (RegistrationDocument $d) => [
                'id' => $d->id,
                'kind' => $d->kind,
                'name' => $d->original_name,
                'size' => $d->size,
                'scan_status' => $d->scan_status,
            ])->values(),
        ];
    }
}
