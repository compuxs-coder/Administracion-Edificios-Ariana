<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EventoAccesoEdificio, InvitacionEdificio, MiembroEdificio, RolAccesoEdificio, RolEdificio } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  edificio: { id: string, nombre: string }
  members: MiembroEdificio[]
  invitations: InvitacionEdificio[]
  roles: RolAccesoEdificio[]
  events: EventoAccesoEdificio[]
}>()
const { can } = useBuildingPermissions()
const canManage = can('miembros.gestionar', props.edificio.id)

const inviteOpen = ref(false)
const inviteLoading = ref(false)
const invite = reactive<{ email: string, rol: RolEdificio | '' }>({ email: '', rol: '' })
const roleState = reactive<Record<string, RolEdificio[]>>(Object.fromEntries(props.members.map(member => [member.id, [...member.roles]])))
const busyMember = ref<string | null>(null)

const roleItems = props.roles.map(role => ({ label: role.nombre, value: role.codigo }))
const eventLabels: Record<string, string> = {
  migracion: 'Acceso migrado',
  membresia_creada: 'Administrador inicial',
  invitacion_creada: 'Invitación creada',
  invitacion_aceptada: 'Invitación aceptada',
  invitacion_revocada: 'Invitación revocada',
  roles_actualizados: 'Roles actualizados',
  membresia_revocada: 'Acceso revocado'
}
const formatDate = (value: string) => new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))

const sendInvitation = () => {
  router.post(route('edificios.invitaciones.store', props.edificio.id), invite, {
    preserveScroll: true,
    onStart: () => { inviteLoading.value = true },
    onSuccess: () => { inviteOpen.value = false; invite.email = ''; invite.rol = '' },
    onFinish: () => { inviteLoading.value = false }
  })
}

const saveRoles = (member: MiembroEdificio) => {
  busyMember.value = member.id
  router.put(route('edificios.miembros.roles.update', [props.edificio.id, member.id]), { roles: roleState[member.id] }, {
    preserveScroll: true,
    onFinish: () => { busyMember.value = null }
  })
}

const revokeMember = (member: MiembroEdificio) => {
  if (!window.confirm(`¿Revocar el acceso de ${member.nombre}? El historial se conservará.`)) return
  busyMember.value = member.id
  router.delete(route('edificios.miembros.revoke', [props.edificio.id, member.id]), {
    preserveScroll: true,
    onFinish: () => { busyMember.value = null }
  })
}
</script>

<template>
  <UDashboardPanel id="building-access">
    <template #header>
      <UDashboardNavbar :title="`Accesos · ${edificio.nombre}`">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton color="neutral" icon="i-lucide-arrow-left" label="Edificio" variant="outline" @click="router.visit(route('edificios.show', edificio.id))" />
          <UButton v-if="canManage" icon="i-lucide-user-plus" label="Invitar" @click="inviteOpen = true" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto grid w-full max-w-7xl gap-6 p-4 sm:p-6 xl:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
        <div class="space-y-6">
          <UCard>
            <template #header>
              <div><h2 class="font-semibold text-highlighted">Miembros activos</h2><p class="text-sm text-muted">Los permisos se calculan por edificio combinando los roles seleccionados.</p></div>
            </template>
            <div class="divide-y divide-default">
              <div v-for="member in members" :key="member.id" class="grid gap-4 py-5 first:pt-0 last:pb-0 lg:grid-cols-[minmax(12rem,1fr)_minmax(16rem,1.5fr)_auto] lg:items-center">
                <div><p class="font-medium text-highlighted">{{ member.nombre }}</p><p class="text-sm text-muted">{{ member.email }}</p><p class="mt-1 text-xs text-dimmed">Desde {{ formatDate(member.miembroDesde) }}</p></div>
                <USelectMenu v-model="roleState[member.id]" :items="roleItems" multiple value-key="value" label-key="label" placeholder="Seleccione roles" :disabled="!canManage" />
                <div v-if="canManage" class="flex justify-end gap-2">
                  <UButton color="neutral" icon="i-lucide-save" label="Guardar" variant="outline" :loading="busyMember === member.id" @click="saveRoles(member)" />
                  <UButton color="error" icon="i-lucide-user-x" aria-label="Revocar acceso" variant="ghost" :disabled="busyMember === member.id" @click="revokeMember(member)" />
                </div>
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header><div><h2 class="font-semibold text-highlighted">Invitaciones pendientes</h2><p class="text-sm text-muted">Vencen automáticamente después de 72 horas.</p></div></template>
            <div v-if="invitations.length" class="divide-y divide-default">
              <div v-for="item in invitations" :key="item.id" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div><p class="font-medium text-highlighted">{{ item.email }}</p><p class="text-sm text-muted">{{ item.rolNombre }} · invitó {{ item.invitadoPor }}</p><p class="text-xs text-dimmed">Expira {{ formatDate(item.expiraEn) }}</p></div>
                <UButton v-if="canManage" color="error" icon="i-lucide-ban" label="Revocar" variant="ghost" @click="router.patch(route('edificios.invitaciones.revoke', [edificio.id, item.id]), {}, { preserveScroll: true })" />
              </div>
            </div>
            <p v-else class="text-sm text-muted">No hay invitaciones pendientes.</p>
          </UCard>
        </div>

        <div class="space-y-6">
          <UCard>
            <template #header><h2 class="font-semibold text-highlighted">Roles disponibles</h2></template>
            <div class="space-y-4">
              <div v-for="roleItem in roles" :key="roleItem.codigo" class="rounded-lg border border-default p-4">
                <p class="font-medium text-highlighted">{{ roleItem.nombre }}</p><p class="mt-1 text-sm text-muted">{{ roleItem.descripcion }}</p><UBadge class="mt-3" color="neutral" :label="`${roleItem.permisos.length} permisos`" variant="subtle" />
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header><div><h2 class="font-semibold text-highlighted">Historial de acceso</h2><p class="text-sm text-muted">Últimos 50 eventos inmutables.</p></div></template>
            <div class="space-y-4">
              <div v-for="event in events" :key="event.id" class="border-l-2 border-primary-400 pl-3">
                <p class="text-sm font-medium text-highlighted">{{ eventLabels[event.tipo] || event.tipo }}</p><p class="text-xs text-muted">{{ event.afectado || event.actor || 'Migración del sistema' }}</p><p class="text-xs text-dimmed">{{ formatDate(event.createdAt) }}</p>
              </div>
              <p v-if="events.length === 0" class="text-sm text-muted">Todavía no existen eventos.</p>
            </div>
          </UCard>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-if="canManage" v-model:open="inviteOpen" title="Invitar miembro" description="La invitación sólo podrá aceptarse con el correo indicado.">
    <template #body>
      <div class="space-y-4"><UFormField label="Correo" name="email" required><UInput v-model="invite.email" type="email" class="w-full" /></UFormField><UFormField label="Rol inicial" name="rol" required><USelect v-model="invite.rol" :items="roleItems" class="w-full" /></UFormField></div>
    </template>
    <template #footer><div class="flex w-full justify-end gap-2"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="inviteLoading" @click="inviteOpen = false" /><UButton label="Enviar invitación" :loading="inviteLoading" :disabled="!invite.email || !invite.rol" @click="sendInvitation" /></div></template>
  </UModal>
</template>
