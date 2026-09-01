<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Infrastructure\Models\DepartamentoEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoPago;
use Src\Finanzas\Domain\Enums\FormaPago;
use Src\Finanzas\Domain\Enums\OrigenPago;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class PagoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'numero',
        'fecha_pago',
        'monto_recibido',
        'forma_pago',
        'referencia',
        'observacion',
        'estado',
        'origen',
        'metadata',
        'registrado_por',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('pagos');
    }

    /** @return BelongsTo<DepartamentoEloquentModel, $this> */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(DepartamentoEloquentModel::class, 'departamento_id');
    }

    /** @return BelongsTo<PropietarioEloquentModel, $this> */
    public function propietario(): BelongsTo
    {
        return $this->belongsTo(PropietarioEloquentModel::class, 'propietario_id');
    }

    /** @return BelongsTo<UserEloquentModel, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(UserEloquentModel::class, 'registrado_por');
    }

    /** @return HasMany<AplicacionPagoEloquentModel, $this> */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(AplicacionPagoEloquentModel::class, 'pago_id');
    }

    /** @return HasMany<PagoTitularEloquentModel, $this> */
    public function titulares(): HasMany
    {
        return $this->hasMany(PagoTitularEloquentModel::class, 'pago_id');
    }

    /** @return HasOne<ReciboPagoEloquentModel, $this> */
    public function recibo(): HasOne
    {
        return $this->hasOne(ReciboPagoEloquentModel::class, 'pago_id');
    }

    /** @return HasMany<EvidenciaPagoEloquentModel, $this> */
    public function evidencias(): HasMany
    {
        return $this->hasMany(EvidenciaPagoEloquentModel::class, 'pago_id');
    }

    protected function casts(): array
    {
        return [
            'fecha_pago' => 'immutable_date',
            'monto_recibido' => 'decimal:4',
            'forma_pago' => FormaPago::class,
            'estado' => EstadoPago::class,
            'origen' => OrigenPago::class,
            'metadata' => 'array',
            'anulado_at' => 'immutable_datetime',
        ];
    }
}
