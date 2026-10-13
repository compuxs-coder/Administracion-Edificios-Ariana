<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EdificioAreaComunOption, EspacioComun, EstadoReservaAreaComun, ReservasAreasComunesPaginadas } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  reservas: ReservasAreasComunesPaginadas
  filters: { edificio_id?: string | null, espacio_comun_id?: string | null, estado?: EstadoReservaAreaComun | null, desde?: string | null, hasta?: string | null }
  edificios: EdificioAreaComunOption[]
  espacios: EspacioComun[]
}>()
const edificioId = ref(props.filters.edificio_id ?? '')
const espacioId = ref(props.filters.espacio_comun_id ?? '')
const estado = ref(props.filters.estado ?? '')
const desde = ref(props.filters.desde ?? '')
const hasta = ref(props.filters.hasta ?? '')
const loading = ref(false)
const { canAny } = useBuildingPermissions()

const filteredSpaces = computed(() => props.espacios.filter(item => !edificioId.value || item.edificioId === edificioId.value))
const stateLabels: Record<EstadoReservaAreaComun, string> = { pendiente: 'Pendiente', confirmada: 'Confirmada', rechazada: 'Rechazada', cancelada: 'Cancelada' }
const stateColor = (value: EstadoReservaAreaComun): 'warning' | 'success' | 'error' | 'neutral' => ({ pendiente: 'warning', confirmada: 'success', rechazada: 'error', cancelada: 'neutral' })[value]
const building = (id: string) => props.edificios.find(item => item.id === id)
const formatDate = (value: string) => new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
const formatLocal = (value: string) => {
  const [date, time] = value.split('T')
  return date && time ? `${formatDate(date)} · ${time}` : value
}
const reload = (page = 1, replace = false) => router.get(route('reservas-areas-comunes.index'), {
  edificio_id: edificioId.value || undefined,
  espacio_comun_id: espacioId.value || undefined,
  estado: estado.value || undefined,
  desde: desde.value || undefined,
  hasta: hasta.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
const changeBuilding = () => {
  if (!filteredSpaces.value.some(item => item.id === espacioId.value)) espacioId.value = ''
  reload(1, true)
}
const changeFrom = () => {
  if (desde.value && hasta.value && hasta.value < desde.value) hasta.value = ''
  reload(1, true)
}
</script>

<template>
  <UDashboardPanel id="reservas-areas-comunes">
    <template #header>
      <UDashboardNavbar title="Reservas de áreas comunes">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('reservas_areas_comunes.registrar')" icon="i-lucide-calendar-plus" label="Nueva reserva" @click="router.visit(route('reservas-areas-comunes.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UCard>
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="changeBuilding" />
            <USelect v-model="espacioId" aria-label="Filtrar por área común" :items="[{ label: 'Todas las áreas', value: '' }, ...filteredSpaces.map(item => ({ label: `${item.codigo} · ${item.nombre}${item.estado === 'inactivo' ? ' (inactivo)' : ''}`, value: item.id }))]" @update:model-value="reload(1, true)" />
            <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, ...Object.entries(stateLabels).map(([value, label]) => ({ label, value }))]" @update:model-value="reload(1, true)" />
            <UFormField label="Desde"><UInput v-model="desde" class="w-full" type="date" @change="changeFrom" /></UFormField>
            <UFormField label="Hasta"><UInput v-model="hasta" class="w-full" type="date" :min="desde || undefined" @change="reload(1, true)" /></UFormField>
          </div>
        </UCard>

        <div class="space-y-3 md:hidden" :class="loading ? 'opacity-60' : ''">
          <button v-for="reservation in reservas.data" :key="reservation.id" class="w-full rounded-xl border border-default p-4 text-left" type="button" @click="router.visit(route('reservas-areas-comunes.show', [reservation.edificioId, reservation.id]))">
            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="font-mono text-sm font-semibold text-primary">{{ reservation.espacioCodigo }}</p><p class="truncate font-semibold text-highlighted">{{ reservation.espacioNombre }}</p><p class="mt-1 text-xs text-muted">{{ building(reservation.edificioId)?.nombre }}</p></div><UBadge :color="stateColor(reservation.estado)" :label="stateLabels[reservation.estado]" variant="subtle" /></div>
            <div class="mt-4 border-t border-default pt-3"><p class="font-medium">{{ reservation.reservante.nombre }}</p><p class="text-xs text-muted">Departamento {{ reservation.reservante.departamentoCodigo }} · {{ reservation.cantidadAsistentes }} asistente(s)</p><p class="mt-2 text-sm">{{ formatLocal(reservation.inicioLocal) }} a {{ reservation.finLocal.split('T')[1] }}</p><p class="text-xs text-muted">{{ reservation.reglaSnapshot.timezone }}</p></div>
          </button>
          <p v-if="!reservas.data.length" class="rounded-xl border border-dashed border-default p-10 text-center text-muted">No hay reservas que coincidan con los filtros.</p>
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-default md:block" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-6xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Espacio</th><th class="p-3">Reservante</th><th class="p-3">Inicio local</th><th class="p-3">Fin local</th><th class="p-3 text-center">Asistentes</th><th class="p-3">Estado</th><th class="p-3 text-right">Acción</th></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="reservation in reservas.data" :key="reservation.id" class="hover:bg-elevated/30">
                <td class="p-3"><p class="font-mono font-semibold text-primary">{{ reservation.espacioCodigo }}</p><p class="font-medium text-highlighted">{{ reservation.espacioNombre }}</p><p class="text-xs text-muted">{{ building(reservation.edificioId)?.nombre }}</p></td>
                <td class="p-3"><p class="font-medium">{{ reservation.reservante.nombre }}</p><p class="text-xs text-muted">Dpto. {{ reservation.reservante.departamentoCodigo }} · {{ reservation.reservante.identificacion }}</p></td>
                <td class="p-3"><p>{{ formatLocal(reservation.inicioLocal) }}</p><p class="text-xs text-muted">{{ reservation.reglaSnapshot.timezone }}</p></td>
                <td class="p-3">{{ formatLocal(reservation.finLocal) }}</td>
                <td class="p-3 text-center">{{ reservation.cantidadAsistentes }}</td>
                <td class="p-3"><UBadge :color="stateColor(reservation.estado)" :label="stateLabels[reservation.estado]" variant="subtle" /></td>
                <td class="p-3 text-right"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver reserva" @click="router.visit(route('reservas-areas-comunes.show', [reservation.edificioId, reservation.id]))" /></td>
              </tr>
              <tr v-if="!reservas.data.length"><td class="p-10 text-center text-muted" colspan="7">No hay reservas que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ reservas.meta.total }} reserva(s) · página {{ reservas.meta.currentPage }} de {{ reservas.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="reservas.meta.currentPage <= 1 || loading" @click="reload(reservas.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="reservas.meta.currentPage >= reservas.meta.lastPage || loading" @click="reload(reservas.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
