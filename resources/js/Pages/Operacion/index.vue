<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EdificioOperacionOption, EstadoOrdenOperativa, OrdenOperativaResumen, PaginationMeta, PrioridadOrdenOperativa, TipoOrdenOperativa } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  ordenes: { data: OrdenOperativaResumen[], meta: PaginationMeta }
  filters: { buscar?: string | null, edificio_id?: string | null, tipo?: TipoOrdenOperativa | null, estado?: EstadoOrdenOperativa | null, prioridad?: PrioridadOrdenOperativa | null }
  edificios: EdificioOperacionOption[]
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const tipo = ref(props.filters.tipo ?? '')
const estado = ref(props.filters.estado ?? '')
const prioridad = ref(props.filters.prioridad ?? '')
const loading = ref(false)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const stateLabels: Record<EstadoOrdenOperativa, string> = {
  reportada: 'Reportada',
  en_revision: 'En revisión',
  en_progreso: 'En progreso',
  resuelta: 'Resuelta',
  cerrada: 'Cerrada',
  cancelada: 'Cancelada'
}
const stateColor = (value: EstadoOrdenOperativa): 'info' | 'warning' | 'primary' | 'success' | 'neutral' | 'error' => ({
  reportada: 'info',
  en_revision: 'warning',
  en_progreso: 'primary',
  resuelta: 'success',
  cerrada: 'neutral',
  cancelada: 'error'
})[value]
const priorityLabels: Record<PrioridadOrdenOperativa, string> = { baja: 'Baja', media: 'Media', alta: 'Alta', critica: 'Crítica' }
const priorityColor = (value: PrioridadOrdenOperativa): 'neutral' | 'info' | 'warning' | 'error' => ({ baja: 'neutral', media: 'info', alta: 'warning', critica: 'error' })[value]
const typeLabels: Record<TipoOrdenOperativa, string> = { incidencia: 'Incidencia', solicitud: 'Solicitud', mantenimiento_preventivo: 'Mantenimiento preventivo' }
const buildingName = (id: string) => props.edificios.find(item => item.id === id)?.nombre ?? 'Edificio no disponible'
const reload = (page = 1, replace = false) => router.get(route('operaciones.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  tipo: tipo.value || undefined,
  estado: estado.value || undefined,
  prioridad: prioridad.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
watch(buscar, () => {
  clearTimeout(timer)
  timer = setTimeout(() => reload(1, true), 300)
})
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <UDashboardPanel id="operaciones">
    <template #header>
      <UDashboardNavbar title="Operaciones">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('operaciones.gestionar')" icon="i-lucide-plus" label="Nueva orden" @click="router.visit(route('operaciones.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UCard>
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <UInput v-model="buscar" aria-label="Buscar órdenes" icon="i-lucide-search" maxlength="100" placeholder="Número, título o descripción" />
            <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="reload(1, true)" />
            <USelect v-model="tipo" aria-label="Filtrar por tipo" :items="[{ label: 'Todos los tipos', value: '' }, ...Object.entries(typeLabels).map(([value, label]) => ({ label, value }))]" @update:model-value="reload(1, true)" />
            <USelect v-model="prioridad" aria-label="Filtrar por prioridad" :items="[{ label: 'Todas las prioridades', value: '' }, { label: 'Baja', value: 'baja' }, { label: 'Media', value: 'media' }, { label: 'Alta', value: 'alta' }, { label: 'Crítica', value: 'critica' }]" @update:model-value="reload(1, true)" />
            <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, ...Object.entries(stateLabels).map(([value, label]) => ({ label, value }))]" @update:model-value="reload(1, true)" />
          </div>
        </UCard>

        <div class="space-y-3 md:hidden" :class="loading ? 'opacity-60' : ''">
          <button v-for="orden in ordenes.data" :key="orden.id" class="w-full rounded-xl border border-default p-4 text-left" type="button" @click="router.visit(route('operaciones.show', [orden.edificioId, orden.id]))">
            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="font-mono font-semibold text-primary">{{ orden.numero }}</p><p class="mt-1 truncate font-medium text-highlighted">{{ orden.titulo }}</p><p class="text-xs text-muted">{{ buildingName(orden.edificioId) }}</p></div><UBadge :color="stateColor(orden.estado)" :label="stateLabels[orden.estado]" variant="subtle" /></div>
            <div class="mt-4 flex items-end justify-between gap-3 border-t border-default pt-3"><div class="text-sm text-muted"><p>{{ typeLabels[orden.tipo] }} · {{ priorityLabels[orden.prioridad] }}</p><p>{{ orden.responsableActual?.responsableNombre || 'Sin asignar' }}</p></div><span class="inline-flex items-center gap-1 text-sm font-medium text-primary"><UIcon name="i-lucide-eye" /> Ver</span></div>
          </button>
          <p v-if="!ordenes.data.length" class="rounded-xl border border-dashed border-default p-10 text-center text-muted">No hay órdenes operativas que coincidan con los filtros.</p>
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-default md:block" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Orden</th><th class="p-3">Tipo</th><th class="p-3">Prioridad</th><th class="p-3">Fecha objetivo</th><th class="p-3">Responsable</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="orden in ordenes.data" :key="orden.id" class="hover:bg-elevated/30">
                <td class="max-w-md p-3"><p class="font-mono font-semibold text-primary">{{ orden.numero }}</p><p class="font-medium text-highlighted">{{ orden.titulo }}</p><p class="text-xs text-muted">{{ buildingName(orden.edificioId) }}</p></td>
                <td class="p-3"><UBadge color="neutral" :label="typeLabels[orden.tipo]" variant="outline" /></td>
                <td class="p-3"><UBadge :color="priorityColor(orden.prioridad)" :label="priorityLabels[orden.prioridad]" variant="subtle" /></td>
                <td class="p-3">{{ orden.fechaObjetivo || 'Sin fecha' }}</td>
                <td class="p-3">{{ orden.responsableActual?.responsableNombre || 'Sin asignar' }}</td>
                <td class="p-3"><UBadge :color="stateColor(orden.estado)" :label="stateLabels[orden.estado]" variant="subtle" /></td>
                <td class="p-3"><div class="flex justify-end gap-1"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver orden" @click="router.visit(route('operaciones.show', [orden.edificioId, orden.id]))" /><UButton v-if="orden.puedeEditar && can('operaciones.gestionar', orden.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar orden" @click="router.visit(route('operaciones.edit', [orden.edificioId, orden.id]))" /></div></td>
              </tr>
              <tr v-if="!ordenes.data.length"><td class="p-10 text-center text-muted" colspan="7">No hay órdenes operativas que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ ordenes.meta.total }} orden(es) · página {{ ordenes.meta.currentPage }} de {{ ordenes.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="ordenes.meta.currentPage <= 1 || loading" @click="reload(ordenes.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="ordenes.meta.currentPage >= ordenes.meta.lastPage || loading" @click="reload(ordenes.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
