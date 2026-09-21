<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EstadoGasto, Gasto, PaginationMeta, ProveedorOption, TipoPagoGasto } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  gastos: { data: Gasto[], meta: PaginationMeta }
  filters: { buscar?: string | null, edificio_id?: string | null, proveedor_id?: string | null, tipo_pago?: TipoPagoGasto | null, estado?: EstadoGasto | null }
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const proveedorId = ref(props.filters.proveedor_id ?? '')
const tipoPago = ref(props.filters.tipo_pago ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined
const providerItems = computed(() => props.proveedores.filter(item => !edificioId.value || item.edificioId === edificioId.value).map(item => ({ label: item.nombre, value: item.id })))
const stateColor = (state: EstadoGasto) => state === 'registrado' ? 'success' : state === 'borrador' ? 'warning' : 'neutral'
const reload = (page = 1, replace = false) => router.get(route('gastos.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  proveedor_id: proveedorId.value || undefined,
  tipo_pago: tipoPago.value || undefined,
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
  <UDashboardPanel id="gastos">
    <template #header><UDashboardNavbar title="Gastos"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton v-if="canAny('gastos.gestionar')" icon="i-lucide-plus" label="Nuevo gasto" @click="router.visit(route('gastos.create'))" /></template></UDashboardNavbar></template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UCard><div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5"><UInput v-model="buscar" aria-label="Buscar gastos" icon="i-lucide-search" placeholder="Número, referencia o concepto" /><USelect v-model="edificioId" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="changeBuilding" /><USelect v-model="proveedorId" :items="[{ label: 'Todos los proveedores', value: '' }, ...providerItems]" @update:model-value="reload(1, true)" /><USelect v-model="tipoPago" :items="[{ label: 'Todos los tipos', value: '' }, { label: 'Contado', value: 'contado' }, { label: 'Crédito', value: 'credito' }]" @update:model-value="reload(1, true)" /><USelect v-model="estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Borrador', value: 'borrador' }, { label: 'Registrado', value: 'registrado' }, { label: 'Anulado', value: 'anulado' }]" @update:model-value="reload(1, true)" /></div></UCard>
        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm"><thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Número</th><th class="p-3">Proveedor</th><th class="p-3">Concepto</th><th class="p-3">Fecha</th><th class="p-3">Tipo de pago</th><th class="p-3 text-right">Total</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead><tbody class="divide-y divide-default">
            <tr v-for="gasto in gastos.data" :key="gasto.id" class="hover:bg-elevated/30"><td class="p-3"><p class="font-mono font-semibold" :class="gasto.numero ? 'text-primary' : 'text-muted'">{{ gasto.numero || 'Borrador' }}</p><p class="text-xs text-muted">{{ gasto.edificio }}</p></td><td class="p-3"><p class="font-medium text-highlighted">{{ gasto.proveedor }}</p><p v-if="gasto.contratoSnapshot" class="font-mono text-xs text-muted">Contrato {{ gasto.contratoSnapshot.referencia }}</p></td><td class="max-w-xs p-3"><p class="line-clamp-2">{{ gasto.concepto }}</p></td><td class="p-3">{{ gasto.fechaGasto }}</td><td class="p-3"><UBadge color="neutral" :label="gasto.tipoPago" variant="outline" /></td><td class="p-3 text-right font-mono font-semibold">${{ gasto.monto }}</td><td class="p-3"><UBadge :color="stateColor(gasto.estado)" :label="gasto.estado" variant="subtle" /></td><td class="p-3"><div class="flex justify-end gap-1"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver gasto" @click="router.visit(route('gastos.show', [gasto.edificioId, gasto.id]))" /><UButton v-if="gasto.estado === 'borrador' && can('gastos.gestionar', gasto.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar gasto" @click="router.visit(route('gastos.edit', [gasto.edificioId, gasto.id]))" /></div></td></tr>
            <tr v-if="!gastos.data.length"><td class="p-10 text-center text-muted" colspan="8">No hay gastos que coincidan con los filtros.</td></tr>
          </tbody></table>
        </div>
        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ gastos.meta.total }} gasto(s) · página {{ gastos.meta.currentPage }} de {{ gastos.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="gastos.meta.currentPage <= 1 || loading" @click="reload(gastos.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="gastos.meta.currentPage >= gastos.meta.lastPage || loading" @click="reload(gastos.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
