<?php

namespace Src\Gastos\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Gastos\Domain\Enums\FormaDesembolso;

final class SaveDesembolsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edificio = $this->route('edificio');

        return $edificio instanceof EdificioEloquentModel
            && ($this->user()?->can('access', [$edificio, PermisoEdificio::DESEMBOLSOS_REGISTRAR]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'proveedor_id' => ['required', 'uuid'],
            'fecha_desembolso' => ['required', 'date_format:Y-m-d'],
            'monto' => ['required', 'decimal:0,4', 'gt:0'],
            'forma_pago' => ['required', Rule::enum(FormaDesembolso::class)],
            'referencia' => ['nullable', 'string', 'max:120'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'aplicacion_fingerprint' => ['required', 'string', 'size:64'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (in_array($this->input('forma_pago'), [
                FormaDesembolso::TRANSFERENCIA->value,
                FormaDesembolso::DEPOSITO->value,
                FormaDesembolso::CHEQUE->value,
            ], true) && $this->input('referencia') === null) {
                $validator->errors()->add('referencia', 'La referencia es obligatoria para esta forma de desembolso.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'monto' => trim((string) $this->input('monto')),
            'referencia' => $this->nullableTrim($this->input('referencia')),
            'observacion' => $this->nullableTrim($this->input('observacion')),
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
