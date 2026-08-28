<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class GenerateCargosRequest extends FormRequest
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
            'periodo' => ['required', 'date_format:Y-m'],
            'concepto_cobro_id' => ['nullable', 'uuid'],
        ];
    }
}
