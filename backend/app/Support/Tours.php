<?php

namespace App\Support;

/**
 * The guided tours the app knows (frontend: src/components/Tour.tsx).
 *
 * Keys are versioned: a big change to a tour, or a "What's new" tour for a new
 * feature, gets a new key, so people who saw the old one see the new one once.
 */
final class Tours
{
    public const KNOWN = [
        'manufacturer-v1',
        'dealer-v1',
    ];
}
