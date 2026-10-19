<?php

return [
    'document_disk' => env('COMUNICACION_DOCUMENT_DISK', 'communications'),
    'document_legacy_disk' => env('COMUNICACION_DOCUMENT_LEGACY_DISK'),
    'document_link_minutes' => (int) env('COMUNICACION_DOCUMENT_LINK_MINUTES', 43200),
    'max_document_bytes' => 10 * 1024 * 1024,
    'max_total_document_bytes' => 10 * 1024 * 1024,
    'max_documents' => 10,
    'trace_page_size' => 250,
    'max_delivery_attempts' => 3,
    'delivery_lease_seconds' => 300,
];
