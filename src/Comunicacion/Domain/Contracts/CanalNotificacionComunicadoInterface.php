<?php

namespace Src\Comunicacion\Domain\Contracts;

interface CanalNotificacionComunicadoInterface
{
    /** @param array<string, mixed> $delivery */
    public function send(array $delivery): ?string;
}
