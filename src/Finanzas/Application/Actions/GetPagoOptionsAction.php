<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\PagoRepositoryInterface;

final readonly class GetPagoOptionsAction
{
    public function __construct(private PagoRepositoryInterface $pagos) {}

    /** @return array<string, mixed> */
    public function execute(string $userId): array
    {
        return $this->pagos->options($userId);
    }
}
