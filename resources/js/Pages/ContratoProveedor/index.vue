<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ContratoProveedor, EstadoContratoProveedor, PaginationMeta, ProveedorOption } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  contratos: { data: ContratoProveedor[], meta: PaginationMeta }
  filters: { buscar?: string | null, edificio_id?: string | null, proveedor_id?: string | null, estado?: EstadoContratoProveedor | null }
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const proveedorId = ref(props.filters.proveedor_id ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined
const providerItems = computed(() => props.proveedores.filter(item => !edificioId.value || item.edificioId === edificioId.value).map(item => ({ label: item.nombre, value: item.id })))
const stateColor = (state: EstadoContratoProveedor) => state === 'registrado' ? 'success' : state === 'borrador' ? 'warning' : 'neutral'
const reload = (page = 1, replace = false) => router.get(route('contratos-proveedor.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  proveedor_id: proveedorId.value || undefined,
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
const changeBuilding = () => { proveedorId.value = ''; reload(1, true) }
</script>

<template>
  <UDashboardPanel id="contratos-proveedor">
    <template #header><UDashboardNavbar title="Contratos de proveedores"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton v-if="canAny('gastos.gestionar')" icon="i-lucide-plus" label="Nuevo contrato" @click="router.visit(route('contratos-proveedor.create'))" /></template></UDashboardNavbar></template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="grid gap-3 xl:grid-cols-[minmax(15rem,1fr)_14rem_14rem_12rem]"><UInput v-model="buscar" aria-label="Buscar contratos" icon="i-lucide-search" placeholder="Número, objeto o proveedor" size="xl" /><USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" size="xl" @update:model-value="changeBuilding" /><USelect v-model="proveedorId" aria-label="Filtrar por proveedor" :items="[{ label: 'Todos los proveedores', value: '' }, ...providerItems]" size="xl" @update:model-value="reload(1, true)" /><USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Borrador', value: 'borrador' }, { label: 'Registrado', value: 'registrado' }, { label: 'Anulado', value: 'anulado' }]" size="xl" @update:model-value="reload(1, true)" /></div>
        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm"><thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Contrato</th><th class="p-3">Proveedor</th><th class="p-3">Objeto</th><th class="p-3">Vigencia</th><th class="p-3 text-right">Valor</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead><tbody class="divide-y divide-default">
            <tr v-for="contrato in contratos.data" :key="contrato.id" class="hover:bg-elevated/30"><td class="p-3"><p class="font-mono font-semibold text-primary">{{ contrato.referencia }}</p><p class="text-xs text-muted">{{ contrato.edificio }}</p></td><td class="p-3 font-medium text-highlighted">{{ contrato.proveedor }}</td><td class="max-w-xs p-3"><p class="line-clamp-2">{{ contrato.objeto }}</p></td><td class="p-3 text-muted"><p>{{ contrato.fechaInicio }}</p><p class="text-xs">{{ contrato.fechaFin || 'Sin fecha final' }}</p></td><td class="p-3 text-right font-mono">{{ contrato.montoTotal === null ? 'No definido' : `$${contrato.montoTotal}` }}</td><td class="p-3"><UBadge :color="stateColor(contrato.estado)" :label="contrato.estado" variant="subtle" /></td><td class="p-3"><div class="flex justify-end gap-1"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver contrato" @click="router.visit(route('contratos-proveedor.show', [contrato.edificioId, contrato.id]))" /><UButton v-if="contrato.estado === 'borrador' && can('gastos.gestionar', contrato.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar contrato" @click="router.visit(route('contratos-proveedor.edit', [contrato.edificioId, contrato.id]))" /></div></td></tr>
            <tr v-if="!contratos.data.length"><td class="p-10 text-center text-muted" colspan="7">No hay contratos que coincidan con los filtros.</td></tr>
          </tbody></table>
        </div>
        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ contratos.meta.total }} contrato(s) · página {{ contratos.meta.currentPage }} de {{ contratos.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="contratos.meta.currentPage <= 1 || loading" @click="reload(contratos.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="contratos.meta.currentPage >= contratos.meta.lastPage || loading" @click="reload(contratos.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
