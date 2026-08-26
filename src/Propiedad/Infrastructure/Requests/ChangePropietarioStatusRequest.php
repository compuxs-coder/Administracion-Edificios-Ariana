<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Propiedad\Domain\Enums\EstadoPropietario;
use Src\Propiedad\Infrastructure\Models\PropietarioEloquentModel;

final class ChangePropietarioStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $propietario = $this->route('propietario');

        return $propietario instanceof PropietarioEloquentModel
            && ($this->user()?->can('changeStatus', $propietario) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(EstadoPropietario::class)]];
    }
}
