<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Pago } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ pago: Pago }>()
const { can } = useBuildingPermissions()
const cancelOpen = ref(false)
const motivo = ref('')
const loading = ref(false)
const error = ref('')

const cancel = () => router.patch(route('pagos.cancel', [props.pago.edificioId, props.pago.id]), { motivo: motivo.value }, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? 'No fue posible anular el pago.') },
  onFinish: () => { loading.value = false }
})

const applyCredit = () => router.post(route('pagos.apply-credit', [props.pago.edificioId, props.pago.id]), {}, { preserveScroll: true })
</script>

<template>
  <UDashboardPanel id="pagos-show">
    <template #header>
      <UDashboardNavbar :title="pago.numero">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
            <div class="flex gap-2">
              <UButton v-if="can('comprobantes.ver', pago.edificioId) && pago.recibo" icon="i-lucide-receipt-text" label="Ver recibo" variant="outline" @click="router.visit(route('recibos.show', [pago.edificioId, pago.id]))" />
              <UButton v-if="can('pagos.aplicar_saldo', pago.edificioId) && pago.estado === 'registrado' && pago.saldoFavor !== '0.0000'" icon="i-lucide-scan-line" label="Aplicar saldo a favor" variant="outline" @click="applyCredit" />
            <UButton v-if="can('pagos.anular', pago.edificioId) && pago.estado === 'registrado'" color="error" icon="i-lucide-ban" label="Anular" variant="outline" @click="cancelOpen = true" />
          </div>
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UCard>
          <template #header>
            <div class="flex items-start justify-between">
              <div>
                <p class="text-sm text-muted">Pago {{ pago.formaPago }}</p>
                <h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ pago.numero }}</h1>
              </div>
              <UBadge :color="pago.estado === 'registrado' ? 'success' : 'neutral'" :label="pago.estado" variant="subtle" />
            </div>
          </template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Departamento</dt><dd class="mt-1">{{ pago.departamento }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Propietario</dt><dd class="mt-1">{{ pago.propietario || 'Sin titular único' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Fecha</dt><dd class="mt-1">{{ pago.fechaPago }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Recibido</dt><dd class="mt-1 font-mono">${{ pago.valorRecibido }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Aplicado</dt><dd class="mt-1 font-mono">${{ pago.valorAplicado }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Saldo a favor</dt><dd class="mt-1 font-mono">${{ pago.saldoFavor }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Referencia</dt><dd class="mt-1">{{ pago.referencia || '—' }}</dd></div>
             <div><dt class="text-xs uppercase text-muted">Registrado por</dt><dd class="mt-1">{{ pago.registradoPor || 'Usuario eliminado' }}</dd></div>
             <div><dt class="text-xs uppercase text-muted">Recibo</dt><dd class="mt-1">{{ pago.recibo?.numero || 'No disponible para pagos históricos' }}</dd></div>
          </dl>
          <UAlert v-if="pago.estado === 'anulado'" class="mt-5" color="neutral" :description="pago.motivoAnulacion || 'Sin motivo registrado.'" icon="i-lucide-ban" variant="subtle" />
        </UCard>
        <UCard>
          <template #header><p class="font-semibold text-highlighted">Aplicaciones</p></template>
          <div class="divide-y divide-default">
            <button v-for="aplicacion in pago.aplicaciones" :key="aplicacion.id" class="flex w-full items-center justify-between gap-4 py-3 text-left hover:bg-elevated/30" type="button" @click="router.visit(route('cargos.show', [pago.edificioId, aplicacion.cargoId]))">
              <div><p>{{ aplicacion.concepto }}</p><p class="text-xs text-muted">Período {{ aplicacion.periodo }}</p></div>
              <span class="font-mono">${{ aplicacion.valorAplicado }}</span>
            </button>
            <p v-if="!pago.aplicaciones?.length" class="py-3 text-muted">Este pago conserva todo su valor como saldo a favor.</p>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Anular pago" description="La anulación restaura los cargos y conserva el historial financiero.">
    <template #body>
      <UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" />
      <UFormField label="Motivo" required><UTextarea v-model="motivo" :rows="3" required /></UFormField>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="cancelOpen = false" />
        <UButton color="error" label="Anular pago" :disabled="!motivo" :loading="loading" @click="cancel" />
      </div>
    </template>
  </UModal>
</template>
