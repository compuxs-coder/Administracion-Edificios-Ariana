<?php

namespace Src\Gastos\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class UpdateProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::GASTOS_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo_persona' => ['prohibited'],
            'nombres' => ['prohibited'],
            'apellidos' => ['prohibited'],
            'razon_social' => ['prohibited'],
            'tipo_identificacion' => ['prohibited'],
            'identificacion' => ['prohibited'],
            'telefono' => ['prohibited'],
            'celular' => ['prohibited'],
            'correo' => ['prohibited'],
            'direccion' => ['prohibited'],
            'nombre_comercial' => ['nullable', 'string', 'max:180'],
            'contacto' => ['nullable', 'string', 'max:180'],
            'telefono_comercial' => ['nullable', 'string', 'max:30'],
            'correo_comercial' => ['nullable', 'email', 'max:255'],
            'direccion_comercial' => ['nullable', 'string', 'max:255'],
            'dias_credito' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('correo_comercial')) {
            $value = trim((string) $this->input('correo_comercial'));
            $this->merge(['correo_comercial' => $value === '' ? null : mb_strtolower($value)]);
        }
    }
}
