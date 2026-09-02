<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type {
  EdificioResidenteOption,
  Residente,
  ResidenteFilters,
  ResidentesPaginados
} from '../../types'

const props = defineProps<{
  residentes: ResidentesPaginados
  filters: ResidenteFilters
  edificios: EdificioResidenteOption[]
}>()

const buscar = ref(props.filters.buscar ?? '')
const estado = ref(props.filters.estado ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const loading = ref(false)
const statusLoading = ref(false)
const statusOpen = ref(false)
const statusError = ref('')
const selected = ref<Residente | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

const edificioItems = computed(() => [
  { label: 'Todos los edificios', value: '' },
  ...props.edificios.map(item => ({ label: item.nombre, value: item.id }))
])

const reload = (page = 1, replace = false) => router.get(route('residentes.index'), {
  buscar: buscar.value || undefined,
  estado: estado.value || undefined,
  edificio_id: edificioId.value || undefined,
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

const confirmStatus = (residente: Residente) => {
  selected.value = residente
  statusError.value = ''
  statusOpen.value = true
}

const changeStatus = () => {
  if (!selected.value) return
  router.patch(route('residentes.estado', selected.value.id), {
    estado: selected.value.estado === 'activo' ? 'inactivo' : 'activo'
  }, {
    preserveScroll: true,
    onStart: () => { statusLoading.value = true },
    onSuccess: () => { statusOpen.value = false; selected.value = null },
    onError: errors => { statusError.value = String(errors.estado ?? 'No fue posible cambiar el estado.') },
    onFinish: () => { statusLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="residentes">
    <template #header>
      <UDashboardNavbar title="Residentes">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton icon="i-lucide-plus" label="Nuevo residente" @click="router.visit(route('residentes.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="grid gap-3 lg:grid-cols-[minmax(16rem,1fr)_12rem_13rem]">
          <UInput v-model="buscar" aria-label="Buscar residentes" icon="i-lucide-search" placeholder="Identificación, nombres o apellidos" size="xl" />
          <USelect
            v-model="estado"
            aria-label="Filtrar por estado"
            :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activos', value: 'activo' }, { label: 'Inactivos', value: 'inactivo' }]"
            size="xl"
            @update:model-value="reload(1, true)"
          />
          <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="edificioItems" size="xl" @update:model-value="reload(1, true)" />
        </div>

        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">Identificación</th>
                <th class="px-4 py-3 font-medium">Residente</th>
                <th class="px-4 py-3 font-medium">Contacto</th>
                <th class="px-4 py-3 font-medium">Ocupaciones</th>
                <th class="px-4 py-3 font-medium">Estado</th>
                <th class="px-4 py-3 text-right font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="residente in residentes.data" :key="residente.id" class="hover:bg-elevated/30">
                <td class="px-4 py-3">
                  <p class="font-mono font-semibold text-highlighted">{{ residente.identificacion }}</p>
                  <p class="text-xs text-muted">{{ residente.tipoIdentificacion.toUpperCase() }}</p>
                </td>
                <td class="px-4 py-3"><p class="font-medium text-highlighted">{{ residente.nombre }}</p><p class="text-xs text-muted">Persona natural</p></td>
                <td class="px-4 py-3"><p class="text-highlighted">{{ residente.celular || residente.telefono || 'Sin teléfono' }}</p><p class="text-xs text-muted">{{ residente.correo || 'Sin correo' }}</p></td>
                <td class="px-4 py-3"><UBadge color="primary" :label="String(residente.ocupacionesActualesCount)" variant="subtle" /></td>
                <td class="px-4 py-3"><UBadge :color="residente.estado === 'activo' ? 'success' : 'neutral'" :label="residente.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></td>
                <td class="px-4 py-3">
                  <div class="flex justify-end gap-1">
                    <UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver residente" @click="router.visit(route('residentes.show', residente.id))" />
                    <UButton v-if="residente.puedeGestionar" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar residente" @click="router.visit(route('residentes.edit', residente.id))" />
                    <UButton v-if="residente.puedeGestionar" :color="residente.estado === 'activo' ? 'error' : 'success'" :icon="residente.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" variant="ghost" aria-label="Cambiar estado" @click="confirmStatus(residente)" />
                  </div>
                </td>
              </tr>
              <tr v-if="residentes.data.length === 0"><td colspan="6" class="px-4 py-12 text-center text-muted">No hay residentes que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">{{ residentes.meta.total }} residente(s) · página {{ residentes.meta.currentPage }} de {{ residentes.meta.lastPage }}</p>
          <div class="flex w-full gap-2 sm:w-auto">
            <UButton color="neutral" icon="i-lucide-chevron-left" label="Anterior" variant="outline" :disabled="residentes.meta.currentPage <= 1 || loading" @click="reload(residentes.meta.currentPage - 1)" />
            <UButton color="neutral" icon="i-lucide-chevron-right" label="Siguiente" trailing variant="outline" :disabled="residentes.meta.currentPage >= residentes.meta.lastPage || loading" @click="reload(residentes.meta.currentPage + 1)" />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="statusOpen"
    :close="!statusLoading"
    :dismissible="!statusLoading"
    :title="selected?.estado === 'activo' ? 'Inactivar residente' : 'Activar residente'"
    :description="selected ? `¿Confirma cambiar el estado de ${selected.nombre}?` : ''"
  >
    <template #body><UAlert v-if="statusError" color="error" :description="statusError" icon="i-lucide-circle-alert" role="alert" variant="subtle" /></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="statusLoading" @click="statusOpen = false" /><UButton :color="selected?.estado === 'activo' ? 'error' : 'success'" :label="selected?.estado === 'activo' ? 'Inactivar' : 'Activar'" :loading="statusLoading" @click="changeStatus" /></div></template>
  </UModal>
</template>
