<?php

namespace Src\Gobierno\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Gobierno\Domain\Enums\EstadoExportacionAuditoria;
use Src\Gobierno\Domain\Enums\FormatoExportacionAuditoria;
use Src\Gobierno\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ExportacionAuditoriaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'solicitante_user_id', 'idempotency_key', 'formato', 'estado', 'fuente', 'tipo',
        'desde_utc', 'hasta_utc', 'corte_at', 'intentos', 'lease_token', 'lease_until',
        'disponible_at', 'total_eventos', 'eventos_procesados', 'disco', 'ruta_privada',
        'nombre_archivo', 'mime_type', 'tamano_bytes', 'sha256', 'error_codigo',
        'iniciada_at', 'completada_at', 'fallida_at', 'expires_at', 'archivo_eliminado_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('exportaciones_auditoria');
    }

    /** @return HasMany<ExportacionAuditoriaEdificioEloquentModel, $this> */
    public function edificios(): HasMany
    {
        return $this->hasMany(ExportacionAuditoriaEdificioEloquentModel::class, 'exportacion_id');
    }

    /** @return HasMany<BitacoraExportacionAuditoriaEloquentModel, $this> */
    public function bitacora(): HasMany
    {
        return $this->hasMany(BitacoraExportacionAuditoriaEloquentModel::class, 'exportacion_id');
    }

    protected function casts(): array
    {
        return [
            'formato' => FormatoExportacionAuditoria::class,
            'estado' => EstadoExportacionAuditoria::class,
            'desde_utc' => 'immutable_datetime',
            'hasta_utc' => 'immutable_datetime',
            'corte_at' => 'immutable_datetime',
            'intentos' => 'integer',
            'lease_until' => 'immutable_datetime',
            'disponible_at' => 'immutable_datetime',
            'total_eventos' => 'integer',
            'eventos_procesados' => 'integer',
            'tamano_bytes' => 'integer',
            'iniciada_at' => 'immutable_datetime',
            'completada_at' => 'immutable_datetime',
            'fallida_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'archivo_eliminado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
