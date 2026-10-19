<?php

namespace Src\Comunicacion\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ComunicadoPublicadoNotification extends Notification
{
    use Queueable;

    /** @param list<array{name: string, url: string}> $documents */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $building,
        private readonly array $documents,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->markdown('mail.comunicado-publicado', [
                'titulo' => $this->title,
                'cuerpo' => $this->body,
                'edificio' => $this->building,
                'documentos' => $this->documents,
            ]);
    }
}
