<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoReciboPago;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ReciboPagoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'numero',
        'fecha_pago_snapshot',
        'edificio_nombre_snapshot',
        'departamento_codigo_snapshot',
        'departamento_nombre_snapshot',
        'titulares_snapshot',
        'monto_recibido_snapshot',
        'forma_pago_snapshot',
        'referencia_snapshot',
        'aplicaciones_snapshot',
        'estado',
        'emitido_por',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('recibos_pago');
    }

    /** @return BelongsTo<PagoEloquentModel, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoEloquentModel::class, 'pago_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function emitidoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'emitido_por');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'anulado_por');
    }

    protected function casts(): array
    {
        return [
            'fecha_pago_snapshot' => 'immutable_date',
            'titulares_snapshot' => 'array',
            'monto_recibido_snapshot' => 'decimal:4',
            'aplicaciones_snapshot' => 'array',
            'estado' => EstadoReciboPago::class,
            'anulado_at' => 'immutable_datetime',
        ];
    }
}
