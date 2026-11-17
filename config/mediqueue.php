<?php

return [
    'hospital_timezone' => 'Asia/Shanghai',
    'availability_days' => 7,
    'payment_channel' => 'MOCK_WECHAT',
    // P0 establishes the configuration boundary; payment endpoints arrive in P4.
    'mock_payments_enabled' => env('MOCK_PAYMENTS_ENABLED', false)
        && in_array(env('APP_ENV', 'production'), ['local', 'demo', 'testing'], true),
];
