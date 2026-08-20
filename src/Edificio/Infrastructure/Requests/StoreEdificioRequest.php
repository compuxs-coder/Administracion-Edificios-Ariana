<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class StoreEdificioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EdificioEloquentModel::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:2', 'max:150'],
            'ruc' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('edificios', 'ruc'),
            ],
            'direccion' => ['required', 'string', 'min:3', 'max:255'],
            'ciudad' => ['required', 'string', 'min:2', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:150'],
        ];
    }
}
