<?php

namespace App\Support\Scanning;

final class ScanResult
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    /** No scanner configured (local development only). */
    public const NOT_SCANNED = 'not_scanned';

    public function __construct(
        public readonly string $status,
        /** The malware's name when infected, e.g. "Eicar-Signature". */
        public readonly ?string $signature = null,
    ) {}

    public function infected(): bool
    {
        return $this->status === self::INFECTED;
    }
}
