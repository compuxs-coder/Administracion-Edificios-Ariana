<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { AplicacionDesembolso, Desembolso, FormaDesembolso } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ desembolso: Desembolso, aplicaciones?: AplicacionDesembolso[] }>()
const { can } = useBuildingPermissions()
const cancelOpen = ref(false)
const motivo = ref('')
const loading = ref(false)
const error = ref('')
const applications = computed(() => props.aplicaciones ?? props.desembolso.aplicaciones ?? [])

const paymentLabels: Record<FormaDesembolso, string> = {
  efectivo: 'Efectivo',
  transferencia: 'Transferencia',
  deposito: 'Depósito',
  tarjeta: 'Tarjeta',
  cheque: 'Cheque',
  otro: 'Otro'
}

const openCancel = () => {
  error.value = ''
  motivo.value = ''
  cancelOpen.value = true
}

const cancel = () => router.patch(route('desembolsos.cancel', [props.desembolso.edificioId, props.desembolso.id]), { motivo: motivo.value }, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? errors.desembolso ?? 'No fue posible anular el desembolso.') },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="desembolsos-show">
    <template #header>
      <UDashboardNavbar :title="`Desembolso ${desembolso.numero}`">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton
            v-if="can('desembolsos.anular', desembolso.edificioId) && desembolso.estado === 'registrado'"
            color="error"
            icon="i-lucide-ban"
            label="Anular"
            variant="outline"
            @click="openCancel"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <section class="overflow-hidden rounded-xl border border-default">
          <div class="flex items-start justify-between gap-4 border-b border-default bg-elevated/30 p-4 sm:p-5">
            <div>
              <p class="text-sm text-muted">{{ desembolso.edificio }}</p>
              <h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ desembolso.numero }}</h1>
            </div>
            <UBadge :color="desembolso.estado === 'registrado' ? 'success' : 'neutral'" :label="desembolso.estado" variant="subtle" />
          </div>
          <div class="grid gap-5 p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-3">
            <div><p class="text-xs uppercase text-muted">Proveedor</p><p class="mt-1 font-medium text-highlighted">{{ desembolso.proveedor }}</p><p class="font-mono text-xs text-muted">{{ desembolso.proveedorIdentificacion }}</p></div>
            <div><p class="text-xs uppercase text-muted">Fecha</p><p class="mt-1">{{ desembolso.fechaDesembolso }}</p></div>
            <div><p class="text-xs uppercase text-muted">Forma de pago</p><p class="mt-1">{{ paymentLabels[desembolso.formaPago] }}</p></div>
            <div><p class="text-xs uppercase text-muted">Referencia</p><p class="mt-1">{{ desembolso.referencia || 'Sin referencia' }}</p></div>
            <div><p class="text-xs uppercase text-muted">Registrado por</p><p class="mt-1">{{ desembolso.registradoPor || 'Usuario eliminado' }}</p></div>
            <div><p class="text-xs uppercase text-muted">Registrado</p><p class="mt-1">{{ desembolso.createdAt || 'No disponible' }}</p></div>
          </div>
          <div class="flex flex-col gap-2 border-t border-default bg-elevated/15 px-4 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-5">
            <div><p class="text-xs uppercase text-muted">Monto desembolsado</p><p class="mt-1 font-mono text-3xl font-semibold text-highlighted">${{ desembolso.monto }}</p></div>
            <p class="text-sm text-muted">Aplicado a {{ desembolso.cantidadCuentas }} cuenta(s)</p>
          </div>
        </section>

        <UAlert
          v-if="desembolso.estado === 'anulado'"
          color="neutral"
          :description="desembolso.motivoAnulacion || 'Sin motivo registrado.'"
          :title="desembolso.anuladoAt ? `Anulado el ${desembolso.anuladoAt}` : 'Desembolso anulado'"
          icon="i-lucide-ban"
          variant="subtle"
        />

        <section class="overflow-hidden rounded-xl border border-default">
          <div class="border-b border-default bg-elevated/30 px-4 py-3 sm:px-5">
            <h2 class="font-semibold text-highlighted">Aplicaciones a cuentas por pagar</h2>
            <p class="text-sm text-muted">Distribución realizada por fecha de vencimiento.</p>
          </div>
          <div class="divide-y divide-default md:hidden">
            <button
              v-for="aplicacion in applications"
              :key="aplicacion.id"
              class="block w-full p-4 text-left hover:bg-elevated/30"
              type="button"
              @click="router.visit(route('cuentas-por-pagar.show', [desembolso.edificioId, aplicacion.cuentaId]))"
            >
              <div class="flex items-start justify-between gap-3"><div><p class="font-mono font-semibold text-primary">{{ aplicacion.numeroGasto }}</p><p class="mt-1 text-sm text-highlighted">{{ aplicacion.concepto }}</p></div><p class="font-mono font-semibold">${{ aplicacion.montoAplicado }}</p></div>
              <p class="mt-2 text-xs text-muted">Vencimiento {{ aplicacion.fechaVencimiento }}</p>
            </button>
          </div>
          <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-3xl text-left text-sm">
              <thead class="text-xs uppercase tracking-wide text-muted"><tr><th class="p-3 sm:px-5">Gasto</th><th class="p-3">Concepto</th><th class="p-3">Vencimiento</th><th class="p-3 text-right">Aplicado</th><th class="p-3" /></tr></thead>
              <tbody class="divide-y divide-default">
                <tr v-for="aplicacion in applications" :key="aplicacion.id" class="hover:bg-elevated/30">
                  <td class="p-3 sm:pl-5"><p class="font-mono font-semibold text-primary">{{ aplicacion.numeroGasto }}</p></td>
                  <td class="max-w-sm p-3">{{ aplicacion.concepto }}</td>
                  <td class="p-3">{{ aplicacion.fechaVencimiento }}</td>
                  <td class="p-3 text-right font-mono font-semibold">${{ aplicacion.montoAplicado }}</td>
                  <td class="p-3 text-right"><UButton color="neutral" icon="i-lucide-arrow-up-right" variant="ghost" aria-label="Ver cuenta por pagar" @click="router.visit(route('cuentas-por-pagar.show', [desembolso.edificioId, aplicacion.cuentaId]))" /></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!applications.length" class="p-8 text-center text-muted">No hay aplicaciones asociadas a este desembolso.</p>
        </section>

        <section v-if="desembolso.observacion" class="rounded-xl border border-default p-4 sm:p-5">
          <h2 class="font-semibold text-highlighted">Observación</h2>
          <p class="mt-3 whitespace-pre-line text-sm text-muted">{{ desembolso.observacion }}</p>
        </section>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Anular desembolso" description="La anulación restaurará los saldos de las cuentas aplicadas y conservará la trazabilidad.">
    <template #body>
      <div class="space-y-4">
        <UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" />
        <UFormField label="Motivo" required><UTextarea v-model="motivo" :rows="3" required /></UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="cancelOpen = false" />
        <UButton color="error" label="Anular desembolso" :disabled="!motivo.trim()" :loading="loading" @click="cancel" />
      </div>
    </template>
  </UModal>
</template>
