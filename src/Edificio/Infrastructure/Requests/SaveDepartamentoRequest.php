<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class SaveDepartamentoRequest extends FormRequest
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
            'piso_id' => ['required', 'uuid'],
            'codigo' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('departamentos', 'codigo')
                    ->where('edificio_id', $edificio->id)
                    ->ignore($this->route('departamento')),
            ],
            'nombre' => ['required', 'string', 'min:2', 'max:100'],
            'alicuota' => ['required', 'numeric', 'decimal:0,6', 'between:0,100'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'parqueaderos' => ['sometimes', 'array', 'max:50'],
            'parqueaderos.*' => ['required', 'uuid', 'distinct:strict'],
            'bodegas' => ['sometimes', 'array', 'max:50'],
            'bodegas.*' => ['required', 'uuid', 'distinct:strict'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'parqueaderos' => $this->input('parqueaderos', []),
            'bodegas' => $this->input('bodegas', []),
        ]);
    }
}
