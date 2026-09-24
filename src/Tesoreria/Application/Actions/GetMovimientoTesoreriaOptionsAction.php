<?php

namespace Src\Tesoreria\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Tesoreria\Domain\Contracts\MovimientoTesoreriaRepositoryInterface;

final readonly class GetMovimientoTesoreriaOptionsAction
{
    public function __construct(private MovimientoTesoreriaRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission, bool $onlyActiveAccounts = false): array
    {
        return $this->repository->options($userId, $permission, $onlyActiveAccounts);
    }
}
