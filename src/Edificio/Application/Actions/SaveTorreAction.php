<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;

final readonly class SaveTorreAction
{
    public function __construct(private EstructuraRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(string $edificioId, ?string $torreId, array $data): array
    {
        return $this->repository->saveTorre($edificioId, $torreId, $data);
    }
}
