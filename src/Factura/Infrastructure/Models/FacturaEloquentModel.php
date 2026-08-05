<?php

namespace Src\Factura\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class FacturaEloquentModel extends Model
{
    protected $table = 'facturas';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'ruc',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
