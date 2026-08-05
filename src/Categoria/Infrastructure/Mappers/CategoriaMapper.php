<?php

namespace Src\Categoria\Infrastructure\Mappers;

use Src\Categoria\Domain\Entities\Categoria;
use Src\Categoria\Infrastructure\Models\CategoriaEloquentModel;

class CategoriaMapper
{
    public static function toDomain(CategoriaEloquentModel $model): Categoria
    {
        return new Categoria(
            id: $model->id,
            nombre: $model->nombre,
            descripcion: $model->descripcion,
            activo: $model->activo,
            createdAt: $model->created_at ? new \DateTimeImmutable($model->created_at->toDateTimeString()) : null,
            updatedAt: $model->updated_at ? new \DateTimeImmutable($model->updated_at->toDateTimeString()) : null
        );
    }

    public static function toEloquent(Categoria $categoria): CategoriaEloquentModel
    {
        $model = new CategoriaEloquentModel();
        $model->id = $categoria->getId();
        $model->nombre = $categoria->getNombre();
        $model->descripcion = $categoria->getDescripcion();
        $model->activo = $categoria->getActivo();

        return $model;
    }

    public static function updateEloquentFromDomain(CategoriaEloquentModel $model, Categoria $categoria): void
    {
        $model->nombre = $categoria->getNombre();
        $model->descripcion = $categoria->getDescripcion();
        $model->activo = $categoria->getActivo();
    }
}
