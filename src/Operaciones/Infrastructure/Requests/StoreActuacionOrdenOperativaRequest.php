<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class StoreActuacionOrdenOperativaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::OPERACIONES_GESTIONAR]) ?? false);
    }

    public function rules(): array
    {
        return ['descripcion' => ['required', 'string', 'max:5000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['descripcion' => trim((string) $this->input('descripcion'))]);
    }
}
