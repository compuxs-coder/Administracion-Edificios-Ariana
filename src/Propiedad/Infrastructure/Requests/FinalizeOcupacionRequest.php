<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class FinalizeOcupacionRequest extends FormRequest
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
            'fecha_fin' => ['required', Rule::date()->format('Y-m-d')->beforeOrEqual(CarbonImmutable::now($timezone)->format('Y-m-d'))],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
