<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { DesembolsoPreview, FormaDesembolso, ProveedorOption } from '../../types'

const props = defineProps<{
  filters: { edificio_id?: string | null, proveedor_id?: string | null, fecha_desembolso?: string | null, monto?: string | null }
  preview: DesembolsoPreview | null
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
}>()

const today = (() => {
  const value = new Date()
  return [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-')
})()

const form = reactive({
  edificio_id: props.filters.edificio_id ?? (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  proveedor_id: props.filters.proveedor_id ?? '',
  fecha_desembolso: props.filters.fecha_desembolso ?? today,
  monto: props.filters.monto ?? '',
  forma_pago: 'efectivo' as FormaDesembolso,
  referencia: '',
  observacion: ''
})
const errors = ref<Record<string, string>>({})
const previewLoading = ref(false)
const submitting = ref(false)
const confirmOpen = ref(false)
const previewReady = ref(Boolean(props.preview))

const providers = computed(() => props.proveedores.filter(item => item.edificioId === form.edificio_id))
const providerItems = computed(() => providers.value.map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.id })))
const selectedProvider = computed(() => props.proveedores.find(item => item.id === form.proveedor_id && item.edificioId === form.edificio_id))
const requiresReference = computed(() => ['transferencia', 'deposito', 'cheque'].includes(form.forma_pago))
const canPreview = computed(() => Boolean(
  form.edificio_id
  && form.proveedor_id
  && form.fecha_desembolso
  && form.monto
  && Number.isFinite(Number(form.monto))
  && Number(form.monto) > 0
))
const previewMatchesForm = computed(() => Boolean(props.preview
  && props.filters.edificio_id === form.edificio_id
  && props.filters.proveedor_id === form.proveedor_id
  && props.filters.fecha_desembolso === form.fecha_desembolso
  && props.filters.monto === form.monto))
const currentPreview = computed(() => previewReady.value && previewMatchesForm.value ? props.preview : null)
const canConfirm = computed(() => Boolean(currentPreview.value))

watch(() => [form.edificio_id, form.proveedor_id, form.fecha_desembolso, form.monto], () => {
  previewReady.value = false
  confirmOpen.value = false
})

const changeBuilding = () => {
  form.proveedor_id = ''
}

const previewDisbursement = () => {
  if (!canPreview.value) return
  errors.value = {}
  router.get(route('desembolsos.create'), {
    edificio_id: form.edificio_id,
    proveedor_id: form.proveedor_id,
    fecha_desembolso: form.fecha_desembolso,
    monto: form.monto
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onStart: () => { previewLoading.value = true },
    onSuccess: () => { previewReady.value = previewMatchesForm.value },
    onError: responseErrors => {
      errors.value = responseErrors
      previewReady.value = false
    },
    onFinish: () => { previewLoading.value = false }
  })
}

const continueToConfirmation = () => {
  if (requiresReference.value && !form.referencia.trim()) {
    errors.value = { ...errors.value, referencia: 'La referencia es obligatoria para esta forma de pago.' }
    return
  }

  const nextErrors = { ...errors.value }
  delete nextErrors.referencia
  errors.value = nextErrors

  if (!previewReady.value) {
    previewDisbursement()
    return
  }
  if (canConfirm.value) confirmOpen.value = true
}

const submit = () => {
  if (!form.edificio_id || !canConfirm.value) return
  router.post(route('desembolsos.store', form.edificio_id), {
    ...form,
    aplicacion_fingerprint: currentPreview.value?.aplicacionFingerprint
  }, {
    onStart: () => { submitting.value = true },
    onError: responseErrors => {
      errors.value = responseErrors
      confirmOpen.value = false
    },
    onFinish: () => { submitting.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="desembolsos-create">
    <template #header><UDashboardNavbar title="Registrar desembolso"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-5xl p-4 sm:p-6">
        <form class="space-y-6" @submit.prevent="continueToConfirmation">
          <UAlert v-if="errors.aplicacionFingerprint" color="warning" :description="errors.aplicacionFingerprint" icon="i-lucide-refresh-cw" variant="subtle" />
          <UAlert v-if="errors.cuentas || errors.default" color="error" :description="errors.cuentas || errors.default" icon="i-lucide-circle-alert" variant="subtle" />
          <UAlert
            color="info"
            description="El monto se aplicará automáticamente a las cuentas del proveedor con vencimiento más antiguo. Se admiten pagos parciales; los sobrepagos se rechazan y no se conserva crédito a favor del proveedor."
            icon="i-lucide-info"
            variant="subtle"
          />

          <section class="overflow-hidden rounded-xl border border-default">
            <div class="border-b border-default bg-elevated/30 px-4 py-3 sm:px-5">
              <h2 class="font-semibold text-highlighted">Destino y aplicación</h2>
              <p class="text-sm text-muted">Estos datos determinan qué cuentas se liquidarán.</p>
            </div>
            <div class="grid gap-4 p-4 md:grid-cols-2 sm:p-5">
              <UFormField label="Edificio" required :error="errors.edificioId">
                <USelect
                  v-model="form.edificio_id"
                  :items="edificios.map(item => ({ label: item.nombre, value: item.id }))"
                  placeholder="Seleccione edificio"
                  required
                  size="xl"
                  @update:model-value="changeBuilding"
                />
              </UFormField>
              <UFormField label="Proveedor" required :error="errors.proveedorId">
                <USelect v-model="form.proveedor_id" :items="providerItems" placeholder="Seleccione proveedor" required size="xl" />
              </UFormField>
              <UFormField label="Fecha del desembolso" required :error="errors.fechaDesembolso">
                <UInput v-model="form.fecha_desembolso" type="date" required size="xl" />
              </UFormField>
              <UFormField label="Monto" required :error="errors.monto">
                <UInput v-model="form.monto" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?" placeholder="0.0000" required size="xl" />
              </UFormField>
            </div>
          </section>

          <section class="overflow-hidden rounded-xl border border-default">
            <div class="border-b border-default bg-elevated/30 px-4 py-3 sm:px-5">
              <h2 class="font-semibold text-highlighted">Medio de pago</h2>
              <p class="text-sm text-muted">Información operativa del desembolso.</p>
            </div>
            <div class="grid gap-4 p-4 md:grid-cols-2 sm:p-5">
              <UFormField label="Forma de pago" required :error="errors.formaPago">
                <USelect
                  v-model="form.forma_pago"
                  :items="[{ label: 'Efectivo', value: 'efectivo' }, { label: 'Transferencia', value: 'transferencia' }, { label: 'Depósito', value: 'deposito' }, { label: 'Tarjeta', value: 'tarjeta' }, { label: 'Cheque', value: 'cheque' }, { label: 'Otro', value: 'otro' }]"
                  required
                  size="xl"
                />
              </UFormField>
              <UFormField label="Referencia" :hint="requiresReference ? 'Obligatoria para esta forma de pago' : 'Opcional'" :error="errors.referencia" :required="requiresReference">
                <UInput v-model="form.referencia" maxlength="120" :required="requiresReference" size="xl" />
              </UFormField>
              <UFormField class="md:col-span-2" label="Observación" hint="Opcional" :error="errors.observacion">
                <UTextarea v-model="form.observacion" :rows="3" maxlength="2000" />
              </UFormField>
            </div>
          </section>

          <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <UButton color="neutral" label="Ver aplicación" type="button" variant="outline" :disabled="!canPreview || previewLoading" :loading="previewLoading" @click="previewDisbursement" />
            <UButton :label="previewReady ? 'Continuar' : 'Ver aplicación y continuar'" type="submit" :disabled="!canPreview || previewLoading || (previewReady && !canConfirm)" />
          </div>
        </form>

        <UAlert
          v-if="preview && !previewReady"
          class="mt-6"
          color="warning"
          description="Los datos de aplicación cambiaron. Actualice la previsualización antes de confirmar."
          icon="i-lucide-refresh-cw"
          variant="subtle"
        />

        <section v-if="currentPreview" class="mt-8 space-y-4">
          <div class="rounded-xl border border-default">
            <div class="flex flex-col gap-2 border-b border-default bg-elevated/30 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
              <div>
                <h2 class="font-semibold text-highlighted">Aplicación por antigüedad</h2>
                <p class="text-sm text-muted">{{ currentPreview.proveedor.nombre }} · {{ currentPreview.proveedor.identificacion }}</p>
              </div>
              <UBadge color="info" :label="`${currentPreview.cuentas.length} cuenta(s)`" variant="subtle" />
            </div>
            <dl class="grid grid-cols-2 gap-px bg-default sm:grid-cols-4">
              <div class="bg-default p-4 sm:p-5"><dt class="text-xs uppercase text-muted">Saldo pendiente</dt><dd class="mt-1 font-mono text-lg font-semibold text-highlighted">${{ currentPreview.saldoPendiente }}</dd></div>
              <div class="bg-default p-4 sm:p-5"><dt class="text-xs uppercase text-muted">Desembolso</dt><dd class="mt-1 font-mono text-lg font-semibold text-highlighted">${{ currentPreview.monto }}</dd></div>
              <div class="bg-default p-4 sm:p-5"><dt class="text-xs uppercase text-muted">Monto aplicado</dt><dd class="mt-1 font-mono text-lg font-semibold text-primary">${{ currentPreview.montoAplicado }}</dd></div>
              <div class="bg-default p-4 sm:p-5"><dt class="text-xs uppercase text-muted">Saldo posterior</dt><dd class="mt-1 font-mono text-lg font-semibold text-highlighted">${{ currentPreview.saldoPosterior }}</dd></div>
            </dl>
          </div>

          <div class="overflow-hidden rounded-xl border border-default">
            <div
              v-for="cuenta in currentPreview.cuentas"
              :key="cuenta.cuentaId"
              class="grid gap-3 border-b border-default p-4 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-5"
            >
              <div class="min-w-0">
                <p class="font-mono text-sm font-semibold text-primary">{{ cuenta.numeroGasto }}</p>
                <p class="truncate text-highlighted">{{ cuenta.concepto }}</p>
                <p class="text-xs text-muted">Vence {{ cuenta.fechaVencimiento }} · saldo anterior ${{ cuenta.saldoAnterior }}</p>
              </div>
              <div class="flex items-end justify-between gap-5 sm:block sm:text-right">
                <div><p class="text-xs uppercase text-muted">Aplicado</p><p class="font-mono font-semibold">${{ cuenta.montoAplicado }}</p></div>
                <p class="text-xs text-muted">Saldo: ${{ cuenta.saldoPosterior }}</p>
              </div>
            </div>
            <p v-if="!currentPreview.cuentas.length" class="p-6 text-center text-muted">El proveedor no tiene cuentas pendientes para aplicar este desembolso.</p>
          </div>
        </section>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="confirmOpen" :close="!submitting" :dismissible="!submitting" title="Confirmar desembolso" description="El desembolso se registrará y aplicará inmediatamente; después sólo podrá anularse.">
    <template #body>
      <div class="space-y-4">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div><dt class="text-xs uppercase text-muted">Proveedor</dt><dd class="mt-1 font-medium">{{ selectedProvider?.nombre }}</dd></div>
          <div><dt class="text-xs uppercase text-muted">Monto</dt><dd class="mt-1 font-mono font-semibold">${{ form.monto }}</dd></div>
          <div><dt class="text-xs uppercase text-muted">Cuentas afectadas</dt><dd class="mt-1">{{ currentPreview?.cuentas.length ?? 0 }}</dd></div>
          <div><dt class="text-xs uppercase text-muted">Saldo posterior</dt><dd class="mt-1 font-mono">${{ currentPreview?.saldoPosterior }}</dd></div>
        </dl>
        <UAlert color="warning" description="No se conservará ningún excedente como crédito del proveedor." icon="i-lucide-triangle-alert" variant="subtle" />
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton color="neutral" label="Volver" variant="outline" :disabled="submitting" @click="confirmOpen = false" />
        <UButton label="Registrar desembolso" :loading="submitting" :disabled="!canConfirm" @click="submit" />
      </div>
    </template>
  </UModal>
</template>
