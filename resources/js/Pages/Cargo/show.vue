<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Cargo } from '../../types'

const props = defineProps<{ cargo: Cargo }>()
const cancelOpen = ref(false)
const motivo = ref('')
const loading = ref(false)
const error = ref('')
const snapshot = computed(() => (props.cargo.snapshot ?? {}) as { concepto?: { formaCalculo?: string }, tipoCalculo?: string })
const cancel = () => router.patch(route('cargos.cancel', [props.cargo.edificioId, props.cargo.id]), { motivo: motivo.value }, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? 'No fue posible anular el cargo.') },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="cargos-show">
    <template #header>
      <UDashboardNavbar :title="`${cargo.departamento} · ${cargo.concepto}`">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><div class="flex gap-2"><UButton v-if="cargo.estado !== 'anulado' && cargo.saldo !== '0.0000'" icon="i-lucide-hand-coins" label="Registrar pago" variant="outline" @click="router.visit(route('pagos.create', { edificio_id: cargo.edificioId, departamento_id: cargo.departamentoId }))" /><UButton v-if="cargo.estado === 'pendiente'" color="error" icon="i-lucide-ban" label="Anular" variant="outline" @click="cancelOpen = true" /></div></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UCard>
          <template #header><div class="flex items-start justify-between"><div><p class="text-sm text-muted">Cargo {{ cargo.origen }}</p><h1 class="mt-1 text-xl font-semibold text-highlighted">{{ cargo.descripcion }}</h1></div><UBadge :color="cargo.estado === 'pendiente' ? 'warning' : cargo.estado === 'anulado' ? 'neutral' : 'success'" :label="cargo.estado" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Período</dt><dd class="mt-1 font-mono">{{ cargo.periodo }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Valor original</dt><dd class="mt-1 font-mono">${{ cargo.valorOriginal }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Saldo</dt><dd class="mt-1 font-mono">${{ cargo.saldo }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Emisión</dt><dd class="mt-1">{{ cargo.fechaEmision }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Vencimiento</dt><dd class="mt-1">{{ cargo.fechaVencimiento }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Lote</dt><dd class="mt-1 font-mono text-sm">{{ cargo.loteId || 'Manual' }}</dd></div>
          </dl>
        </UCard>
        <UCard v-if="cargo.aplicacionesPago && cargo.aplicacionesPago.length">
          <template #header><p class="font-semibold text-highlighted">Pagos aplicados</p></template>
          <div class="divide-y divide-default">
            <button v-for="aplicacion in cargo.aplicacionesPago" :key="`${aplicacion.pagoId}-${aplicacion.valorAplicado}`" class="flex w-full items-center justify-between gap-4 py-3 text-left hover:bg-elevated/30" type="button" @click="router.visit(route('pagos.show', [cargo.edificioId, aplicacion.pagoId]))"><div><p class="font-mono text-sm text-primary">{{ aplicacion.numeroPago }}</p><p class="text-xs text-muted">{{ aplicacion.fechaPago }} · {{ aplicacion.estadoPago }}</p></div><span class="font-mono">${{ aplicacion.valorAplicado }}</span></button>
          </div>
        </UCard>
        <UCard>
          <template #header><p class="font-semibold text-highlighted">Trazabilidad</p></template>
          <dl class="grid gap-5 sm:grid-cols-2">
            <div><dt class="text-xs uppercase text-muted">Concepto</dt><dd class="mt-1">{{ cargo.codigoConcepto }} · {{ cargo.concepto }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Tarifa utilizada</dt><dd class="mt-1 font-mono">{{ cargo.tarifaId || 'Cargo manual' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Método de cálculo</dt><dd class="mt-1">{{ snapshot.concepto?.formaCalculo ?? snapshot.tipoCalculo ?? 'Manual' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Referencia</dt><dd class="mt-1 font-mono text-xs">{{ cargo.referenciaGeneracion || '—' }}</dd></div>
          </dl>
          <UAlert v-if="cargo.estado === 'anulado'" class="mt-5" color="neutral" :description="cargo.motivoAnulacion || 'Sin motivo registrado.'" icon="i-lucide-ban" variant="subtle" />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Anular cargo" description="La anulación conserva el cargo y su trazabilidad financiera.">
    <template #body><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo" required><UTextarea v-model="motivo" :rows="3" required /></UFormField></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="cancelOpen = false" /><UButton color="error" label="Anular cargo" :disabled="!motivo" :loading="loading" @click="cancel" /></div></template>
  </UModal>
</template>
