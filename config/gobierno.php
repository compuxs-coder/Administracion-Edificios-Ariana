<?php

$retentionDays = json_decode((string) env('GOBIERNO_RETENCION_EVENTOS', '{}'), true);

return [
    'audit_page_size' => max(1, min(100, (int) env('GOBIERNO_AUDITORIA_PAGE_SIZE', 25))),
    'retention_days' => is_array($retentionDays) ? $retentionDays : [],
];
