<?php

namespace Src\Gobierno\Infrastructure\Exports;

use RuntimeException;

final class CsvAuditoriaWriter
{
    /** @param resource $stream */
    public function header($stream): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        $this->write($stream, [
            'Fecha UTC', 'Fecha local', 'Edificio', 'Fuente', 'Evento', 'Actor tipo',
            'Actor', 'Recurso', 'Referencia', 'Detalle restringido', 'Retención (días)',
        ]);
    }

    /** @param resource $stream @param array<string, mixed> $event */
    public function row($stream, array $event): void
    {
        $this->write($stream, [
            $event['ocurridoAt'], $event['ocurridoLocal'], $event['edificio'], $event['fuenteLabel'],
            $event['tipoLabel'], $event['actorTipo'], $event['actor'], $event['recursoTipo'],
            $event['recursoReferencia'], $event['detalleRestringido'] ? 'Sí' : 'No', $event['retencionDias'],
        ]);
    }

    /** @param resource $stream @param list<mixed> $values */
    private function write($stream, array $values): void
    {
        $safe = array_map(fn (mixed $value): string => $this->safeCell($value), $values);
        if (fputcsv($stream, $safe, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('No fue posible escribir la exportación CSV.');
        }
    }

    private function safeCell(mixed $value): string
    {
        $cell = $value === null ? '' : (string) $value;
        if (preg_match('/^[\t\r\n]/u', $cell) === 1 || preg_match('/^\s*[=+\-@]/u', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }
}
