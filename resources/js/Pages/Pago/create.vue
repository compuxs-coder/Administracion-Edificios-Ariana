<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { FormaPago, PagoPreview } from '../../types'

const props = defineProps<{
  filters: { edificio_id?: string | null, departamento_id?: string | null, fecha_pago?: string | null, valor_recibido?: string | null }
  preview: PagoPreview | null
  edificios: Array<{ id: string, nombre: string }>
  departamentos: Array<{ id: string, edificioId: string, codigo: string, nombre: string }>
}>()

const today = (() => {
  const value = new Date()
  return [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-')
})()
const form = reactive({
  edificio_id: props.filters.edificio_id ?? (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  departamento_id: props.filters.departamento_id ?? '',
  fecha_pago: props.filters.fecha_pago ?? today,
  valor_recibido: props.filters.valor_recibido ?? '',
  forma_pago: 'efectivo' as FormaPago,
  referencia: '',
  observacion: ''
})
const errors = ref<Record<string, string>>({})
const loading = ref(false)
const confirmOpen = ref(false)
const previewReady = ref(Boolean(props.preview))
const departments = computed(() => props.departamentos
  .filter(item => item.edificioId === form.edificio_id)
  .map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id })))
const canPreview = computed(() => Boolean(form.edificio_id && form.departamento_id && form.fecha_pago && form.valor_recibido))

watch(() => [form.edificio_id, form.departamento_id, form.fecha_pago, form.valor_recibido], () => { previewReady.value = false })

const previewPayment = () => {
  if (!canPreview.value) return
  router.get(route('pagos.create'), {
    edificio_id: form.edificio_id,
    departamento_id: form.departamento_id,
    fecha_pago: form.fecha_pago,
    valor_recibido: form.valor_recibido
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: () => { previewReady.value = true }
  })
}

const continueToConfirmation = () => {
  if (!previewReady.value) {
    previewPayment()
    return
  }
  confirmOpen.value = true
}

const submit = () => {
  if (!form.edificio_id) return
  router.post(route('pagos.store', form.edificio_id), form, {
    onStart: () => { loading.value = true },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="pagos-create">
    <template #header><UDashboardNavbar title="Registrar pago"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <form class="space-y-6" @submit.prevent="continueToConfirmation">
          <UAlert color="info" description="El sistema aplicará el pago a los cargos más antiguos. El saldo restante se conserva como saldo a favor." icon="i-lucide-info" variant="subtle" />
          <div class="grid gap-4 md:grid-cols-2">
            <UFormField label="Edificio" required :error="errors.edificioId">
              <USelect v-model="form.edificio_id" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" required size="xl" @update:model-value="form.departamento_id = ''" />
            </UFormField>
            <UFormField label="Departamento" required :error="errors.departamentoId">
              <USelect v-model="form.departamento_id" :items="departments" placeholder="Seleccione departamento" required size="xl" />
            </UFormField>
            <UFormField label="Fecha" required :error="errors.fechaPago"><UInput v-model="form.fecha_pago" type="date" required size="xl" /></UFormField>
            <UFormField label="Valor recibido" required :error="errors.valorRecibido"><UInput v-model="form.valor_recibido" inputmode="decimal" placeholder="0.0000" required size="xl" /></UFormField>
            <UFormField label="Forma de pago" required :error="errors.formaPago">
              <USelect v-model="form.forma_pago" :items="[{ label: 'Efectivo', value: 'efectivo' }, { label: 'Transferencia', value: 'transferencia' }, { label: 'Depósito', value: 'deposito' }, { label: 'Tarjeta', value: 'tarjeta' }, { label: 'Cheque', value: 'cheque' }, { label: 'Otro', value: 'otro' }]" required size="xl" />
            </UFormField>
            <UFormField label="Referencia" :hint="['transferencia', 'deposito', 'cheque'].includes(form.forma_pago) ? 'Obligatoria para esta forma de pago' : 'Opcional'" :error="errors.referencia"><UInput v-model="form.referencia" maxlength="120" size="xl" /></UFormField>
          </div>
          <UFormField label="Observación" hint="Opcional" :error="errors.observacion"><UTextarea v-model="form.observacion" :rows="3" /></UFormField>
          <div class="flex flex-wrap justify-end gap-3">
            <UButton color="neutral" label="Ver aplicación" variant="outline" :disabled="!canPreview" @click="previewPayment" />
            <UButton :label="previewReady ? 'Continuar' : 'Ver aplicación y continuar'" :disabled="!canPreview" type="submit" />
          </div>
        </form>
        <section v-if="preview" class="mt-8 space-y-4">
          <UCard>
            <template #header><div><p class="font-semibold text-highlighted">Previsualización</p><p class="text-sm text-muted">{{ preview.departamento.codigo }} · {{ preview.departamento.nombre }}</p></div></template>
            <div class="grid gap-4 sm:grid-cols-3">
              <div><p class="text-xs uppercase text-muted">Titular(es)</p><p class="mt-1">{{ preview.propietarios.map(item => item.nombre).join(', ') || 'Sin titular vigente' }}</p></div>
              <div><p class="text-xs uppercase text-muted">Saldo pendiente</p><p class="mt-1 font-mono">${{ preview.saldoPendiente }}</p></div>
              <div><p class="text-xs uppercase text-muted">Saldo a favor nuevo</p><p class="mt-1 font-mono">${{ preview.saldoFavorNuevo }}</p></div>
            </div>
          </UCard>
          <UCard>
            <template #header><p class="font-semibold text-highlighted">Aplicación por antigüedad</p></template>
            <div class="divide-y divide-default">
              <div v-for="cargo in preview.cargos" :key="cargo.cargoId" class="flex items-center justify-between gap-4 py-3">
                <div><p>{{ cargo.concepto }} · {{ cargo.periodo }}</p><p class="text-xs text-muted">Vence {{ cargo.fechaVencimiento }} · saldo posterior ${{ cargo.saldoPosterior }}</p></div>
                <span class="font-mono">${{ cargo.valorAplicado }}</span>
              </div>
              <p v-if="!preview.cargos.length" class="py-3 text-muted">No hay cargos pendientes: el valor quedará como saldo a favor.</p>
            </div>
          </UCard>
        </section>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="confirmOpen" title="Confirmar pago" description="La aplicación se ejecutará en una única transacción y no podrá editarse.">
    <template #body><dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-xs uppercase text-muted">Valor recibido</dt><dd class="font-mono">${{ form.valor_recibido }}</dd></div><div><dt class="text-xs uppercase text-muted">Forma</dt><dd class="capitalize">{{ form.forma_pago }}</dd></div></dl></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="confirmOpen = false" /><UButton label="Registrar pago" :loading="loading" @click="submit" /></div></template>
  </UModal>
</template>
