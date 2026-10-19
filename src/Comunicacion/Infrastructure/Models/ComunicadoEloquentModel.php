<?php

namespace Src\Comunicacion\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Comunicacion\Domain\Enums\EstadoComunicado;
use Src\Comunicacion\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class ComunicadoEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    protected $fillable = [
        'edificio_id', 'titulo', 'cuerpo', 'audiencia_propietarios', 'audiencia_residentes',
        'estado', 'version', 'creado_por_user_id', 'actualizado_por_user_id',
        'publicado_por_user_id', 'publicado_at', 'archivado_por_user_id', 'archivado_at',
        'total_candidatos', 'total_entregas', 'total_omitidos',
    ];

    public function getTable(): string
    {
        return $this->qualifiedTable('comunicados');
    }

    /** @return HasMany<DocumentoComunicadoEloquentModel, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoComunicadoEloquentModel::class, 'comunicado_id');
    }

    /** @return HasMany<DestinatarioComunicadoEloquentModel, $this> */
    public function destinatarios(): HasMany
    {
        return $this->hasMany(DestinatarioComunicadoEloquentModel::class, 'comunicado_id');
    }

    /** @return HasMany<EntregaComunicadoEloquentModel, $this> */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaComunicadoEloquentModel::class, 'comunicado_id');
    }

    /** @return HasMany<BitacoraComunicacionEloquentModel, $this> */
    public function bitacora(): HasMany
    {
        return $this->hasMany(BitacoraComunicacionEloquentModel::class, 'comunicado_id');
    }

    protected function casts(): array
    {
        return [
            'audiencia_propietarios' => 'boolean',
            'audiencia_residentes' => 'boolean',
            'estado' => EstadoComunicado::class,
            'version' => 'integer',
            'total_candidatos' => 'integer',
            'total_entregas' => 'integer',
            'total_omitidos' => 'integer',
            'publicado_at' => 'immutable_datetime',
            'archivado_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
