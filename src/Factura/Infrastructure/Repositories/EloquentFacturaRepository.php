<?php

namespace Src\Factura\Infrastructure\Repositories;

use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Domain\Entities\Factura;
use Src\Factura\Infrastructure\Mappers\FacturaMapper;
use Src\Factura\Infrastructure\Models\FacturaEloquentModel;

final class EloquentFacturaRepository implements FacturaRepositoryInterface
{
    public function all(): array
    {
        return FacturaEloquentModel::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (FacturaEloquentModel $model): Factura => FacturaMapper::toDomain($model))
            ->all();
    }

    public function find(string $id): ?Factura
    {
        $model = FacturaEloquentModel::query()->find($id);

        return $model === null ? null : FacturaMapper::toDomain($model);
    }

    public function save(Factura $factura): Factura
    {
        $model = FacturaEloquentModel::query()->updateOrCreate(
            ['id' => $factura->id()],
            FacturaMapper::toPersistence($factura),
        );

        return FacturaMapper::toDomain($model);
    }

    public function delete(Factura $factura): void
    {
        FacturaEloquentModel::query()
            ->whereKey($factura->id())
            ->delete();
    }
}
