<?php

namespace Src\Producto\Infrastructure\Mappers;

use Src\Producto\Domain\Entities\Producto;
use Src\Producto\Infrastructure\Models\ProductoEloquentModel;

class ProductoMapper
{
    public static function toDomain(ProductoEloquentModel $model): Producto
    {
        return new Producto(
            id: $model->id,
            categoriaId: $model->categoria_id,
            codigo: $model->codigo,
            nombre: $model->nombre,
            descripcion: $model->descripcion,
            precioUnitario: (float) $model->precio_unitario,
            stock: (int) $model->stock,
            tipo: $model->tipo,
            activo: (bool) $model->activo,
            createdAt: new \DateTimeImmutable($model->created_at->toDateTimeString()),
            updatedAt: new \DateTimeImmutable($model->updated_at->toDateTimeString())
        );
    }

    public static function toEloquent(Producto $producto): array
    {
        return [
            'id' => $producto->getId(),
            'categoria_id' => $producto->getCategoriaId(),
            'codigo' => $producto->getCodigo(),
            'nombre' => $producto->getNombre(),
            'descripcion' => $producto->getDescripcion(),
            'precio_unitario' => $producto->getPrecioUnitario(),
            'stock' => $producto->getStock(),
            'tipo' => $producto->getTipo(),
            'activo' => $producto->isActivo(),
        ];
    }
}
