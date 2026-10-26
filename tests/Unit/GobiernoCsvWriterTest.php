<?php

namespace Tests\Unit;

use Src\Gobierno\Infrastructure\Exports\CsvAuditoriaWriter;
use Tests\TestCase;

final class GobiernoCsvWriterTest extends TestCase
{
    public function test_writer_neutralizes_formula_prefixes_and_streams_fifty_thousand_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-load-');
        $this->assertNotFalse($path);
        $stream = fopen($path, 'wb');
        $this->assertIsResource($stream);
        $writer = app(CsvAuditoriaWriter::class);
        $writer->header($stream);
        $memory = memory_get_usage(true);
        $prefixes = ['=1+1', '+SUM(1,1)', '-2+3', '@cmd', "\t=1", "\r=1", "\n=1", '  =1+1'];
        for ($index = 0; $index < 50000; $index++) {
            $writer->row($stream, [
                'ocurridoAt' => '2026-10-22T15:00:00Z',
                'ocurridoLocal' => '2026-10-22 10:00:00 -05',
                'edificio' => $prefixes[$index % count($prefixes)],
                'fuenteLabel' => 'Accesos',
                'tipoLabel' => 'Membresía creada',
                'actorTipo' => 'usuario',
                'actor' => '=HYPERLINK("https://invalid.test")',
                'recursoTipo' => 'Membresía',
                'recursoReferencia' => '+123',
                'detalleRestringido' => false,
                'retencionDias' => 365,
            ]);
        }
        fclose($stream);

        $this->assertLessThan(8 * 1024 * 1024, memory_get_usage(true) - $memory);
        $this->assertGreaterThan(5 * 1024 * 1024, filesize($path));
        $sample = file_get_contents($path, false, null, 0, 4096);
        $this->assertStringContainsString("'=1+1", $sample);
        $this->assertStringContainsString("'+SUM(1,1)", $sample);
        $this->assertStringContainsString("'@cmd", $sample);
        unlink($path);
    }
}
