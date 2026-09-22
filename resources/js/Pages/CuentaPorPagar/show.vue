<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CuentaPorPagar, EstadoCuentaPorPagar } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ cuentaPorPagar: CuentaPorPagar }>()
const { can } = useBuildingPermissions()
const daysOverdue = (date: string) => Math.max(0, Math.floor((Date.now() - new Date(`${date}T00:00:00`).getTime()) / 86400000))
const accountState = computed<EstadoCuentaPorPagar>(() => {
  if (props.cuentaPorPagar.estado === 'anulada') return 'anulada'
  if (props.cuentaPorPagar.estado === 'pagada' || Number(props.cuentaPorPagar.saldo) <= 0) return 'pagada'
  if (props.cuentaPorPagar.estado === 'parcial' || Number(props.cuentaPorPagar.saldo) < Number(props.cuentaPorPagar.montoOriginal)) return 'parcial'
  return 'pendiente'
})
const isOpen = computed(() => accountState.value !== 'anulada' && accountState.value !== 'pagada' && Number(props.cuentaPorPagar.saldo) > 0)
const isOverdue = computed(() => isOpen.value && daysOverdue(props.cuentaPorPagar.fechaVencimiento) > 0)
const canRegister = computed(() => can('desembolsos.registrar', props.cuentaPorPagar.edificioId) && isOpen.value)
const amountPaid = computed(() => Math.max(0, Number(props.cuentaPorPagar.montoOriginal) - Number(props.cuentaPorPagar.saldo)).toFixed(4))
const statusColor = computed<'warning' | 'info' | 'success' | 'neutral'>(() => accountState.value === 'pendiente' ? 'warning' : accountState.value === 'parcial' ? 'info' : accountState.value === 'pagada' ? 'success' : 'neutral')
const statusLabel = computed(() => ({ pendiente: 'Pendiente', parcial: 'Parcial', pagada: 'Pagada', anulada: 'Anulada' })[accountState.value])
const registerDisbursement = () => router.visit(route('desembolsos.create', {
  edificio_id: props.cuentaPorPagar.edificioId,
  proveedor_id: props.cuentaPorPagar.proveedorId
}))
</script>

<template>
  <UDashboardPanel id="cuentas-por-pagar-show">
    <template #header><UDashboardNavbar :title="`Cuenta · ${cuentaPorPagar.numeroGasto}`"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton v-if="canRegister" icon="i-lucide-banknote-arrow-down" label="Registrar desembolso" @click="registerDisbursement" /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UAlert color="info" description="Esta es una vista de consulta. La cuenta se origina en el gasto y su saldo se actualiza mediante desembolsos." icon="i-lucide-lock-keyhole" variant="subtle" />
        <UCard><template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">{{ cuentaPorPagar.edificio }}</p><h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ cuentaPorPagar.numeroGasto }}</h1></div><UBadge :color="statusColor" :label="statusLabel" variant="subtle" /></div></template><dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><div><dt class="text-xs uppercase text-muted">Proveedor</dt><dd class="mt-1 font-medium">{{ cuentaPorPagar.proveedor }}</dd></div><div><dt class="text-xs uppercase text-muted">Creada</dt><dd class="mt-1">{{ cuentaPorPagar.createdAt || 'No disponible' }}</dd></div><div><dt class="text-xs uppercase text-muted">Fecha de vencimiento</dt><dd class="mt-1" :class="isOverdue ? 'text-error' : ''">{{ cuentaPorPagar.fechaVencimiento }}</dd><dd v-if="isOverdue" class="text-xs text-error">{{ daysOverdue(cuentaPorPagar.fechaVencimiento) }} día(s) de vencimiento</dd></div><div v-if="cuentaPorPagar.anuladoAt"><dt class="text-xs uppercase text-muted">Anulada</dt><dd class="mt-1">{{ cuentaPorPagar.anuladoAt }}</dd></div></dl></UCard>
        <div class="grid gap-6 sm:grid-cols-3"><UCard><p class="text-xs uppercase text-muted">Monto original</p><p class="mt-2 font-mono text-2xl font-semibold text-highlighted">${{ cuentaPorPagar.montoOriginal }}</p></UCard><UCard><p class="text-xs uppercase text-muted">Monto pagado</p><p class="mt-2 font-mono text-2xl font-semibold text-primary">${{ amountPaid }}</p></UCard><UCard><p class="text-xs uppercase text-muted">Saldo pendiente</p><p class="mt-2 font-mono text-2xl font-semibold" :class="isOverdue ? 'text-error' : accountState === 'pagada' ? 'text-success' : 'text-highlighted'">${{ cuentaPorPagar.saldo }}</p></UCard></div>
        <UCard><template #header><p class="font-semibold text-highlighted">Origen</p></template><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-sm text-muted">Gasto que generó la cuenta</p><p class="mt-1 font-mono text-highlighted">{{ cuentaPorPagar.numeroGasto }}</p></div><UButton color="neutral" icon="i-lucide-receipt" label="Ver gasto" variant="outline" @click="router.visit(route('gastos.show', [cuentaPorPagar.edificioId, cuentaPorPagar.gastoId]))" /></div></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
