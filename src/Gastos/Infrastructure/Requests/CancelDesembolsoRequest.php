<?php

namespace Src\Gastos\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class CancelDesembolsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::DESEMBOLSOS_ANULAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:2000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => trim((string) $this->input('motivo'))]);
    }
}
