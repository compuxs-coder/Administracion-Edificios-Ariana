<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Carbon\CarbonImmutable;
use Src\Finanzas\Application\Actions\AutomaticCargosAction;
use Src\Operaciones\Application\Actions\GeneratePreventiveMaintenanceOrdersAction;

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

Artisan::command('operaciones:generar-mantenimiento-preventivo
    {--edificio= : UUID del edificio}
    {--fecha= : Fecha operativa YYYY-MM-DD; por defecto hoy}
    {--limite=100 : Máximo de ocurrencias por lote}
    {--dry-run : Previsualiza el lote sin persistir}', function (GeneratePreventiveMaintenanceOrdersAction $generate): int {
    $dateInput = (string) ($this->option('fecha') ?: CarbonImmutable::today()->format('Y-m-d'));
    try {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateInput);
    } catch (Throwable) {
        $date = null;
    }
    if ($date === null || $date->format('Y-m-d') !== $dateInput) {
        $this->error('La fecha operativa debe ser una fecha válida con formato YYYY-MM-DD.');

        return 1;
    }
    $buildingId = $this->option('edificio') ?: null;
    if ($buildingId !== null && ! Str::isUuid((string) $buildingId)) {
        $this->error('El edificio debe ser un UUID válido.');

        return 1;
    }
    $result = $generate->execute(
        $date,
        $buildingId,
        (int) $this->option('limite'),
        (bool) $this->option('dry-run'),
    );
    $this->table(['Fecha', 'Procesadas', 'Generadas', 'Bloqueadas', 'Reutilizadas', 'Límite', 'Modo'], [[
        $date->format('Y-m-d'),
        $result['procesadas'],
        $result['generadas'],
        $result['bloqueadas'],
        $result['reutilizadas'],
        $result['limiteAlcanzado'] ? 'sí' : 'no',
        $result['dryRun'] ? 'dry-run' : 'generado',
    ]]);

    return 0;
})->purpose('Genera órdenes para vencimientos de mantenimiento preventivo.');

Schedule::command('operaciones:generar-mantenimiento-preventivo --limite=100')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
