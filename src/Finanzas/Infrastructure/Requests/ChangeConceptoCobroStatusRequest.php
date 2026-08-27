<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;

final class ChangeConceptoCobroStatusRequest extends FormRequest
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
        return ['estado' => ['required', Rule::enum(EstadoConceptoCobro::class)]];
    }
}
