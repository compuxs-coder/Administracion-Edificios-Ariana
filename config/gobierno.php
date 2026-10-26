<?php

$retentionDays = json_decode((string) env('GOBIERNO_RETENCION_EVENTOS', '{}'), true);
$exportLeaseSeconds = max(660, min(1800, (int) env('GOBIERNO_EXPORT_LEASE_SECONDS', 900)));

return [
    'audit_page_size' => max(1, min(100, (int) env('GOBIERNO_AUDITORIA_PAGE_SIZE', 25))),
    'retention_days' => is_array($retentionDays) ? $retentionDays : [],
    'exports' => [
        'disk' => env('GOBIERNO_EXPORT_DISK', 'audit-exports'),
        'queue_connection' => env('GOBIERNO_EXPORT_QUEUE_CONNECTION', 'database-exports'),
        'queue' => env('GOBIERNO_EXPORT_QUEUE', 'gobierno-exportaciones'),
        'ttl_hours' => max(1, min(24, (int) env('GOBIERNO_EXPORT_TTL_HOURS', 24))),
        'max_range_days' => max(1, min(90, (int) env('GOBIERNO_EXPORT_MAX_RANGE_DAYS', 90))),
        'chunk_size' => max(100, min(5000, (int) env('GOBIERNO_EXPORT_CHUNK_SIZE', 1000))),
        'csv_max_rows' => max(1, min(50000, (int) env('GOBIERNO_EXPORT_CSV_MAX_ROWS', 50000))),
        'pdf_max_rows' => max(1, min(2000, (int) env('GOBIERNO_EXPORT_PDF_MAX_ROWS', 2000))),
        'max_active_per_user' => max(1, min(10, (int) env('GOBIERNO_EXPORT_MAX_ACTIVE_PER_USER', 2))),
        'max_daily_per_user' => max(1, min(100, (int) env('GOBIERNO_EXPORT_MAX_DAILY_PER_USER', 10))),
        'lease_seconds' => $exportLeaseSeconds,
        'orphan_grace_seconds' => max($exportLeaseSeconds, min(7200, (int) env('GOBIERNO_EXPORT_ORPHAN_GRACE_SECONDS', 1800))),
        'temp_max_age_seconds' => max(900, min(86400, (int) env('GOBIERNO_EXPORT_TEMP_MAX_AGE_SECONDS', 3600))),
        'recent_scan_limit' => max(50, min(1000, (int) env('GOBIERNO_EXPORT_RECENT_SCAN_LIMIT', 500))),
        'max_attempts' => max(1, min(5, (int) env('GOBIERNO_EXPORT_MAX_ATTEMPTS', 3))),
    ],
];
