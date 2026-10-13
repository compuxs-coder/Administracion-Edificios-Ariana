<?php

namespace Src\AreasComunes\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Src\AreasComunes\Domain\Contracts\AreaComunRepositoryInterface;
use Src\AreasComunes\Domain\Contracts\EstructuraAreasComunesReadInterface;
use Src\AreasComunes\Domain\Contracts\OcupantesAreasComunesReadInterface;
use Src\AreasComunes\Domain\Enums\EstadoEspacioComun;
use Src\AreasComunes\Domain\Enums\EstadoReservaEspacioComun;
use Src\AreasComunes\Domain\Enums\TipoEventoAreaComun;
use Src\AreasComunes\Infrastructure\Models\BitacoraAreaComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\EspacioComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\HorarioReglaEspacioComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\ReglaEspacioComunEloquentModel;
use Src\AreasComunes\Infrastructure\Models\ReservaEspacioComunEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class EloquentAreaComunRepository implements AreaComunRepositoryInterface
{
    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly EstructuraAreasComunesReadInterface $structure,
        private readonly OcupantesAreasComunesReadInterface $occupants,
    ) {}

    public function paginateSpacesForUser(string $userId, array $filters): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::AREAS_COMUNES_VER);
        $reservationBuildingIds = $this->access->buildingIds($userId, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER);
        $query = EspacioComunEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->withCount(['reservas as reservas_futuras_count' => static fn (Builder $query) => $query
                ->whereIn('edificio_id', $reservationBuildingIds)
                ->where('estado', EstadoReservaEspacioComun::CONFIRMADA)->where('fin_at', '>', CarbonImmutable::now())])
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $status) => $query->where('estado', $status))
            ->when($filters['buscar'] ?? null, static function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(static fn (Builder $nested) => $nested->whereRaw('LOWER(codigo) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(descripcion, ?)) LIKE ?', ['', $term]));
            })->orderBy('edificio_id')->orderBy('codigo');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return $this->pagination($paginator, function (EspacioComunEloquentModel $space) use ($reservationBuildingIds): array {
            $canViewReservations = in_array($space->edificio_id, $reservationBuildingIds, true);

            return [
                ...$this->serializeSpace($space),
                'puedeVerReservas' => $canViewReservations,
                'reservasFuturas' => $canViewReservations ? (int) ($space->reservas_futuras_count ?? 0) : null,
            ];
        });
    }

    public function getSpace(string $userId, string $buildingId, string $spaceId): array
    {
        $building = $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::AREAS_COMUNES_VER);
        $canViewReservations = $this->access->hasPermission($userId, $buildingId, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER);
        $relations = [
            'reglas' => static fn ($query) => $query->with('horarios')->orderByDesc('vigente_desde'),
            'bitacora' => static function ($query) use ($canViewReservations): void {
                $query->when(! $canViewReservations, static fn ($query) => $query->whereNull('reserva_id'))
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(100);
            },
        ];
        if ($canViewReservations) {
            $relations['reservas'] = static fn ($query) => $query->whereIn('estado', [EstadoReservaEspacioComun::PENDIENTE, EstadoReservaEspacioComun::CONFIRMADA])
                ->where('fin_at', '>', CarbonImmutable::now())->orderBy('inicio_at')->limit(50);
        }
        $space = EspacioComunEloquentModel::query()->where('edificio_id', $buildingId)
            ->with($relations)->findOrFail($spaceId);
        $actorIds = $space->bitacora->pluck('actor_user_id')->unique()->values()->all();
        $actorNames = $this->structure->memberNames($buildingId, $actorIds);
        $location = $this->structure->location($buildingId, $space->getAttributes());

        return [
            ...$this->serializeSpace($space),
            'edificio' => $building->nombre,
            'timezone' => $building->timezone,
            'puedeVerReservas' => $canViewReservations,
            'ubicacion' => [
                'tipo' => $location['tipo'] ?? null,
                'id' => $location['id'] ?? null,
                'etiqueta' => $location['etiqueta'] ?? null,
                'detalle' => $space->ubicacion_detalle,
            ],
            'reglas' => $space->reglas->map(fn (ReglaEspacioComunEloquentModel $rule): array => $this->serializeRule($rule))->all(),
            'reservas' => $canViewReservations
                ? $space->reservas->map(fn (ReservaEspacioComunEloquentModel $reservation): array => $this->serializeReservation($reservation, $building->timezone, $space))->all()
                : [],
            'bitacora' => $space->bitacora->map(static fn (BitacoraAreaComunEloquentModel $entry): array => [
                'id' => $entry->id,
                'tipo' => $entry->tipo->value,
                'actorNombre' => $actorNames[$entry->actor_user_id] ?? 'Usuario no disponible',
                'detalle' => $entry->detalle,
                'createdAt' => $entry->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function spaceOptions(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array
    {
        $buildingIds = $buildingId === null
            ? $this->access->buildingIds($userId, $permission)
            : [$this->authorizedBuilding($userId, $buildingId, $permission)->id];

        return $this->structure->options($buildingIds);
    }

    public function createSpace(string $userId, string $buildingId, array $data): array
    {
        return DB::transaction(function () use ($userId, $buildingId, $data): array {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::AREAS_COMUNES_GESTIONAR, true);
            $this->structure->assertLocation($buildingId, $data, [], true);
            $code = mb_strtoupper(trim($data['codigo']));
            if (EspacioComunEloquentModel::query()->where('edificio_id', $buildingId)->where('codigo', $code)->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código del espacio ya existe en el edificio.']);
            }
            $space = EspacioComunEloquentModel::query()->create([
                'edificio_id' => $buildingId,
                ...$this->spaceData($data),
                'codigo' => $code,
                'estado' => EstadoEspacioComun::ACTIVO,
                'creado_por_user_id' => $userId,
                'actualizado_por_user_id' => $userId,
            ]);
            $this->recordEvent($space, TipoEventoAreaComun::ESPACIO_CREADO, $userId, ['datos' => $this->spaceSnapshot($space)]);

            return ['id' => $space->id, 'codigo' => $space->codigo];
        });
    }

    public function updateSpace(string $userId, string $buildingId, string $spaceId, array $data): void
    {
        DB::transaction(function () use ($userId, $buildingId, $spaceId, $data): void {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::AREAS_COMUNES_GESTIONAR, true);
            $space = $this->lockedSpace($buildingId, $spaceId);
            $this->assertVersion($space, (string) $data['updated_at']);
            $this->structure->assertLocation($buildingId, $data, $space->getAttributes(), true);
            $capacity = (int) $data['capacidad'];
            if (ReglaEspacioComunEloquentModel::query()->where('espacio_comun_id', $space->id)->where('aforo_maximo', '>', $capacity)->exists()
                || ReservaEspacioComunEloquentModel::query()->where('espacio_comun_id', $space->id)
                    ->whereIn('estado', [EstadoReservaEspacioComun::PENDIENTE, EstadoReservaEspacioComun::CONFIRMADA])
                    ->where('fin_at', '>', CarbonImmutable::now())->where('cantidad_asistentes', '>', $capacity)->exists()) {
                throw ValidationException::withMessages(['capacidad' => 'La capacidad no puede quedar por debajo de reglas o reservas futuras existentes.']);
            }
            $code = mb_strtoupper(trim($data['codigo']));
            if (EspacioComunEloquentModel::query()->where('edificio_id', $buildingId)->where('codigo', $code)->whereKeyNot($space->id)->exists()) {
                throw ValidationException::withMessages(['codigo' => 'El código del espacio ya existe en el edificio.']);
            }
            $before = $this->spaceSnapshot($space);
            $space->fill([...$this->spaceData($data), 'codigo' => $code, 'actualizado_por_user_id' => $userId]);
            $after = $this->spaceSnapshot($space);
            $changes = $this->changes($before, $after);
            if ($changes === []) {
                return;
            }
            $this->advanceVersion($space);
            $space->save();
            $this->recordEvent($space, TipoEventoAreaComun::ESPACIO_ACTUALIZADO, $userId, ['cambios' => $changes]);
        });
    }

    public function changeSpaceStatus(string $userId, string $buildingId, string $spaceId, string $status): void
    {
        DB::transaction(function () use ($userId, $buildingId, $spaceId, $status): void {
            $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::AREAS_COMUNES_GESTIONAR, true);
            $space = $this->lockedSpace($buildingId, $spaceId);
            $target = EstadoEspacioComun::tryFrom($status);
            if ($target === null || $target === $space->estado) {
                throw ValidationException::withMessages(['estado' => 'El cambio de estado solicitado no es válido.']);
            }
            if ($target === EstadoEspacioComun::INACTIVO && ReservaEspacioComunEloquentModel::query()
                ->where('espacio_comun_id', $space->id)->where('estado', EstadoReservaEspacioComun::CONFIRMADA)
                ->where('fin_at', '>', CarbonImmutable::now())->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['estado' => 'Cancele primero las reservas futuras confirmadas del espacio.']);
            }
            $now = $this->transitionTime($space->estado_actualizado_at);
            $space->fill(['estado' => $target, 'actualizado_por_user_id' => $userId, 'estado_actualizado_por' => $userId, 'estado_actualizado_at' => $now])->save();
            $this->recordEvent($space, $target === EstadoEspacioComun::ACTIVO ? TipoEventoAreaComun::ESPACIO_ACTIVADO : TipoEventoAreaComun::ESPACIO_INACTIVADO, $userId);
        });
    }

    public function addRule(string $userId, string $buildingId, string $spaceId, array $data): array
    {
        return DB::transaction(function () use ($userId, $buildingId, $spaceId, $data): array {
            $building = $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::AREAS_COMUNES_GESTIONAR, true);
            $space = $this->lockedSpace($buildingId, $spaceId);
            $start = CarbonImmutable::createFromFormat('Y-m-d', $data['vigente_desde'], $building->timezone)->startOfDay();
            if ($start->lessThan(CarbonImmutable::now($building->timezone)->startOfDay())) {
                throw ValidationException::withMessages(['vigente_desde' => 'La nueva regla no puede iniciar en el pasado.']);
            }
            if ((int) $data['aforo_maximo'] > (int) $space->capacidad) {
                throw ValidationException::withMessages(['aforo_maximo' => 'El aforo de la regla no puede superar la capacidad del espacio.']);
            }
            $this->validateSchedules($data['horarios']);
            $rules = ReglaEspacioComunEloquentModel::query()->where('espacio_comun_id', $space->id)->lockForUpdate()->orderBy('vigente_desde')->get();
            if ($rules->contains(fn (ReglaEspacioComunEloquentModel $rule): bool => $rule->vigente_desde->format('Y-m-d') >= $data['vigente_desde'])) {
                throw ValidationException::withMessages(['vigente_desde' => 'Ya existe una regla que inicia en esa fecha o posteriormente.']);
            }
            $openRule = $rules->firstWhere('vigente_hasta', null);
            if ($openRule !== null) {
                if ($start->lessThanOrEqualTo(CarbonImmutable::now($building->timezone)->startOfDay())) {
                    throw ValidationException::withMessages(['vigente_desde' => 'El reemplazo de una regla vigente debe iniciar en una fecha futura.']);
                }
                $openRule->vigente_hasta = $start;
                $openRule->save();
            } elseif ($rules->isNotEmpty() && $rules->last()->vigente_hasta?->format('Y-m-d') > $data['vigente_desde']) {
                throw ValidationException::withMessages(['vigente_desde' => 'La vigencia se superpone con una regla existente.']);
            }
            $rule = ReglaEspacioComunEloquentModel::query()->create([
                'edificio_id' => $buildingId,
                'espacio_comun_id' => $space->id,
                'vigente_desde' => $start,
                'vigente_hasta' => null,
                'timezone' => $building->timezone,
                'requiere_aprobacion' => (bool) $data['requiere_aprobacion'],
                'duracion_minima_minutos' => (int) $data['duracion_minima_minutos'],
                'duracion_maxima_minutos' => (int) $data['duracion_maxima_minutos'],
                'antelacion_minima_minutos' => (int) $data['antelacion_minima_minutos'],
                'antelacion_maxima_dias' => (int) $data['antelacion_maxima_dias'],
                'buffer_antes_minutos' => (int) $data['buffer_antes_minutos'],
                'buffer_despues_minutos' => (int) $data['buffer_despues_minutos'],
                'aforo_maximo' => (int) $data['aforo_maximo'],
                'reglamento' => $this->nullableTrim($data['reglamento'] ?? null),
                'creada_por_user_id' => $userId,
                'created_at' => CarbonImmutable::now(),
            ]);
            foreach ($data['horarios'] as $schedule) {
                HorarioReglaEspacioComunEloquentModel::query()->create([
                    'edificio_id' => $buildingId,
                    'regla_id' => $rule->id,
                    'dia_semana' => (int) $schedule['dia_semana'],
                    'hora_inicio' => $schedule['hora_inicio'],
                    'hora_fin' => $schedule['hora_fin'],
                    'created_at' => CarbonImmutable::now(),
                ]);
            }
            $rule->load('horarios');
            $this->recordEvent($space, TipoEventoAreaComun::REGLA_CREADA, $userId, ['regla' => $this->serializeRule($rule)], $rule->id);

            return ['id' => $rule->id];
        });
    }

    public function paginateReservationsForUser(string $userId, array $filters): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER);
        $timezones = EdificioEloquentModel::query()->whereKey($buildingIds)->pluck('timezone', 'id');
        $query = ReservaEspacioComunEloquentModel::query()
            ->whereIn('edificio_id', $buildingIds)
            ->with('espacio')
            ->when($filters['edificio_id'] ?? null, static fn (Builder $query, string $id) => $query->where('edificio_id', $id))
            ->when($filters['espacio_comun_id'] ?? null, static fn (Builder $query, string $id) => $query->where('espacio_comun_id', $id))
            ->when($filters['estado'] ?? null, static fn (Builder $query, string $status) => $query->where('estado', $status))
            ->when(($filters['desde'] ?? null) !== null || ($filters['hasta'] ?? null) !== null, function (Builder $query) use ($filters, $timezones): void {
                $query->where(function (Builder $scope) use ($filters, $timezones): void {
                    foreach ($timezones as $buildingId => $timezone) {
                        $scope->orWhere(function (Builder $buildingScope) use ($buildingId, $timezone, $filters): void {
                            $buildingScope->where('edificio_id', $buildingId);
                            if (($filters['desde'] ?? null) !== null) {
                                $buildingScope->where('inicio_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $filters['desde'], $timezone)->startOfDay()->utc());
                            }
                            if (($filters['hasta'] ?? null) !== null) {
                                $buildingScope->where('inicio_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $filters['hasta'], $timezone)->addDay()->startOfDay()->utc());
                            }
                        });
                    }
                });
            })
            ->orderByRaw("CASE WHEN estado = 'pendiente' AND inicio_at > ? THEN 0 WHEN estado = 'confirmada' AND inicio_at > ? THEN 1 WHEN inicio_at > ? THEN 2 ELSE 3 END", [CarbonImmutable::now(), CarbonImmutable::now(), CarbonImmutable::now()])
            ->orderBy('inicio_at')
            ->orderBy('id');
        $paginator = $query->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return $this->pagination($paginator, fn (ReservaEspacioComunEloquentModel $reservation): array => $this->serializeReservation(
            $reservation,
            $timezones[$reservation->edificio_id] ?? 'UTC',
            $reservation->espacio,
        ));
    }

    public function getReservation(string $userId, string $buildingId, string $reservationId): array
    {
        $building = $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::RESERVAS_AREAS_COMUNES_VER);
        $reservation = ReservaEspacioComunEloquentModel::query()->where('edificio_id', $buildingId)->with(['espacio', 'regla.horarios'])->findOrFail($reservationId);
        $logs = BitacoraAreaComunEloquentModel::query()->where('reserva_id', $reservation->id)->orderBy('created_at')->get();
        $actorNames = $this->structure->memberNames($buildingId, $logs->pluck('actor_user_id')->unique()->values()->all());

        return [
            ...$this->serializeReservation($reservation, $building->timezone, $reservation->espacio),
            'edificio' => $building->nombre,
            'timezone' => (string) ($reservation->regla_snapshot['timezone'] ?? $building->timezone),
            'regla' => $this->serializeRule($reservation->regla),
            'bitacora' => $logs->map(static fn (BitacoraAreaComunEloquentModel $entry): array => [
                'id' => $entry->id,
                'tipo' => $entry->tipo->value,
                'actorNombre' => $actorNames[$entry->actor_user_id] ?? 'Usuario no disponible',
                'detalle' => $entry->detalle,
                'createdAt' => $entry->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function reservationOptions(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array
    {
        $buildingIds = $buildingId === null
            ? $this->access->buildingIds($userId, $permission)
            : [$this->authorizedBuilding($userId, $buildingId, $permission)->id];

        return [
            'edificios' => $this->structure->buildings($buildingIds),
            'espacios' => EspacioComunEloquentModel::query()->whereIn('edificio_id', $buildingIds)
                ->when($permission === PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR, static fn (Builder $query) => $query->where('estado', EstadoEspacioComun::ACTIVO))
                ->orderBy('edificio_id')->orderBy('codigo')->get()->map(fn (EspacioComunEloquentModel $space): array => $this->serializeSpace($space))->all(),
            'ocupaciones' => $permission === PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR ? $this->occupants->options($buildingIds) : [],
        ];
    }

    public function createReservation(string $userId, string $buildingId, string $spaceId, array $data): array
    {
        return DB::transaction(function () use ($userId, $buildingId, $spaceId, $data): array {
            $building = $this->authorizedBuilding($userId, $buildingId, PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR, true);
            $space = $this->lockedSpace($buildingId, $spaceId);
            if ($space->estado !== EstadoEspacioComun::ACTIVO) {
                throw ValidationException::withMessages(['espacio_comun_id' => 'El espacio debe estar activo para recibir reservas.']);
            }
            [$startLocal, $endLocal] = $this->localInterval($data, $building->timezone);
            $rule = ReglaEspacioComunEloquentModel::query()->where('espacio_comun_id', $space->id)
                ->whereDate('vigente_desde', '<=', $startLocal->format('Y-m-d'))
                ->where(static fn (Builder $query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>', $startLocal->format('Y-m-d')))
                ->with('horarios')->lockForUpdate()->first();
            if ($rule === null) {
                throw ValidationException::withMessages(['inicio' => 'El espacio no tiene una regla vigente para la fecha seleccionada.']);
            }
            $this->validateReservationAgainstRule($rule, $space, $startLocal, $endLocal, (int) $data['cantidad_asistentes']);
            $occupation = $this->occupants->eligibleOccupation($buildingId, $data['ocupacion_id'], $startLocal->format('Y-m-d'), true);
            if ($occupation === null) {
                throw ValidationException::withMessages(['ocupacion_id' => 'Seleccione un residente con ocupación vigente en el edificio para la fecha de la reserva.']);
            }
            $start = $startLocal->utc();
            $end = $endLocal->utc();
            $blockStart = $start->subMinutes((int) $rule->buffer_antes_minutos);
            $blockEnd = $end->addMinutes((int) $rule->buffer_despues_minutos);
            $status = $rule->requiere_aprobacion ? EstadoReservaEspacioComun::PENDIENTE : EstadoReservaEspacioComun::CONFIRMADA;
            if ($status === EstadoReservaEspacioComun::CONFIRMADA) {
                $this->assertAvailability($space->id, $blockStart, $blockEnd);
            }
            try {
                $reservation = ReservaEspacioComunEloquentModel::query()->create([
                    'edificio_id' => $buildingId,
                    'espacio_comun_id' => $space->id,
                    'regla_id' => $rule->id,
                    'ocupacion_id' => $occupation['id'],
                    'departamento_id' => $occupation['departamentoId'],
                    'residente_id' => $occupation['residenteId'],
                    'reservante_snapshot' => $occupation,
                    'regla_snapshot' => $this->ruleSnapshot($rule),
                    'inicio_at' => $start,
                    'fin_at' => $end,
                    'bloqueo_inicio_at' => $blockStart,
                    'bloqueo_fin_at' => $blockEnd,
                    'cantidad_asistentes' => (int) $data['cantidad_asistentes'],
                    'estado' => $status,
                    'observaciones' => $this->nullableTrim($data['observaciones'] ?? null),
                    'creada_por_user_id' => $userId,
                ]);
            } catch (QueryException $exception) {
                if ($status === EstadoReservaEspacioComun::CONFIRMADA) {
                    throw ValidationException::withMessages(['inicio' => 'El espacio ya está reservado en ese horario, incluidos sus tiempos de preparación.']);
                }
                throw $exception;
            }
            $this->recordEvent($space, TipoEventoAreaComun::RESERVA_CREADA, $userId, [
                'reservaId' => $reservation->id,
                'estado' => $status->value,
                'inicioLocal' => $startLocal->format('Y-m-d H:i'),
                'finLocal' => $endLocal->format('Y-m-d H:i'),
                'timezone' => $building->timezone,
            ], $rule->id, $reservation->id);

            return ['id' => $reservation->id, 'estado' => $status->value];
        });
    }

    public function transitionReservation(string $userId, string $buildingId, string $reservationId, string $status, ?string $reason): void
    {
        $target = EstadoReservaEspacioComun::tryFrom($status);
        if ($target === null || ! in_array($target, [EstadoReservaEspacioComun::CONFIRMADA, EstadoReservaEspacioComun::RECHAZADA, EstadoReservaEspacioComun::CANCELADA], true)) {
            throw ValidationException::withMessages(['estado' => 'La transición solicitada no es válida.']);
        }
        $permission = $target === EstadoReservaEspacioComun::CANCELADA
            ? PermisoEdificio::RESERVAS_AREAS_COMUNES_CANCELAR
            : PermisoEdificio::RESERVAS_AREAS_COMUNES_APROBAR;
        DB::transaction(function () use ($userId, $buildingId, $reservationId, $target, $reason, $permission): void {
            $building = $this->authorizedBuilding($userId, $buildingId, $permission, true);
            $reference = ReservaEspacioComunEloquentModel::query()->where('edificio_id', $buildingId)->findOrFail($reservationId, ['id', 'espacio_comun_id']);
            $space = $this->lockedSpace($buildingId, $reference->espacio_comun_id);
            $reservation = ReservaEspacioComunEloquentModel::query()->where('edificio_id', $buildingId)->lockForUpdate()->findOrFail($reservationId);
            $allowed = ($reservation->estado === EstadoReservaEspacioComun::PENDIENTE
                    && in_array($target, [EstadoReservaEspacioComun::CONFIRMADA, EstadoReservaEspacioComun::RECHAZADA, EstadoReservaEspacioComun::CANCELADA], true))
                || ($reservation->estado === EstadoReservaEspacioComun::CONFIRMADA && $target === EstadoReservaEspacioComun::CANCELADA);
            if (! $allowed) {
                throw ValidationException::withMessages(['estado' => 'La reserva ya no admite esa transición.']);
            }
            if ($target === EstadoReservaEspacioComun::CANCELADA && $reservation->estado === EstadoReservaEspacioComun::CONFIRMADA
                && $reservation->inicio_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['estado' => 'Una reserva confirmada que ya inició no puede cancelarse.']);
            }
            $reason = $this->nullableTrim($reason);
            if (in_array($target, [EstadoReservaEspacioComun::RECHAZADA, EstadoReservaEspacioComun::CANCELADA], true) && $reason === null) {
                throw ValidationException::withMessages(['motivo' => 'Indique un motivo para completar la transición.']);
            }
            if ($target === EstadoReservaEspacioComun::CONFIRMADA) {
                if ($space->estado !== EstadoEspacioComun::ACTIVO || $reservation->inicio_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw ValidationException::withMessages(['estado' => 'La reserva sólo puede aprobarse antes de iniciar y mientras el espacio esté activo.']);
                }
                $localDate = $reservation->inicio_at->setTimezone($building->timezone)->format('Y-m-d');
                if ($this->occupants->eligibleOccupation($buildingId, $reservation->ocupacion_id, $localDate, true) === null) {
                    throw ValidationException::withMessages(['estado' => 'La ocupación del residente ya no está vigente para la fecha de la reserva.']);
                }
                $this->assertAvailability($space->id, $reservation->bloqueo_inicio_at, $reservation->bloqueo_fin_at, $reservation->id);
                $reason = null;
            }
            $now = $this->transitionTime($reservation->resuelta_at);
            try {
                $reservation->fill(['estado' => $target, 'resuelta_por_user_id' => $userId, 'resuelta_at' => $now, 'motivo_resolucion' => $reason])->save();
            } catch (QueryException $exception) {
                if ($target === EstadoReservaEspacioComun::CONFIRMADA && $exception->getCode() === '23P01') {
                    throw ValidationException::withMessages(['estado' => 'Otra reserva confirmó ese horario antes de esta aprobación.']);
                }
                throw $exception;
            }
            $event = match ($target) {
                EstadoReservaEspacioComun::CONFIRMADA => TipoEventoAreaComun::RESERVA_APROBADA,
                EstadoReservaEspacioComun::RECHAZADA => TipoEventoAreaComun::RESERVA_RECHAZADA,
                EstadoReservaEspacioComun::CANCELADA => TipoEventoAreaComun::RESERVA_CANCELADA,
                default => throw new \LogicException('Transición no soportada.'),
            };
            $this->recordEvent($space, $event, $userId, ['reservaId' => $reservation->id, 'motivo' => $reason], $reservation->regla_id, $reservation->id);
        });
    }

    private function validateReservationAgainstRule(ReglaEspacioComunEloquentModel $rule, EspacioComunEloquentModel $space, CarbonImmutable $start, CarbonImmutable $end, int $attendees): void
    {
        if ($start->lessThanOrEqualTo(CarbonImmutable::now($start->getTimezone()))) {
            throw ValidationException::withMessages(['inicio' => 'La reserva debe iniciar en el futuro.']);
        }
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            throw ValidationException::withMessages(['fin' => 'La reserva debe iniciar y terminar el mismo día local.']);
        }
        $duration = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        if ($duration < (int) $rule->duracion_minima_minutos || $duration > (int) $rule->duracion_maxima_minutos) {
            throw ValidationException::withMessages(['fin' => 'La duración no cumple los límites de la regla vigente.']);
        }
        $now = CarbonImmutable::now();
        $advanceSeconds = $start->utc()->getTimestamp() - $now->getTimestamp();
        $maximumStart = $now->setTimezone($start->getTimezone())->addDays((int) $rule->antelacion_maxima_dias);
        if ($advanceSeconds < ((int) $rule->antelacion_minima_minutos * 60) || $start->greaterThan($maximumStart)) {
            throw ValidationException::withMessages(['inicio' => 'La anticipación no cumple los límites de la regla vigente.']);
        }
        if ($attendees > (int) $rule->aforo_maximo || $attendees > (int) $space->capacidad) {
            throw ValidationException::withMessages(['cantidad_asistentes' => 'La cantidad de asistentes supera el aforo permitido.']);
        }
        $day = $start->dayOfWeekIso;
        $startTime = $start->format('H:i');
        $endTime = $end->format('H:i');
        if (! $rule->horarios->contains(static fn (HorarioReglaEspacioComunEloquentModel $schedule): bool => (int) $schedule->dia_semana === $day
            && substr($schedule->hora_inicio, 0, 5) <= $startTime && substr($schedule->hora_fin, 0, 5) >= $endTime)) {
            throw ValidationException::withMessages(['inicio' => 'El intervalo está fuera de los horarios habilitados por la regla vigente.']);
        }
    }

    /** @param list<array<string, mixed>> $schedules */
    private function validateSchedules(array $schedules): void
    {
        foreach ($schedules as $index => $schedule) {
            foreach ($schedules as $otherIndex => $other) {
                if ($index >= $otherIndex || (int) $schedule['dia_semana'] !== (int) $other['dia_semana']) {
                    continue;
                }
                if ($schedule['hora_inicio'] < $other['hora_fin'] && $other['hora_inicio'] < $schedule['hora_fin']) {
                    throw ValidationException::withMessages(['horarios' => 'Los horarios de un mismo día no pueden solaparse.']);
                }
            }
        }
    }

    /** @param array<string, mixed> $data @return array{CarbonImmutable, CarbonImmutable} */
    private function localInterval(array $data, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['inicio'], $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['fin'], $timezone);
        if ($start === false || $end === false || $start->format('Y-m-d\TH:i') !== $data['inicio'] || $end->format('Y-m-d\TH:i') !== $data['fin']) {
            throw ValidationException::withMessages(['inicio' => 'El intervalo local no es válido para la zona horaria del edificio.']);
        }
        if ($this->isAmbiguousLocalTime($data['inicio'], $timezone) || $this->isAmbiguousLocalTime($data['fin'], $timezone)) {
            throw ValidationException::withMessages(['inicio' => 'El intervalo coincide con una hora local repetida por cambio de horario. Seleccione otra hora.']);
        }
        if ($end->greaterThan($start) && $this->hasOffsetTransition($start, $end, $timezone)) {
            throw ValidationException::withMessages(['inicio' => 'El intervalo no puede atravesar un cambio de horario de la zona del edificio.']);
        }

        return [$start, $end];
    }

    private function assertAvailability(string $spaceId, CarbonImmutable $start, CarbonImmutable $end, ?string $exceptId = null): void
    {
        $conflict = ReservaEspacioComunEloquentModel::query()->where('espacio_comun_id', $spaceId)
            ->where('estado', EstadoReservaEspacioComun::CONFIRMADA)
            ->where('bloqueo_inicio_at', '<', $end)->where('bloqueo_fin_at', '>', $start)
            ->when($exceptId, static fn (Builder $query, string $id) => $query->whereKeyNot($id))->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['inicio' => 'El espacio ya está reservado en ese horario, incluidos sus tiempos de preparación.']);
        }
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

    private function lockedSpace(string $buildingId, string $spaceId): EspacioComunEloquentModel
    {
        return EspacioComunEloquentModel::query()->where('edificio_id', $buildingId)->lockForUpdate()->findOrFail($spaceId);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function spaceData(array $data): array
    {
        return [
            'nombre' => trim($data['nombre']),
            'descripcion' => $this->nullableTrim($data['descripcion'] ?? null),
            'capacidad' => (int) $data['capacidad'],
            'torre_id' => $data['torre_id'] ?? null,
            'piso_id' => $data['piso_id'] ?? null,
            'ubicacion_detalle' => $this->nullableTrim($data['ubicacion_detalle'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSpace(EspacioComunEloquentModel $space): array
    {
        return [
            'id' => $space->id,
            'edificioId' => $space->edificio_id,
            'codigo' => $space->codigo,
            'nombre' => $space->nombre,
            'descripcion' => $space->descripcion,
            'capacidad' => (int) $space->capacidad,
            'torreId' => $space->torre_id,
            'pisoId' => $space->piso_id,
            'ubicacionDetalle' => $space->ubicacion_detalle,
            'estado' => $space->estado->value,
            'createdAt' => $space->created_at?->toIso8601String(),
            'updatedAt' => $space->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRule(ReglaEspacioComunEloquentModel $rule): array
    {
        return [
            'id' => $rule->id,
            ...$this->ruleSnapshot($rule),
            'vigenteHasta' => $rule->vigente_hasta?->format('Y-m-d'),
            'horarios' => $rule->horarios->sortBy(['dia_semana', 'hora_inicio'])->map(static fn (HorarioReglaEspacioComunEloquentModel $schedule): array => [
                'id' => $schedule->id,
                'diaSemana' => (int) $schedule->dia_semana,
                'horaInicio' => substr($schedule->hora_inicio, 0, 5),
                'horaFin' => substr($schedule->hora_fin, 0, 5),
            ])->values()->all(),
            'createdAt' => $rule->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function ruleSnapshot(ReglaEspacioComunEloquentModel $rule): array
    {
        return [
            'vigenteDesde' => $rule->vigente_desde?->format('Y-m-d'),
            'timezone' => $rule->timezone,
            'requiereAprobacion' => (bool) $rule->requiere_aprobacion,
            'duracionMinimaMinutos' => (int) $rule->duracion_minima_minutos,
            'duracionMaximaMinutos' => (int) $rule->duracion_maxima_minutos,
            'antelacionMinimaMinutos' => (int) $rule->antelacion_minima_minutos,
            'antelacionMaximaDias' => (int) $rule->antelacion_maxima_dias,
            'bufferAntesMinutos' => (int) $rule->buffer_antes_minutos,
            'bufferDespuesMinutos' => (int) $rule->buffer_despues_minutos,
            'aforoMaximo' => (int) $rule->aforo_maximo,
            'reglamento' => $rule->reglamento,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeReservation(ReservaEspacioComunEloquentModel $reservation, string $timezone, ?EspacioComunEloquentModel $space = null): array
    {
        $historicalTimezone = (string) ($reservation->regla_snapshot['timezone'] ?? $timezone);
        $start = $reservation->inicio_at?->setTimezone($historicalTimezone);
        $end = $reservation->fin_at?->setTimezone($historicalTimezone);
        $now = CarbonImmutable::now();

        return [
            'id' => $reservation->id,
            'edificioId' => $reservation->edificio_id,
            'espacioComunId' => $reservation->espacio_comun_id,
            'espacioCodigo' => $space?->codigo,
            'espacioNombre' => $space?->nombre,
            'reglaId' => $reservation->regla_id,
            'ocupacionId' => $reservation->ocupacion_id,
            'departamentoId' => $reservation->departamento_id,
            'residenteId' => $reservation->residente_id,
            'reservante' => $reservation->reservante_snapshot,
            'reglaSnapshot' => $reservation->regla_snapshot,
            'inicioLocal' => $start?->format('Y-m-d\TH:i'),
            'finLocal' => $end?->format('Y-m-d\TH:i'),
            'inicioAt' => $reservation->inicio_at?->toIso8601String(),
            'finAt' => $reservation->fin_at?->toIso8601String(),
            'cantidadAsistentes' => (int) $reservation->cantidad_asistentes,
            'estado' => $reservation->estado->value,
            'observaciones' => $reservation->observaciones,
            'motivoResolucion' => $reservation->motivo_resolucion,
            'resueltaAt' => $reservation->resuelta_at?->toIso8601String(),
            'createdAt' => $reservation->created_at?->toIso8601String(),
            'puedeAprobar' => $reservation->estado === EstadoReservaEspacioComun::PENDIENTE
                && $reservation->inicio_at?->greaterThan($now) && $space?->estado === EstadoEspacioComun::ACTIVO,
            'puedeCancelar' => $reservation->estado === EstadoReservaEspacioComun::PENDIENTE
                || ($reservation->estado === EstadoReservaEspacioComun::CONFIRMADA && $reservation->inicio_at?->greaterThan($now)),
        ];
    }

    private function isAmbiguousLocalTime(string $value, string $timezone): bool
    {
        $wallClock = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'UTC');
        if ($wallClock === false) {
            return false;
        }
        $zone = new \DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wallClock->getTimestamp() - 172800, $wallClock->getTimestamp() + 172800);
        if ($transitions === false) {
            return false;
        }
        for ($index = 1, $count = count($transitions); $index < $count; $index++) {
            $previousOffset = (int) $transitions[$index - 1]['offset'];
            $currentOffset = (int) $transitions[$index]['offset'];
            if ($currentOffset >= $previousOffset) {
                continue;
            }
            $repeatedStart = (int) $transitions[$index]['ts'] + $currentOffset;
            $repeatedEnd = (int) $transitions[$index]['ts'] + $previousOffset;
            if ($wallClock->getTimestamp() >= $repeatedStart && $wallClock->getTimestamp() < $repeatedEnd) {
                return true;
            }
        }

        return false;
    }

    private function hasOffsetTransition(CarbonImmutable $start, CarbonImmutable $end, string $timezone): bool
    {
        $transitions = (new \DateTimeZone($timezone))->getTransitions($start->getTimestamp(), $end->getTimestamp());
        if ($transitions === false) {
            return false;
        }
        foreach (array_slice($transitions, 1) as $transition) {
            if ((int) $transition['ts'] > $start->getTimestamp() && (int) $transition['ts'] <= $end->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function spaceSnapshot(EspacioComunEloquentModel $space): array
    {
        return collect($this->serializeSpace($space))->except(['id', 'edificioId', 'estado', 'createdAt', 'updatedAt'])->all();
    }

    private function recordEvent(EspacioComunEloquentModel $space, TipoEventoAreaComun $type, string $actorId, ?array $detail = null, ?string $ruleId = null, ?string $reservationId = null): void
    {
        BitacoraAreaComunEloquentModel::query()->create([
            'edificio_id' => $space->edificio_id,
            'espacio_comun_id' => $space->id,
            'regla_id' => $ruleId,
            'reserva_id' => $reservationId,
            'tipo' => $type,
            'actor_user_id' => $actorId,
            'detalle' => $detail,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function assertVersion(EspacioComunEloquentModel $space, string $expected): void
    {
        if ($space->updated_at === null || ! $space->updated_at->equalTo(CarbonImmutable::parse($expected))) {
            throw ValidationException::withMessages(['updated_at' => 'El espacio cambió desde que abrió el formulario. Recargue antes de guardar.']);
        }
    }

    private function advanceVersion(EspacioComunEloquentModel $space): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $space->updated_at = $space->updated_at !== null && $now->lessThanOrEqualTo($space->updated_at)
            ? CarbonImmutable::instance($space->updated_at)->addSecond()
            : $now;
    }

    private function transitionTime(mixed $previous): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return $previous !== null && $now->lessThanOrEqualTo($previous)
            ? CarbonImmutable::instance($previous)->addSecond()
            : $now;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, array<string, mixed>> */
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

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function pagination($paginator, callable $serialize): array
    {
        return [
            'items' => $paginator->getCollection()->map($serialize)->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }
}
