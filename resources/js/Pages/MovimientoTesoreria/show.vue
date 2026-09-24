<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ConciliacionTesoreria, EstadoConciliacionMovimiento, FormaDesembolso, MovimientoTesoreria } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ movimiento: MovimientoTesoreria }>()
const { can } = useBuildingPermissions()
const cancelOpen = ref(false)
const reverseOpen = ref(false)
const motivoAnulacion = ref('')
const motivoReversion = ref('')
const loading = ref(false)
const error = ref('')
const history = computed(() => props.movimiento.historialConciliaciones ?? [])
const currentReconciliation = computed(() => {
  return props.movimiento.conciliacion?.estado === 'vigente' ? props.movimiento.conciliacion : null
})
const canReconcile = computed(() => can('conciliaciones.gestionar', props.movimiento.edificioId) && props.movimiento.estado === 'registrado' && props.movimiento.naturaleza === 'egreso' && props.movimiento.estadoConciliacion === 'pendiente')
const canReverse = computed(() => can('conciliaciones.gestionar', props.movimiento.edificioId) && Boolean(currentReconciliation.value))
const canCancel = computed(() => can('movimientos_tesoreria.anular', props.movimiento.edificioId) && props.movimiento.estado === 'registrado' && props.movimiento.estadoConciliacion !== 'conciliado')
const stateLabels: Record<EstadoConciliacionMovimiento, string> = { pendiente: 'Pendiente', conciliado: 'Conciliado', no_aplica: 'No aplica', anulado: 'Anulado' }
const stateColor = (state: EstadoConciliacionMovimiento): 'warning' | 'success' | 'neutral' | 'error' => state === 'pendiente' ? 'warning' : state === 'conciliado' ? 'success' : state === 'anulado' ? 'error' : 'neutral'
const paymentLabels: Record<FormaDesembolso, string> = { efectivo: 'Efectivo', transferencia: 'Transferencia', deposito: 'Deposito', tarjeta: 'Tarjeta', cheque: 'Cheque', otro: 'Otro' }
const disbursementId = (reconciliation: ConciliacionTesoreria) => reconciliation.desembolso?.id ?? ''
const disbursementNumber = (reconciliation: ConciliacionTesoreria) => reconciliation.desembolso?.numero ?? 'Sin numero'

const openCancel = () => {
  error.value = ''
  motivoAnulacion.value = ''
  cancelOpen.value = true
}
const openReverse = () => {
  error.value = ''
  motivoReversion.value = ''
  reverseOpen.value = true
}
const cancel = () => router.patch(route('movimientos-tesoreria.cancel', [props.movimiento.edificioId, props.movimiento.cuentaId, props.movimiento.id]), {
  motivo: motivoAnulacion.value
}, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? errors.conciliacion ?? errors.movimiento ?? 'No fue posible anular el movimiento.') },
  onFinish: () => { loading.value = false }
})
const reverse = () => {
  const conciliacion = currentReconciliation.value
  if (!conciliacion) return
  router.patch(route('conciliaciones-tesoreria.reverse', [props.movimiento.edificioId, props.movimiento.cuentaId, props.movimiento.id, conciliacion.id]), {
    motivo: motivoReversion.value
  }, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onSuccess: () => { reverseOpen.value = false },
    onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? errors.conciliacion ?? 'No fue posible revertir la conciliacion.') },
    onFinish: () => { loading.value = false }
  })
}
const openDisbursement = (conciliacion: ConciliacionTesoreria) => {
  const id = disbursementId(conciliacion)
  if (id) router.visit(route('desembolsos.show', [props.movimiento.edificioId, id]))
}
</script>

<template>
  <UDashboardPanel id="movimientos-tesoreria-show">
    <template #header>
      <UDashboardNavbar title="Detalle del movimiento">
        <template #leading><UDashboardSidebarCollapse /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap justify-end gap-2"><UButton v-if="canReconcile" icon="i-lucide-link" label="Conciliar" @click="router.visit(route('movimientos-tesoreria.reconcile', [movimiento.edificioId, movimiento.cuentaId, movimiento.id]))" /><UButton v-if="canReverse" color="warning" icon="i-lucide-link-2-off" label="Revertir conciliacion" variant="outline" @click="openReverse" /><UButton v-if="canCancel" color="error" icon="i-lucide-ban" label="Anular" variant="outline" @click="openCancel" /></div>
        <UAlert v-if="movimiento.estadoConciliacion === 'conciliado'" color="warning" description="Este movimiento tiene una conciliacion vigente. Debe revertirla antes de poder anular el movimiento." icon="i-lucide-triangle-alert" variant="subtle" />
        <UAlert v-if="movimiento.naturaleza === 'ingreso' && movimiento.estado === 'registrado'" color="info" description="Los ingresos no se concilian con desembolsos en esta etapa." icon="i-lucide-info" variant="subtle" />

        <UCard>
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">{{ movimiento.edificio }}</p><h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ movimiento.referencia || 'Sin referencia' }}</h1><p class="mt-1 text-sm">{{ movimiento.cuentaCodigo }} - {{ movimiento.cuenta }}</p></div><UBadge :color="stateColor(movimiento.estadoConciliacion)" :label="stateLabels[movimiento.estadoConciliacion]" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Fecha</dt><dd class="mt-1">{{ movimiento.fechaMovimiento }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Naturaleza</dt><dd class="mt-1"><UBadge :color="movimiento.naturaleza === 'ingreso' ? 'success' : 'error'" :label="movimiento.naturaleza === 'ingreso' ? 'Ingreso' : 'Egreso'" variant="outline" /></dd></div>
            <div><dt class="text-xs uppercase text-muted">Tipo de cuenta</dt><dd class="mt-1">{{ movimiento.cuentaTipo === 'bancaria' ? 'Bancaria' : 'Caja' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Estado</dt><dd class="mt-1">{{ movimiento.estado === 'registrado' ? 'Registrado' : 'Anulado' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Registrado por</dt><dd class="mt-1">{{ movimiento.registradoPor || 'Usuario eliminado' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Registrado</dt><dd class="mt-1">{{ movimiento.createdAt || 'No disponible' }}</dd></div>
          </dl>
          <div class="mt-6 border-t border-default pt-5"><p class="text-xs uppercase text-muted">Monto</p><p class="mt-1 font-mono text-3xl font-semibold" :class="movimiento.naturaleza === 'ingreso' ? 'text-success' : 'text-error'">{{ movimiento.naturaleza === 'ingreso' ? '+' : '-' }}${{ movimiento.monto }}</p></div>
        </UCard>

        <UCard v-if="movimiento.descripcion"><template #header><p class="font-semibold text-highlighted">Descripcion</p></template><p class="whitespace-pre-line text-sm text-muted">{{ movimiento.descripcion }}</p></UCard>
        <UAlert v-if="movimiento.estado === 'anulado'" color="neutral" :title="movimiento.anuladoAt ? `Anulado el ${movimiento.anuladoAt} por ${movimiento.anuladoPor || 'usuario no disponible'}` : 'Movimiento anulado'" :description="movimiento.motivoAnulacion || 'Sin motivo registrado.'" icon="i-lucide-ban" variant="subtle" />

        <UCard v-if="currentReconciliation">
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="font-semibold text-highlighted">Conciliacion vigente</p><p class="mt-1 text-xs text-muted">Relacion individual con un desembolso registrado.</p></div><UBadge color="success" label="Vigente" variant="subtle" /></div></template>
          <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><p class="text-xs uppercase text-muted">Desembolso</p><button class="mt-1 font-mono font-semibold text-primary hover:underline disabled:cursor-default disabled:no-underline" type="button" :disabled="!disbursementId(currentReconciliation)" @click="openDisbursement(currentReconciliation)">{{ disbursementNumber(currentReconciliation) }}</button></div>
            <div><p class="text-xs uppercase text-muted">Proveedor</p><p class="mt-1">{{ currentReconciliation.desembolso?.proveedor || 'No disponible' }}</p></div>
            <div><p class="text-xs uppercase text-muted">Forma de pago</p><p class="mt-1">{{ currentReconciliation.desembolso ? paymentLabels[currentReconciliation.desembolso.formaPago] : 'No disponible' }}</p></div>
            <div><p class="text-xs uppercase text-muted">Conciliado</p><p class="mt-1">{{ currentReconciliation.conciliadoAt || 'No disponible' }}</p></div>
            <div><p class="text-xs uppercase text-muted">Conciliado por</p><p class="mt-1">{{ currentReconciliation.conciliadoPor || 'Usuario eliminado' }}</p></div>
            <div><p class="text-xs uppercase text-muted">Nota</p><p class="mt-1">{{ currentReconciliation.nota || 'Sin nota' }}</p></div>
          </div>
        </UCard>

        <UCard>
          <template #header><div><p class="font-semibold text-highlighted">Historial de conciliaciones</p><p class="mt-1 text-xs text-muted">Cada conciliacion y reversion permanece registrada.</p></div></template>
          <div v-if="history.length" class="divide-y divide-default">
            <div v-for="conciliacion in history" :key="conciliacion.id" class="grid gap-4 py-4 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
              <div><div class="flex flex-wrap items-center gap-2"><button class="font-mono font-semibold text-primary hover:underline disabled:cursor-default disabled:no-underline" type="button" :disabled="!disbursementId(conciliacion)" @click="openDisbursement(conciliacion)">{{ disbursementNumber(conciliacion) }}</button><UBadge :color="conciliacion.estado === 'vigente' ? 'success' : 'neutral'" :label="conciliacion.estado === 'vigente' ? 'Vigente' : 'Revertida'" variant="subtle" /></div><p v-if="conciliacion.desembolso" class="mt-1 text-sm text-muted">{{ conciliacion.desembolso.proveedor }} - ${{ conciliacion.desembolso.monto }} - {{ paymentLabels[conciliacion.desembolso.formaPago] }}</p><p v-if="conciliacion.nota" class="mt-2 text-sm">{{ conciliacion.nota }}</p><p v-if="conciliacion.motivoReversion" class="mt-2 text-sm text-warning">Motivo de reversion: {{ conciliacion.motivoReversion }}</p></div>
              <div class="text-xs text-muted sm:text-right"><p>Conciliado: {{ conciliacion.conciliadoAt || 'No disponible' }}</p><p v-if="conciliacion.revertidoAt">Revertido: {{ conciliacion.revertidoAt }}</p><p>{{ conciliacion.estado === 'vigente' ? conciliacion.conciliadoPor : conciliacion.revertidoPor || 'Usuario eliminado' }}</p></div>
            </div>
          </div>
          <p v-else class="text-sm text-muted">Este movimiento no tiene episodios de conciliacion.</p>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Anular movimiento" description="La anulacion conservara el movimiento y su trazabilidad. No podra deshacerse.">
    <template #body><div class="space-y-4"><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo de anulacion" required><UTextarea v-model="motivoAnulacion" :rows="3" maxlength="2000" required /></UFormField></div></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="cancelOpen = false" /><UButton color="error" label="Anular movimiento" :disabled="!motivoAnulacion.trim()" :loading="loading" @click="cancel" /></div></template>
  </UModal>

  <UModal v-model:open="reverseOpen" :close="!loading" :dismissible="!loading" title="Revertir conciliacion" description="La relacion quedara en el historial y el egreso volvera a estado pendiente.">
    <template #body><div class="space-y-4"><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo de reversion" required><UTextarea v-model="motivoReversion" :rows="3" maxlength="2000" required /></UFormField></div></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="reverseOpen = false" /><UButton color="warning" label="Revertir conciliacion" :disabled="!motivoReversion.trim()" :loading="loading" @click="reverse" /></div></template>
  </UModal>
</template>
