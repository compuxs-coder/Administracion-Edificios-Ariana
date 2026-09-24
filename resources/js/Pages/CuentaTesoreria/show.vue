<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CuentaTesoreria } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ cuenta: CuentaTesoreria }>()
const { can } = useBuildingPermissions()
const statusOpen = ref(false)
const loading = ref(false)
const error = ref('')
const displayNumber = computed(() => props.cuenta.tipo === 'caja' ? 'No aplica' : props.cuenta.numeroCuentaMascara || 'Cuenta protegida')

const changeStatus = () => router.patch(route('cuentas-tesoreria.status', [props.cuenta.edificioId, props.cuenta.id]), {
  estado: props.cuenta.estado === 'activa' ? 'inactiva' : 'activa'
}, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { statusOpen.value = false },
  onError: errors => { error.value = String(errors.estado ?? errors.cuenta ?? 'No fue posible cambiar el estado de la cuenta.') },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="cuentas-tesoreria-show">
    <template #header>
      <UDashboardNavbar :title="`Cuenta ${cuenta.codigo}`">
        <template #leading><UDashboardSidebarCollapse /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap justify-end gap-2"><UButton v-if="cuenta.estado === 'activa' && can('movimientos_tesoreria.registrar', cuenta.edificioId)" icon="i-lucide-plus" label="Registrar movimiento" @click="router.visit(route('movimientos-tesoreria.create', { edificio_id: cuenta.edificioId, cuenta_id: cuenta.id }))" /><UButton v-if="can('cuentas_tesoreria.gestionar', cuenta.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('cuentas-tesoreria.edit', [cuenta.edificioId, cuenta.id]))" /><UButton v-if="can('cuentas_tesoreria.gestionar', cuenta.edificioId)" :color="cuenta.estado === 'activa' ? 'error' : 'success'" :icon="cuenta.estado === 'activa' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" :label="cuenta.estado === 'activa' ? 'Inactivar' : 'Activar'" variant="outline" @click="error = ''; statusOpen = true" /></div>
        <UAlert color="info" description="El saldo registrado se deriva de movimientos activos y no equivale a un saldo bancario certificado." icon="i-lucide-info" variant="subtle" />
        <UCard>
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">{{ cuenta.edificio }}</p><h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ cuenta.codigo }}</h1><p class="mt-1 font-medium">{{ cuenta.nombre }}</p></div><UBadge :color="cuenta.estado === 'activa' ? 'success' : 'neutral'" :label="cuenta.estado === 'activa' ? 'Activa' : 'Inactiva'" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Tipo</dt><dd class="mt-1">{{ cuenta.tipo === 'bancaria' ? 'Cuenta bancaria' : 'Caja' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Entidad</dt><dd class="mt-1">{{ cuenta.entidadFinanciera || 'No aplica' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Tipo bancario</dt><dd class="mt-1">{{ cuenta.tipoCuentaBancaria || 'No aplica' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Numero de cuenta</dt><dd class="mt-1 font-mono">{{ displayNumber }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Movimientos</dt><dd class="mt-1">{{ cuenta.movimientosCount }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Creada</dt><dd class="mt-1">{{ cuenta.createdAt || 'No disponible' }}</dd></div>
          </dl>
        </UCard>

        <div class="grid gap-4 sm:grid-cols-3">
          <UCard><p class="text-xs uppercase text-muted">Saldo registrado</p><p class="mt-2 font-mono text-2xl font-semibold text-highlighted">${{ cuenta.saldoRegistrado }}</p></UCard>
          <UCard><p class="text-xs uppercase text-muted">Ingresos registrados</p><p class="mt-2 font-mono text-2xl font-semibold text-success">${{ cuenta.totalIngresos }}</p></UCard>
          <UCard><p class="text-xs uppercase text-muted">Egresos registrados</p><p class="mt-2 font-mono text-2xl font-semibold text-error">${{ cuenta.totalEgresos }}</p></UCard>
        </div>

        <UCard>
          <template #header><div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-semibold text-highlighted">Movimientos</p><p class="mt-1 text-xs text-muted">Consulte el historial de esta cuenta.</p></div><UButton color="neutral" icon="i-lucide-list" label="Ver todos" variant="outline" @click="router.visit(route('movimientos-tesoreria.index', { edificio_id: cuenta.edificioId, cuenta_id: cuenta.id }))" /></div></template>
          <p class="text-sm text-muted">Use la vista de movimientos para consultar o filtrar el historial de la cuenta.</p>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="statusOpen" :close="!loading" :dismissible="!loading" :title="cuenta.estado === 'activa' ? 'Inactivar cuenta' : 'Activar cuenta'" :description="`Confirme el cambio de estado de ${cuenta.nombre}.`">
    <template #body><div class="space-y-4"><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UAlert v-if="cuenta.estado === 'activa'" color="warning" description="La cuenta conservara su historial, pero no admitira movimientos nuevos mientras este inactiva." icon="i-lucide-triangle-alert" variant="subtle" /></div></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="statusOpen = false" /><UButton :color="cuenta.estado === 'activa' ? 'error' : 'success'" :label="cuenta.estado === 'activa' ? 'Inactivar cuenta' : 'Activar cuenta'" :loading="loading" @click="changeStatus" /></div></template>
  </UModal>
</template>
