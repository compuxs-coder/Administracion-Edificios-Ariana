<?php

namespace Src\AreasComunes\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface AreaComunRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateSpacesForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function getSpace(string $userId, string $buildingId, string $spaceId): array;

    /** @return array<string, mixed> */
    public function spaceOptions(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array;

    /** @return array<string, mixed> */
    public function createSpace(string $userId, string $buildingId, array $data): array;

    /** @param array<string, mixed> $data */
    public function updateSpace(string $userId, string $buildingId, string $spaceId, array $data): void;

    public function changeSpaceStatus(string $userId, string $buildingId, string $spaceId, string $status): void;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function addRule(string $userId, string $buildingId, string $spaceId, array $data): array;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateReservationsForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function getReservation(string $userId, string $buildingId, string $reservationId): array;

    /** @return array<string, mixed> */
    public function reservationOptions(string $userId, PermisoEdificio $permission, ?string $buildingId = null): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createReservation(string $userId, string $buildingId, string $spaceId, array $data): array;

    public function transitionReservation(string $userId, string $buildingId, string $reservationId, string $status, ?string $reason): void;
}
