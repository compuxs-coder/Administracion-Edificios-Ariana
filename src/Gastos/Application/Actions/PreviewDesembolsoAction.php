<?php

namespace Src\Gastos\Application\Actions;

use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;

final readonly class PreviewDesembolsoAction
{
    public function __construct(private DesembolsoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $proveedorId, string $amount): array
    {
        return $this->repository->preview($userId, $edificioId, $proveedorId, $amount);
    }
}
