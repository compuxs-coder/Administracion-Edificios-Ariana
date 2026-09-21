<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CuentaPorPagar, CuentasPorPagarResumen, EstadoCuentaPorPagar, PaginationMeta, ProveedorOption } from '../../types'

const props = defineProps<{
  cuentasPorPagar: { data: CuentaPorPagar[], meta: PaginationMeta }
  resumen?: CuentasPorPagarResumen
  filters: { edificio_id?: string | null, proveedor_id?: string | null, estado?: EstadoCuentaPorPagar | null }
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
}>()
const edificioId = ref(props.filters.edificio_id ?? '')
const proveedorId = ref(props.filters.proveedor_id ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const providerItems = computed(() => props.proveedores.filter(item => !edificioId.value || item.edificioId === edificioId.value).map(item => ({ label: item.nombre, value: item.id })))
const daysOverdue = (date: string) => Math.max(0, Math.floor((Date.now() - new Date(`${date}T00:00:00`).getTime()) / 86400000))
const summary = computed<CuentasPorPagarResumen>(() => props.resumen ?? {
  totalPendiente: props.cuentasPorPagar.data.reduce((total, item) => total + Number(item.saldo), 0).toFixed(4),
  totalVencido: props.cuentasPorPagar.data.filter(item => item.estado === 'pendiente' && daysOverdue(item.fechaVencimiento) > 0).reduce((total, item) => total + Number(item.saldo), 0).toFixed(4),
  porVencer: props.cuentasPorPagar.data.filter(item => item.estado === 'pendiente' && daysOverdue(item.fechaVencimiento) === 0).reduce((total, item) => total + Number(item.saldo), 0).toFixed(4),
  cantidadPendiente: props.cuentasPorPagar.data.filter(item => item.estado === 'pendiente').length
})
const statusColor = (account: CuentaPorPagar) => account.estado === 'anulada' ? 'neutral' : daysOverdue(account.fechaVencimiento) > 0 ? 'error' : 'warning'
const statusLabel = (account: CuentaPorPagar) => account.estado === 'pendiente' && daysOverdue(account.fechaVencimiento) > 0 ? 'vencida' : account.estado
const reload = (page = 1, replace = false) => router.get(route('cuentas-por-pagar.index'), {
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
const changeBuilding = () => { proveedorId.value = ''; reload(1, true) }
</script>

<template>
  <UDashboardPanel id="cuentas-por-pagar">
    <template #header><UDashboardNavbar title="Cuentas por pagar"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UAlert color="info" description="Vista de consulta generada por gastos a crédito registrados. Los saldos no se modifican desde este módulo." icon="i-lucide-info" variant="subtle" />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><UCard><p class="text-xs uppercase text-muted">Saldo pendiente</p><p class="mt-2 font-mono text-xl font-semibold text-highlighted">${{ summary.totalPendiente }}</p></UCard><UCard><p class="text-xs uppercase text-muted">Saldo vencido</p><p class="mt-2 font-mono text-xl font-semibold text-error">${{ summary.totalVencido }}</p></UCard><UCard><p class="text-xs uppercase text-muted">Por vencer</p><p class="mt-2 font-mono text-xl font-semibold text-highlighted">${{ summary.porVencer }}</p></UCard><UCard><p class="text-xs uppercase text-muted">Cuentas pendientes</p><p class="mt-2 text-xl font-semibold text-highlighted">{{ summary.cantidadPendiente }}</p></UCard></div>
        <UCard><div class="grid gap-3 md:grid-cols-3"><USelect v-model="edificioId" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="changeBuilding" /><USelect v-model="proveedorId" :items="[{ label: 'Todos los proveedores', value: '' }, ...providerItems]" @update:model-value="reload(1, true)" /><USelect v-model="estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Pendiente', value: 'pendiente' }, { label: 'Anulada', value: 'anulada' }]" @update:model-value="reload(1, true)" /></div></UCard>
        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''"><table class="w-full min-w-4xl text-left text-sm"><thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Gasto</th><th class="p-3">Proveedor</th><th class="p-3">Vencimiento</th><th class="p-3 text-right">Monto original</th><th class="p-3 text-right">Saldo</th><th class="p-3">Estado</th><th class="p-3" /></tr></thead><tbody class="divide-y divide-default"><tr v-for="cuenta in cuentasPorPagar.data" :key="cuenta.id" class="cursor-pointer hover:bg-elevated/30" @click="router.visit(route('cuentas-por-pagar.show', [cuenta.edificioId, cuenta.id]))"><td class="p-3"><p class="font-mono font-semibold text-primary">{{ cuenta.numeroGasto }}</p><p class="text-xs text-muted">{{ cuenta.edificio }}</p></td><td class="p-3 font-medium text-highlighted">{{ cuenta.proveedor }}</td><td class="p-3"><p :class="daysOverdue(cuenta.fechaVencimiento) > 0 && cuenta.estado === 'pendiente' ? 'font-medium text-error' : ''">{{ cuenta.fechaVencimiento }}</p><p v-if="daysOverdue(cuenta.fechaVencimiento) > 0 && cuenta.estado === 'pendiente'" class="text-xs text-error">{{ daysOverdue(cuenta.fechaVencimiento) }} día(s) vencida</p></td><td class="p-3 text-right font-mono">${{ cuenta.montoOriginal }}</td><td class="p-3 text-right font-mono font-semibold">${{ cuenta.saldo }}</td><td class="p-3"><UBadge :color="statusColor(cuenta)" :label="statusLabel(cuenta)" variant="subtle" /></td><td class="p-3 text-right"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver cuenta por pagar" @click.stop="router.visit(route('cuentas-por-pagar.show', [cuenta.edificioId, cuenta.id]))" /></td></tr><tr v-if="!cuentasPorPagar.data.length"><td class="p-10 text-center text-muted" colspan="7">No hay cuentas por pagar que coincidan con los filtros.</td></tr></tbody></table></div>
        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ cuentasPorPagar.meta.total }} cuenta(s) · página {{ cuentasPorPagar.meta.currentPage }} de {{ cuentasPorPagar.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="cuentasPorPagar.meta.currentPage <= 1 || loading" @click="reload(cuentasPorPagar.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="cuentasPorPagar.meta.currentPage >= cuentasPorPagar.meta.lastPage || loading" @click="reload(cuentasPorPagar.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
