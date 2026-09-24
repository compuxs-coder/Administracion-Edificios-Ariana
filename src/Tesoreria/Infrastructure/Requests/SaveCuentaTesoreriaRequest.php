<?php

namespace Src\Tesoreria\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Tesoreria\Domain\Enums\TipoCuentaTesoreria;

final class SaveCuentaTesoreriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('edificio');

        return $building instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$building, PermisoEdificio::CUENTAS_TESORERIA_GESTIONAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:30'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::enum(TipoCuentaTesoreria::class)],
            'entidad_financiera' => ['nullable', 'required_if:tipo,bancaria', 'prohibited_unless:tipo,bancaria', 'string', 'max:120'],
            'tipo_cuenta_bancaria' => ['nullable', 'required_if:tipo,bancaria', 'prohibited_unless:tipo,bancaria', 'string', 'max:60'],
            'numero_cuenta' => ['nullable', 'required_if:tipo,bancaria', 'prohibited_unless:tipo,bancaria', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => mb_strtoupper(trim((string) $this->input('codigo'))),
            'nombre' => trim((string) $this->input('nombre')),
            'entidad_financiera' => $this->nullableTrim($this->input('entidad_financiera')),
            'tipo_cuenta_bancaria' => $this->nullableTrim($this->input('tipo_cuenta_bancaria')),
            'numero_cuenta' => $this->nullableTrim($this->input('numero_cuenta')),
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
