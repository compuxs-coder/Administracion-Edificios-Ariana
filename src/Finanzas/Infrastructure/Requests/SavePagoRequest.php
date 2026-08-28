<?php

namespace Src\Finanzas\Infrastructure\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Finanzas\Domain\Enums\FormaPago;

final class SavePagoRequest extends FormRequest
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
            'departamento_id' => ['required', 'uuid'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'valor_recibido' => ['required', 'decimal:0,4', 'gt:0'],
            'forma_pago' => ['required', Rule::enum(FormaPago::class)],
            'referencia' => ['nullable', 'string', 'max:120'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (in_array($this->input('forma_pago'), [FormaPago::TRANSFERENCIA->value, FormaPago::DEPOSITO->value, FormaPago::CHEQUE->value], true)
                && $this->input('referencia') === null) {
                $validator->errors()->add('referencia', 'La referencia es obligatoria para esta forma de pago.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'valor_recibido' => trim((string) $this->input('valor_recibido')),
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
