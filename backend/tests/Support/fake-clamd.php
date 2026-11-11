<?php

/*
 * A one-connection stand-in for clamd, for testing ClamAvScanner's INSTREAM
 * protocol end to end. Prints the port it listens on, takes one stream,
 * replies like clamd, and exits.
 */

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT).PHP_EOL;
fflush(STDOUT);

$conn = stream_socket_accept($server, 10);
$read = function (int $n) use ($conn): string {
    $data = '';
    while (strlen($data) < $n && ! feof($conn)) {
        $data .= fread($conn, $n - strlen($data));
    }

    return $data;
};

$command = $read(10);
if ($command !== "zINSTREAM\0") {
    fwrite($conn, "UNKNOWN COMMAND\0");
    exit(1);
}

$body = '';
while (true) {
    $length = unpack('N', $read(4))[1];
    if ($length === 0) {
        break;
    }
    $body .= $read($length);
}

fwrite($conn, str_contains($body, 'EICAR') ? "stream: Eicar-Signature FOUND\0" : "stream: OK\0");
fclose($conn);
