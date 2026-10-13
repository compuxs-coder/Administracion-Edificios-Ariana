<?php

namespace Src\AreasComunes\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveEspacioComunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::AREAS_COMUNES_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'updated_at' => $this->isMethod('post') ? ['prohibited'] : ['required', 'date'],
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'nombre' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:10000'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:9999'],
            'torre_id' => ['nullable', 'uuid'],
            'piso_id' => ['nullable', 'uuid'],
            'ubicacion_detalle' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('torre_id') !== null && $this->input('piso_id') !== null) {
                $validator->errors()->add('ubicacion', 'Seleccione como máximo una torre o un piso.');
            }
            if ($this->input('torre_id') === null && $this->input('piso_id') === null && trim((string) $this->input('ubicacion_detalle')) === '') {
                $validator->errors()->add('ubicacion_detalle', 'Indique una torre, un piso o un detalle de ubicación.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['descripcion', 'torre_id', 'piso_id', 'ubicacion_detalle'] as $field) {
            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' ? null : $value;
        }
        $this->merge([
            ...$normalized,
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'nombre' => trim((string) $this->input('nombre')),
        ]);
    }
}
