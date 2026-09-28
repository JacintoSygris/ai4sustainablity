<?php

$raw = (string) env('TRUSTED_PROXIES', '');

return [
    // Never use '*' on a public service. Container profiles may use
    // REMOTE_ADDR only when the application port is private to the edge proxy.
    'proxies' => array_values(array_filter(
        array_map('trim', explode(',', $raw)),
        static fn (string $proxy): bool => $proxy !== '',
    )),
];
