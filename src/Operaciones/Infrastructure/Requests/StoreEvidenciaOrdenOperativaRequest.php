<?php

namespace Src\Operaciones\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class StoreEvidenciaOrdenOperativaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::OPERACIONES_GESTIONAR]) ?? false);
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('descripcion'));
        $this->merge(['descripcion' => $description === '' ? null : $description]);
    }
}
