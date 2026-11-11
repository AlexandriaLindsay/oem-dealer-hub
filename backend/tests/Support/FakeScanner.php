<?php

namespace Tests\Support;

use App\Support\Scanning\Scanner;
use App\Support\Scanning\ScannerUnavailable;
use App\Support\Scanning\ScanResult;

/** Flags any file containing "EICAR" as infected; down() makes it unreachable. */
class FakeScanner implements Scanner
{
    public int $scanned = 0;

    private bool $down = false;

    public function down(): self
    {
        $this->down = true;

        return $this;
    }

    public function scan(string $path): ScanResult
    {
        if ($this->down) {
            throw new ScannerUnavailable('Fake scanner is down.');
        }
        $this->scanned++;

        return str_contains((string) file_get_contents($path), 'EICAR')
            ? new ScanResult(ScanResult::INFECTED, 'Eicar-Test-Signature')
            : new ScanResult(ScanResult::CLEAN);
    }
}
