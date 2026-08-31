<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CarteraReadRepositoryInterface;

final readonly class GetEstadoCuentaAction
{
    public function __construct(private CarteraReadRepositoryInterface $cartera) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, string $edificioId, string $departamentoId, array $filters): array
    {
        return $this->cartera->statement($userId, $edificioId, $departamentoId, $filters);
    }
}
