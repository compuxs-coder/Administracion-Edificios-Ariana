<?php

namespace Src\Gobierno\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Gobierno\Domain\Enums\TipoEventoExportacionAuditoria;
use Src\Gobierno\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class BitacoraExportacionAuditoriaEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = [
        'exportacion_id', 'tipo', 'actor_tipo', 'actor_user_id', 'intento', 'error_codigo', 'created_at',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('bitacora_exportacion_auditoria');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoExportacionAuditoria::class,
            'intento' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
