<?php

namespace Src\Producto\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        // Convert camelCase to snake_case for validation
        $this->merge([
            'categoria_id' => $this->categoriaId,
            'precio_unitario' => $this->precioUnitario,
        ]);
    }

    public function rules(): array
    {
        return [
            'categoria_id' => 'required|string|exists:categorias,id',
            'codigo' => 'required|string|unique:productos,codigo',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio_unitario' => 'required|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'tipo' => 'nullable|string|in:bien,servicio',
            'activo' => 'nullable|boolean'
        ];
    }

    public function attributes(): array
    {
        return [
            'categoria_id' => 'categoría',
            'precio_unitario' => 'precio unitario',
            'codigo' => 'código',
            'nombre' => 'nombre',
            'descripcion' => 'descripción',
            'stock' => 'stock',
            'tipo' => 'tipo',
            'activo' => 'activo',
        ];
    }

    public function messages(): array
    {
        return [
            'categoria_id.required' => 'La categoría es obligatoria',
            'categoria_id.exists' => 'La categoría seleccionada no existe',
            'codigo.required' => 'El código es obligatorio',
            'codigo.unique' => 'Este código ya está registrado',
            'nombre.required' => 'El nombre es obligatorio',
            'precio_unitario.required' => 'El precio unitario es obligatorio',
            'precio_unitario.min' => 'El precio unitario debe ser mayor o igual a 0',
            'stock.min' => 'El stock debe ser mayor o igual a 0',
            'tipo.in' => 'El tipo debe ser bien o servicio'
        ];
    }
}
