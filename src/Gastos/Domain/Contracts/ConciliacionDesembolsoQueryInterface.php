<?php

namespace Src\Gastos\Domain\Contracts;

interface ConciliacionDesembolsoQueryInterface
{
    public function hasActiveReconciliation(
        string $edificioId,
        string $desembolsoId,
        bool $lockForUpdate = false,
    ): bool;
}
