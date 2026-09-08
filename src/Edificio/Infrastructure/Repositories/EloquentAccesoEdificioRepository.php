<?php

namespace Src\Edificio\Infrastructure\Repositories;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoInvitacion;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EventoAccesoEdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\InvitacionEdificioEloquentModel;

final class EloquentAccesoEdificioRepository implements AccesoEdificioRepositoryInterface
{
    public function hasPermission(string $userId, string $edificioId, PermisoEdificio $permission): bool
    {
        return $this->permissionQuery($userId, $permission)
            ->where('assignment.edificio_id', $edificioId)
            ->exists();
    }

    public function buildingIds(string $userId, PermisoEdificio $permission): array
    {
        return $this->permissionQuery($userId, $permission)
            ->distinct()
            ->orderBy('assignment.edificio_id')
            ->pluck('assignment.edificio_id')
            ->all();
    }

    public function permissionMap(string $userId): array
    {
        $assignments = $this->table('edificio_usuario_roles');
        $memberships = $this->table('edificio_usuario');
        $rolePermissions = $this->table('rol_permisos');

        return DB::table($assignments.' as assignment')
            ->join($memberships.' as membership', static function ($join): void {
                $join->on('membership.edificio_id', '=', 'assignment.edificio_id')
                    ->on('membership.user_id', '=', 'assignment.user_id');
            })
            ->join($rolePermissions.' as role_permission', 'role_permission.rol_codigo', '=', 'assignment.rol_codigo')
            ->where('assignment.user_id', $userId)
            ->whereNull('membership.revoked_at')
            ->orderBy('assignment.edificio_id')
            ->orderBy('role_permission.permiso_codigo')
            ->get(['assignment.edificio_id', 'role_permission.permiso_codigo'])
            ->groupBy('edificio_id')
            ->map(static fn ($permissions): array => $permissions->pluck('permiso_codigo')->unique()->values()->all())
            ->all();
    }

    public function assignAdministrator(string $edificioId, string $userId): void
    {
        $memberships = $this->table('edificio_usuario');
        $assignments = $this->table('edificio_usuario_roles');

        DB::table($memberships)
            ->where('edificio_id', $edificioId)
            ->where('user_id', $userId)
            ->update(['creado_por_user_id' => $userId, 'revoked_at' => null, 'revocado_por_user_id' => null]);

        $inserted = DB::table($assignments)->insertOrIgnore([
            'edificio_id' => $edificioId,
            'user_id' => $userId,
            'rol_codigo' => RolEdificio::ADMINISTRADOR->value,
            'asignado_por_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            $this->recordEvent(
                edificioId: $edificioId,
                type: 'membresia_creada',
                affectedUserId: $userId,
                actorId: $userId,
                newRoles: [RolEdificio::ADMINISTRADOR->value],
            );
        }
    }

    public function managementData(string $edificioId): array
    {
        $memberships = $this->table('edificio_usuario');
        $assignments = $this->table('edificio_usuario_roles');
        $rolesTable = $this->table('roles');
        $rolePermissions = $this->table('rol_permisos');
        $invitations = $this->table('invitaciones_edificio');
        $events = $this->table('eventos_acceso_edificio');
        $users = $this->usersTable();

        $roles = DB::table($rolesTable.' as role')
            ->leftJoin($rolePermissions.' as role_permission', 'role_permission.rol_codigo', '=', 'role.codigo')
            ->orderBy('role.nombre')
            ->get(['role.codigo', 'role.nombre', 'role.descripcion', 'role_permission.permiso_codigo'])
            ->groupBy('codigo')
            ->map(static function ($rows): array {
                $role = $rows->first();

                return [
                    'codigo' => $role->codigo,
                    'nombre' => $role->nombre,
                    'descripcion' => $role->descripcion,
                    'permisos' => $rows->pluck('permiso_codigo')->filter()->values()->all(),
                ];
            })
            ->values()
            ->all();

        $rolesByMember = DB::table($assignments)
            ->where('edificio_id', $edificioId)
            ->orderBy('rol_codigo')
            ->get(['user_id', 'rol_codigo'])
            ->groupBy('user_id')
            ->map(static fn ($rows): array => $rows->pluck('rol_codigo')->values()->all());

        $members = DB::table($memberships.' as membership')
            ->join($users.' as users', 'users.id', '=', 'membership.user_id')
            ->where('membership.edificio_id', $edificioId)
            ->whereNull('membership.revoked_at')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'membership.created_at'])
            ->map(static fn (object $member): array => [
                'id' => $member->id,
                'nombre' => $member->name,
                'email' => $member->email,
                'roles' => $rolesByMember->get($member->id, []),
                'miembroDesde' => $member->created_at,
            ])
            ->all();

        $pendingInvitations = DB::table($invitations.' as invitation')
            ->join($rolesTable.' as role', 'role.codigo', '=', 'invitation.rol_codigo')
            ->join($users.' as inviter', 'inviter.id', '=', 'invitation.invitado_por_user_id')
            ->where('invitation.edificio_id', $edificioId)
            ->where('invitation.estado', EstadoInvitacion::PENDIENTE->value)
            ->where('invitation.expires_at', '>', now())
            ->orderByDesc('invitation.created_at')
            ->get(['invitation.id', 'invitation.email_normalizado', 'invitation.rol_codigo', 'role.nombre as rol_nombre', 'inviter.name as invitado_por', 'invitation.expires_at', 'invitation.created_at'])
            ->map(static fn (object $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email_normalizado,
                'rolCodigo' => $invitation->rol_codigo,
                'rolNombre' => $invitation->rol_nombre,
                'invitadoPor' => $invitation->invitado_por,
                'expiraEn' => $invitation->expires_at,
                'createdAt' => $invitation->created_at,
            ])
            ->all();

        $history = DB::table($events.' as event')
            ->leftJoin($users.' as actor', 'actor.id', '=', 'event.actor_user_id')
            ->leftJoin($users.' as affected', 'affected.id', '=', 'event.usuario_afectado_id')
            ->where('event.edificio_id', $edificioId)
            ->orderByDesc('event.created_at')
            ->limit(50)
            ->get(['event.id', 'event.tipo', 'event.roles_anteriores', 'event.roles_nuevos', 'event.detalle', 'event.created_at', 'actor.name as actor', 'affected.name as afectado'])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'tipo' => $event->tipo,
                'actor' => $event->actor,
                'afectado' => $event->afectado,
                'rolesAnteriores' => $this->decodeJson($event->roles_anteriores),
                'rolesNuevos' => $this->decodeJson($event->roles_nuevos),
                'detalle' => $this->decodeJson($event->detalle),
                'createdAt' => $event->created_at,
            ])
            ->all();

        return ['members' => $members, 'invitations' => $pendingInvitations, 'roles' => $roles, 'events' => $history];
    }

    public function invite(string $actorId, string $edificioId, string $email, string $role): array
    {
        $email = mb_strtolower(trim($email));
        $plainToken = Str::random(64);

        return DB::transaction(function () use ($actorId, $edificioId, $email, $role, $plainToken): array {
            $this->lockBuildingAndAuthorize($actorId, $edificioId);
            $this->assertRolesExist([$role]);

            $memberships = $this->table('edificio_usuario');
            $invitations = $this->table('invitaciones_edificio');
            $users = $this->usersTable();
            $existingUser = DB::table($users)->whereRaw('LOWER(email) = ?', [$email])->first(['id']);

            $expiredInvitations = DB::table($invitations)
                ->where('edificio_id', $edificioId)
                ->where('email_normalizado', $email)
                ->where('estado', EstadoInvitacion::PENDIENTE->value)
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get(['id', 'rol_codigo']);
            foreach ($expiredInvitations as $expired) {
                DB::table($invitations)->where('id', $expired->id)->update([
                    'estado' => EstadoInvitacion::REVOCADA->value,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordEvent(
                    edificioId: $edificioId,
                    type: 'invitacion_revocada',
                    invitationId: $expired->id,
                    oldRoles: [$expired->rol_codigo],
                    detail: ['email' => $email, 'motivo' => 'expiracion_automatica'],
                );
            }

            if ($existingUser !== null && DB::table($memberships)
                ->where('edificio_id', $edificioId)
                ->where('user_id', $existingUser->id)
                ->whereNull('revoked_at')
                ->exists()) {
                throw ValidationException::withMessages(['email' => 'El usuario ya es miembro activo del edificio.']);
            }

            if (DB::table($invitations)
                ->where('edificio_id', $edificioId)
                ->where('email_normalizado', $email)
                ->where('estado', EstadoInvitacion::PENDIENTE->value)
                ->exists()) {
                throw ValidationException::withMessages(['email' => 'Ya existe una invitación pendiente para este correo.']);
            }

            try {
                $invitation = InvitacionEdificioEloquentModel::query()->create([
                    'edificio_id' => $edificioId,
                    'email_normalizado' => $email,
                    'rol_codigo' => $role,
                    'token_hash' => hash('sha256', $plainToken),
                    'estado' => EstadoInvitacion::PENDIENTE,
                    'invitado_por_user_id' => $actorId,
                    'expires_at' => now()->addHours(72),
                ]);
            } catch (QueryException) {
                throw ValidationException::withMessages(['email' => 'No fue posible crear una invitación duplicada.']);
            }

            $this->recordEvent(
                edificioId: $edificioId,
                type: 'invitacion_creada',
                actorId: $actorId,
                invitationId: $invitation->id,
                newRoles: [$role],
                detail: ['email' => $email],
            );

            return ['invitation' => ['id' => $invitation->id, 'email' => $email], 'plainToken' => $plainToken];
        });
    }

    public function invitation(string $plainToken, string $userId): array
    {
        $invitation = InvitacionEdificioEloquentModel::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('estado', EstadoInvitacion::PENDIENTE->value)
            ->firstOrFail();
        $this->assertInvitationCanBeAccepted($invitation, $userId);

        $building = DB::table($this->table('edificios'))->where('id', $invitation->edificio_id)->firstOrFail(['id', 'nombre']);
        $role = DB::table($this->table('roles'))->where('codigo', $invitation->rol_codigo)->firstOrFail(['codigo', 'nombre', 'descripcion']);

        return [
            'edificio' => ['id' => $building->id, 'nombre' => $building->nombre],
            'rol' => ['codigo' => $role->codigo, 'nombre' => $role->nombre, 'descripcion' => $role->descripcion],
            'email' => $invitation->email_normalizado,
            'expiraEn' => $invitation->expires_at->toIso8601String(),
        ];
    }

    public function acceptInvitation(string $plainToken, string $userId): array
    {
        return DB::transaction(function () use ($plainToken, $userId): array {
            $tokenHash = hash('sha256', $plainToken);
            $candidate = InvitacionEdificioEloquentModel::query()
                ->where('token_hash', $tokenHash)
                ->firstOrFail(['edificio_id']);
            DB::table($this->table('edificios'))->where('id', $candidate->edificio_id)->lockForUpdate()->firstOrFail();
            $invitation = InvitacionEdificioEloquentModel::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertInvitationCanBeAccepted($invitation, $userId);

            $memberships = $this->table('edificio_usuario');
            $assignments = $this->table('edificio_usuario_roles');
            $membership = DB::table($memberships)
                ->where('edificio_id', $invitation->edificio_id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            $oldRoles = DB::table($assignments)
                ->where('edificio_id', $invitation->edificio_id)
                ->where('user_id', $userId)
                ->pluck('rol_codigo')
                ->all();

            if ($membership === null) {
                DB::table($memberships)->insert([
                    'edificio_id' => $invitation->edificio_id,
                    'user_id' => $userId,
                    'creado_por_user_id' => $invitation->invitado_por_user_id,
                    'revocado_por_user_id' => null,
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($membership->revoked_at === null) {
                throw ValidationException::withMessages(['invitacion' => 'El usuario ya pertenece al edificio.']);
            } else {
                DB::table($memberships)
                    ->where('edificio_id', $invitation->edificio_id)
                    ->where('user_id', $userId)
                    ->update([
                        'creado_por_user_id' => $invitation->invitado_por_user_id,
                        'revocado_por_user_id' => null,
                        'revoked_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            DB::table($assignments)
                ->where('edificio_id', $invitation->edificio_id)
                ->where('user_id', $userId)
                ->delete();
            DB::table($assignments)->insert([
                'edificio_id' => $invitation->edificio_id,
                'user_id' => $userId,
                'rol_codigo' => $invitation->rol_codigo,
                'asignado_por_user_id' => $invitation->invitado_por_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $invitation->update([
                'estado' => EstadoInvitacion::ACEPTADA,
                'aceptado_por_user_id' => $userId,
                'accepted_at' => now(),
            ]);
            $this->recordEvent(
                edificioId: $invitation->edificio_id,
                type: 'invitacion_aceptada',
                affectedUserId: $userId,
                actorId: $userId,
                invitationId: $invitation->id,
                oldRoles: $oldRoles,
                newRoles: [$invitation->rol_codigo],
                detail: ['email' => $invitation->email_normalizado],
            );

            return ['edificioId' => $invitation->edificio_id];
        });
    }

    public function revokeInvitation(string $actorId, string $edificioId, string $invitationId): void
    {
        DB::transaction(function () use ($actorId, $edificioId, $invitationId): void {
            $this->lockBuildingAndAuthorize($actorId, $edificioId);
            $invitation = InvitacionEdificioEloquentModel::query()
                ->where('edificio_id', $edificioId)
                ->where('estado', EstadoInvitacion::PENDIENTE->value)
                ->lockForUpdate()
                ->findOrFail($invitationId);
            $invitation->update(['estado' => EstadoInvitacion::REVOCADA, 'revoked_at' => now()]);
            $this->recordEvent(
                edificioId: $edificioId,
                type: 'invitacion_revocada',
                actorId: $actorId,
                invitationId: $invitation->id,
                oldRoles: [$invitation->rol_codigo],
                detail: ['email' => $invitation->email_normalizado],
            );
        });
    }

    public function updateMemberRoles(string $actorId, string $edificioId, string $memberId, array $roles): void
    {
        $roles = array_values(array_unique($roles));

        DB::transaction(function () use ($actorId, $edificioId, $memberId, $roles): void {
            $this->lockBuildingAndAuthorize($actorId, $edificioId);
            $this->assertRolesExist($roles);
            $membership = DB::table($this->table('edificio_usuario'))
                ->where('edificio_id', $edificioId)
                ->where('user_id', $memberId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();
            $assignments = $this->table('edificio_usuario_roles');
            $oldRoles = DB::table($assignments)
                ->where('edificio_id', $edificioId)
                ->where('user_id', $memberId)
                ->orderBy('rol_codigo')
                ->pluck('rol_codigo')
                ->all();

            $this->assertAdministratorRemains($edificioId, $memberId, $oldRoles, $roles);

            foreach (array_diff($roles, $oldRoles) as $role) {
                DB::table($assignments)->insert([
                    'edificio_id' => $edificioId,
                    'user_id' => $memberId,
                    'rol_codigo' => $role,
                    'asignado_por_user_id' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if ($removed = array_diff($oldRoles, $roles)) {
                DB::table($assignments)
                    ->where('edificio_id', $edificioId)
                    ->where('user_id', $memberId)
                    ->whereIn('rol_codigo', $removed)
                    ->delete();
            }

            $this->recordEvent(
                edificioId: $edificioId,
                type: 'roles_actualizados',
                affectedUserId: $membership->user_id,
                actorId: $actorId,
                oldRoles: $oldRoles,
                newRoles: $roles,
            );
        });
    }

    public function revokeMembership(string $actorId, string $edificioId, string $memberId): void
    {
        DB::transaction(function () use ($actorId, $edificioId, $memberId): void {
            $this->lockBuildingAndAuthorize($actorId, $edificioId);
            $memberships = $this->table('edificio_usuario');
            $membership = DB::table($memberships)
                ->where('edificio_id', $edificioId)
                ->where('user_id', $memberId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->firstOrFail();
            $oldRoles = DB::table($this->table('edificio_usuario_roles'))
                ->where('edificio_id', $edificioId)
                ->where('user_id', $memberId)
                ->orderBy('rol_codigo')
                ->pluck('rol_codigo')
                ->all();
            $this->assertAdministratorRemains($edificioId, $memberId, $oldRoles, []);

            DB::table($memberships)
                ->where('edificio_id', $edificioId)
                ->where('user_id', $memberId)
                ->update(['revocado_por_user_id' => $actorId, 'revoked_at' => now(), 'updated_at' => now()]);
            $this->recordEvent(
                edificioId: $edificioId,
                type: 'membresia_revocada',
                affectedUserId: $membership->user_id,
                actorId: $actorId,
                oldRoles: $oldRoles,
            );
        });
    }

    private function permissionQuery(string $userId, PermisoEdificio $permission)
    {
        $assignments = $this->table('edificio_usuario_roles');
        $memberships = $this->table('edificio_usuario');
        $rolePermissions = $this->table('rol_permisos');

        return DB::table($assignments.' as assignment')
            ->join($memberships.' as membership', static function ($join): void {
                $join->on('membership.edificio_id', '=', 'assignment.edificio_id')
                    ->on('membership.user_id', '=', 'assignment.user_id');
            })
            ->join($rolePermissions.' as role_permission', 'role_permission.rol_codigo', '=', 'assignment.rol_codigo')
            ->where('assignment.user_id', $userId)
            ->whereNull('membership.revoked_at')
            ->where('role_permission.permiso_codigo', $permission->value);
    }

    private function lockBuildingAndAuthorize(string $actorId, string $edificioId): void
    {
        DB::table($this->table('edificios'))->where('id', $edificioId)->lockForUpdate()->firstOrFail();

        if (! $this->hasPermission($actorId, $edificioId, PermisoEdificio::MIEMBROS_GESTIONAR)) {
            abort(403);
        }
    }

    /** @param list<string> $roles */
    private function assertRolesExist(array $roles): void
    {
        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'Seleccione al menos un rol.']);
        }

        $existing = DB::table($this->table('roles'))->whereIn('codigo', $roles)->count();
        if ($existing !== count($roles)) {
            throw ValidationException::withMessages(['roles' => 'Uno o más roles no son válidos.']);
        }
    }

    /** @param list<string> $oldRoles @param list<string> $newRoles */
    private function assertAdministratorRemains(string $edificioId, string $memberId, array $oldRoles, array $newRoles): void
    {
        if (! in_array(RolEdificio::ADMINISTRADOR->value, $oldRoles, true)
            || in_array(RolEdificio::ADMINISTRADOR->value, $newRoles, true)) {
            return;
        }

        $assignments = $this->table('edificio_usuario_roles');
        $memberships = $this->table('edificio_usuario');
        $hasOtherAdministrator = DB::table($assignments.' as assignment')
            ->join($memberships.' as membership', static function ($join): void {
                $join->on('membership.edificio_id', '=', 'assignment.edificio_id')
                    ->on('membership.user_id', '=', 'assignment.user_id');
            })
            ->where('assignment.edificio_id', $edificioId)
            ->where('assignment.rol_codigo', RolEdificio::ADMINISTRADOR->value)
            ->where('assignment.user_id', '<>', $memberId)
            ->whereNull('membership.revoked_at')
            ->exists();

        if (! $hasOtherAdministrator) {
            throw ValidationException::withMessages(['roles' => 'El edificio debe conservar al menos un administrador.']);
        }
    }

    private function assertInvitationCanBeAccepted(InvitacionEdificioEloquentModel $invitation, string $userId): void
    {
        if ($invitation->estado !== EstadoInvitacion::PENDIENTE || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitacion' => 'La invitación expiró o ya no está disponible.']);
        }

        $user = DB::table($this->usersTable())->where('id', $userId)->firstOrFail(['email']);
        if (mb_strtolower(trim($user->email)) !== $invitation->email_normalizado) {
            abort(403);
        }
    }

    /** @param list<string>|null $oldRoles @param list<string>|null $newRoles @param array<string, mixed>|null $detail */
    private function recordEvent(
        string $edificioId,
        string $type,
        ?string $affectedUserId = null,
        ?string $actorId = null,
        ?string $invitationId = null,
        ?array $oldRoles = null,
        ?array $newRoles = null,
        ?array $detail = null,
    ): void {
        EventoAccesoEdificioEloquentModel::query()->create([
            'edificio_id' => $edificioId,
            'usuario_afectado_id' => $affectedUserId,
            'actor_user_id' => $actorId,
            'invitacion_id' => $invitationId,
            'tipo' => $type,
            'roles_anteriores' => $oldRoles,
            'roles_nuevos' => $newRoles,
            'detalle' => $detail,
            'created_at' => now(),
        ]);
    }

    /** @return array<mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }

    private function usersTable(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'public.users' : 'users';
    }
}
