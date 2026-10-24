<?php

return [
    // This does not enable a production import: the target and environment are checked separately.
    'offline_rehearsal' => (bool) env('COFFRAC_OFFLINE_REHEARSAL', false),
];
