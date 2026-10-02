<?php

namespace Src\Operaciones\Domain\Contracts;

use Illuminate\Http\UploadedFile;
use Src\Edificio\Domain\Enums\PermisoEdificio;

interface OrdenOperativaRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginateForUser(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $ordenId, bool $withHistory = true): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission, ?string $edificioId = null): array;

    /** @return array{miembros: list<array<string, mixed>>, proveedores: list<array<string, mixed>>} */
    public function assignmentOptions(string $userId, string $edificioId): array;

    /** @return array{edificios: list<array<string, mixed>>} */
    public function indexOptions(string $userId): array;

    /** @param array<string, mixed> $data @return array{id: string, numero: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @param array<string, mixed> $data */
    public function update(string $userId, string $edificioId, string $ordenId, array $data): void;

    public function transition(string $userId, string $edificioId, string $ordenId, string $state): void;

    public function assign(string $userId, string $edificioId, string $ordenId, string $type, string $responsibleId): void;

    public function cancel(string $userId, string $edificioId, string $ordenId, string $reason): void;

    public function reopen(string $userId, string $edificioId, string $ordenId, string $reason): void;

    public function recordManualAction(string $userId, string $edificioId, string $ordenId, string $description): void;

    /** @return array{id: string} */
    public function storeEvidence(string $userId, string $edificioId, string $ordenId, UploadedFile $file, ?string $description): array;

    /** @return array{contents: string, nombre: string, mimeType: string} */
    public function evidenceDownload(string $userId, string $edificioId, string $ordenId, string $evidenceId): array;
}
