<?php

namespace Src\Categoria\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class CategoriaEloquentModel extends Model
{
    use HasUuid;

    protected $table = 'categorias';

    protected $fillable = [
        'id',
        'nombre',
        'descripcion',
        'activo'
    ];

    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
