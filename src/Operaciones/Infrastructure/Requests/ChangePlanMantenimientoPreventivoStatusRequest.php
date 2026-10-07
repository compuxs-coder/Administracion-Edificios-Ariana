<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Operaciones\Domain\Enums\EstadoPlanMantenimientoPreventivo;

final class ChangePlanMantenimientoPreventivoStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::MANTENIMIENTO_PREVENTIVO_PROGRAMAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(EstadoPlanMantenimientoPreventivo::class)],
            'proxima_fecha_programada' => ['nullable', 'required_if:estado,activo', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $date = trim((string) $this->input('proxima_fecha_programada'));
        $this->merge(['proxima_fecha_programada' => $date === '' ? null : $date]);
    }
}
