<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Tesoreria\Domain\Contracts\CuentaTesoreriaRepositoryInterface;

final readonly class GetCuentaTesoreriaOptionsAction
{
    public function __construct(private CuentaTesoreriaRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission): array
    {
        return $this->repository->options($userId, $permission);
    }
}
