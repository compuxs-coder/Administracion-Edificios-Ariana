<?php

namespace Src\Comunicacion\Infrastructure\Notifications;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use LogicException;
use Src\Comunicacion\Domain\Contracts\CanalNotificacionComunicadoInterface;
use Src\Comunicacion\Domain\Enums\CanalNotificacionComunicado;

final class CorreoComunicadoChannel implements CanalNotificacionComunicadoInterface
{
    public function send(array $delivery): ?string
    {
        if ($delivery['channel'] !== CanalNotificacionComunicado::EMAIL->value) {
            throw new LogicException('El canal de notificación solicitado no está implementado.');
        }
        $expiresAt = now()->addMinutes((int) config('comunicacion.document_link_minutes'));
        $documents = array_map(static fn (array $document): array => [
            'name' => $document['name'],
            'url' => URL::temporarySignedRoute('comunicados.documents.signed', $expiresAt, [
                'entrega' => $delivery['id'],
                'documento' => $document['id'],
            ]),
        ], $delivery['documents']);
        $names = $delivery['recipientNames'];
        $displayName = count($names) === 1 ? $names[0] : 'Comunidad '.$delivery['building'];
        Notification::route('mail', [$delivery['destination'] => $displayName])->notify(
            new ComunicadoPublicadoNotification(
                $delivery['title'],
                $delivery['body'],
                $delivery['building'],
                $documents,
            ),
        );

        return null;
    }
}
