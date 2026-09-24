<?php

namespace Src\Tesoreria\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;

final class StoreConciliacionTesoreriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::CONCILIACIONES_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'desembolso_id' => ['required', 'uuid'],
            'nota' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = trim((string) $this->input('nota'));
        $this->merge(['nota' => $note === '' ? null : $note]);
    }
}
