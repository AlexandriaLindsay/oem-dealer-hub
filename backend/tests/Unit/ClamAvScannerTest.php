<?php

namespace Tests\Unit;

use App\Support\Scanning\ClamAvScanner;
use App\Support\Scanning\ScannerUnavailable;
use App\Support\Scanning\ScanResult;
use PHPUnit\Framework\TestCase;

/** The clamd INSTREAM protocol, against a stand-in clamd (tests/Support/fake-clamd.php). */
class ClamAvScannerTest extends TestCase
{
    public function test_a_clean_file_is_clean(): void
    {
        $result = $this->scanWithFakeClamd('Just a bill of sale. '.str_repeat('x', 200_000));

        $this->assertSame(ScanResult::CLEAN, $result->status);
    }

    public function test_malware_is_reported_with_its_name(): void
    {
        // Spread over more than one 64 KB chunk, so chunking is exercised too.
        $result = $this->scanWithFakeClamd(str_repeat('y', 70_000).'EICAR');

        $this->assertTrue($result->infected());
        $this->assertSame('Eicar-Signature', $result->signature);
    }

    public function test_an_unreachable_scanner_is_an_error_not_a_pass(): void
    {
        $this->expectException(ScannerUnavailable::class);

        // Port 1 on localhost: nothing listens there.
        (new ClamAvScanner('127.0.0.1', 1, 2))->scan(__FILE__);
    }

    public function test_an_unexpected_reply_is_an_error_not_a_pass(): void
    {
        $this->expectException(ScannerUnavailable::class);

        ClamAvScanner::parse("INSTREAM size limit exceeded. ERROR\0");
    }

    private function scanWithFakeClamd(string $content): ScanResult
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/../Support/fake-clamd.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $port = (int) trim(fgets($pipes[1]));
        $file = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($file, $content);

        try {
            return (new ClamAvScanner('127.0.0.1', $port, 5))->scan($file);
        } finally {
            unlink($file);
            proc_close($process);
        }
    }
}
