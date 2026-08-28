<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Carbon\CarbonImmutable;
use Src\Finanzas\Application\Actions\AutomaticCargosAction;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('finanzas:generar-cargos
    {--edificio= : UUID del edificio}
    {--periodo= : Período YYYY-MM; por defecto el mes actual}
    {--concepto= : UUID del concepto}
    {--dry-run : Previsualiza sin persistir cargos ni lotes}', function (AutomaticCargosAction $generate): int {
    $periodo = (string) ($this->option('periodo') ?: CarbonImmutable::today()->format('Y-m'));
    $resultados = $generate->execute(
        $periodo,
        $this->option('edificio') ?: null,
        $this->option('concepto') ?: null,
        (bool) $this->option('dry-run'),
    );
    $this->table(['Edificio', 'Creados', 'Omitidos', 'Total', 'Modo'], collect($resultados)->map(static fn (array $result): array => [
        $result['edificioId'],
        $result['cargosCreados'] ?? $result['cantidadCargos'],
        $result['cargosOmitidos'] ?? $result['cantidadOmitidos'],
        $result['totalValor'],
        $result['dryRun'] ? 'dry-run' : 'generado',
    ])->all());

    return 0;
})->purpose('Genera cargos automáticos configurados para un período.');

Schedule::command('finanzas:generar-cargos')
    ->dailyAt('01:10')
    ->withoutOverlapping();
