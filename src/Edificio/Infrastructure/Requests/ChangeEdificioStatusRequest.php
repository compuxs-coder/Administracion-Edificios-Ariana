<?php

namespace Src\Edificio\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class ChangeEdificioStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('changeStatus', $edificio) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(EstadoEdificio::class)],
        ];
    }
}
