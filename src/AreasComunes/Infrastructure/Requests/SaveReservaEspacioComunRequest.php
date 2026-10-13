<?php

namespace Src\AreasComunes\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveReservaEspacioComunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::RESERVAS_AREAS_COMUNES_REGISTRAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ocupacion_id' => ['required', 'uuid'],
            'inicio' => ['required', 'date_format:Y-m-d\TH:i'],
            'fin' => ['required', 'date_format:Y-m-d\TH:i', 'after:inicio'],
            'cantidad_asistentes' => ['required', 'integer', 'min:1', 'max:9999'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $observations = trim((string) $this->input('observaciones'));
        $this->merge(['observaciones' => $observations === '' ? null : $observations]);
    }
}
