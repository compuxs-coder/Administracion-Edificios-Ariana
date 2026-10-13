<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ReglaResumen from '../../components/areas-comunes/ReglaResumen.vue'
import type { BitacoraAreaComun, EstadoReservaAreaComun, ReservaAreaComunDetalle, TipoEventoAreaComun } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ reserva: ReservaAreaComunDetalle }>()
const { can } = useBuildingPermissions()
const loading = ref(false)
const actionError = ref('')
const reasonOpen = ref(false)
const action = ref<'reject' | 'cancel'>('reject')
const reason = ref('')

const stateLabels: Record<EstadoReservaAreaComun, string> = { pendiente: 'Pendiente', confirmada: 'Confirmada', rechazada: 'Rechazada', cancelada: 'Cancelada' }
const stateColor = (value: EstadoReservaAreaComun): 'warning' | 'success' | 'error' | 'neutral' => ({ pendiente: 'warning', confirmada: 'success', rechazada: 'error', cancelada: 'neutral' })[value]
const eventLabels: Record<TipoEventoAreaComun, string> = {
  espacio_creado: 'Espacio creado',
  espacio_actualizado: 'Espacio actualizado',
  espacio_activado: 'Espacio activado',
  espacio_inactivado: 'Espacio inactivado',
  regla_creada: 'Regla registrada',
  reserva_creada: 'Reserva creada',
  reserva_aprobada: 'Reserva aprobada',
  reserva_rechazada: 'Reserva rechazada',
  reserva_cancelada: 'Reserva cancelada'
}
const canReview = computed(() => props.reserva.puedeAprobar && can('reservas_areas_comunes.aprobar', props.reserva.edificioId))
const canCancel = computed(() => props.reserva.puedeCancelar && can('reservas_areas_comunes.cancelar', props.reserva.edificioId))
const formatDate = (value: string) => new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
const formatDateTime = (value: string | null) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.reserva.timezone }).format(new Date(value)) : 'No disponible'
const formatLocal = (value: string) => {
  const [date, time] = value.split('T')
  return date && time ? `${formatDate(date)} · ${time}` : value
}
const eventDetail = (entry: BitacoraAreaComun) => {
  const detail = entry.detalle
  if (!detail) return 'Sin detalle adicional.'
  if (typeof detail.motivo === 'string' && detail.motivo) return detail.motivo
  if (typeof detail.inicioLocal === 'string') return `${detail.inicioLocal} a ${String(detail.finLocal ?? '')} · ${String(detail.timezone ?? props.reserva.timezone)}`
  return 'Transición registrada en la reserva.'
}
const requestOptions = {
  preserveScroll: true,
  onStart: () => { loading.value = true; actionError.value = '' },
  onFinish: () => { loading.value = false }
}
const approve = () => router.patch(route('reservas-areas-comunes.approve', [props.reserva.edificioId, props.reserva.id]), {}, {
  ...requestOptions,
  onError: errors => { actionError.value = String(errors.estado ?? 'No fue posible aprobar la reserva.') }
})
const openReason = (target: 'reject' | 'cancel') => {
  action.value = target
  reason.value = ''
  actionError.value = ''
  reasonOpen.value = true
}
const submitReason = () => {
  const routeName = action.value === 'reject' ? 'reservas-areas-comunes.reject' : 'reservas-areas-comunes.cancel'
  router.patch(route(routeName, [props.reserva.edificioId, props.reserva.id]), { motivo: reason.value }, {
    ...requestOptions,
    onSuccess: () => { reasonOpen.value = false },
    onError: errors => { actionError.value = String(errors.motivo ?? errors.estado ?? `No fue posible ${action.value === 'reject' ? 'rechazar' : 'cancelar'} la reserva.`) }
  })
}
</script>

<template>
  <UDashboardPanel id="reservas-areas-comunes-show">
    <template #header><UDashboardNavbar :title="`Reserva · ${reserva.espacioCodigo}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <UAlert v-if="actionError && !reasonOpen" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" />
        <div class="flex flex-wrap justify-end gap-2">
          <UButton color="neutral" icon="i-lucide-list" label="Todas las reservas" variant="outline" @click="router.visit(route('reservas-areas-comunes.index'))" />
          <UButton color="neutral" icon="i-lucide-warehouse" label="Ver espacio" variant="outline" @click="router.visit(route('areas-comunes.show', [reserva.edificioId, reserva.espacioComunId]))" />
          <UButton v-if="canReview" color="error" icon="i-lucide-x" label="Rechazar" variant="outline" @click="openReason('reject')" />
          <UButton v-if="canReview" icon="i-lucide-check" label="Aprobar" :loading="loading" @click="approve" />
          <UButton v-if="canCancel" color="error" icon="i-lucide-calendar-x" label="Cancelar reserva" variant="outline" @click="openReason('cancel')" />
        </div>

        <UCard>
          <template #header><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm text-muted">{{ reserva.edificio }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ reserva.espacioNombre }}</h1><p class="mt-1 font-mono text-sm text-primary">{{ reserva.espacioCodigo }}</p></div><UBadge :color="stateColor(reserva.estado)" :label="stateLabels[reserva.estado]" size="lg" variant="subtle" /></div></template>
          <div class="grid gap-5 md:grid-cols-2">
            <div class="rounded-xl bg-elevated/40 p-4"><p class="text-xs uppercase tracking-wide text-muted">Inicio local</p><p class="mt-2 text-lg font-semibold text-highlighted">{{ formatLocal(reserva.inicioLocal) }}</p></div>
            <div class="rounded-xl bg-elevated/40 p-4"><p class="text-xs uppercase tracking-wide text-muted">Fin local</p><p class="mt-2 text-lg font-semibold text-highlighted">{{ formatLocal(reserva.finLocal) }}</p></div>
          </div>
          <dl class="mt-5 grid gap-5 border-t border-default pt-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs uppercase text-muted">Zona horaria</dt><dd class="mt-1 font-mono text-sm">{{ reserva.timezone }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Asistentes</dt><dd class="mt-1 font-medium">{{ reserva.cantidadAsistentes }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Registrada</dt><dd class="mt-1 text-sm">{{ formatDateTime(reserva.createdAt) }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Resolución</dt><dd class="mt-1 text-sm">{{ formatDateTime(reserva.resueltaAt) }}</dd></div>
          </dl>
          <UAlert v-if="reserva.motivoResolucion" class="mt-5" :color="reserva.estado === 'rechazada' ? 'error' : 'neutral'" :title="reserva.estado === 'rechazada' ? 'Motivo de rechazo' : 'Motivo de cancelación'" :description="reserva.motivoResolucion" variant="subtle" />
        </UCard>

        <div class="grid gap-6 lg:grid-cols-2">
          <UCard><template #header><p class="font-semibold text-highlighted">Reservante</p></template><dl class="space-y-4 text-sm"><div><dt class="text-xs uppercase text-muted">Residente</dt><dd class="mt-1 font-medium">{{ reserva.reservante.nombre }}</dd><dd class="text-muted">{{ reserva.reservante.tipoIdentificacion }} · {{ reserva.reservante.identificacion }}</dd></div><div><dt class="text-xs uppercase text-muted">Departamento</dt><dd class="mt-1">{{ reserva.reservante.departamentoCodigo }} · {{ reserva.reservante.departamentoNombre }}</dd></div><div><dt class="text-xs uppercase text-muted">Ocupación</dt><dd class="mt-1">{{ reserva.reservante.tipoOcupacion.replaceAll('_', ' ') }} · desde {{ formatDate(reserva.reservante.fechaInicio) }}</dd></div></dl></UCard>
          <UCard><template #header><p class="font-semibold text-highlighted">Solicitud</p></template><p v-if="reserva.observaciones" class="whitespace-pre-line text-sm leading-6 text-muted">{{ reserva.observaciones }}</p><p v-else class="text-sm text-muted">No se registraron observaciones.</p></UCard>
        </div>

        <section class="space-y-4">
          <div><h2 class="text-lg font-semibold text-highlighted">Regla aplicada</h2><p class="mt-1 text-sm text-muted">La reserva conserva un snapshot de estas condiciones aunque luego se creen nuevas versiones.</p></div>
          <ReglaResumen :regla="reserva.regla" :timezone="reserva.regla.timezone" />
        </section>

        <UCard>
          <template #header><div><p class="font-semibold text-highlighted">Bitácora de la reserva</p><p class="mt-1 text-xs text-muted">Creación y decisiones en orden cronológico.</p></div></template>
          <div v-if="reserva.bitacora.length" class="relative ml-2 border-l border-default pl-6"><div v-for="entry in reserva.bitacora" :key="entry.id" class="relative min-w-0 pb-6 last:pb-0"><span class="absolute -left-[1.78rem] top-1 size-3 rounded-full border-2 border-default bg-primary"></span><p class="font-medium">{{ eventLabels[entry.tipo] }}</p><p class="mt-1 break-words text-sm text-muted">{{ eventDetail(entry) }}</p><p class="mt-1 text-xs text-dimmed">{{ entry.actorNombre }} · {{ formatDateTime(entry.createdAt) }}</p></div></div>
          <p v-else class="text-sm text-muted">No hay eventos registrados.</p>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="reasonOpen" :close="!loading" :dismissible="!loading" :title="action === 'reject' ? 'Rechazar solicitud' : 'Cancelar reserva'" :description="action === 'reject' ? 'La solicitud quedará rechazada y conservará el motivo en su bitácora.' : 'La reserva liberará el horario y conservará el motivo en su bitácora.'">
    <template #body><div class="space-y-4"><UAlert v-if="actionError" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo" required><UTextarea v-model="reason" class="w-full" :rows="4" minlength="3" maxlength="2000" required /></UFormField></div></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="reasonOpen = false" /><UButton color="error" :label="action === 'reject' ? 'Rechazar solicitud' : 'Cancelar reserva'" :disabled="reason.trim().length < 3" :loading="loading" @click="submitReason" /></div></template>
  </UModal>
</template>
