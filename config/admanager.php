<?php

return [
    'network_code' => env('ADMANAGER_NETWORK_CODE'),

    // Absolute path to the service-account JSON key (keep it out of the repo).
    'credentials' => env('ADMANAGER_CREDENTIALS'),

    'currency' => env('ADMANAGER_CURRENCY', 'EUR'),
    'time_zone' => env('ADMANAGER_TIME_ZONE', 'Europe/Brussels'),
];
