<?php

namespace Src\Edificio\Infrastructure\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Entities\Edificio;
use Src\Edificio\Infrastructure\Mappers\EdificioMapper;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\TorreEloquentModel;

final class EloquentEdificioRepository implements EdificioRepositoryInterface
{
    public function paginateAssignedTo(
        string $userId,
        ?string $search,
        int $page,
        int $perPage,
    ): array {
        $paginator = EdificioEloquentModel::query()
            ->whereHas('usuarios', static fn (Builder $query) => $query->whereKey($userId))
            ->when($search !== null && $search !== '', static function (Builder $query) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(static function (Builder $query) use ($term): void {
                    $query
                        ->whereRaw('LOWER(nombre) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(ciudad) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(ruc, ?)) LIKE ?', ['', $term])
                        ->orWhereRaw('LOWER(COALESCE(responsable, ?)) LIKE ?', ['', $term]);
                });
            })
            ->orderBy('nombre')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator->getCollection()
                ->map(static fn (EdificioEloquentModel $model): Edificio => EdificioMapper::toDomain($model))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
        ];
    }

    public function find(string $id): ?Edificio
    {
        $model = EdificioEloquentModel::query()->find($id);

        return $model === null ? null : EdificioMapper::toDomain($model);
    }

    public function createForUser(Edificio $edificio, string $userId): Edificio
    {
        return DB::transaction(function () use ($edificio, $userId): Edificio {
            $model = EdificioEloquentModel::query()->create(
                EdificioMapper::toPersistence($edificio),
            );
            $model->usuarios()->attach($userId);
            TorreEloquentModel::query()->forceCreate([
                'edificio_id' => $model->id,
                'codigo' => 'PRINCIPAL',
                'nombre' => 'Torre principal',
                'descripcion' => null,
                'es_predeterminada' => true,
                'estado' => 'activo',
            ]);

            return EdificioMapper::toDomain($model->refresh());
        });
    }

    public function save(Edificio $edificio): Edificio
    {
        $model = EdificioEloquentModel::query()->updateOrCreate(
            ['id' => $edificio->id()],
            EdificioMapper::toPersistence($edificio),
        );

        return EdificioMapper::toDomain($model);
    }
}
