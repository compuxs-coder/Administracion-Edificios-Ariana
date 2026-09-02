<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class FinalizeOcupacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('update', $edificio) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
