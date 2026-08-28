<?php

namespace Src\Finanzas\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Finanzas\Domain\Enums\EstadoLoteGeneracion;
use Src\Finanzas\Domain\Enums\OrigenCargo;
use Src\Finanzas\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class LoteGeneracionCargoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'concepto_cobro_id',
        'periodo',
        'ejecutado_por',
        'fecha_ejecucion',
        'estado',
        'total_departamentos',
        'cargos_creados',
        'cargos_omitidos',
        'errores',
        'total_valor',
        'origen',
        'metadata',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('lotes_generacion_cargos');
    }

    /** @return HasMany<CargoEloquentModel, $this> */
    public function cargos(): HasMany
    {
        return $this->hasMany(CargoEloquentModel::class, 'lote_generacion_id');
    }

    protected function casts(): array
    {
        return [
            'periodo' => 'immutable_date',
            'fecha_ejecucion' => 'immutable_datetime',
            'estado' => EstadoLoteGeneracion::class,
            'total_departamentos' => 'integer',
            'cargos_creados' => 'integer',
            'cargos_omitidos' => 'integer',
            'total_valor' => 'decimal:4',
            'origen' => OrigenCargo::class,
            'errores' => 'array',
            'metadata' => 'array',
        ];
    }
}
