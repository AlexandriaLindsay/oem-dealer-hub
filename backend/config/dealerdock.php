<?php

return [
    // Everyone sets up two-factor sign-in before they can reach any
    // manufacturer's data (docs/PLAN.md section 4).
    'require_two_factor' => env('DEALERDOCK_REQUIRE_TWO_FACTOR', true),

    // Shown in the authenticator app next to the account.
    'two_factor_issuer' => env('DEALERDOCK_TWO_FACTOR_ISSUER', 'DealerDock'),

    /*
     * Uploaded documents (bills of sale, PDI checklists, later manuals).
     *
     * The disk is never the web root: files are only ever sent through the
     * API, after its access checks. Locally it's a private folder; in
     * production it's object storage (Azure Blob), so the app servers stay
     * stateless and slot swaps don't lose files.
     */
    'documents' => [
        'disk' => env('DEALERDOCK_DOCUMENTS_DISK', 'documents'),
        'max_kb' => (int) env('DEALERDOCK_UPLOAD_MAX_KB', 20 * 1024),
        // Manuals and parts books run bigger. Raising this also needs PHP's
        // upload_max_filesize and post_max_size, and clamd's StreamMaxLength
        // (25 MB by default - set it to match, or big files can't be scanned).
        'library_max_kb' => (int) env('DEALERDOCK_LIBRARY_MAX_KB', 40 * 1024),
        // What a bill of sale or checklist realistically is. No Office files
        // (macros) and no HTML/SVG (scripts).
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
     * Malware scanning for every upload (docs/PLAN.md section 4).
     *
     * - clamav: send each file to a ClamAV daemon (clamd) before storing it.
     *   If clamd can't be reached, the upload is refused - never stored
     *   unscanned.
     * - none: no scanning - for local development only. Files are marked
     *   "not scanned", and the app refuses to boot with it in production.
     */
    'scanner' => [
        'driver' => env('DEALERDOCK_SCANNER', 'none'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        ],
    ],
];
