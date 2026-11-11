<?php

namespace App\Support\Scanning;

/**
 * Checks an uploaded file for malware before it's stored (docs/PLAN.md
 * section 4). Bound in AppServiceProvider from config('dealerdock.scanner').
 */
interface Scanner
{
    /** @throws ScannerUnavailable when the file couldn't be checked - the caller must not store it. */
    public function scan(string $path): ScanResult;
}
