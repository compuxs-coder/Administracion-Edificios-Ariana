<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Departamento, EdificioEstructuraOption, EstadoEstructura } from '../../types'

const props = defineProps<{
  departamentos: {
    data: Departamento[]
    meta: { total: number, currentPage: number, lastPage: number, perPage: number }
  }
  filters: { buscar?: string | null, edificio_id?: string | null, torre_id?: string | null, estado?: EstadoEstructura | null }
  edificios: EdificioEstructuraOption[]
}>()

const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const torreId = ref(props.filters.torre_id ?? '')
const estado = ref(props.filters.estado ?? '')
const isLoading = ref(false)
const isStatusModalOpen = ref(false)
const isChangingStatus = ref(false)
const statusError = ref('')
const selected = ref<Departamento | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | undefined

const edificioItems = computed(() => [
  { label: 'Todos los edificios', value: '' },
  ...props.edificios.map(item => ({
    label: item.estado === 'activo' ? item.nombre : `${item.nombre} (inactivo)`,
    value: item.id
  }))
])
const selectedEdificio = computed(() => props.edificios.find(item => item.id === edificioId.value))
const torreItems = computed(() => [
  { label: 'Todas las torres', value: '' },
  ...(selectedEdificio.value?.torres.map(item => ({
    label: item.estado === 'activo' ? item.nombre : `${item.nombre} (inactiva)`,
    value: item.id
  })) ?? [])
])

const reload = (page = 1, replace = false) => {
  router.get(route('departamentos.index'), {
    buscar: buscar.value || undefined,
    edificio_id: edificioId.value || undefined,
    torre_id: torreId.value || undefined,
    estado: estado.value || undefined,
    page
  }, {
    preserveState: true,
    preserveScroll: true,
    replace,
    onStart: () => { isLoading.value = true },
    onFinish: () => { isLoading.value = false }
  })
}

watch(buscar, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => reload(1, true), 300)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

const changeBuilding = () => {
  torreId.value = ''
  reload(1, true)
}

const confirmStatus = (departamento: Departamento) => {
  statusError.value = ''
  selected.value = departamento
  isStatusModalOpen.value = true
}

const changeStatus = () => {
  if (!selected.value) return
  const nextStatus: EstadoEstructura = selected.value.estado === 'activo' ? 'inactivo' : 'activo'
  router.patch(route('departamentos.estado', [selected.value.edificioId, selected.value.id]), {
    estado: nextStatus
  }, {
    preserveScroll: true,
    onStart: () => { isChangingStatus.value = true },
    onSuccess: () => {
      isStatusModalOpen.value = false
      selected.value = null
    },
    onError: errors => { statusError.value = String(errors.estado ?? 'No fue posible cambiar el estado.') },
    onFinish: () => { isChangingStatus.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="departamentos">
    <template #header>
      <UDashboardNavbar title="Departamentos">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton icon="i-lucide-plus" label="Nuevo departamento" @click="router.visit(route('departamentos.create'))" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="grid gap-3 lg:grid-cols-[minmax(16rem,1fr)_13rem_13rem_11rem]">
          <UInput v-model="buscar" aria-label="Buscar departamentos" icon="i-lucide-search" placeholder="Buscar código o nombre" size="xl" />
          <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="edificioItems" size="xl" @update:model-value="changeBuilding" />
          <USelect v-model="torreId" aria-label="Filtrar por torre" :items="torreItems" :disabled="!edificioId" size="xl" @update:model-value="reload(1, true)" />
          <USelect
            v-model="estado"
            aria-label="Filtrar por estado"
            :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activos', value: 'activo' }, { label: 'Inactivos', value: 'inactivo' }]"
            size="xl"
            @update:model-value="reload(1, true)"
          />
        </div>

        <div class="overflow-x-auto rounded-xl border border-default" :class="isLoading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted">
              <tr>
                <th class="px-4 py-3 font-medium">Departamento</th>
                <th class="px-4 py-3 font-medium">Edificio</th>
                <th class="px-4 py-3 font-medium">Torre / piso</th>
                <th class="px-4 py-3 font-medium">Alícuota</th>
                <th class="px-4 py-3 font-medium">Anexos</th>
                <th class="px-4 py-3 font-medium">Estado</th>
                <th class="px-4 py-3 text-right font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="departamento in departamentos.data" :key="departamento.id" class="hover:bg-elevated/30">
                <td class="px-4 py-3">
                  <p class="font-mono font-semibold text-highlighted">{{ departamento.codigo }}</p>
                  <p class="mt-0.5 text-xs text-muted">{{ departamento.nombre }}</p>
                </td>
                <td class="px-4 py-3 text-highlighted">{{ departamento.edificio }}</td>
                <td class="px-4 py-3">
                  <p class="text-highlighted">{{ departamento.torre }}</p>
                  <p class="text-xs text-muted">{{ departamento.piso }}</p>
                </td>
                <td class="px-4 py-3 font-mono text-highlighted">{{ Number(departamento.alicuota).toFixed(6) }}%</td>
                <td class="px-4 py-3">
                  <div class="flex max-w-52 flex-wrap gap-1">
                    <UBadge v-for="item in departamento.parqueaderos" :key="`p-${item.id}`" color="neutral" :label="item.codigo" variant="subtle" />
                    <UBadge v-for="item in departamento.bodegas" :key="`b-${item.id}`" color="primary" :label="item.codigo" variant="subtle" />
                    <span v-if="!departamento.parqueaderos.length && !departamento.bodegas.length" class="text-xs text-muted">Sin anexos</span>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <UBadge :color="departamento.estado === 'activo' ? 'success' : 'neutral'" :label="departamento.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" />
                </td>
                <td class="px-4 py-3">
                  <div class="flex justify-end gap-1">
                    <UButton
                      color="neutral"
                      icon="i-lucide-eye"
                      variant="ghost"
                      aria-label="Ver departamento"
                      @click="router.visit(route('departamentos.show', [departamento.edificioId, departamento.id]))"
                    />
                    <UButton
                      color="neutral"
                      icon="i-lucide-pencil"
                      variant="ghost"
                      aria-label="Editar departamento"
                      @click="router.visit(route('departamentos.edit', [departamento.edificioId, departamento.id]))"
                    />
                    <UButton
                      :color="departamento.estado === 'activo' ? 'error' : 'success'"
                      :icon="departamento.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'"
                      variant="ghost"
                      aria-label="Cambiar estado del departamento"
                      @click="confirmStatus(departamento)"
                    />
                  </div>
                </td>
              </tr>
              <tr v-if="departamentos.data.length === 0">
                <td colspan="7" class="px-4 py-12 text-center text-muted">No hay departamentos que coincidan con los filtros.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">{{ departamentos.meta.total }} departamento(s) · página {{ departamentos.meta.currentPage }} de {{ departamentos.meta.lastPage }}</p>
          <div class="flex w-full gap-2 sm:w-auto">
            <UButton color="neutral" icon="i-lucide-chevron-left" label="Anterior" variant="outline" :disabled="departamentos.meta.currentPage <= 1 || isLoading" @click="reload(departamentos.meta.currentPage - 1)" />
            <UButton color="neutral" icon="i-lucide-chevron-right" label="Siguiente" trailing variant="outline" :disabled="departamentos.meta.currentPage >= departamentos.meta.lastPage || isLoading" @click="reload(departamentos.meta.currentPage + 1)" />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="isStatusModalOpen"
    :close="!isChangingStatus"
    :dismissible="!isChangingStatus"
    :title="selected?.estado === 'activo' ? 'Inactivar departamento' : 'Activar departamento'"
    :description="selected ? (selected.estado === 'activo' ? `¿Confirma inactivar ${selected.codigo}? Se cerrarán sus asignaciones vigentes.` : `¿Confirma activar ${selected.codigo}?`) : ''"
  >
    <template #body>
      <UAlert v-if="statusError" color="error" icon="i-lucide-circle-alert" :description="statusError" role="alert" variant="subtle" />
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton color="neutral" label="Cancelar" variant="outline" :disabled="isChangingStatus" @click="isStatusModalOpen = false" />
        <UButton :color="selected?.estado === 'activo' ? 'error' : 'success'" :label="selected?.estado === 'activo' ? 'Inactivar' : 'Activar'" :loading="isChangingStatus" @click="changeStatus" />
      </div>
    </template>
  </UModal>
</template>
