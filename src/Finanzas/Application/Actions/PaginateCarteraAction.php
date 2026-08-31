<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\CarteraReadRepositoryInterface;

final readonly class PaginateCarteraAction
{
    public function __construct(private CarteraReadRepositoryInterface $cartera) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        return $this->cartera->paginate($userId, $filters);
    }
}
