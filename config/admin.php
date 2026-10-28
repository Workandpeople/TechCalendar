<?php

return [
    'mail' => env('ADMIN_MAIL'),
    'password' => env('ADMIN_PASSWORD'),
    // Set in memory only by the sealed Cofrac import; never inferred from an email collision.
    'coffrac_source_user_id' => null,
];
