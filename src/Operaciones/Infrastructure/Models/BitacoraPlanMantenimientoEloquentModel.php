<?php

namespace Src\Operaciones\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Operaciones\Domain\Enums\TipoActorOperativo;
use Src\Operaciones\Domain\Enums\TipoEventoPlanMantenimiento;
use Src\Operaciones\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class BitacoraPlanMantenimientoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['edificio_id', 'plan_id', 'tipo', 'actor_tipo', 'actor_user_id', 'detalle', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('bitacora_plan_mantenimiento');
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoPlanMantenimiento::class,
            'actor_tipo' => TipoActorOperativo::class,
            'detalle' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
