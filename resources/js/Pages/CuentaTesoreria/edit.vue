<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import CuentaTesoreriaForm from '../../components/cuentas-tesoreria/CuentaTesoreriaForm.vue'
import type { CuentaTesoreria, CuentaTesoreriaFormData } from '../../types'

const props = defineProps<{ cuenta: CuentaTesoreria }>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<CuentaTesoreriaFormData>(() => ({
  edificio_id: props.cuenta.edificioId,
  codigo: props.cuenta.codigo,
  nombre: props.cuenta.nombre,
  tipo: props.cuenta.tipo,
  entidad_financiera: props.cuenta.entidadFinanciera ?? '',
  tipo_cuenta_bancaria: props.cuenta.tipoCuentaBancaria ?? '',
  numero_cuenta: props.cuenta.numeroCuenta ?? ''
}))
const edificios = computed(() => [{ id: props.cuenta.edificioId, nombre: props.cuenta.edificio ?? 'Edificio' }])

const submit = (data: CuentaTesoreriaFormData) => router.put(route('cuentas-tesoreria.update', [props.cuenta.edificioId, props.cuenta.id]), data, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="cuentas-tesoreria-edit">
    <template #header><UDashboardNavbar title="Editar cuenta de tesoreria"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="font-mono text-sm font-medium text-primary">{{ cuenta.codigo }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ cuenta.nombre }}</h1><p class="mt-2 text-sm text-muted">Los datos de tipo e identificacion bancaria quedan bloqueados despues del primer movimiento.</p></div>
        <UCard><CuentaTesoreriaForm :edificios="edificios" :initial="initial" :errors="errors" :loading="loading" lock-building :lock-identity="cuenta.movimientosCount > 0" submit-label="Actualizar cuenta" @cancel="router.visit(route('cuentas-tesoreria.show', [cuenta.edificioId, cuenta.id]))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
