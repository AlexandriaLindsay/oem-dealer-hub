<?php

namespace App\Support\Scanning;

/**
 * Sends a file to a ClamAV daemon (clamd) over TCP with its INSTREAM command:
 * "zINSTREAM\0", then the file in chunks (each prefixed by its length as a
 * 4-byte big-endian number), then a zero-length chunk. clamd answers
 * "stream: OK" or "stream: <name> FOUND".
 *
 * Anything else - can't connect, timeout, an ERROR reply, the file over
 * clamd's StreamMaxLength - throws ScannerUnavailable, so the file is never
 * stored unchecked.
 */
final class ClamAvScanner implements Scanner
{
    private const CHUNK = 64 * 1024;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout = 30,
    ) {}

    public function scan(string $path): ScanResult
    {
        // A refused connection is an answer we handle (ScannerUnavailable), not a PHP warning.
        set_error_handler(fn () => true);
        try {
            $socket = stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        } finally {
            restore_error_handler();
        }
        if (! $socket) {
            throw new ScannerUnavailable("Can't reach the virus scanner at {$this->host}:{$this->port}: {$error}");
        }

        $file = fopen($path, 'rb');

        try {
            stream_set_timeout($socket, $this->timeout);
            $this->write($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false) {
                    throw new ScannerUnavailable('Could not read the uploaded file to scan it.');
                }
                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->write($socket, pack('N', 0));

            $reply = '';
            while (! feof($socket) && ! str_contains($reply, "\0")) {
                $part = fread($socket, 4096);
                if ($part === false || ($part === '' && stream_get_meta_data($socket)['timed_out'])) {
                    throw new ScannerUnavailable('The virus scanner timed out.');
                }
                $reply .= $part;
            }

            return self::parse($reply);
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    /** clamd's reply -> a result. Public for tests. */
    public static function parse(string $reply): ScanResult
    {
        $reply = trim($reply, "\0\r\n ");

        if ($reply === 'stream: OK') {
            return new ScanResult(ScanResult::CLEAN);
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
            return new ScanResult(ScanResult::INFECTED, $m[1]);
        }

        throw new ScannerUnavailable('The virus scanner gave an unexpected answer: '.mb_substr($reply, 0, 200));
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new ScannerUnavailable('Lost the connection to the virus scanner.');
            }
            $data = substr($data, $written);
        }
    }
}
