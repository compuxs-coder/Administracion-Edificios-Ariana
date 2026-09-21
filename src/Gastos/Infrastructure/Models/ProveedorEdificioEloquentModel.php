<?php

namespace Src\Gastos\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\EstadoProveedor;
use Src\Gastos\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ProveedorEdificioEloquentModel extends Model
{
    use UsesApplicationSchema;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'edificio_id',
        'proveedor_id',
        'estado',
        'nombre_comercial',
        'contacto',
        'telefono',
        'correo',
        'direccion',
        'dias_credito',
        'observaciones',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('proveedor_edificio');
    }

    /** @return BelongsTo<ProveedorEloquentModel, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorEloquentModel::class, 'proveedor_id');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoProveedor::class,
            'dias_credito' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected function setKeysForSelectQuery($query)
    {
        return $query
            ->where('edificio_id', $this->original['edificio_id'] ?? $this->edificio_id)
            ->where('proveedor_id', $this->original['proveedor_id'] ?? $this->proveedor_id);
    }

    protected function setKeysForSaveQuery($query)
    {
        return $this->setKeysForSelectQuery($query);
    }
}
