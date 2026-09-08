<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class StoreEvidenciaPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::EVIDENCIAS_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $descripcion = trim((string) $this->input('descripcion'));
        $this->merge(['descripcion' => $descripcion === '' ? null : $descripcion]);
    }
}
