<?php

namespace Src\Tesoreria\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Domain\Enums\NaturalezaMovimientoTesoreria;

final class StoreMovimientoTesoreriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::MOVIMIENTOS_TESORERIA_REGISTRAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_movimiento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'naturaleza' => ['required', Rule::enum(NaturalezaMovimientoTesoreria::class)],
            'monto' => ['required', 'decimal:0,4', 'gt:0'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $reference = trim((string) $this->input('referencia'));
        $this->merge([
            'monto' => trim((string) $this->input('monto')),
            'referencia' => $reference === '' ? null : $reference,
            'descripcion' => trim((string) $this->input('descripcion')),
        ]);
    }
}
