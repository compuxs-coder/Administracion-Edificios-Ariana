<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveCargoManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::CARGOS_CREAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'departamento_id' => ['required', 'uuid'],
            'concepto_cobro_id' => ['required', 'uuid'],
            'periodo' => ['required', 'date_format:Y-m'],
            'fecha_emision' => ['required', 'date_format:Y-m-d'],
            'fecha_vencimiento' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_emision'],
            'valor' => ['required', 'decimal:0,4', 'gt:0'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'valor' => trim((string) $this->input('valor')),
            'descripcion' => ($value = trim((string) $this->input('descripcion'))) === '' ? null : $value,
        ]);
    }
}
