<?php

namespace Src\Operaciones\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Operaciones\Domain\Contracts\EstructuraMiembrosOperacionesReadInterface;
use Src\Operaciones\Domain\Contracts\MantenimientoPreventivoRepositoryInterface;
use Src\Operaciones\Domain\Contracts\OrdenOperativaRepositoryInterface;
use Src\Operaciones\Domain\Contracts\ProveedoresOperacionesReadInterface;
use Src\Operaciones\Domain\Enums\EstadoOcurrenciaMantenimientoPreventivo;
use Src\Operaciones\Domain\Enums\EstadoPlanMantenimientoPreventivo;
use Src\Operaciones\Domain\Enums\TipoActorOperativo;
use Src\Operaciones\Domain\Enums\TipoEventoPlanMantenimiento;
use Src\Operaciones\Infrastructure\Models\BitacoraPlanMantenimientoEloquentModel;
use Src\Operaciones\Infrastructure\Models\OcurrenciaMantenimientoPreventivoEloquentModel;
use Src\Operaciones\Infrastructure\Models\PlanMantenimientoPreventivoEloquentModel;

final class EloquentMantenimientoPreventivoRepository implements MantenimientoPreventivoRepositoryInterface
{
    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly EstructuraMiembrosOperacionesReadInterface $structure,
        private readonly ProveedoresOperacionesReadInterface $suppliers,
        private readonly OrdenOperativaRepositoryInterface $orders,
    ) {}

    public function paginateForUser(string $userId, array $filters): array
    {
        $query = PlanMantenimientoPreventivoEloquentModel::query()
            ->whereIn('edificio_id', $this->access->buildingIds($userId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER))
            ->withCount(['ocurrencias as ocurrencias_bloqueadas_count' => static fn (Builder $query) => $query->where('estado', EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA)])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $state) => $query->where('estado', $state))
            ->when($filters['unidad_recurrencia'] ?? null, static fn (Builder $query, string $unit) => $query->where('unidad_recurrencia', $unit))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $nested) => $nested
                    ->whereRaw('LOWER(codigo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(titulo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', [$term]));
            })
            ->orderByRaw("CASE WHEN estado = 'activo' THEN 0 ELSE 1 END")
            ->orderBy('proxima_fecha_programada')
            ->orderBy('codigo');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'items' => $paginator->getCollection()->map(fn (PlanMantenimientoPreventivoEloquentModel $plan): array => $this->serializeSummary($plan))->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function get(string $userId, string $edificioId, string $planId): array
    {
        $building = $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER);
        $plan = PlanMantenimientoPreventivoEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->with([
                'ocurrencias' => static fn ($query) => $query->with('orden')->orderByDesc('fecha_programada')->orderByDesc('id'),
                'bitacora' => static fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            ])
            ->findOrFail($planId);
        $result = $this->serialize($plan);
        $result['edificio'] = $building->nombre;
        $location = $this->structure->location($edificioId, $plan->getAttributes());
        $result['ubicacion'] = [
            'tipo' => $location['tipo'] ?? null,
            'id' => $location['id'] ?? null,
            'etiqueta' => $location['etiqueta'] ?? null,
            'detalle' => $plan->ubicacion_detalle,
        ];
        $result['proveedor'] = $plan->proveedor_id === null ? null : $this->suppliers->provider($edificioId, $plan->proveedor_id);
        $result['contrato'] = $plan->contrato_id === null || $plan->proveedor_id === null
            ? null
            : $this->suppliers->contract($edificioId, $plan->proveedor_id, $plan->contrato_id);
        $actorIds = $plan->bitacora->pluck('actor_user_id')->filter()->unique()->values()->all();
        $actorNames = $this->structure->memberNames($edificioId, $actorIds);
        $result['ocurrencias'] = $plan->ocurrencias->map(static fn (OcurrenciaMantenimientoPreventivoEloquentModel $occurrence): array => [
            'id' => $occurrence->id,
            'fechaProgramada' => $occurrence->fecha_programada?->format('Y-m-d'),
            'estado' => $occurrence->estado->value,
            'motivo' => $occurrence->motivo,
            'procesadaAt' => $occurrence->procesada_at?->toIso8601String(),
            'orden' => $occurrence->orden === null ? null : [
                'id' => $occurrence->orden->id,
                'numero' => $occurrence->orden->numero,
                'estado' => $occurrence->orden->estado->value,
            ],
        ])->all();
        $result['bitacora'] = $plan->bitacora->map(static fn (BitacoraPlanMantenimientoEloquentModel $entry): array => [
            'id' => $entry->id,
            'tipo' => $entry->tipo->value,
            'actorTipo' => $entry->actor_tipo->value,
            'actorNombre' => $entry->actor_tipo === TipoActorOperativo::SISTEMA
                ? 'Sistema'
                : ($actorNames[$entry->actor_user_id] ?? 'Usuario no disponible'),
            'detalle' => $entry->detalle,
            'createdAt' => $entry->created_at?->toIso8601String(),
        ])->all();

        return $result;
    }

    public function options(string $userId, PermisoEdificio $permission, ?string $edificioId = null): array
    {
        $buildingIds = $edificioId === null
            ? $this->access->buildingIds($userId, $permission)
            : [$this->authorizedBuilding($userId, $edificioId, $permission)->id];

        return [...$this->structure->options($buildingIds), ...$this->suppliers->options($buildingIds)];
    }

    public function indexOptions(string $userId): array
    {
        return ['edificios' => $this->structure->buildings(
            $this->access->buildingIds($userId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_VER),
        )];
    }

    public function create(string $userId, string $edificioId, array $data): array
    {
        return DB::transaction(function () use ($userId, $edificioId, $data): array {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR, true);
            $this->validateConfiguration($edificioId, $data, true);
            $code = mb_strtoupper(trim($data['codigo']));
            if (PlanMantenimientoPreventivoEloquentModel::query()->where('edificio_id', $edificioId)->where('codigo', $code)->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código del plan ya existe en el edificio.']);
            }
            $plan = PlanMantenimientoPreventivoEloquentModel::query()->create([
                'edificio_id' => $edificioId,
                ...$this->persistenceData($data),
                'codigo' => $code,
                'estado' => EstadoPlanMantenimientoPreventivo::INACTIVO,
                'fecha_ancla' => null,
                'secuencia_siguiente' => 0,
                'proxima_fecha_programada' => null,
                'creado_por_user_id' => $userId,
                'actualizado_por_user_id' => $userId,
            ]);
            $this->recordEvent($plan, TipoEventoPlanMantenimiento::CREACION, $userId, [
                'resumen' => 'Plan preventivo creado en estado inactivo.',
                'datos' => $this->snapshot($plan),
            ]);

            return ['id' => $plan->id, 'codigo' => $plan->codigo];
        });
    }

    public function update(string $userId, string $edificioId, string $planId, array $data): void
    {
        DB::transaction(function () use ($userId, $edificioId, $planId, $data): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_GESTIONAR, true);
            $plan = $this->lockedPlan($edificioId, $planId);
            if ($plan->estado !== EstadoPlanMantenimientoPreventivo::INACTIVO) {
                throw ValidationException::withMessages(['estado' => 'Pause el plan antes de modificar su configuración.']);
            }
            $expected = CarbonImmutable::parse($data['updated_at']);
            if ($plan->updated_at === null || ! $plan->updated_at->equalTo($expected)) {
                throw ValidationException::withMessages(['updated_at' => 'El plan cambió desde que abrió el formulario. Recargue antes de guardar.']);
            }
            $this->validateConfiguration($edificioId, $data, true, $plan->getAttributes());
            $code = mb_strtoupper(trim($data['codigo']));
            if (PlanMantenimientoPreventivoEloquentModel::query()->where('edificio_id', $edificioId)->where('codigo', $code)->whereKeyNot($plan->id)->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código del plan ya existe en el edificio.']);
            }
            $before = $this->snapshot($plan);
            $plan->fill([...$this->persistenceData($data), 'codigo' => $code, 'actualizado_por_user_id' => $userId]);
            $after = $this->snapshot($plan);
            $changes = $this->changes($before, $after);
            if ($changes === []) {
                return;
            }
            $this->advanceVersion($plan);
            $plan->save();
            $this->recordEvent($plan, TipoEventoPlanMantenimiento::CAMBIO_DATOS, $userId, ['cambios' => $changes]);
        });
    }

    public function changeStatus(string $userId, string $edificioId, string $planId, string $state, ?string $nextDate): void
    {
        DB::transaction(function () use ($userId, $edificioId, $planId, $state, $nextDate): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_PROGRAMAR, true);
            $plan = $this->lockedPlan($edificioId, $planId);
            $target = EstadoPlanMantenimientoPreventivo::tryFrom($state);
            if ($target === null || $target === $plan->estado) {
                throw ValidationException::withMessages(['estado' => 'El cambio de estado solicitado no es válido.']);
            }
            $now = CarbonImmutable::now()->startOfSecond();
            if ($plan->estado_actualizado_at !== null && $now->lessThanOrEqualTo($plan->estado_actualizado_at)) {
                $now = CarbonImmutable::instance($plan->estado_actualizado_at)->addSecond();
            }
            if ($target === EstadoPlanMantenimientoPreventivo::ACTIVO) {
                if ($nextDate === null) {
                    throw ValidationException::withMessages(['proxima_fecha_programada' => 'Indique la primera fecha programada.']);
                }
                $next = CarbonImmutable::createFromFormat('Y-m-d', $nextDate)->startOfDay();
                if ($next->lessThan(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['proxima_fecha_programada' => 'La próxima fecha no puede estar en el pasado.']);
                }
                $usedDates = OcurrenciaMantenimientoPreventivoEloquentModel::query()
                    ->where('plan_id', $plan->id)
                    ->whereDate('fecha_programada', '>=', $next->format('Y-m-d'))
                    ->lockForUpdate()
                    ->get(['fecha_programada']);
                if ($usedDates->contains(fn (OcurrenciaMantenimientoPreventivoEloquentModel $occurrence): bool => $plan->unidad_recurrencia->includesDate(
                    $next,
                    (int) $plan->intervalo_recurrencia,
                    $occurrence->fecha_programada,
                ))) {
                    throw ValidationException::withMessages(['proxima_fecha_programada' => 'La fecha indicada ya fue utilizada por este plan.']);
                }
                $this->validateDependencies($plan, $next, true);
                $plan->fill([
                    'estado' => $target,
                    'fecha_ancla' => $next,
                    'secuencia_siguiente' => 0,
                    'proxima_fecha_programada' => $next,
                    'actualizado_por_user_id' => $userId,
                    'estado_actualizado_por' => $userId,
                    'estado_actualizado_at' => $now,
                ])->save();
                $this->recordEvent($plan, TipoEventoPlanMantenimiento::ACTIVACION, $userId, [
                    'proximaFechaProgramada' => $next->format('Y-m-d'),
                ]);

                return;
            }

            $openOccurrences = OcurrenciaMantenimientoPreventivoEloquentModel::query()
                ->where('plan_id', $plan->id)
                ->whereIn('estado', [EstadoOcurrenciaMantenimientoPreventivo::PENDIENTE, EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA])
                ->lockForUpdate()
                ->get();
            foreach ($openOccurrences as $openOccurrence) {
                $openOccurrence->fill([
                    'estado' => EstadoOcurrenciaMantenimientoPreventivo::OMITIDA,
                    'motivo' => 'Ocurrencia omitida por pausa deliberada del plan.',
                    'procesada_at' => $now,
                ])->save();
                $this->recordEvent($plan, TipoEventoPlanMantenimiento::OMISION, $userId, [
                    'ocurrenciaId' => $openOccurrence->id,
                    'fechaProgramada' => $openOccurrence->fecha_programada?->format('Y-m-d'),
                    'motivo' => $openOccurrence->motivo,
                ]);
            }
            $plan->fill([
                'estado' => $target,
                'fecha_ancla' => null,
                'secuencia_siguiente' => 0,
                'proxima_fecha_programada' => null,
                'actualizado_por_user_id' => $userId,
                'estado_actualizado_por' => $userId,
                'estado_actualizado_at' => $now,
            ])->save();
            $this->recordEvent($plan, TipoEventoPlanMantenimiento::PAUSA, $userId, [
                'resumen' => 'Plan pausado; la reactivación requerirá una nueva próxima fecha.',
            ]);
        });
    }

    public function retry(string $userId, string $edificioId, string $planId, string $occurrenceId): void
    {
        $status = DB::transaction(function () use ($userId, $edificioId, $planId, $occurrenceId): ?string {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_PROGRAMAR, true);
            $plan = $this->lockedPlan($edificioId, $planId);
            $occurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()
                ->where('edificio_id', $edificioId)->where('plan_id', $planId)->lockForUpdate()->findOrFail($occurrenceId);
            if ($occurrence->estado !== EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA
                || $plan->estado !== EstadoPlanMantenimientoPreventivo::ACTIVO
                || ! $plan->proxima_fecha_programada?->equalTo($occurrence->fecha_programada)) {
                throw ValidationException::withMessages(['ocurrencia' => 'Sólo puede reintentarse la ocurrencia bloqueada vigente de un plan activo.']);
            }
            $this->recordEvent($plan, TipoEventoPlanMantenimiento::REINTENTO, $userId, [
                'ocurrenciaId' => $occurrence->id,
                'fechaProgramada' => $occurrence->fecha_programada?->format('Y-m-d'),
            ]);

            return $this->processPlan($planId, $occurrenceId, $occurrence->fecha_programada->format('Y-m-d'));
        });
        if ($status !== 'generadas') {
            $reason = OcurrenciaMantenimientoPreventivoEloquentModel::query()->find($occurrenceId)?->motivo;
            throw ValidationException::withMessages([
                'ocurrencia' => $reason ?: 'La ocurrencia no pudo generarse durante el reintento.',
            ]);
        }
    }

    public function omit(string $userId, string $edificioId, string $planId, string $occurrenceId, string $reason): void
    {
        DB::transaction(function () use ($userId, $edificioId, $planId, $occurrenceId, $reason): void {
            $this->authorizedBuilding($userId, $edificioId, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_PROGRAMAR, true);
            $plan = $this->lockedPlan($edificioId, $planId);
            $occurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()
                ->where('edificio_id', $edificioId)->where('plan_id', $planId)->lockForUpdate()->findOrFail($occurrenceId);
            if (! in_array($occurrence->estado, [EstadoOcurrenciaMantenimientoPreventivo::PENDIENTE, EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA], true)
                || ! $plan->proxima_fecha_programada?->equalTo($occurrence->fecha_programada)) {
                throw ValidationException::withMessages(['ocurrencia' => 'La ocurrencia ya no puede omitirse.']);
            }
            $reason = trim($reason);
            $occurrence->fill([
                'estado' => EstadoOcurrenciaMantenimientoPreventivo::OMITIDA,
                'motivo' => $reason,
                'procesada_at' => CarbonImmutable::now(),
            ])->save();
            $this->advanceSchedule($plan);
            $this->recordEvent($plan, TipoEventoPlanMantenimiento::OMISION, $userId, [
                'ocurrenciaId' => $occurrence->id,
                'fechaProgramada' => $occurrence->fecha_programada?->format('Y-m-d'),
                'motivo' => $reason,
            ]);
        });
    }

    public function generateDue(CarbonImmutable $date, ?string $edificioId, int $limit, bool $dryRun = false): array
    {
        $limit = max(1, min(500, $limit));
        if ($dryRun) {
            return $this->previewDue($date, $edificioId, $limit);
        }

        $result = ['procesadas' => 0, 'generadas' => 0, 'bloqueadas' => 0, 'omitidas' => 0, 'reutilizadas' => 0, 'limiteAlcanzado' => false, 'dryRun' => $dryRun];
        $blockedPlans = [];

        while ($result['procesadas'] < $limit) {
            $plans = $this->duePlans($date, $edificioId, $blockedPlans);
            if ($plans->isEmpty()) {
                break;
            }
            $plan = $plans->first();
            $status = $this->processPlan(
                $plan->id,
                expectedScheduledDate: $plan->proxima_fecha_programada->format('Y-m-d'),
                operationalDate: $date,
            );
            if ($status === null) {
                $blockedPlans[] = $plan->id;

                continue;
            }
            $result['procesadas']++;
            $result[$status]++;
            if ($status === 'bloqueadas') {
                $blockedPlans[] = $plan->id;
            }
        }
        $result['limiteAlcanzado'] = $result['procesadas'] >= $limit
            && $this->duePlans($date, $edificioId, $blockedPlans)->isNotEmpty();

        return $result;
    }

    private function processPlan(
        string $planId,
        ?string $expectedOccurrenceId = null,
        ?string $expectedScheduledDate = null,
        ?CarbonImmutable $operationalDate = null,
    ): ?string
    {
        return DB::transaction(function () use ($planId, $expectedOccurrenceId, $expectedScheduledDate, $operationalDate): ?string {
            $plan = PlanMantenimientoPreventivoEloquentModel::query()->lockForUpdate()->findOrFail($planId);
            if ($plan->estado !== EstadoPlanMantenimientoPreventivo::ACTIVO || $plan->proxima_fecha_programada === null) {
                return null;
            }
            $scheduledDate = $plan->proxima_fecha_programada->format('Y-m-d');
            if (($expectedScheduledDate !== null && $scheduledDate !== $expectedScheduledDate)
                || ($operationalDate !== null && ! $this->isDue($plan, $operationalDate))) {
                return null;
            }
            $occurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()
                ->where('edificio_id', $plan->edificio_id)
                ->where('plan_id', $plan->id)
                ->whereDate('fecha_programada', $scheduledDate)
                ->first();
            if ($occurrence === null) {
                $occurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()->create([
                    'edificio_id' => $plan->edificio_id,
                    'plan_id' => $plan->id,
                    'fecha_programada' => $scheduledDate,
                    'estado' => EstadoOcurrenciaMantenimientoPreventivo::PENDIENTE,
                ]);
            }
            $occurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()->lockForUpdate()->findOrFail($occurrence->id);
            if ($expectedOccurrenceId !== null && $occurrence->id !== $expectedOccurrenceId) {
                throw ValidationException::withMessages(['ocurrencia' => 'La ocurrencia vigente cambió antes del reintento.']);
            }
            if (in_array($occurrence->estado, [EstadoOcurrenciaMantenimientoPreventivo::GENERADA, EstadoOcurrenciaMantenimientoPreventivo::OMITIDA], true)) {
                $this->advanceSchedule($plan);

                return 'reutilizadas';
            }

            try {
                $this->validateDependencies($plan, $occurrence->fecha_programada, true);
                $created = $this->orders->createPreventive($plan->edificio_id, $occurrence->id, [
                    ...$plan->getAttributes(),
                    'plan_codigo' => $plan->codigo,
                    'fecha_programada' => $occurrence->fecha_programada->format('Y-m-d'),
                ]);
            } catch (ValidationException $exception) {
                $reason = collect($exception->errors())->flatten()->implode(' ');
                $changed = $occurrence->estado !== EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA || $occurrence->motivo !== $reason;
                $occurrence->fill([
                    'estado' => EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA,
                    'motivo' => $reason,
                    'procesada_at' => CarbonImmutable::now(),
                ])->save();
                if ($changed) {
                    $this->recordEvent($plan, TipoEventoPlanMantenimiento::BLOQUEO, null, [
                        'ocurrenciaId' => $occurrence->id,
                        'fechaProgramada' => $occurrence->fecha_programada->format('Y-m-d'),
                        'motivo' => $reason,
                    ], TipoActorOperativo::SISTEMA);
                }

                return 'bloqueadas';
            }

            $occurrence->refresh();
            if ($occurrence->estado !== EstadoOcurrenciaMantenimientoPreventivo::GENERADA) {
                $occurrence->fill([
                    'estado' => EstadoOcurrenciaMantenimientoPreventivo::GENERADA,
                    'motivo' => null,
                    'procesada_at' => CarbonImmutable::now(),
                ])->save();
            }
            $this->advanceSchedule($plan);
            $this->recordEvent($plan, TipoEventoPlanMantenimiento::GENERACION, null, [
                'ocurrenciaId' => $occurrence->id,
                'fechaProgramada' => $occurrence->fecha_programada->format('Y-m-d'),
                'ordenId' => $created['id'],
                'ordenNumero' => $created['numero'],
            ], TipoActorOperativo::SISTEMA);

            return 'generadas';
        });
    }

    private function advanceSchedule(PlanMantenimientoPreventivoEloquentModel $plan): void
    {
        $sequence = ((int) $plan->secuencia_siguiente) + 1;
        $next = $plan->unidad_recurrencia->dateAt($plan->fecha_ancla, (int) $plan->intervalo_recurrencia, $sequence);
        $plan->fill(['secuencia_siguiente' => $sequence, 'proxima_fecha_programada' => $next])->save();
    }

    private function isDue(PlanMantenimientoPreventivoEloquentModel $plan, CarbonImmutable $date): bool
    {
        return $plan->proxima_fecha_programada !== null
            && $plan->proxima_fecha_programada->subDays((int) $plan->dias_anticipacion)->lessThanOrEqualTo($date->startOfDay());
    }

    private function validateConfiguration(string $buildingId, array $data, bool $lock, array $current = []): void
    {
        $this->structure->assertLocation($buildingId, $data, $current, $lock);
        $providerId = $data['proveedor_id'] ?? null;
        if ($providerId !== null && $this->suppliers->activeProvider($buildingId, $providerId, $lock) === null) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor debe estar activo en el edificio.']);
        }
        $contractId = $data['contrato_id'] ?? null;
        if ($contractId !== null && ($providerId === null || $this->suppliers->registeredContract($buildingId, $providerId, $contractId, $lock) === null)) {
            throw ValidationException::withMessages(['contrato_id' => 'El contrato debe estar registrado y corresponder al proveedor del edificio.']);
        }
    }

    private function validateDependencies(PlanMantenimientoPreventivoEloquentModel $plan, CarbonImmutable $date, bool $lock): void
    {
        $this->structure->assertLocation($plan->edificio_id, $plan->getAttributes(), [], $lock);
        if ($plan->proveedor_id !== null && $this->suppliers->activeProvider($plan->edificio_id, $plan->proveedor_id, $lock) === null) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor asociado dejó de estar activo en el edificio.']);
        }
        if ($plan->contrato_id === null) {
            return;
        }
        $contract = $this->suppliers->registeredContract($plan->edificio_id, (string) $plan->proveedor_id, $plan->contrato_id, $lock);
        if ($contract === null) {
            throw ValidationException::withMessages(['contrato_id' => 'El contrato asociado dejó de estar registrado o ya no corresponde al proveedor.']);
        }
        $start = CarbonImmutable::parse($contract['fechaInicio'])->startOfDay();
        $end = $contract['fechaFin'] === null ? null : CarbonImmutable::parse($contract['fechaFin'])->startOfDay();
        if ($date->lessThan($start) || ($end !== null && $date->greaterThan($end))) {
            throw ValidationException::withMessages(['contrato_id' => 'El contrato no está vigente para la fecha programada.']);
        }
    }

    /** @param list<string> $excludedPlanIds */
    private function duePlans(CarbonImmutable $date, ?string $edificioId, array $excludedPlanIds)
    {
        return PlanMantenimientoPreventivoEloquentModel::query()
            ->where('estado', EstadoPlanMantenimientoPreventivo::ACTIVO)
            ->whereNotNull('proxima_fecha_programada')
            ->whereDoesntHave('ocurrencias', static fn (Builder $query) => $query->where('estado', EstadoOcurrenciaMantenimientoPreventivo::BLOQUEADA->value))
            ->when($edificioId, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($excludedPlanIds !== [], static fn (Builder $query) => $query->whereNotIn('id', $excludedPlanIds))
            ->orderBy('proxima_fecha_programada')
            ->orderBy('codigo')
            ->get()
            ->filter(fn (PlanMantenimientoPreventivoEloquentModel $plan): bool => $this->isDue($plan, $date));
    }

    private function previewDue(CarbonImmutable $date, ?string $edificioId, int $limit): array
    {
        $result = ['procesadas' => 0, 'generadas' => 0, 'bloqueadas' => 0, 'omitidas' => 0, 'reutilizadas' => 0, 'limiteAlcanzado' => false, 'dryRun' => true];
        $states = $this->duePlans($date, $edificioId, [])->mapWithKeys(static fn (PlanMantenimientoPreventivoEloquentModel $plan): array => [
            $plan->id => [
                'plan' => $plan,
                'secuencia' => (int) $plan->secuencia_siguiente,
                'fecha' => $plan->proxima_fecha_programada,
            ],
        ])->all();

        while ($result['procesadas'] < $limit) {
            $due = collect($states)
                ->filter(static fn (array $state): bool => $state['fecha']->subDays((int) $state['plan']->dias_anticipacion)->lessThanOrEqualTo($date->startOfDay()))
                ->sortBy(static fn (array $state): string => $state['fecha']->format('Y-m-d').'|'.$state['plan']->codigo)
                ->first();
            if ($due === null) {
                break;
            }

            /** @var PlanMantenimientoPreventivoEloquentModel $plan */
            $plan = $due['plan'];
            $result['procesadas']++;
            $existingOccurrence = OcurrenciaMantenimientoPreventivoEloquentModel::query()
                ->where('plan_id', $plan->id)
                ->whereDate('fecha_programada', $due['fecha']->format('Y-m-d'))
                ->first(['estado']);
            if ($existingOccurrence !== null && in_array($existingOccurrence->estado, [EstadoOcurrenciaMantenimientoPreventivo::GENERADA, EstadoOcurrenciaMantenimientoPreventivo::OMITIDA], true)) {
                $result['reutilizadas']++;
                $sequence = $due['secuencia'] + 1;
                $states[$plan->id] = [
                    'plan' => $plan,
                    'secuencia' => $sequence,
                    'fecha' => $plan->unidad_recurrencia->dateAt($plan->fecha_ancla, (int) $plan->intervalo_recurrencia, $sequence),
                ];

                continue;
            }
            try {
                $this->validateDependencies($plan, $due['fecha'], false);
                $result['generadas']++;
                $sequence = $due['secuencia'] + 1;
                $states[$plan->id] = [
                    'plan' => $plan,
                    'secuencia' => $sequence,
                    'fecha' => $plan->unidad_recurrencia->dateAt($plan->fecha_ancla, (int) $plan->intervalo_recurrencia, $sequence),
                ];
            } catch (ValidationException) {
                $result['bloqueadas']++;
                unset($states[$plan->id]);
            }
        }

        $result['limiteAlcanzado'] = $result['procesadas'] >= $limit
            && collect($states)->contains(static fn (array $state): bool => $state['fecha']->subDays((int) $state['plan']->dias_anticipacion)->lessThanOrEqualTo($date->startOfDay()));

        return $result;
    }

    private function authorizedBuilding(string $userId, string $buildingId, PermisoEdificio $permission, bool $lock = false): EdificioEloquentModel
    {
        if ($lock) {
            $building = EdificioEloquentModel::query()->lockForUpdate()->findOrFail($buildingId);
            abort_unless($this->access->hasPermission($userId, $buildingId, $permission), 403);

            return $building;
        }

        return EdificioEloquentModel::query()->whereKey($this->access->buildingIds($userId, $permission))->findOrFail($buildingId);
    }

    private function lockedPlan(string $buildingId, string $planId): PlanMantenimientoPreventivoEloquentModel
    {
        return PlanMantenimientoPreventivoEloquentModel::query()->where('edificio_id', $buildingId)->lockForUpdate()->findOrFail($planId);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function persistenceData(array $data): array
    {
        return [
            'titulo' => trim($data['titulo']),
            'descripcion' => trim($data['descripcion']),
            'prioridad' => $data['prioridad'],
            'unidad_recurrencia' => $data['unidad_recurrencia'],
            'intervalo_recurrencia' => (int) $data['intervalo_recurrencia'],
            'dias_anticipacion' => (int) $data['dias_anticipacion'],
            'torre_id' => $data['torre_id'] ?? null,
            'piso_id' => $data['piso_id'] ?? null,
            'departamento_id' => $data['departamento_id'] ?? null,
            'parqueadero_id' => $data['parqueadero_id'] ?? null,
            'bodega_id' => $data['bodega_id'] ?? null,
            'ubicacion_detalle' => $this->nullableTrim($data['ubicacion_detalle'] ?? null),
            'proveedor_id' => $data['proveedor_id'] ?? null,
            'contrato_id' => $data['contrato_id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(PlanMantenimientoPreventivoEloquentModel $plan): array
    {
        return [
            'codigo' => $plan->codigo,
            'titulo' => $plan->titulo,
            'descripcion' => $plan->descripcion,
            'prioridad' => $plan->prioridad?->value,
            'unidadRecurrencia' => $plan->unidad_recurrencia?->value,
            'intervaloRecurrencia' => (int) $plan->intervalo_recurrencia,
            'diasAnticipacion' => (int) $plan->dias_anticipacion,
            'torreId' => $plan->torre_id,
            'pisoId' => $plan->piso_id,
            'departamentoId' => $plan->departamento_id,
            'parqueaderoId' => $plan->parqueadero_id,
            'bodegaId' => $plan->bodega_id,
            'ubicacionDetalle' => $plan->ubicacion_detalle,
            'proveedorId' => $plan->proveedor_id,
            'contratoId' => $plan->contrato_id,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(PlanMantenimientoPreventivoEloquentModel $plan): array
    {
        return [
            'id' => $plan->id,
            'edificioId' => $plan->edificio_id,
            ...$this->snapshot($plan),
            'estado' => $plan->estado->value,
            'fechaAncla' => $plan->fecha_ancla?->format('Y-m-d'),
            'proximaFechaProgramada' => $plan->proxima_fecha_programada?->format('Y-m-d'),
            'secuenciaSiguiente' => (int) $plan->secuencia_siguiente,
            'createdAt' => $plan->created_at?->toIso8601String(),
            'updatedAt' => $plan->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSummary(PlanMantenimientoPreventivoEloquentModel $plan): array
    {
        return [
            'id' => $plan->id,
            'edificioId' => $plan->edificio_id,
            'codigo' => $plan->codigo,
            'titulo' => $plan->titulo,
            'prioridad' => $plan->prioridad->value,
            'unidadRecurrencia' => $plan->unidad_recurrencia->value,
            'intervaloRecurrencia' => (int) $plan->intervalo_recurrencia,
            'estado' => $plan->estado->value,
            'proximaFechaProgramada' => $plan->proxima_fecha_programada?->format('Y-m-d'),
            'ocurrenciasBloqueadas' => (int) ($plan->ocurrencias_bloqueadas_count ?? 0),
        ];
    }

    private function recordEvent(PlanMantenimientoPreventivoEloquentModel $plan, TipoEventoPlanMantenimiento $type, ?string $actorId, ?array $detail, TipoActorOperativo $actorType = TipoActorOperativo::USUARIO): void
    {
        BitacoraPlanMantenimientoEloquentModel::query()->create([
            'edificio_id' => $plan->edificio_id,
            'plan_id' => $plan->id,
            'tipo' => $type,
            'actor_tipo' => $actorType,
            'actor_user_id' => $actorId,
            'detalle' => $detail,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, array{anterior: mixed, nuevo: mixed}> */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $field => $value) {
            if (json_encode($value) !== json_encode($after[$field])) {
                $changes[$field] = ['anterior' => $value, 'nuevo' => $after[$field]];
            }
        }

        return $changes;
    }

    private function advanceVersion(PlanMantenimientoPreventivoEloquentModel $plan): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $plan->updated_at = $plan->updated_at !== null && $now->lessThanOrEqualTo($plan->updated_at)
            ? CarbonImmutable::instance($plan->updated_at)->addSecond()
            : $now;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
