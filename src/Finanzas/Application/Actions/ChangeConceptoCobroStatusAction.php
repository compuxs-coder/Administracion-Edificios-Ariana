<?php

namespace Src\Finanzas\Application\Actions;

use Src\Finanzas\Domain\Contracts\ConceptoCobroRepositoryInterface;
use Src\Finanzas\Domain\Enums\EstadoConceptoCobro;

final readonly class ChangeConceptoCobroStatusAction
{
    public function __construct(private ConceptoCobroRepositoryInterface $conceptos) {}

    public function execute(
        string $userId,
        string $edificioId,
        string $conceptoId,
        EstadoConceptoCobro $estado,
    ): void {
        $this->conceptos->changeStatus($userId, $edificioId, $conceptoId, $estado);
    }
}
