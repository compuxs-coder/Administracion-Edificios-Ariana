<?php

namespace Src\Cliente\Infrastructure\Mappers;

use Src\Cliente\Domain\Entities\Cliente;
use Src\Cliente\Infrastructure\Models\ClienteEloquentModel;

class ClienteMapper
{
    public static function toDomain(ClienteEloquentModel $model): Cliente
    {
        return new Cliente(
            id: $model->id,
            tipoDocumento: $model->tipo_documento,
            numeroDocumento: $model->numero_documento,
            razonSocial: $model->razon_social,
            direccion: $model->direccion,
            telefono: $model->telefono,
            email: $model->email,
            createdAt: new \DateTimeImmutable($model->created_at->toDateTimeString()),
            updatedAt: new \DateTimeImmutable($model->updated_at->toDateTimeString())
        );
    }

    public static function toEloquent(Cliente $cliente): array
    {
        return [
            'id' => $cliente->getId(),
            'tipo_documento' => $cliente->getTipoDocumento(),
            'numero_documento' => $cliente->getNumeroDocumento(),
            'razon_social' => $cliente->getRazonSocial(),
            'direccion' => $cliente->getDireccion(),
            'telefono' => $cliente->getTelefono(),
            'email' => $cliente->getEmail(),
        ];
    }
}
