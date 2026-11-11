<?php

return [
    // Everyone sets up two-factor sign-in before they can reach any
    // manufacturer's data (docs/PLAN.md section 4).
    'require_two_factor' => env('DEALERDOCK_REQUIRE_TWO_FACTOR', true),

    // Shown in the authenticator app next to the account.
    'two_factor_issuer' => env('DEALERDOCK_TWO_FACTOR_ISSUER', 'DealerDock'),
];
