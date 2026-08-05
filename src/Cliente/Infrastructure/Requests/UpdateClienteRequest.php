<?php

namespace Src\Cliente\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        // Convert camelCase to snake_case for validation only if fields are present
        $data = [];

        if ($this->has('tipoDocumento')) {
            $data['tipo_documento'] = $this->tipoDocumento;
        }

        if ($this->has('numeroDocumento')) {
            $data['numero_documento'] = $this->numeroDocumento;
        }

        if ($this->has('razonSocial')) {
            $data['razon_social'] = $this->razonSocial;
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        // Obtener el ID desde la ruta (puede ser 'id' o 'cliente' dependiendo de si es web o API)
        $clienteId = $this->route('id') ?? $this->route('cliente');

        return [
            'tipo_documento' => 'sometimes|string|in:DNI,RUC,CE,PASAPORTE',
            'numero_documento' => 'sometimes|string|unique:clientes,numero_documento,' . $clienteId . ',id',
            'razon_social' => 'sometimes|string|max:255',
            'direccion' => 'sometimes|string|max:255',
            'telefono' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:clientes,email,' . $clienteId . ',id'
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo_documento' => 'tipo de documento',
            'numero_documento' => 'número de documento',
            'razon_social' => 'razón social',
            'direccion' => 'dirección',
            'telefono' => 'teléfono',
            'email' => 'email',
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_documento.in' => 'El tipo de documento debe ser DNI, RUC, CE o PASAPORTE',
            'numero_documento.unique' => 'Este número de documento ya está registrado',
            'razon_social.required' => 'La razón social es obligatoria',
            'direccion.required' => 'La dirección es obligatoria',
            'telefono.required' => 'El teléfono es obligatorio',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'Este email ya está registrado'
        ];
    }
}
