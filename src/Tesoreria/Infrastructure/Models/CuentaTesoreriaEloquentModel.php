<?php

namespace Src\Tesoreria\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Domain\Enums\EstadoCuentaTesoreria;
use Src\Tesoreria\Domain\Enums\TipoCuentaTesoreria;
use Src\Tesoreria\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class CuentaTesoreriaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $table = 'cuentas_tesoreria';

    protected $fillable = [
        'edificio_id', 'codigo', 'nombre', 'tipo', 'entidad_financiera',
        'tipo_cuenta_bancaria', 'numero_cuenta', 'estado', 'registrado_por',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('cuentas_tesoreria');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'registrado_por');
    }

    /** @return HasMany<MovimientoTesoreriaEloquentModel, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoTesoreriaEloquentModel::class, 'cuenta_id');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoCuentaTesoreria::class,
            'estado' => EstadoCuentaTesoreria::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
