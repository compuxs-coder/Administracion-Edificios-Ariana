<?php

namespace Src\Tesoreria\Infrastructure\Repositories;

use Src\Gastos\Domain\Contracts\ConciliacionDesembolsoQueryInterface;
use Src\Tesoreria\Domain\Enums\EstadoConciliacionTesoreria;
use Src\Tesoreria\Infrastructure\Models\ConciliacionTesoreriaEloquentModel;

final class EloquentConciliacionDesembolsoQuery implements ConciliacionDesembolsoQueryInterface
{
    public function hasActiveReconciliation(
        string $edificioId,
        string $desembolsoId,
        bool $lockForUpdate = false,
    ): bool {
        $query = ConciliacionTesoreriaEloquentModel::query()
            ->where('edificio_id', $edificioId)
            ->where('desembolso_id', $desembolsoId)
            ->where('estado', EstadoConciliacionTesoreria::VIGENTE->value);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first(['id']) !== null;
    }
}
