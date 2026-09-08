<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveTorreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::ESTRUCTURA_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var EdificioEloquentModel $edificio */
        $edificio = $this->route('edificio');

        return [
            'codigo' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('torres', 'codigo')
                    ->where('edificio_id', $edificio->id)
                    ->ignore($this->route('torre')),
            ],
            'nombre' => ['required', 'string', 'min:2', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['codigo' => mb_strtoupper(trim((string) $this->input('codigo')))]);
    }
}
