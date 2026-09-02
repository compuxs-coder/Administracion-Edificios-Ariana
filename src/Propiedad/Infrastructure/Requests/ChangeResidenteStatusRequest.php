<?php

namespace Src\Propiedad\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Propiedad\Domain\Enums\EstadoResidente;
use Src\Propiedad\Infrastructure\Models\ResidenteEloquentModel;

final class ChangeResidenteStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $residente = $this->route('residente');

        return $residente instanceof ResidenteEloquentModel
            && ($this->user()?->can('changeStatus', $residente) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['estado' => ['required', Rule::enum(EstadoResidente::class)]];
    }
}
