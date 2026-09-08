<?php

namespace Src\Edificio\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvitacionEdificioNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $invitationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitación para administrar un edificio')
            ->line('Has recibido una invitación para colaborar en Administración Ariana.')
            ->action('Revisar invitación', $this->invitationUrl)
            ->line('La invitación vence en 72 horas y sólo puede aceptarse con el correo invitado.');
    }
}
