<?php

return [
    'hospital_timezone' => 'Asia/Shanghai',
    'availability_days' => 7,
    'payment_channel' => 'MOCK_WECHAT',
    // Mock events require both an explicit switch and a non-production demonstration environment.
    'mock_payments_enabled' => env('MOCK_PAYMENTS_ENABLED', false)
        && in_array(env('APP_ENV', 'production'), ['local', 'demo', 'testing'], true),
];
