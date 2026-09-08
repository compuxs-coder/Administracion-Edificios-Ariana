<?php

namespace Src\Edificio\Application\Actions;

use Illuminate\Support\Facades\Notification;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Infrastructure\Notifications\InvitacionEdificioNotification;

final readonly class InviteEdificioMemberAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function execute(string $actorId, string $edificioId, string $email, string $role): void
    {
        $result = $this->repository->invite($actorId, $edificioId, $email, $role);

        Notification::route('mail', $result['invitation']['email'])->notify(
            new InvitacionEdificioNotification(
                invitationUrl: route('invitaciones-edificio.show', $result['plainToken']),
            ),
        );
    }
}
