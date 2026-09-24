<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CuentaTesoreriaOption, MovimientoTesoreriaFormData, NaturalezaMovimientoTesoreria } from '../../types'

const props = defineProps<{
  edificios: Array<{ id: string, nombre: string }>
  cuentas: CuentaTesoreriaOption[]
  edificioSeleccionado?: string | null
  cuentaSeleccionada?: string | null
}>()
const page = usePage()
const query = new URL(page.url, 'http://localhost').searchParams
const requestedBuilding = props.edificioSeleccionado ?? query.get('edificio_id') ?? ''
const requestedAccount = props.cuentaSeleccionada ?? query.get('cuenta_id') ?? ''
const initialBuilding = props.edificios.some(item => item.id === requestedBuilding) ? requestedBuilding : (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : '')
const initialAccount = props.cuentas.some(item => item.id === requestedAccount && item.edificioId === initialBuilding && item.estado !== 'inactiva') ? requestedAccount : ''
const today = (() => {
  const value = new Date()
  return [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-')
})()

const form = reactive<MovimientoTesoreriaFormData>({
  edificio_id: initialBuilding,
  cuenta_id: initialAccount,
  fecha_movimiento: today,
  naturaleza: 'ingreso',
  monto: '',
  referencia: '',
  descripcion: ''
})
const errors = ref<Record<string, string>>({})
const loading = ref(false)
const confirmOpen = ref(false)
const accountItems = computed(() => props.cuentas
  .filter(item => item.edificioId === form.edificio_id && item.estado !== 'inactiva')
  .map(item => ({ label: `${item.codigo} - ${item.nombre} (${item.tipo === 'bancaria' ? 'Bancaria' : 'Caja'})`, value: item.id })))
const selectedAccount = computed(() => props.cuentas.find(item => item.id === form.cuenta_id && item.edificioId === form.edificio_id))
const canContinue = computed(() => Boolean(form.edificio_id && form.cuenta_id && form.fecha_movimiento && form.descripcion.trim() && Number(form.monto) > 0))
const natureItems: Array<{ label: string, value: NaturalezaMovimientoTesoreria }> = [{ label: 'Ingreso', value: 'ingreso' }, { label: 'Egreso', value: 'egreso' }]

const changeBuilding = () => { form.cuenta_id = '' }
const review = () => {
  errors.value = {}
  if (canContinue.value) confirmOpen.value = true
}
const submit = () => {
  if (!canContinue.value) return
  router.post(route('movimientos-tesoreria.store', [form.edificio_id, form.cuenta_id]), form, {
    onStart: () => { loading.value = true },
    onError: responseErrors => { errors.value = responseErrors; confirmOpen.value = false },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="movimientos-tesoreria-create">
    <template #header><UDashboardNavbar title="Registrar movimiento"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <form class="space-y-6" @submit.prevent="review">
          <UAlert v-if="errors.default || errors.cuenta" color="error" :description="errors.default || errors.cuenta" icon="i-lucide-circle-alert" variant="subtle" />
          <UAlert color="info" description="El movimiento se registra manualmente y sus datos financieros no podran editarse. Una correccion posterior requiere anularlo." icon="i-lucide-info" variant="subtle" />
          <section class="overflow-hidden rounded-xl border border-default">
            <div class="border-b border-default bg-elevated/30 px-4 py-3 sm:px-5"><h2 class="font-semibold text-highlighted">Cuenta y naturaleza</h2><p class="text-sm text-muted">Seleccione una cuenta activa del edificio.</p></div>
            <div class="grid gap-4 p-4 md:grid-cols-2 sm:p-5">
              <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId"><USelect v-model="form.edificio_id" class="w-full" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" @update:model-value="changeBuilding" /></UFormField>
              <UFormField label="Cuenta" name="cuenta_id" required :error="errors.cuentaId"><USelect v-model="form.cuenta_id" class="w-full" :disabled="!form.edificio_id" :items="accountItems" placeholder="Seleccione cuenta" required size="xl" /></UFormField>
              <UFormField label="Naturaleza" name="naturaleza" required :error="errors.naturaleza"><USelect v-model="form.naturaleza" class="w-full" :items="natureItems" required size="xl" /></UFormField>
              <UFormField label="Fecha del movimiento" name="fecha_movimiento" required :error="errors.fechaMovimiento"><UInput v-model="form.fecha_movimiento" class="w-full" type="date" required size="xl" /></UFormField>
            </div>
          </section>
          <section class="overflow-hidden rounded-xl border border-default">
            <div class="border-b border-default bg-elevated/30 px-4 py-3 sm:px-5"><h2 class="font-semibold text-highlighted">Detalle</h2><p class="text-sm text-muted">Registre el valor positivo y la referencia operativa.</p></div>
            <div class="grid gap-4 p-4 md:grid-cols-2 sm:p-5">
              <UFormField label="Monto" name="monto" required :error="errors.monto"><UInput v-model="form.monto" class="w-full" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?" placeholder="0.0000" required size="xl" /></UFormField>
              <UFormField label="Referencia" name="referencia" hint="Opcional" :error="errors.referencia"><UInput v-model="form.referencia" class="w-full" maxlength="120" size="xl" /></UFormField>
              <UFormField class="md:col-span-2" label="Descripcion" name="descripcion" required :error="errors.descripcion"><UTextarea v-model="form.descripcion" class="w-full" :rows="3" maxlength="255" required /></UFormField>
            </div>
          </section>
          <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="router.visit(route('movimientos-tesoreria.index'))" /><UButton icon="i-lucide-arrow-right" label="Revisar movimiento" trailing type="submit" :disabled="!canContinue" /></div>
        </form>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="confirmOpen" :close="!loading" :dismissible="!loading" title="Confirmar movimiento" description="Los datos financieros quedaran inmutables despues del registro.">
    <template #body><div class="space-y-4"><UAlert v-if="errors.default || errors.cuenta" color="error" :description="errors.default || errors.cuenta" icon="i-lucide-circle-alert" variant="subtle" /><dl class="grid gap-4 sm:grid-cols-2"><div><dt class="text-xs uppercase text-muted">Cuenta</dt><dd class="mt-1 font-medium">{{ selectedAccount?.codigo }} - {{ selectedAccount?.nombre }}</dd></div><div><dt class="text-xs uppercase text-muted">Fecha</dt><dd class="mt-1">{{ form.fecha_movimiento }}</dd></div><div><dt class="text-xs uppercase text-muted">Naturaleza</dt><dd class="mt-1">{{ form.naturaleza === 'ingreso' ? 'Ingreso' : 'Egreso' }}</dd></div><div><dt class="text-xs uppercase text-muted">Monto</dt><dd class="mt-1 font-mono text-lg font-semibold">${{ form.monto }}</dd></div></dl></div></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="confirmOpen = false" /><UButton label="Registrar movimiento" :loading="loading" :disabled="!canContinue" @click="submit" /></div></template>
  </UModal>
</template>
