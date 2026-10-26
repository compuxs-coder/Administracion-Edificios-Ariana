<?php

namespace Src\Gobierno\Infrastructure\ReadModels;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\PermisoEdificio;

abstract class FuenteAuditoriaRead
{
    public function __construct(protected readonly AccesoEdificioRepositoryInterface $access) {}

    abstract public function code(): string;

    abstract public function label(): string;

    /** @return array<string, string> */
    abstract public function eventTypes(): array;

    /** @param list<string> $buildingIds */
    abstract public function query(string $userId, array $buildingIds, ?string $eventType): ?Builder;

    /** @param list<string> $buildingIds @return list<string> */
    public function visibleBuildingIds(string $userId, array $buildingIds): array
    {
        return $this->authorizedBuildingIds($userId, $buildingIds, $this->permission());
    }

    /** @param list<string> $buildingIds @return array<string, string> */
    public function visibleEventTypes(string $userId, array $buildingIds): array
    {
        return $this->visibleBuildingIds($userId, $buildingIds) === [] ? [] : $this->eventTypes();
    }

    /** @return list<string> */
    public function visiblePermissionCodes(string $userId, string $buildingId, ?string $eventType): array
    {
        if ($eventType !== null && ! array_key_exists($eventType, $this->eventTypes())) {
            return [];
        }

        return $this->authorizedBuildingIds($userId, [$buildingId], $this->permission()) === []
            ? []
            : [$this->permission()->value];
    }

    abstract protected function permission(): PermisoEdificio;

    /** @param list<string> $buildingIds @return list<string> */
    protected function authorizedBuildingIds(string $userId, array $buildingIds, PermisoEdificio $permission): array
    {
        return array_values(array_intersect($buildingIds, $this->access->buildingIds($userId, $permission)));
    }

    protected function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
}
