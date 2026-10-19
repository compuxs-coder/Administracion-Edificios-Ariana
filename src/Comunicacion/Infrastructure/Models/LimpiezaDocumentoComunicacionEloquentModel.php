<?php

namespace Src\Comunicacion\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Src\Comunicacion\Infrastructure\Models\Concerns\UsesApplicationSchema;

final class LimpiezaDocumentoComunicacionEloquentModel extends Model
{
    use HasUuid, UsesApplicationSchema;

    public $timestamps = false;

    protected $fillable = ['disco', 'ruta_privada', 'created_at'];

    public function getTable(): string
    {
        return $this->qualifiedTable('limpieza_documentos_comunicacion');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
