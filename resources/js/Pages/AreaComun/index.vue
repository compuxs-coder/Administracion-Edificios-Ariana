<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EdificioAreaComunOption, EspaciosComunesPaginados, EstadoEspacioComun, PisoAreaComunOption, TorreAreaComunOption } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  espacios: EspaciosComunesPaginados
  filters: { buscar?: string | null, edificio_id?: string | null, estado?: EstadoEspacioComun | null }
  edificios: EdificioAreaComunOption[]
  torres: TorreAreaComunOption[]
  pisos: PisoAreaComunOption[]
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const building = (id: string) => props.edificios.find(item => item.id === id)
const location = (space: EspaciosComunesPaginados['data'][number]) => {
  if (space.torreId) {
    const tower = props.torres.find(item => item.id === space.torreId)
    return tower ? `${tower.codigo} · ${tower.nombre}` : 'Torre no disponible'
  }
  if (space.pisoId) {
    const floor = props.pisos.find(item => item.id === space.pisoId)
    return floor ? `Piso ${floor.numero}${floor.nombre ? ` · ${floor.nombre}` : ''}` : 'Piso no disponible'
  }
  return space.ubicacionDetalle || 'Sin ubicación'
}
const reload = (page = 1, replace = false) => router.get(route('areas-comunes.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  estado: estado.value || undefined,
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
  <UDashboardPanel id="areas-comunes">
    <template #header>
      <UDashboardNavbar title="Áreas comunes">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('areas_comunes.gestionar')" icon="i-lucide-plus" label="Nuevo espacio" @click="router.visit(route('areas-comunes.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UCard>
          <div class="grid gap-3 md:grid-cols-3">
            <UInput v-model="buscar" aria-label="Buscar áreas comunes" icon="i-lucide-search" maxlength="100" placeholder="Código, nombre o descripción" />
            <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="reload(1, true)" />
            <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activos', value: 'activo' }, { label: 'Inactivos', value: 'inactivo' }]" @update:model-value="reload(1, true)" />
          </div>
        </UCard>

        <div class="space-y-3 md:hidden" :class="loading ? 'opacity-60' : ''">
          <button v-for="space in espacios.data" :key="space.id" class="w-full rounded-xl border border-default p-4 text-left" type="button" @click="router.visit(route('areas-comunes.show', [space.edificioId, space.id]))">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0"><p class="font-mono text-sm font-semibold text-primary">{{ space.codigo }}</p><p class="mt-1 truncate font-semibold text-highlighted">{{ space.nombre }}</p><p class="mt-1 text-xs text-muted">{{ building(space.edificioId)?.nombre || 'Edificio no disponible' }}</p></div>
              <UBadge :color="space.estado === 'activo' ? 'success' : 'neutral'" :label="space.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" />
            </div>
            <div class="mt-4 grid gap-3 border-t border-default pt-3 text-sm" :class="space.puedeVerReservas ? 'grid-cols-2' : 'grid-cols-1'"><div><p class="text-xs text-muted">Ubicación</p><p class="mt-1">{{ location(space) }}</p></div><div v-if="space.puedeVerReservas"><p class="text-xs text-muted">Agenda</p><p class="mt-1">{{ space.reservasFuturas }} próxima(s)</p></div></div>
          </button>
          <p v-if="!espacios.data.length" class="rounded-xl border border-dashed border-default p-10 text-center text-muted">No hay espacios que coincidan con los filtros.</p>
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-default md:block" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Espacio</th><th class="p-3">Edificio</th><th class="p-3">Ubicación</th><th class="p-3 text-center">Capacidad</th><th class="p-3 text-center">Agenda próxima</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="space in espacios.data" :key="space.id" class="hover:bg-elevated/30">
                <td class="p-3"><p class="font-mono font-semibold text-primary">{{ space.codigo }}</p><p class="font-medium text-highlighted">{{ space.nombre }}</p></td>
                <td class="p-3"><p>{{ building(space.edificioId)?.nombre || 'No disponible' }}</p><p class="text-xs text-muted">{{ building(space.edificioId)?.timezone }}</p></td>
                <td class="p-3 text-muted">{{ location(space) }}</td>
                <td class="p-3 text-center">{{ space.capacidad }}</td>
                <td class="p-3 text-center"><UBadge v-if="space.puedeVerReservas" color="neutral" :label="String(space.reservasFuturas)" variant="outline" /><span v-else class="text-muted">Sin acceso</span></td>
                <td class="p-3"><UBadge :color="space.estado === 'activo' ? 'success' : 'neutral'" :label="space.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></td>
                <td class="p-3"><div class="flex justify-end gap-1"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver espacio" @click="router.visit(route('areas-comunes.show', [space.edificioId, space.id]))" /><UButton v-if="can('areas_comunes.gestionar', space.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar espacio" @click="router.visit(route('areas-comunes.edit', [space.edificioId, space.id]))" /></div></td>
              </tr>
              <tr v-if="!espacios.data.length"><td class="p-10 text-center text-muted" colspan="7">No hay espacios que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ espacios.meta.total }} espacio(s) · página {{ espacios.meta.currentPage }} de {{ espacios.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="espacios.meta.currentPage <= 1 || loading" @click="reload(espacios.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="espacios.meta.currentPage >= espacios.meta.lastPage || loading" @click="reload(espacios.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
