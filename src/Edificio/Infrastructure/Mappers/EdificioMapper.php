<?php

namespace Src\Edificio\Infrastructure\Mappers;

use DateTimeImmutable;
use Src\Edificio\Domain\Entities\Edificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class EdificioMapper
{
    public static function toDomain(EdificioEloquentModel $model): Edificio
    {
        return new Edificio(
            id: $model->id,
            nombre: $model->nombre,
            ruc: $model->ruc,
            direccion: $model->direccion,
            ciudad: $model->ciudad,
            telefono: $model->telefono,
            correo: $model->correo,
            responsable: $model->responsable,
            estado: $model->estado,
            createdAt: new DateTimeImmutable($model->created_at->format(DATE_ATOM)),
            updatedAt: new DateTimeImmutable($model->updated_at->format(DATE_ATOM)),
        );
    }

    /** @return array<string, mixed> */
    public static function toPersistence(Edificio $edificio): array
    {
        return [
            'id' => $edificio->id(),
            'nombre' => $edificio->nombre(),
            'ruc' => $edificio->ruc(),
            'direccion' => $edificio->direccion(),
            'ciudad' => $edificio->ciudad(),
            'telefono' => $edificio->telefono(),
            'correo' => $edificio->correo(),
            'responsable' => $edificio->responsable(),
            'estado' => $edificio->estado()->value,
            'created_at' => $edificio->createdAt(),
            'updated_at' => $edificio->updatedAt(),
        ];
    }
}
