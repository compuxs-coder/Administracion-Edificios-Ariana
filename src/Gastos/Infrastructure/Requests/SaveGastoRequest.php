<?php

namespace Src\Gastos\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\TipoPagoGasto;

final class SaveGastoRequest extends FormRequest
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
            'contrato_id' => ['nullable', 'uuid'],
            'fecha_gasto' => ['required', 'date_format:Y-m-d'],
            'fecha_vencimiento' => ['nullable', 'required_if:tipo_pago,credito', 'date_format:Y-m-d', 'after_or_equal:fecha_gasto'],
            'concepto' => ['required', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'monto' => ['required', 'decimal:0,4', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'tipo_pago' => ['required', Rule::enum(TipoPagoGasto::class)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'concepto' => trim((string) $this->input('concepto')),
            'referencia' => ($reference = trim((string) $this->input('referencia'))) === '' ? null : $reference,
            'monto' => trim((string) $this->input('monto')),
        ]);
    }
}
