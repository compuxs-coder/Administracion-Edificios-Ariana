<?php

namespace Src\Cliente\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ClienteEloquentModel extends Model
{
    use HasUuid;

    protected $table = 'clientes';

    protected $fillable = [
        'id',
        'tipo_documento',
        'numero_documento',
        'razon_social',
        'direccion',
        'telefono',
        'email',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
