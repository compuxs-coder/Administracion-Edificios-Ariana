<?php

namespace Src\AreasComunes\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\AreasComunes\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class HorarioReglaEspacioComunEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['edificio_id', 'regla_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('horarios_regla_espacio_comun');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
