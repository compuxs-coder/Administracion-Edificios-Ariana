<?php

namespace Src\Gobierno\Domain\Enums;

enum FormatoExportacionAuditoria: string
{
    case CSV = 'csv';
    case PDF = 'pdf';
}
