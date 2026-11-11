<?php

namespace App\Support\Scanning;

/**
 * Local development only: nothing is scanned, and files are marked so.
 * AppServiceProvider refuses to boot with this in production.
 */
final class NoScanner implements Scanner
{
    public function scan(string $path): ScanResult
    {
        return new ScanResult(ScanResult::NOT_SCANNED);
    }
}
