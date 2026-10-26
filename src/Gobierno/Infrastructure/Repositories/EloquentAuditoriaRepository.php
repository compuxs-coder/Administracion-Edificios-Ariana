<?php

namespace Src\Gobierno\Infrastructure\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Src\Gobierno\Infrastructure\ReadModels\AccesosAuditoriaRead;
use Src\Gobierno\Infrastructure\ReadModels\AreasComunesAuditoriaRead;
use Src\Gobierno\Infrastructure\ReadModels\ComunicacionAuditoriaRead;
use Src\Gobierno\Infrastructure\ReadModels\FuenteAuditoriaRead;
use Src\Gobierno\Infrastructure\ReadModels\MantenimientoAuditoriaRead;
use Src\Gobierno\Infrastructure\ReadModels\OrdenesAuditoriaRead;

final class EloquentAuditoriaRepository implements AuditoriaRepositoryInterface
{
    public function __construct(
        private readonly AccesoEdificioRepositoryInterface $access,
        private readonly AccesosAuditoriaRead $accessEvents,
        private readonly OrdenesAuditoriaRead $operationEvents,
        private readonly MantenimientoAuditoriaRead $maintenanceEvents,
        private readonly AreasComunesAuditoriaRead $commonAreaEvents,
        private readonly ComunicacionAuditoriaRead $communicationEvents,
    ) {}

    public function withinConsistentExportSnapshot(callable $reader): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('El snapshot de exportación sólo admite PostgreSQL y SQLite.');
        }
        if ($connection->transactionLevel() > 0) {
            if ($driver === 'pgsql') {
                $isolation = strtolower((string) $connection->scalar('SHOW transaction_isolation'));
                if (! in_array($isolation, ['repeatable read', 'serializable'], true)) {
                    throw new RuntimeException('La exportación requiere una transacción PostgreSQL REPEATABLE READ.');
                }
            }

            return $reader();
        }

        return $connection->transaction(function () use ($connection, $driver, $reader): mixed {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $reader();
        });
    }

    public function paginateForUser(string $userId, array $filters): array
    {
        $selectedBuildingIds = $this->selectedBuildingIds($userId, $filters['edificios'] ?? []);
        $queries = $this->queriesFor($userId, $selectedBuildingIds, $filters['fuente'] ?? null, $filters['tipo'] ?? null);

        $perPage = (int) config('gobierno.audit_page_size', 25);
        $page = (int) ($filters['page'] ?? 1);
        if ($queries === []) {
            return $this->emptyPage($selectedBuildingIds, $perPage);
        }

        $paginator = $this->orderedUnion($queries)
            ->orderByDesc('created_at')
            ->orderBy('fuente')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items());

        return [
            'items' => $this->projectRows($userId, $selectedBuildingIds, $rows),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'selectedBuildingIds' => $selectedBuildingIds,
        ];
    }

    public function options(string $userId, array $selectedBuildingIds = []): array
    {
        $buildingIds = $this->access->buildingIds($userId, PermisoEdificio::GOBIERNO_VER);
        $sourceBuildingIds = $selectedBuildingIds === []
            ? $buildingIds
            : array_values(array_intersect($selectedBuildingIds, $buildingIds));

        return [
            'edificios' => EdificioEloquentModel::query()->whereKey($buildingIds)->orderBy('nombre')
                ->get(['id', 'nombre', 'estado', 'timezone'])
                ->map(static fn (EdificioEloquentModel $building): array => [
                    'id' => $building->id,
                    'nombre' => $building->nombre,
                    'estado' => $building->estado->value,
                    'timezone' => $building->timezone,
                ])->all(),
            'fuentes' => collect($this->sources())
                ->map(function (FuenteAuditoriaRead $source) use ($userId, $sourceBuildingIds): ?array {
                    $eventTypes = $source->visibleEventTypes($userId, $sourceBuildingIds);

                    return $eventTypes === [] ? null : [
                        'codigo' => $source->code(),
                        'nombre' => $source->label(),
                        'tipos' => collect($eventTypes)->map(
                            static fn (string $label, string $code): array => ['codigo' => $code, 'nombre' => $label]
                        )->values()->all(),
                    ];
                })->filter()->values()->all(),
            'politicaRetencion' => [
                'configurada' => config('gobierno.retention_days', []) !== [],
                'eliminacionAutomatica' => false,
            ],
        ];
    }

    public function exportScopeForUser(string $userId, array $buildingIds, ?string $source, ?string $eventType): array
    {
        $buildingIds = array_values(array_unique($buildingIds));
        if ($buildingIds === []) {
            abort(403);
        }
        foreach ([PermisoEdificio::GOBIERNO_VER, PermisoEdificio::GOBIERNO_EXPORTAR] as $permission) {
            if (array_diff($buildingIds, $this->access->buildingIds($userId, $permission)) !== []) {
                abort(403);
            }
        }
        $detailBuildingIds = $this->access->buildingIds($userId, PermisoEdificio::GOBIERNO_DETALLES_VER);
        $sources = collect($this->sources())->filter(
            static fn (FuenteAuditoriaRead $candidate): bool => $source === null || $candidate->code() === $source,
        );

        return EdificioEloquentModel::query()->whereKey($buildingIds)->get(['id', 'nombre', 'timezone'])
            ->sortBy(static fn (EdificioEloquentModel $building): int => array_search($building->id, $buildingIds, true))
            ->map(function (EdificioEloquentModel $building) use ($userId, $eventType, $detailBuildingIds, $sources): array {
                $permissions = $sources->flatMap(
                    static fn (FuenteAuditoriaRead $candidate): array => $candidate->visiblePermissionCodes($userId, $building->id, $eventType),
                )->unique()->values()->all();

                return [
                    'id' => $building->id,
                    'nombre' => $building->nombre,
                    'timezone' => $building->timezone,
                    'permisosFuente' => $permissions,
                    'detalleIncluido' => in_array($building->id, $detailBuildingIds, true),
                ];
            })->values()->all();
    }

    public function exportChunkForUser(string $userId, array $filters, ?array $cursor, int $limit): array
    {
        $buildingIds = array_values(array_unique($filters['edificios'] ?? []));
        $scope = $this->exportScopeForUser($userId, $buildingIds, $filters['fuente'] ?? null, $filters['tipo'] ?? null);
        $queries = $this->queriesFor($userId, $buildingIds, $filters['fuente'] ?? null, $filters['tipo'] ?? null);
        if ($queries === []) {
            return ['items' => [], 'nextCursor' => null, 'hasMore' => false, 'scope' => $scope];
        }
        $from = CarbonImmutable::parse($filters['desde_utc'])->utc();
        $until = CarbonImmutable::parse($filters['hasta_utc'])->utc();
        $cutoff = CarbonImmutable::parse($filters['corte_at'])->utc();
        $query = $this->orderedUnion($queries)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->where('created_at', '<', $cutoff);
        if ($cursor !== null) {
            $query->where(static function (Builder $builder) use ($cursor): void {
                $builder->where('created_at', '<', $cursor['created_at'])
                    ->orWhere(static fn (Builder $sameDate) => $sameDate
                        ->where('created_at', '=', $cursor['created_at'])
                        ->where('fuente', '>', $cursor['fuente']))
                    ->orWhere(static fn (Builder $sameSource) => $sameSource
                        ->where('created_at', '=', $cursor['created_at'])
                        ->where('fuente', '=', $cursor['fuente'])
                        ->where('id', '<', $cursor['id']));
            });
        }
        $limit = max(1, min($limit, 5000));
        $rows = $query->orderByDesc('created_at')->orderBy('fuente')->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $last = $rows->last();

        return [
            'items' => $this->projectRows($userId, $buildingIds, $rows),
            'nextCursor' => $hasMore && $last !== null ? [
                'created_at' => (string) $last->created_at,
                'fuente' => (string) $last->fuente,
                'id' => (string) $last->id,
            ] : null,
            'hasMore' => $hasMore,
            'scope' => $scope,
        ];
    }

    /** @return list<FuenteAuditoriaRead> */
    private function sources(): array
    {
        return [$this->accessEvents, $this->operationEvents, $this->maintenanceEvents, $this->commonAreaEvents, $this->communicationEvents];
    }

    /** @param list<string> $requestedBuildingIds @return list<string> */
    private function selectedBuildingIds(string $userId, array $requestedBuildingIds): array
    {
        $authorizedBuildingIds = $this->access->buildingIds($userId, PermisoEdificio::GOBIERNO_VER);
        $requestedBuildingIds = array_values(array_unique($requestedBuildingIds));
        $selectedBuildingIds = $requestedBuildingIds === [] ? $authorizedBuildingIds : $requestedBuildingIds;
        if ($selectedBuildingIds === [] || array_diff($selectedBuildingIds, $authorizedBuildingIds) !== []) {
            abort(403);
        }

        return $selectedBuildingIds;
    }

    /** @param list<string> $buildingIds @return list<Builder> */
    private function queriesFor(string $userId, array $buildingIds, ?string $sourceCode, ?string $eventType): array
    {
        $queries = [];
        foreach ($this->sources() as $source) {
            if ($sourceCode !== null && $sourceCode !== $source->code()) {
                continue;
            }
            if ($eventType !== null && ! array_key_exists($eventType, $source->eventTypes())) {
                continue;
            }
            $query = $source->query($userId, $buildingIds, $eventType);
            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    /** @param non-empty-list<Builder> $queries */
    private function orderedUnion(array $queries): Builder
    {
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'audit_events');
    }

    /** @param list<string> $buildingIds @param Collection<int, object> $rows @return list<array<string, mixed>> */
    private function projectRows(string $userId, array $buildingIds, Collection $rows): array
    {
        $buildingNames = EdificioEloquentModel::query()->whereKey($buildingIds)->pluck('nombre', 'id');
        $detailBuildingIds = $this->access->buildingIds($userId, PermisoEdificio::GOBIERNO_DETALLES_VER);
        $actorIds = $rows->filter(static fn (object $row): bool => in_array($row->edificio_id, $detailBuildingIds, true))
            ->pluck('actor_user_id')->filter()->unique()->values()->all();
        $actorNames = DB::table($this->usersTable())->whereIn('id', $actorIds)->pluck('name', 'id');
        $sources = collect($this->sources())->keyBy(static fn (FuenteAuditoriaRead $source): string => $source->code());

        return $rows->map(function (object $row) use ($buildingNames, $detailBuildingIds, $actorNames, $sources): array {
            $canViewDetails = in_array($row->edificio_id, $detailBuildingIds, true);
            $source = $sources->get($row->fuente);

            return [
                'id' => $this->opaqueReference($row->edificio_id, $row->fuente, $row->id),
                'edificioId' => $row->edificio_id,
                'edificio' => $buildingNames[$row->edificio_id] ?? 'Edificio no disponible',
                'fuente' => $row->fuente,
                'fuenteLabel' => $source?->label() ?? $row->fuente,
                'tipo' => $row->tipo,
                'tipoLabel' => $source?->eventTypes()[$row->tipo] ?? $row->tipo,
                'actorTipo' => $row->actor_tipo,
                'actor' => $row->actor_tipo === 'sistema'
                    ? 'Sistema'
                    : ($canViewDetails ? ($actorNames[$row->actor_user_id] ?? 'Usuario no disponible') : null),
                'recursoTipo' => $this->resourceLabel($row->recurso_tipo),
                'recursoReferencia' => $canViewDetails
                    ? $this->opaqueReference($row->edificio_id, $row->recurso_tipo, $row->recurso_id)
                    : null,
                'detalleRestringido' => ! $canViewDetails,
                'ocurridoAt' => CarbonImmutable::parse($row->created_at)->utc()->toIso8601ZuluString(),
                'retencionDias' => $this->retentionDays($row->fuente, $row->tipo),
            ];
        })->all();
    }

    /** @param list<string> $selectedBuildingIds @return array<string, mixed> */
    private function emptyPage(array $selectedBuildingIds, int $perPage): array
    {
        return [
            'items' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1,
            'perPage' => $perPage, 'selectedBuildingIds' => $selectedBuildingIds,
        ];
    }

    private function opaqueReference(string $buildingId, string $scope, mixed $id): string
    {
        return strtoupper(substr(hash_hmac(
            'sha256',
            'gobierno:v1:'.$buildingId.':'.$scope.':'.(string) $id,
            (string) config('app.key'),
        ), 0, 12));
    }

    private function resourceLabel(string $resource): string
    {
        return [
            'usuario' => 'Usuario', 'invitacion' => 'Invitacion', 'acceso' => 'Acceso',
            'orden_operativa' => 'Orden operativa', 'plan_mantenimiento' => 'Plan de mantenimiento',
            'reserva' => 'Reserva', 'regla' => 'Regla', 'espacio_comun' => 'Espacio comun',
            'comunicado' => 'Comunicado',
        ][$resource] ?? 'Recurso';
    }

    private function retentionDays(string $source, string $type): ?int
    {
        $retention = config('gobierno.retention_days', []);
        $value = $retention[$source.'.'.$type] ?? $retention[$source.'.*'] ?? $retention['*'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function usersTable(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'public.users' : 'users';
    }
}
