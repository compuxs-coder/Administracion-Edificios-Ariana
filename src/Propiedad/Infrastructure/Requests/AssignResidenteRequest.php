<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Propiedad\Domain\Enums\TipoOcupacion;

final class AssignResidenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::PROPIEDAD_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $edificio = $this->route('edificio');
        $timezone = $edificio instanceof EdificioEloquentModel ? $edificio->timezone : (string) config('app.timezone');

        return [
            'residente_id' => ['required', 'uuid'],
            'tipo_ocupacion' => ['required', Rule::enum(TipoOcupacion::class)],
            'fecha_inicio' => ['required', Rule::date()->format('Y-m-d')->beforeOrEqual(CarbonImmutable::now($timezone)->format('Y-m-d'))],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['residente_id' => strtolower((string) $this->input('residente_id'))]);
    }
}
