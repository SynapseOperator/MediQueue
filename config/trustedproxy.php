<?php

return [
    // Development port forwarding terminates HTTPS outside the container.
    // Enable only for the isolated demo, never implicitly for ordinary deployments.
    'proxies' => env('APP_ENV') === 'demo' && env('MEDIQUEUE_TRUST_DEMO_PROXY', false) ? '*' : null,
];
