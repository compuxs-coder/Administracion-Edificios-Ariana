<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { DesembolsoConciliacionCandidate, FormaDesembolso, MovimientoTesoreria } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  movimiento: MovimientoTesoreria
  desembolsos: DesembolsoConciliacionCandidate[]
}>()
const { can } = useBuildingPermissions()
const selectedId = ref('')
const nota = ref('')
const confirmOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string>>({})
const paymentLabels: Record<FormaDesembolso, string> = { efectivo: 'Efectivo', transferencia: 'Transferencia', deposito: 'Deposito', tarjeta: 'Tarjeta', cheque: 'Cheque', otro: 'Otro' }
const allowed = computed(() => can('conciliaciones.gestionar', props.movimiento.edificioId))
const amountKey = (value: string) => Number(value).toFixed(4)
const candidates = computed(() => props.desembolsos.filter(item => amountKey(item.monto) === amountKey(props.movimiento.monto)
  && (props.movimiento.cuentaTipo === 'caja' ? item.formaPago === 'efectivo' : item.formaPago !== 'efectivo')))
const selected = computed(() => candidates.value.find(item => item.id === selectedId.value) ?? null)
const history = computed(() => props.movimiento.historialConciliaciones ?? [])

const review = () => {
  errors.value = {}
  if (selected.value && allowed.value) confirmOpen.value = true
}
const submit = () => {
  if (!selected.value || !allowed.value) return
  router.post(route('conciliaciones-tesoreria.store', [props.movimiento.edificioId, props.movimiento.cuentaId, props.movimiento.id]), {
    desembolso_id: selected.value.id,
    nota: nota.value
  }, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onError: responseErrors => { errors.value = responseErrors; confirmOpen.value = false },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="movimientos-tesoreria-reconcile">
    <template #header><UDashboardNavbar title="Conciliar egreso"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UAlert v-if="!allowed" color="warning" description="No tiene permiso para gestionar conciliaciones en este edificio." icon="i-lucide-lock-keyhole" variant="subtle" />
        <UAlert v-if="errors.desembolsoId || errors.movimiento || errors.conciliacion" color="error" :description="errors.desembolsoId || errors.movimiento || errors.conciliacion" icon="i-lucide-circle-alert" variant="subtle" />
        <UAlert color="info" description="La conciliacion es individual y exige un desembolso registrado por el mismo monto. Caja admite efectivo; cuenta bancaria admite medios no efectivos." icon="i-lucide-info" variant="subtle" />

        <UCard>
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="font-semibold text-highlighted">Egreso pendiente</p><p class="mt-1 text-xs text-muted">{{ movimiento.edificio }} - {{ movimiento.cuentaCodigo }} - {{ movimiento.cuenta }}</p></div><UBadge color="warning" label="Pendiente" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4"><div><dt class="text-xs uppercase text-muted">Fecha</dt><dd class="mt-1">{{ movimiento.fechaMovimiento }}</dd></div><div><dt class="text-xs uppercase text-muted">Referencia</dt><dd class="mt-1">{{ movimiento.referencia || 'Sin referencia' }}</dd></div><div><dt class="text-xs uppercase text-muted">Cuenta</dt><dd class="mt-1">{{ movimiento.cuentaTipo === 'caja' ? 'Caja' : 'Bancaria' }}</dd></div><div><dt class="text-xs uppercase text-muted">Monto exacto</dt><dd class="mt-1 font-mono text-xl font-semibold">${{ movimiento.monto }}</dd></div></dl>
        </UCard>

        <section class="overflow-hidden rounded-xl border border-default">
          <div class="border-b border-default bg-elevated/30 px-4 py-4 sm:px-5"><h2 class="font-semibold text-highlighted">Desembolsos compatibles</h2><p class="text-sm text-muted">Seleccione un unico desembolso para crear la conciliacion.</p></div>
          <div v-if="candidates.length" class="grid gap-3 p-4 lg:grid-cols-2 sm:p-5">
            <button v-for="desembolso in candidates" :key="desembolso.id" class="rounded-xl border p-4 text-left transition" :class="selectedId === desembolso.id ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-default hover:bg-elevated/30'" type="button" :disabled="!allowed" @click="selectedId = desembolso.id">
              <div class="flex items-start justify-between gap-3"><div><p class="font-mono font-semibold text-primary">{{ desembolso.numero }}</p><p class="mt-1 font-medium text-highlighted">{{ desembolso.proveedor }}</p></div><UIcon :name="selectedId === desembolso.id ? 'i-lucide-circle-check' : 'i-lucide-circle'" class="size-5" :class="selectedId === desembolso.id ? 'text-primary' : 'text-muted'" /></div>
              <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-default pt-3 text-sm"><div><dt class="text-xs uppercase text-muted">Fecha</dt><dd class="mt-1">{{ desembolso.fechaDesembolso }}</dd></div><div><dt class="text-xs uppercase text-muted">Monto</dt><dd class="mt-1 font-mono font-semibold">${{ desembolso.monto }}</dd></div><div><dt class="text-xs uppercase text-muted">Forma</dt><dd class="mt-1">{{ paymentLabels[desembolso.formaPago] }}</dd></div><div><dt class="text-xs uppercase text-muted">Referencia</dt><dd class="mt-1 truncate">{{ desembolso.referencia || 'Sin referencia' }}</dd></div></dl>
            </button>
          </div>
          <div v-else class="p-8 text-center"><UIcon name="i-lucide-search-x" class="mx-auto size-8 text-muted" /><p class="mt-3 font-medium text-highlighted">No hay desembolsos compatibles</p><p class="mt-1 text-sm text-muted">No existe un desembolso disponible con monto exacto y forma de pago compatible.</p></div>
        </section>

        <UFormField label="Nota de conciliacion" hint="Opcional" :error="errors.nota"><UTextarea v-model="nota" class="w-full" :rows="3" maxlength="2000" placeholder="Contexto o verificacion realizada" /></UFormField>
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver al movimiento" variant="outline" :disabled="loading" @click="router.visit(route('movimientos-tesoreria.show', [movimiento.edificioId, movimiento.cuentaId, movimiento.id]))" /><UButton icon="i-lucide-link" label="Revisar conciliacion" :disabled="!selected || !allowed" @click="review" /></div>

        <UCard v-if="history.length"><template #header><div><p class="font-semibold text-highlighted">Historial anterior</p><p class="mt-1 text-xs text-muted">Las conciliaciones revertidas permanecen visibles.</p></div></template><div class="divide-y divide-default"><div v-for="conciliacion in history" :key="conciliacion.id" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between"><div><p class="font-mono font-semibold">{{ conciliacion.desembolso?.numero || 'Desembolso no disponible' }}</p><p v-if="conciliacion.desembolso" class="text-sm text-muted">{{ conciliacion.desembolso.proveedor }} - ${{ conciliacion.desembolso.monto }}</p><p v-if="conciliacion.motivoReversion" class="mt-1 text-sm text-warning">{{ conciliacion.motivoReversion }}</p></div><div class="sm:text-right"><UBadge :color="conciliacion.estado === 'vigente' ? 'success' : 'neutral'" :label="conciliacion.estado === 'vigente' ? 'Vigente' : 'Revertida'" variant="subtle" /><p class="mt-1 text-xs text-muted">{{ conciliacion.revertidoAt || conciliacion.conciliadoAt }}</p></div></div></div></UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="confirmOpen" :close="!loading" :dismissible="!loading" title="Confirmar conciliacion" description="Se creara una relacion vigente uno a uno. Para anular el movimiento o desembolso primero debera revertirla.">
    <template #body><div class="space-y-4"><UAlert v-if="errors.desembolsoId || errors.movimiento || errors.conciliacion" color="error" :description="errors.desembolsoId || errors.movimiento || errors.conciliacion" icon="i-lucide-circle-alert" variant="subtle" /><dl v-if="selected" class="grid gap-4 sm:grid-cols-2"><div><dt class="text-xs uppercase text-muted">Movimiento</dt><dd class="mt-1">{{ movimiento.referencia || movimiento.fechaMovimiento }}</dd></div><div><dt class="text-xs uppercase text-muted">Desembolso</dt><dd class="mt-1 font-mono">{{ selected.numero }}</dd></div><div><dt class="text-xs uppercase text-muted">Proveedor</dt><dd class="mt-1">{{ selected.proveedor }}</dd></div><div><dt class="text-xs uppercase text-muted">Monto</dt><dd class="mt-1 font-mono font-semibold">${{ selected.monto }}</dd></div></dl></div></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="confirmOpen = false" /><UButton label="Conciliar egreso" :loading="loading" :disabled="!selected || !allowed" @click="submit" /></div></template>
  </UModal>
</template>
