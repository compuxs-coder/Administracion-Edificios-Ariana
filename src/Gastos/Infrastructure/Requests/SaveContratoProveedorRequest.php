<?php

namespace Src\Gastos\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveContratoProveedorRequest extends FormRequest
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
            'proveedor_id' => ['required', 'uuid'],
            'referencia' => ['required', 'string', 'max:80'],
            'objeto' => ['required', 'string', 'max:255'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'monto_total' => ['nullable', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'referencia' => trim((string) $this->input('referencia')),
            'objeto' => trim((string) $this->input('objeto')),
            'monto_total' => $this->input('monto_total') === null ? null : trim((string) $this->input('monto_total')),
        ]);
    }
}
