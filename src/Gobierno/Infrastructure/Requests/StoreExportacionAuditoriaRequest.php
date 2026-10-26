<?php

namespace Src\Gobierno\Infrastructure\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;
use Src\Gobierno\Domain\CatalogoAuditoria;
use Src\Gobierno\Domain\Enums\FormatoExportacionAuditoria;
use Throwable;

final class StoreExportacionAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'formato' => ['required', new Enum(FormatoExportacionAuditoria::class)],
            'edificios' => ['required', 'array', 'min:1', 'max:50'],
            'edificios.*' => ['required', 'uuid', 'distinct:strict'],
            'fuente' => ['nullable', Rule::in(CatalogoAuditoria::SOURCES)],
            'tipo' => ['nullable', Rule::in(CatalogoAuditoria::EVENT_TYPES)],
            'desde_utc' => ['required', 'date'],
            'hasta_utc' => ['required', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $source = $this->input('fuente');
            $eventType = $this->input('tipo');
            if (is_string($source) && is_string($eventType)
                && ! in_array($eventType, CatalogoAuditoria::SOURCE_EVENT_TYPES[$source] ?? [], true)) {
                $validator->errors()->add('tipo', 'El tipo de evento no pertenece a la fuente seleccionada.');
            }
            if ($validator->errors()->hasAny(['desde_utc', 'hasta_utc'])) {
                return;
            }
            foreach (['desde_utc', 'hasta_utc'] as $attribute) {
                $raw = (string) $this->input($attribute);
                if (preg_match('/(?:Z|[+-]00:00)$/i', $raw) !== 1) {
                    $validator->errors()->add($attribute, 'La fecha debe incluir una zona UTC explícita.');

                    continue;
                }
                try {
                    $date = CarbonImmutable::parse($raw);
                    if ($date->getOffset() !== 0) {
                        $validator->errors()->add($attribute, 'La fecha debe expresarse en UTC.');
                    }
                    if ($date->format('u') !== '000000') {
                        $validator->errors()->add($attribute, 'La fecha sólo admite precisión de segundos.');
                    }
                } catch (Throwable) {
                    $validator->errors()->add($attribute, 'La fecha no es válida.');
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => strtolower(trim((string) $this->input('idempotency_key'))),
            'formato' => strtolower(trim((string) $this->input('formato'))),
            'fuente' => $this->filled('fuente') ? trim((string) $this->input('fuente')) : null,
            'tipo' => $this->filled('tipo') ? trim((string) $this->input('tipo')) : null,
        ]);
    }
}
