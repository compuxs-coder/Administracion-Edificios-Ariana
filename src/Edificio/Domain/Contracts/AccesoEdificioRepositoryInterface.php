<?php

namespace Src\Edificio\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface AccesoEdificioRepositoryInterface
{
    public function hasPermission(string $userId, string $edificioId, PermisoEdificio $permission): bool;

    /** @return list<string> */
    public function buildingIds(string $userId, PermisoEdificio $permission): array;

    /** @return array<string, list<string>> */
    public function permissionMap(string $userId): array;

    public function assignAdministrator(string $edificioId, string $userId): void;

    /** @return array{members: list<array<string, mixed>>, invitations: list<array<string, mixed>>, roles: list<array<string, mixed>>, events: list<array<string, mixed>>} */
    public function managementData(string $edificioId): array;

    /** @return array{invitation: array<string, mixed>, plainToken: string} */
    public function invite(string $actorId, string $edificioId, string $email, string $role): array;

    /** @return array<string, mixed> */
    public function invitation(string $plainToken, string $userId): array;

    /** @return array{edificioId: string} */
    public function acceptInvitation(string $plainToken, string $userId): array;

    public function revokeInvitation(string $actorId, string $edificioId, string $invitationId): void;

    /** @param list<string> $roles */
    public function updateMemberRoles(string $actorId, string $edificioId, string $memberId, array $roles): void;

    public function revokeMembership(string $actorId, string $edificioId, string $memberId): void;
}
