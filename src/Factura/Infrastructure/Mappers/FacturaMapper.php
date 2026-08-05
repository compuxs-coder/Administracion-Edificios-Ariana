<?php

namespace Src\Factura\Infrastructure\Mappers;

use DateTimeImmutable;
use Src\Factura\Domain\Entities\Factura;
use Src\Factura\Infrastructure\Models\FacturaEloquentModel;

final class FacturaMapper
{
    public static function toDomain(FacturaEloquentModel $model): Factura
    {
        return new Factura(
            id: $model->id,
            name: $model->name,
            ruc: $model->ruc,
            email: $model->email,
            phone: $model->phone,
            address: $model->address,
            city: $model->city,
            country: $model->country,
            status: $model->status,
            createdAt: new DateTimeImmutable($model->created_at->toDateTimeString()),
        );
    }

    /** @return array<string, string> */
    public static function toPersistence(Factura $factura): array
    {
        return [
            'id' => $factura->id(),
            'name' => $factura->name(),
            'ruc' => $factura->ruc(),
            'email' => $factura->email(),
            'phone' => $factura->phone(),
            'address' => $factura->address(),
            'city' => $factura->city(),
            'country' => $factura->country(),
            'status' => $factura->status(),
            'created_at' => $factura->createdAt()->format('Y-m-d H:i:s'),
        ];
    }
}
