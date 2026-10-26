<?php

namespace Src\Gobierno\Infrastructure\Exports;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

final class PdfAuditoriaWriter
{
    /** @param list<array<string, mixed>> $events @param array<string, mixed> $metadata */
    public function write(string $path, array $events, array $metadata): void
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setChroot(storage_path('framework'));
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml($this->html($events, $metadata), 'UTF-8');
        $dompdf->render();
        if (file_put_contents($path, $dompdf->output(), LOCK_EX) === false) {
            throw new RuntimeException('No fue posible escribir la exportación PDF.');
        }
    }

    /** @param list<array<string, mixed>> $events @param array<string, mixed> $metadata */
    private function html(array $events, array $metadata): string
    {
        $rows = '';
        foreach ($events as $event) {
            $values = [
                $event['ocurridoAt'], $event['ocurridoLocal'], $event['edificio'], $event['fuenteLabel'],
                $event['tipoLabel'], $event['actor'] ?? $event['actorTipo'], $event['recursoTipo'],
                $event['recursoReferencia'] ?? 'Restringida',
            ];
            $rows .= '<tr>'.implode('', array_map(fn (mixed $value): string => '<td>'.$this->escape($value).'</td>', $values)).'</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="8">No se encontraron eventos para el alcance solicitado.</td></tr>';
        }

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>'
            .'@page{margin:24px}body{font-family:"DejaVu Sans",sans-serif;color:#172033;font-size:8px}'
            .'h1{font-size:16px;margin:0 0 5px}.meta{color:#526078;margin:0 0 14px}'
            .'table{width:100%;border-collapse:collapse}th{background:#172033;color:#fff;text-align:left}'
            .'th,td{border:1px solid #ccd3dd;padding:4px;vertical-align:top;word-break:break-word}'
            .'tr:nth-child(even) td{background:#f3f5f8}</style></head><body>'
            .'<h1>Centro de Auditoría · Exportación</h1><p class="meta">Rango UTC: '
            .$this->escape($metadata['from']).' a '.$this->escape($metadata['until'])
            .' · Corte: '.$this->escape($metadata['cutoff']).' · Eventos: '.count($events).'</p>'
            .'<table><thead><tr><th>Fecha UTC</th><th>Fecha local</th><th>Edificio</th><th>Fuente</th>'
            .'<th>Evento</th><th>Actor</th><th>Recurso</th><th>Referencia</th></tr></thead><tbody>'
            .$rows.'</tbody></table></body></html>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
