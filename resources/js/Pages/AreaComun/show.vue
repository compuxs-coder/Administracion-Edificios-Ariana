<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ReglaAreaComunForm from '../../components/areas-comunes/ReglaAreaComunForm.vue'
import ReglaResumen from '../../components/areas-comunes/ReglaResumen.vue'
import type { BitacoraAreaComun, EspacioComunDetalle, EstadoReservaAreaComun, ReglaAreaComunFormData, TipoEventoAreaComun } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ espacio: EspacioComunDetalle }>()
const { can } = useBuildingPermissions()
const loading = ref(false)
const statusOpen = ref(false)
const ruleOpen = ref(false)
const actionError = ref('')
const ruleErrors = ref<Record<string, string>>({})

const localToday = (timezone: string) => {
  const values = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map(part => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}
const today = localToday(props.espacio.timezone)
const addDay = (date: string) => {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + 1)
  return value.toISOString().slice(0, 10)
}
const currentRules = computed(() => props.espacio.reglas.filter(rule => !rule.vigenteHasta || rule.vigenteHasta > today))
const historicalRules = computed(() => props.espacio.reglas.filter(rule => Boolean(rule.vigenteHasta && rule.vigenteHasta <= today)))
const minimumRuleDate = computed(() => {
  const latestStart = props.espacio.reglas.reduce((latest, rule) => rule.vigenteDesde > latest ? rule.vigenteDesde : latest, '')
  return latestStart ? [addDay(today), addDay(latestStart)].sort().at(-1) ?? addDay(today) : today
})
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
const formatDate = (value: string) => new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
const formatDateTime = (value: string | null) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.espacio.timezone }).format(new Date(value)) : 'No disponible'
const formatLocal = (value: string) => {
  const [date, time] = value.split('T')
  return date && time ? `${formatDate(date)} · ${time}` : value
}
const eventDetail = (entry: BitacoraAreaComun) => {
  const detail = entry.detalle
  if (!detail) return 'Sin detalle adicional.'
  if (typeof detail.motivo === 'string' && detail.motivo) return detail.motivo
  if (typeof detail.inicioLocal === 'string') return `${detail.inicioLocal} a ${String(detail.finLocal ?? '')} · ${String(detail.timezone ?? props.espacio.timezone)}`
  if (detail.cambios && typeof detail.cambios === 'object') return `Campos actualizados: ${Object.keys(detail.cambios as object).join(', ')}`
  if (detail.regla) return 'Se registró una nueva versión de reglas y horarios.'
  if (detail.datos) return 'Se guardó la configuración inicial del espacio.'
  return 'Evento registrado con detalle estructurado.'
}
const changeStatus = () => router.patch(route('areas-comunes.status', [props.espacio.edificioId, props.espacio.id]), {
  estado: props.espacio.estado === 'activo' ? 'inactivo' : 'activo'
}, {
  preserveScroll: true,
  onStart: () => { loading.value = true; actionError.value = '' },
  onSuccess: () => { statusOpen.value = false },
  onError: errors => { actionError.value = String(errors.estado ?? 'No fue posible cambiar el estado.') },
  onFinish: () => { loading.value = false }
})
const openRule = () => {
  ruleErrors.value = {}
  ruleOpen.value = true
}
const storeRule = (data: ReglaAreaComunFormData) => router.post(route('areas-comunes.rules.store', [props.espacio.edificioId, props.espacio.id]), data, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { ruleOpen.value = false },
  onError: errors => { ruleErrors.value = errors },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="areas-comunes-show">
    <template #header><UDashboardNavbar :title="`${espacio.codigo} · ${espacio.nombre}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <UAlert v-if="actionError" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" />
        <div class="flex flex-wrap justify-end gap-2">
          <UButton color="neutral" icon="i-lucide-list" label="Todas las áreas" variant="outline" @click="router.visit(route('areas-comunes.index'))" />
          <UButton v-if="espacio.estado === 'activo' && can('reservas_areas_comunes.registrar', espacio.edificioId)" icon="i-lucide-calendar-plus" label="Nueva reserva" variant="outline" @click="router.visit(route('reservas-areas-comunes.create', { edificio_id: espacio.edificioId, espacio_comun_id: espacio.id }))" />
          <UButton v-if="can('areas_comunes.gestionar', espacio.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('areas-comunes.edit', [espacio.edificioId, espacio.id]))" />
          <UButton v-if="can('areas_comunes.gestionar', espacio.edificioId)" :color="espacio.estado === 'activo' ? 'error' : 'success'" :icon="espacio.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" :label="espacio.estado === 'activo' ? 'Inactivar' : 'Activar'" variant="outline" @click="actionError = ''; statusOpen = true" />
        </div>

        <UCard>
          <template #header><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm text-muted">{{ espacio.edificio }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ espacio.nombre }}</h1><p class="mt-1 font-mono text-sm text-primary">{{ espacio.codigo }}</p></div><UBadge :color="espacio.estado === 'activo' ? 'success' : 'neutral'" :label="espacio.estado === 'activo' ? 'Activo' : 'Inactivo'" size="lg" variant="subtle" /></div></template>
          <p v-if="espacio.descripcion" class="whitespace-pre-line text-sm leading-6 text-muted">{{ espacio.descripcion }}</p>
          <dl class="mt-5 grid gap-5 border-t border-default pt-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs uppercase text-muted">Capacidad</dt><dd class="mt-1 font-medium">{{ espacio.capacidad }} personas</dd></div>
            <div><dt class="text-xs uppercase text-muted">Ubicación</dt><dd class="mt-1 font-medium">{{ espacio.ubicacion.etiqueta || espacio.ubicacion.detalle || 'No disponible' }}</dd><dd v-if="espacio.ubicacion.etiqueta && espacio.ubicacion.detalle" class="text-xs text-muted">{{ espacio.ubicacion.detalle }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Zona horaria</dt><dd class="mt-1 font-mono text-sm">{{ espacio.timezone }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Actualizado</dt><dd class="mt-1 text-sm">{{ formatDateTime(espacio.updatedAt) }}</dd></div>
          </dl>
        </UCard>

        <section class="space-y-4">
          <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-lg font-semibold text-highlighted">Reglas vigentes y programadas</h2><p class="mt-1 text-sm text-muted">Cada versión conserva sus límites, horarios y reglamento.</p></div><UButton v-if="can('areas_comunes.gestionar', espacio.edificioId)" icon="i-lucide-list-plus" label="Nueva regla" @click="openRule" /></div>
          <div v-if="currentRules.length" class="space-y-4"><ReglaResumen v-for="rule in currentRules" :key="rule.id" :regla="rule" :timezone="espacio.timezone" /></div>
          <UAlert v-else color="warning" description="El espacio no tiene una regla vigente ni programada; no podrá recibir reservas válidas." icon="i-lucide-triangle-alert" variant="subtle" />
        </section>

        <section v-if="historicalRules.length" class="space-y-4">
          <div><h2 class="text-lg font-semibold text-highlighted">Reglas históricas</h2><p class="mt-1 text-sm text-muted">Las versiones cerradas permanecen disponibles para auditoría.</p></div>
          <ReglaResumen v-for="rule in historicalRules" :key="rule.id" :regla="rule" :timezone="espacio.timezone" />
        </section>

        <UCard v-if="espacio.puedeVerReservas">
          <template #header><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="font-semibold text-highlighted">Agenda próxima</p><p class="mt-1 text-xs text-muted">Reservas cuyo fin aún no ha ocurrido, expresadas en {{ espacio.timezone }}.</p></div><UButton color="neutral" icon="i-lucide-calendar-days" label="Ver reservas" variant="outline" @click="router.visit(route('reservas-areas-comunes.index', { edificio_id: espacio.edificioId, espacio_comun_id: espacio.id }))" /></div></template>
          <div v-if="espacio.reservas.length" class="divide-y divide-default">
            <button v-for="reservation in espacio.reservas" :key="reservation.id" class="grid w-full gap-3 py-4 text-left first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-center" type="button" @click="router.visit(route('reservas-areas-comunes.show', [espacio.edificioId, reservation.id]))">
              <div><p class="font-medium text-highlighted">{{ reservation.reservante.nombre }}</p><p class="text-xs text-muted">Departamento {{ reservation.reservante.departamentoCodigo }} · {{ reservation.cantidadAsistentes }} asistente(s)</p></div>
              <div class="text-sm"><p>{{ formatLocal(reservation.inicioLocal) }}</p><p class="text-muted">hasta {{ formatLocal(reservation.finLocal) }}</p></div>
              <UBadge :color="stateColor(reservation.estado)" :label="stateLabels[reservation.estado]" variant="subtle" />
            </button>
          </div>
          <p v-else class="text-sm text-muted">No hay reservas próximas para este espacio.</p>
        </UCard>

        <UCard>
          <template #header><div><p class="font-semibold text-highlighted">Bitácora</p><p class="mt-1 text-xs text-muted">{{ espacio.puedeVerReservas ? 'Historial inmutable de configuración, reglas y reservas.' : 'Historial inmutable de configuración y reglas.' }}</p></div></template>
          <div v-if="espacio.bitacora.length" class="relative ml-2 border-l border-default pl-6">
            <div v-for="entry in espacio.bitacora" :key="entry.id" class="relative min-w-0 pb-6 last:pb-0"><span class="absolute -left-[1.78rem] top-1 size-3 rounded-full border-2 border-default bg-primary"></span><p class="font-medium">{{ eventLabels[entry.tipo] }}</p><p class="mt-1 break-words text-sm text-muted">{{ eventDetail(entry) }}</p><p class="mt-1 text-xs text-dimmed">{{ entry.actorNombre }} · {{ formatDateTime(entry.createdAt) }}</p></div>
          </div>
          <p v-else class="text-sm text-muted">No hay eventos registrados.</p>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="statusOpen" :close="!loading" :dismissible="!loading" :title="espacio.estado === 'activo' ? 'Inactivar espacio' : 'Activar espacio'" :description="espacio.estado === 'activo' ? 'No podrá inactivarse si conserva reservas futuras confirmadas.' : 'El espacio volverá a estar disponible para nuevas solicitudes.'">
    <template #body><UAlert v-if="actionError" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="statusOpen = false" /><UButton :color="espacio.estado === 'activo' ? 'error' : 'success'" :label="espacio.estado === 'activo' ? 'Inactivar espacio' : 'Activar espacio'" :loading="loading" @click="changeStatus" /></div></template>
  </UModal>

  <UModal v-model:open="ruleOpen" :close="!loading" :dismissible="!loading" title="Nueva versión de reglas" :description="`Configure la vigencia y horarios locales de ${espacio.nombre}.`" :ui="{ content: 'sm:max-w-4xl' }">
    <template #body><ReglaAreaComunForm :timezone="espacio.timezone" :capacidad="espacio.capacidad" :minimum-date="minimumRuleDate" :errors="ruleErrors" :loading="loading" @cancel="ruleOpen = false" @submit="storeRule" /></template>
  </UModal>
</template>
