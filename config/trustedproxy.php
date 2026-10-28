<?php

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies !== '' ? $proxies : null,
];
