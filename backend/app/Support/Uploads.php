<?php

namespace App\Support;

use App\Models\RegistrationDocument;
use App\Support\Scanning\Scanner;
use App\Support\Scanning\ScannerUnavailable;
use App\Support\Scanning\ScanResult;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every upload goes through here: fits the plan's storage, scanned for
 * malware, then stored on the documents disk under a generated name.
 *
 * Two steps, so a batch is all-or-nothing: check() every file first (nothing
 * stored yet), then put() them. If saving the database rows fails after
 * that, forget() removes the stored files again.
 */
final class Uploads
{
    public function __construct(private readonly Scanner $scanner) {}

    public static function disk(): Filesystem
    {
        return Storage::disk(config('dealerdock.documents.disk'));
    }

    /** The validation rule for one uploaded document. */
    public static function rule(): array
    {
        return ['file', 'mimes:'.implode(',', config('dealerdock.documents.mimes')), 'max:'.config('dealerdock.documents.max_kb')];
    }

    /** Bytes stored by the current manufacturer (all its documents). */
    public static function usedBytes(): int
    {
        return (int) RegistrationDocument::sum('size');
    }

    /**
     * Checks a batch of files - each keyed by its form field, so errors land
     * under the right input - against the storage limit and the scanner.
     *
     * @param  array<string, UploadedFile>  $files
     * @return array<string, ScanResult>
     */
    public function check(array $files): array
    {
        $manufacturer = Tenant::current()->manufacturer();
        $limitGb = Plan::limit($manufacturer, 'storage_gb');
        $adding = array_sum(array_map(fn (UploadedFile $f) => $f->getSize(), $files));

        if ($limitGb !== null && self::usedBytes() + $adding > $limitGb * 1024 ** 3) {
            throw ValidationException::withMessages([
                array_key_first($files) => 'This would go over your plan\'s '.$limitGb.' GB of file storage.'.(Plan::effective($manufacturer) === Plan::STARTER ? ' Pro includes 100 GB.' : ''),
            ]);
        }

        $results = [];
        foreach ($files as $field => $file) {
            try {
                $result = $this->scanner->scan($file->getRealPath());
            } catch (ScannerUnavailable $e) {
                report($e);
                throw ValidationException::withMessages([
                    $field => 'We couldn\'t check this file for viruses just now, so it wasn\'t uploaded. Please try again in a few minutes.',
                ]);
            }

            if ($result->infected()) {
                // Logged for the security record - which manufacturer, what was found.
                logger()->warning('Upload blocked: malware found', [
                    'manufacturer_id' => $manufacturer->id,
                    'signature' => $result->signature,
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                ]);
                throw ValidationException::withMessages([
                    $field => 'This file was blocked because our virus scan flagged it ('.$result->signature.'). If you think that\'s wrong, contact your manufacturer.',
                ]);
            }

            $results[$field] = $result;
        }

        return $results;
    }

    /**
     * Stores a checked file under "{manufacturer id}/{folder}/{random}.{ext}" -
     * the person's file name is never part of the path.
     *
     * @return array{path: string, original_name: string, mime: string, size: int, sha256: string, scan_status: string}
     */
    public function put(UploadedFile $file, string $folder, ScanResult $scan): array
    {
        // The extension from the content, not the name the person gave it.
        $ext = $file->guessExtension() ?: 'bin';
        $name = Str::uuid().'.'.$ext;
        $directory = Tenant::current()->id().'/'.$folder;

        self::disk()->putFileAs($directory, $file, $name);

        return [
            'path' => $directory.'/'.$name,
            'original_name' => self::cleanName($file->getClientOriginalName()),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => $scan->status,
        ];
    }

    /** Removes stored files again (a save that failed after they were stored). */
    public static function forget(array $paths): void
    {
        foreach ($paths as $path) {
            rescue(fn () => self::disk()->delete($path), report: true);
        }
    }

    /** A display name: no folders, no control characters, not absurdly long. */
    private static function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?? '';

        return mb_substr(trim($name), 0, 200) ?: 'document';
    }
}
