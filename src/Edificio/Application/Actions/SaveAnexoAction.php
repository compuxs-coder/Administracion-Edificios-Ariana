<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;
use Src\Edificio\Domain\Enums\TipoAnexo;

final readonly class SaveAnexoAction
{
    public function __construct(private EstructuraRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function execute(
        TipoAnexo $tipo,
        string $edificioId,
        ?string $anexoId,
        array $data,
    ): array {
        return $this->repository->saveAnexo($tipo, $edificioId, $anexoId, $data);
    }
}
