<?php

namespace App\Support\Scanning;

use RuntimeException;

/** The scanner couldn't give an answer (down, timed out, errored). Treated as "don't store it". */
class ScannerUnavailable extends RuntimeException {}
