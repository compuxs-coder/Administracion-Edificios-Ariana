<?php

namespace Src\Producto\Infrastructure\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Categoria\Infrastructure\Models\CategoriaEloquentModel;

class ProductoEloquentModel extends Model
{
    use HasUuid;

    protected $table = 'productos';

    protected $fillable = [
        'id',
        'categoria_id',
        'codigo',
        'nombre',
        'descripcion',
        'precio_unitario',
        'stock',
        'tipo',
        'activo',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'stock' => 'integer',
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaEloquentModel::class);
    }
}
