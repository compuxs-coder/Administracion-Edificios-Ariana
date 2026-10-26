<?php

namespace Src\Gobierno\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Gobierno\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class LimpiezaArchivoExportacionEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['exportacion_id', 'disco', 'ruta_privada', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('limpieza_archivos_exportacion');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
