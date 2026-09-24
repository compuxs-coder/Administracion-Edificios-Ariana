<?php

namespace Src\Tesoreria\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Domain\Enums\EstadoConciliacionTesoreria;
use Src\Tesoreria\Domain\Enums\EstadoMovimientoTesoreria;
use Src\Tesoreria\Domain\Enums\NaturalezaMovimientoTesoreria;
use Src\Tesoreria\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class MovimientoTesoreriaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $table = 'movimientos_tesoreria';

    protected $fillable = [
        'edificio_id', 'cuenta_id', 'fecha_movimiento', 'naturaleza', 'monto',
        'referencia', 'descripcion', 'estado', 'registrado_por', 'anulado_por',
        'anulado_at', 'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('movimientos_tesoreria');
    }

    /** @return BelongsTo<EdificioEloquentModel, $this> */
    public function edificio(): BelongsTo
    {
        return $this->belongsTo(EdificioEloquentModel::class, 'edificio_id');
    }

    /** @return BelongsTo<CuentaTesoreriaEloquentModel, $this> */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreriaEloquentModel::class, 'cuenta_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'registrado_por');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'anulado_por');
    }

    /** @return HasMany<ConciliacionTesoreriaEloquentModel, $this> */
    public function conciliaciones(): HasMany
    {
        return $this->hasMany(ConciliacionTesoreriaEloquentModel::class, 'movimiento_id');
    }

    /** @return HasOne<ConciliacionTesoreriaEloquentModel, $this> */
    public function conciliacionVigente(): HasOne
    {
        return $this->hasOne(ConciliacionTesoreriaEloquentModel::class, 'movimiento_id')
            ->where('estado', EstadoConciliacionTesoreria::VIGENTE->value);
    }

    protected function casts(): array
    {
        return [
            'fecha_movimiento' => 'immutable_date',
            'naturaleza' => NaturalezaMovimientoTesoreria::class,
            'monto' => 'decimal:4',
            'estado' => EstadoMovimientoTesoreria::class,
            'anulado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
