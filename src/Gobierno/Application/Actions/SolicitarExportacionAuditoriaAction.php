<?php

namespace Src\Gobierno\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Infrastructure\Jobs\GenerarExportacionAuditoriaJob;

final class SolicitarExportacionAuditoriaAction
{
    public function __construct(
        private readonly AuditoriaRepositoryInterface $audit,
        private readonly ExportacionAuditoriaRepositoryInterface $exports,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $userId, array $data): array
    {
        $data['idempotency_key'] = strtolower((string) $data['idempotency_key']);
        $from = CarbonImmutable::parse($data['desde_utc'])->utc();
        $until = CarbonImmutable::parse($data['hasta_utc'])->utc();
        if ($from->format('u') !== '000000') {
            throw ValidationException::withMessages(['desde_utc' => 'La fecha sólo admite precisión de segundos.']);
        }
        if ($until->format('u') !== '000000') {
            throw ValidationException::withMessages(['hasta_utc' => 'La fecha sólo admite precisión de segundos.']);
        }
        $from = $from->startOfSecond();
        $until = $until->startOfSecond();
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        if (! $from->isBefore($until)) {
            throw ValidationException::withMessages(['hasta_utc' => 'El fin debe ser posterior al inicio.']);
        }
        if ($from->diffInSeconds($until) > (int) config('gobierno.exports.max_range_days') * 86400) {
            throw ValidationException::withMessages(['hasta_utc' => 'El rango supera el máximo permitido.']);
        }
        if ($from->isAfter($now)) {
            throw ValidationException::withMessages(['desde_utc' => 'El inicio no puede estar en el futuro.']);
        }
        $cutoff = $until->isAfter($now) ? $now : $until;
        if (! $from->isBefore($cutoff)) {
            throw ValidationException::withMessages(['desde_utc' => 'El rango debe incluir al menos un segundo UTC cerrado.']);
        }
        $buildingIds = array_values(array_unique($data['edificios']));
        $scope = $this->audit->exportScopeForUser(
            $userId,
            $buildingIds,
            $data['fuente'] ?? null,
            $data['tipo'] ?? null,
        );
        $result = $this->exports->request($userId, [
            ...$data,
            'edificios' => $buildingIds,
            'desde_utc' => $from,
            'hasta_utc' => $until,
            'corte_at' => $cutoff,
        ], $scope);
        if ($result['created']) {
            GenerarExportacionAuditoriaJob::dispatch($result['id'])
                ->onConnection((string) config('gobierno.exports.queue_connection'))
                ->onQueue((string) config('gobierno.exports.queue'))
                ->afterCommit();
        }

        return $result;
    }
}
