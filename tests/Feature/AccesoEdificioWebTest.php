<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;
use Src\Edificio\Domain\Enums\EstadoEdificio;
use Src\Edificio\Domain\Enums\EstadoInvitacion;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Edificio\Domain\Enums\RolEdificio;
use Src\Edificio\Infrastructure\Models\EdificioEloquentModel;
use Src\Edificio\Infrastructure\Models\InvitacionEdificioEloquentModel;
use Src\Edificio\Infrastructure\Notifications\InvitacionEdificioNotification;
use Tests\TestCase;

final class AccesoEdificioWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_role_matrix_is_scoped_per_building_and_combines_permissions(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $financeManager = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $building = $this->buildingFor($administrator);
        $otherBuilding = $this->buildingFor($administrator);
        $this->addMember($building, $propertyManager, [RolEdificio::GESTOR_PROPIEDAD]);
        $this->addMember($building, $financeManager, [RolEdificio::GESTOR_FINANZAS]);
        $this->addMember($building, $viewer, [RolEdificio::CONSULTA]);
        $this->addMember($otherBuilding, $propertyManager, [RolEdificio::CONSULTA]);
        $this->addMember($otherBuilding, $financeManager, [RolEdificio::CONSULTA]);
        $access = $this->access();

        foreach (PermisoEdificio::cases() as $permission) {
            $this->assertTrue($access->hasPermission($administrator->id, $building->id, $permission));
        }

        $this->assertTrue($access->hasPermission($propertyManager->id, $building->id, PermisoEdificio::ESTRUCTURA_GESTIONAR));
        $this->assertTrue($access->hasPermission($propertyManager->id, $building->id, PermisoEdificio::PROPIEDAD_GESTIONAR));
        $this->assertFalse($access->hasPermission($propertyManager->id, $building->id, PermisoEdificio::FINANZAS_VER));
        $this->assertFalse($access->hasPermission($propertyManager->id, $otherBuilding->id, PermisoEdificio::ESTRUCTURA_GESTIONAR));
        $this->actingAs($propertyManager)->put(
            route('edificios.miembros.roles.update', [$otherBuilding, $propertyManager->id]),
            ['roles' => [RolEdificio::ADMINISTRADOR->value]],
        )->assertForbidden();
        $this->assertFalse($access->hasPermission($propertyManager->id, $otherBuilding->id, PermisoEdificio::MIEMBROS_GESTIONAR));

        $this->assertTrue($access->hasPermission($financeManager->id, $building->id, PermisoEdificio::CARGOS_GENERAR));
        $this->assertTrue($access->hasPermission($financeManager->id, $building->id, PermisoEdificio::LECTURAS_REGISTRAR));
        $this->assertTrue($access->hasPermission($financeManager->id, $building->id, PermisoEdificio::PAGOS_ANULAR));
        $this->assertFalse($access->hasPermission($financeManager->id, $building->id, PermisoEdificio::ESTRUCTURA_GESTIONAR));
        $this->assertTrue($access->hasPermission($financeManager->id, $otherBuilding->id, PermisoEdificio::FINANZAS_VER));
        $this->assertFalse($access->hasPermission($financeManager->id, $otherBuilding->id, PermisoEdificio::CARGOS_GENERAR));
        $this->assertFalse($access->hasPermission($financeManager->id, $otherBuilding->id, PermisoEdificio::LECTURAS_REGISTRAR));

        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::FINANZAS_VER));
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::COMPROBANTES_VER));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::PAGOS_REGISTRAR));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::LECTURAS_REGISTRAR));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::MIEMBROS_VER));

        $this->actingAs($financeManager)
            ->get(route('cargos.index'))
            ->assertInertia(fn (Assert $page) => $page->has('edificios', 2));
        foreach (['cargos.generate', 'cargos.create', 'pagos.create', 'conceptos.create', 'lecturas.create'] as $routeName) {
            $this->actingAs($financeManager)
                ->get(route($routeName))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('edificios', 1)
                    ->where('edificios.0.id', $building->id));
        }
        $this->actingAs($propertyManager)
            ->get(route('departamentos.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('edificios', 1)
                ->where('edificios.0.id', $building->id));

        $this->actingAs($administrator)->put(
            route('edificios.miembros.roles.update', [$building, $viewer->id]),
            ['roles' => [RolEdificio::CONSULTA->value, RolEdificio::GESTOR_PROPIEDAD->value]],
        )->assertRedirect();

        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::FINANZAS_VER));
        $this->assertTrue($access->hasPermission($viewer->id, $building->id, PermisoEdificio::PROPIEDAD_GESTIONAR));
        $this->assertFalse($access->hasPermission($viewer->id, $building->id, PermisoEdificio::PAGOS_REGISTRAR));
    }

    public function test_permissions_are_enforced_in_backend_and_shared_with_inertia(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $viewer = UserEloquentModel::factory()->create();
        $propertyManager = UserEloquentModel::factory()->create();
        $financeManager = UserEloquentModel::factory()->create();
        $building = $this->buildingFor($administrator);
        $this->addMember($building, $viewer, [RolEdificio::CONSULTA]);
        $this->addMember($building, $propertyManager, [RolEdificio::GESTOR_PROPIEDAD]);
        $this->addMember($building, $financeManager, [RolEdificio::GESTOR_FINANZAS]);

        $this->actingAs($viewer)
            ->get(route('edificios.show', $building))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.access.byBuilding.'.$building->id, function ($permissions): bool {
                    return $permissions->contains(PermisoEdificio::FINANZAS_VER->value)
                        && ! $permissions->contains(PermisoEdificio::EDIFICIO_EDITAR->value);
                }));
        $this->actingAs($viewer)->get(route('edificios.edit', $building))->assertForbidden();
        $this->actingAs($viewer)->get(route('edificios.accesos.index', $building))->assertForbidden();
        $this->actingAs($viewer)->post(route('edificios.torres.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('edificios.invitaciones.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('cargos.generate.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('cargos.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('pagos.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('conceptos.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('lecturas.store', $building), [])->assertForbidden();
        $this->actingAs($viewer)->get(route('cargos.index'))->assertOk();
        $this->actingAs($viewer)->get(route('pagos.index'))->assertOk();
        $this->actingAs($viewer)->get(route('lecturas.index'))->assertOk();
        $this->actingAs($viewer)->get(route('cargos.generate'))->assertForbidden();
        $this->actingAs($viewer)->get(route('cargos.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('pagos.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('lecturas.create'))->assertForbidden();

        $this->actingAs($propertyManager)->get(route('edificios.estructura', $building))->assertOk();
        $this->actingAs($propertyManager)->get(route('cargos.index'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('pagos.index'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('conceptos.index'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('cartera.index'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('lecturas.index'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('cargos.generate'))->assertForbidden();
        $this->actingAs($propertyManager)->get(route('edificios.accesos.index', $building))->assertForbidden();
        $this->actingAs($financeManager)->get(route('cargos.generate'))->assertOk();
        $this->actingAs($financeManager)->get(route('cargos.create'))->assertOk();
        $this->actingAs($financeManager)->get(route('pagos.create'))->assertOk();
        $this->actingAs($financeManager)->get(route('lecturas.create'))->assertOk();
    }

    public function test_administrator_invites_normalized_email_and_only_matching_user_accepts(): void
    {
        Notification::fake();
        $administrator = UserEloquentModel::factory()->create();
        $invited = UserEloquentModel::factory()->create(['email' => 'member@example.test']);
        $other = UserEloquentModel::factory()->create(['email' => 'other@example.test']);
        $building = $this->buildingFor($administrator);

        $this->actingAs($administrator)->post(route('edificios.invitaciones.store', $building), [
            'email' => '  MEMBER@EXAMPLE.TEST ',
            'rol' => RolEdificio::GESTOR_FINANZAS->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(InvitacionEdificioNotification::class);
        $invitation = InvitacionEdificioEloquentModel::query()->sole();
        $this->assertSame('member@example.test', $invitation->email_normalizado);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertTrue($invitation->expires_at->between(now()->addHours(71), now()->addHours(73)));

        $this->actingAs($administrator)->patch(
            route('edificios.invitaciones.revoke', [$building, $invitation]),
        )->assertRedirect();
        $this->assertSame(EstadoInvitacion::REVOCADA, $invitation->refresh()->estado);

        $created = $this->access()->invite(
            $administrator->id,
            $building->id,
            $invited->email,
            RolEdificio::GESTOR_FINANZAS->value,
        );
        $token = $created['plainToken'];
        $stored = InvitacionEdificioEloquentModel::query()->findOrFail($created['invitation']['id']);
        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);

        $this->post(route('logout'));
        $invitationUrl = route('invitaciones-edificio.show', $token);
        $this->get($invitationUrl)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $invitationUrl);
        $this->post(route('login'), [
            'email' => $invited->email,
            'password' => 'password',
        ])->assertRedirect($invitationUrl);
        $this->get($invitationUrl)->assertOk();

        $this->actingAs($other)->get(route('invitaciones-edificio.show', $token))->assertForbidden();
        $this->actingAs($invited)
            ->get(route('invitaciones-edificio.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Acceso/accept')
                ->where('invitacion.edificio.id', $building->id)
                ->where('invitacion.rol.codigo', RolEdificio::GESTOR_FINANZAS->value));

        $this->actingAs($invited)
            ->post(route('invitaciones-edificio.accept', $token))
            ->assertRedirect(route('edificios.show', $building));
        $this->assertDatabaseHas('edificio_usuario', [
            'edificio_id' => $building->id,
            'user_id' => $invited->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('edificio_usuario_roles', [
            'edificio_id' => $building->id,
            'user_id' => $invited->id,
            'rol_codigo' => RolEdificio::GESTOR_FINANZAS->value,
        ]);
        $this->assertDatabaseHas('eventos_acceso_edificio', [
            'edificio_id' => $building->id,
            'usuario_afectado_id' => $invited->id,
            'tipo' => 'invitacion_aceptada',
        ]);
    }

    public function test_guest_returns_to_invitation_after_registration(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = $this->buildingFor($administrator);
        $created = $this->access()->invite(
            $administrator->id,
            $building->id,
            'new.member@example.test',
            RolEdificio::CONSULTA->value,
        );
        $invitationUrl = route('invitaciones-edificio.show', $created['plainToken']);

        $this->get($invitationUrl)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $invitationUrl);
        $this->post(route('register'), [
            'name' => 'Nuevo Miembro',
            'email' => ' NEW.MEMBER@EXAMPLE.TEST ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect($invitationUrl);

        $this->get($invitationUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Acceso/accept')
                ->where('invitacion.edificio.id', $building->id));
    }

    public function test_expired_invitation_is_rejected_and_can_be_replaced(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');
        $administrator = UserEloquentModel::factory()->create();
        $invited = UserEloquentModel::factory()->create(['email' => 'expired@example.test']);
        $building = $this->buildingFor($administrator);
        $first = $this->access()->invite($administrator->id, $building->id, $invited->email, RolEdificio::CONSULTA->value);

        Carbon::setTestNow('2026-09-07 10:00:00');
        $this->actingAs($invited)
            ->get(route('invitaciones-edificio.show', $first['plainToken']))
            ->assertRedirect()
            ->assertSessionHasErrors('invitacion');

        $second = $this->access()->invite($administrator->id, $building->id, $invited->email, RolEdificio::GESTOR_PROPIEDAD->value);
        $this->assertNotSame($first['invitation']['id'], $second['invitation']['id']);
        $this->assertDatabaseHas('invitaciones_edificio', [
            'id' => $first['invitation']['id'],
            'estado' => EstadoInvitacion::REVOCADA->value,
        ]);
        $this->assertDatabaseHas('invitaciones_edificio', [
            'id' => $second['invitation']['id'],
            'estado' => EstadoInvitacion::PENDIENTE->value,
        ]);
    }

    public function test_revoked_member_loses_access_and_can_be_reactivated_with_a_new_role(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $member = UserEloquentModel::factory()->create(['email' => 'reactivate@example.test']);
        $building = $this->buildingFor($administrator);
        $this->addMember($building, $member, [RolEdificio::CONSULTA]);

        $this->actingAs($administrator)
            ->delete(route('edificios.miembros.revoke', [$building, $member->id]))
            ->assertRedirect();
        $this->assertFalse($this->access()->hasPermission($member->id, $building->id, PermisoEdificio::EDIFICIO_VER));

        $invitation = $this->access()->invite(
            $administrator->id,
            $building->id,
            $member->email,
            RolEdificio::GESTOR_PROPIEDAD->value,
        );
        $this->actingAs($member)
            ->post(route('invitaciones-edificio.accept', $invitation['plainToken']))
            ->assertRedirect(route('edificios.show', $building));

        $this->assertDatabaseHas('edificio_usuario', [
            'edificio_id' => $building->id,
            'user_id' => $member->id,
            'revoked_at' => null,
            'revocado_por_user_id' => null,
        ]);
        $this->assertDatabaseMissing('edificio_usuario_roles', [
            'edificio_id' => $building->id,
            'user_id' => $member->id,
            'rol_codigo' => RolEdificio::CONSULTA->value,
        ]);
        $this->assertTrue($this->access()->hasPermission($member->id, $building->id, PermisoEdificio::PROPIEDAD_GESTIONAR));
    }

    public function test_last_administrator_and_access_history_are_protected_by_application_and_database(): void
    {
        $administrator = UserEloquentModel::factory()->create();
        $building = $this->buildingFor($administrator);

        $this->actingAs($administrator)->put(
            route('edificios.miembros.roles.update', [$building, $administrator->id]),
            ['roles' => [RolEdificio::CONSULTA->value]],
        )->assertSessionHasErrors('roles');
        $this->actingAs($administrator)
            ->delete(route('edificios.miembros.revoke', [$building, $administrator->id]))
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseRejects(static fn () => DB::table('edificio_usuario_roles')
            ->where('edificio_id', $building->id)
            ->where('user_id', $administrator->id)
            ->where('rol_codigo', RolEdificio::ADMINISTRADOR->value)
            ->delete());
        $this->assertDatabaseRejects(static fn () => DB::table('edificio_usuario_roles')
            ->where('edificio_id', $building->id)
            ->where('user_id', $administrator->id)
            ->where('rol_codigo', RolEdificio::ADMINISTRADOR->value)
            ->update(['rol_codigo' => RolEdificio::CONSULTA->value]));
        $this->assertDatabaseRejects(static fn () => DB::table('edificio_usuario')
            ->where('edificio_id', $building->id)
            ->where('user_id', $administrator->id)
            ->delete());
        $outsider = UserEloquentModel::factory()->create();
        $this->assertDatabaseRejects(static fn () => DB::table('edificio_usuario_roles')->insert([
            'edificio_id' => $building->id,
            'user_id' => $outsider->id,
            'rol_codigo' => RolEdificio::CONSULTA->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $eventId = DB::table('eventos_acceso_edificio')
            ->where('edificio_id', $building->id)
            ->value('id');
        $this->assertNotNull($eventId);
        $this->assertDatabaseRejects(static fn () => DB::table('eventos_acceso_edificio')
            ->where('id', $eventId)
            ->update(['tipo' => 'roles_actualizados']));
        $this->assertDatabaseRejects(static fn () => DB::table('eventos_acceso_edificio')
            ->where('id', $eventId)
            ->delete());
    }

    private function buildingFor(UserEloquentModel $administrator): EdificioEloquentModel
    {
        $number = DB::table('edificios')->count() + 1;
        $building = EdificioEloquentModel::query()->create([
            'nombre' => 'Edificio '.$number,
            'ruc' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
            'direccion' => 'Dirección '.$number,
            'ciudad' => 'Quito',
            'telefono' => null,
            'correo' => null,
            'responsable' => null,
            'estado' => EstadoEdificio::ACTIVO,
        ]);
        $building->usuarios()->attach($administrator->id);
        $this->access()->assignAdministrator($building->id, $administrator->id);

        return $building;
    }

    /** @param list<RolEdificio> $roles */
    private function addMember(EdificioEloquentModel $building, UserEloquentModel $member, array $roles): void
    {
        $building->usuarios()->attach($member->id, [
            'creado_por_user_id' => $building->usuarios()->firstOrFail()->id,
        ]);
        foreach ($roles as $role) {
            DB::table('edificio_usuario_roles')->insert([
                'edificio_id' => $building->id,
                'user_id' => $member->id,
                'rol_codigo' => $role->value,
                'asignado_por_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function access(): AccesoEdificioRepositoryInterface
    {
        return $this->app->make(AccesoEdificioRepositoryInterface::class);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('La base de datos permitió una mutación protegida.');
        } catch (QueryException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
