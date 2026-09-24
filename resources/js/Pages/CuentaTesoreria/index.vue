<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CuentaTesoreria, EstadoCuentaTesoreria, PaginationMeta, TipoCuentaTesoreria } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  cuentas: { data: CuentaTesoreria[], meta: PaginationMeta }
  filters: { buscar?: string | null, edificio_id?: string | null, tipo?: TipoCuentaTesoreria | null, estado?: EstadoCuentaTesoreria | null }
  edificios: Array<{ id: string, nombre: string }>
}>()

const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const tipo = ref(props.filters.tipo ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const statusLoading = ref(false)
const statusOpen = ref(false)
const statusError = ref('')
const selected = ref<CuentaTesoreria | null>(null)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const buildingItems = computed(() => [{ label: 'Todos los edificios', value: '' }, ...props.edificios.map(item => ({ label: item.nombre, value: item.id }))])
const typeLabel = (value: TipoCuentaTesoreria) => value === 'bancaria' ? 'Bancaria' : 'Caja'
const accountNumber = (cuenta: CuentaTesoreria) => cuenta.tipo === 'caja' ? 'No aplica' : cuenta.numeroCuentaMascara || 'Cuenta protegida'

const reload = (page = 1, replace = false) => router.get(route('cuentas-tesoreria.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  tipo: tipo.value || undefined,
  estado: estado.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})

watch(buscar, () => {
  clearTimeout(timer)
  timer = setTimeout(() => reload(1, true), 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const confirmStatus = (cuenta: CuentaTesoreria) => {
  selected.value = cuenta
  statusError.value = ''
  statusOpen.value = true
}

const changeStatus = () => {
  if (!selected.value) return
  router.patch(route('cuentas-tesoreria.status', [selected.value.edificioId, selected.value.id]), {
    estado: selected.value.estado === 'activa' ? 'inactiva' : 'activa'
  }, {
    preserveScroll: true,
    onStart: () => { statusLoading.value = true },
    onSuccess: () => { statusOpen.value = false; selected.value = null },
    onError: errors => { statusError.value = String(errors.estado ?? errors.cuenta ?? 'No fue posible cambiar el estado de la cuenta.') },
    onFinish: () => { statusLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="cuentas-tesoreria">
    <template #header>
      <UDashboardNavbar title="Cuentas de tesoreria">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('cuentas_tesoreria.gestionar')" icon="i-lucide-plus" label="Nueva cuenta" @click="router.visit(route('cuentas-tesoreria.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UAlert color="info" description="El saldo registrado corresponde al neto de movimientos activos; no representa un saldo bancario certificado." icon="i-lucide-info" variant="subtle" />
        <div class="grid gap-3 lg:grid-cols-[minmax(16rem,1fr)_14rem_12rem_12rem]">
          <UInput v-model="buscar" aria-label="Buscar cuentas" icon="i-lucide-search" placeholder="Codigo, nombre o entidad" size="xl" />
          <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="buildingItems" size="xl" @update:model-value="reload(1, true)" />
          <USelect v-model="tipo" aria-label="Filtrar por tipo" :items="[{ label: 'Todos los tipos', value: '' }, { label: 'Bancaria', value: 'bancaria' }, { label: 'Caja', value: 'caja' }]" size="xl" @update:model-value="reload(1, true)" />
          <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activas', value: 'activa' }, { label: 'Inactivas', value: 'inactiva' }]" size="xl" @update:model-value="reload(1, true)" />
        </div>

        <div class="space-y-3 md:hidden" :class="loading ? 'opacity-60' : ''">
          <button v-for="cuenta in cuentas.data" :key="cuenta.id" class="w-full rounded-xl border border-default p-4 text-left" type="button" @click="router.visit(route('cuentas-tesoreria.show', [cuenta.edificioId, cuenta.id]))">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0"><p class="font-mono text-sm font-semibold text-primary">{{ cuenta.codigo }}</p><p class="truncate font-medium text-highlighted">{{ cuenta.nombre }}</p><p class="text-xs text-muted">{{ cuenta.edificio }}</p></div>
              <UBadge :color="cuenta.estado === 'activa' ? 'success' : 'neutral'" :label="cuenta.estado === 'activa' ? 'Activa' : 'Inactiva'" variant="subtle" />
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 border-t border-default pt-3 text-sm">
              <div><p class="text-xs uppercase text-muted">Tipo</p><p class="mt-1">{{ typeLabel(cuenta.tipo) }}</p></div>
              <div class="text-right"><p class="text-xs uppercase text-muted">Saldo registrado</p><p class="mt-1 font-mono font-semibold">${{ cuenta.saldoRegistrado }}</p></div>
              <div class="col-span-2"><p class="text-xs uppercase text-muted">Cuenta</p><p class="mt-1 font-mono">{{ accountNumber(cuenta) }}</p></div>
            </div>
          </button>
          <p v-if="!cuentas.data.length" class="rounded-xl border border-dashed border-default p-10 text-center text-muted">No hay cuentas que coincidan con los filtros.</p>
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-default md:block" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Cuenta</th><th class="p-3">Edificio</th><th class="p-3">Tipo</th><th class="p-3">Identificacion</th><th class="p-3 text-right">Saldo registrado</th><th class="p-3 text-center">Movimientos</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="cuenta in cuentas.data" :key="cuenta.id" class="cursor-pointer hover:bg-elevated/30" @click="router.visit(route('cuentas-tesoreria.show', [cuenta.edificioId, cuenta.id]))">
                <td class="p-3"><p class="font-mono font-semibold text-primary">{{ cuenta.codigo }}</p><p class="font-medium text-highlighted">{{ cuenta.nombre }}</p></td>
                <td class="p-3 text-muted">{{ cuenta.edificio }}</td>
                <td class="p-3"><UBadge color="neutral" :label="typeLabel(cuenta.tipo)" variant="outline" /></td>
                <td class="p-3"><p>{{ cuenta.tipo === 'bancaria' ? cuenta.entidadFinanciera || 'Sin entidad' : 'Efectivo' }}</p><p class="font-mono text-xs text-muted">{{ accountNumber(cuenta) }}</p></td>
                <td class="p-3 text-right font-mono font-semibold">${{ cuenta.saldoRegistrado }}</td>
                <td class="p-3 text-center">{{ cuenta.movimientosCount }}</td>
                <td class="p-3"><UBadge :color="cuenta.estado === 'activa' ? 'success' : 'neutral'" :label="cuenta.estado === 'activa' ? 'Activa' : 'Inactiva'" variant="subtle" /></td>
                <td class="p-3"><div class="flex justify-end gap-1"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver cuenta" @click.stop="router.visit(route('cuentas-tesoreria.show', [cuenta.edificioId, cuenta.id]))" /><UButton v-if="can('cuentas_tesoreria.gestionar', cuenta.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar cuenta" @click.stop="router.visit(route('cuentas-tesoreria.edit', [cuenta.edificioId, cuenta.id]))" /><UButton v-if="can('cuentas_tesoreria.gestionar', cuenta.edificioId)" :color="cuenta.estado === 'activa' ? 'error' : 'success'" :icon="cuenta.estado === 'activa' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" variant="ghost" aria-label="Cambiar estado" @click.stop="confirmStatus(cuenta)" /></div></td>
              </tr>
              <tr v-if="!cuentas.data.length"><td class="p-10 text-center text-muted" colspan="8">No hay cuentas que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">{{ cuentas.meta.total }} cuenta(s) - pagina {{ cuentas.meta.currentPage }} de {{ cuentas.meta.lastPage }}</p>
          <div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="cuentas.meta.currentPage <= 1 || loading" @click="reload(cuentas.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="cuentas.meta.currentPage >= cuentas.meta.lastPage || loading" @click="reload(cuentas.meta.currentPage + 1)" /></div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="statusOpen" :close="!statusLoading" :dismissible="!statusLoading" :title="selected?.estado === 'activa' ? 'Inactivar cuenta' : 'Activar cuenta'" :description="selected ? `Confirme el cambio de estado de ${selected.nombre}.` : ''">
    <template #body><div class="space-y-4"><UAlert v-if="statusError" color="error" :description="statusError" icon="i-lucide-circle-alert" variant="subtle" /><UAlert v-if="selected?.estado === 'activa'" color="warning" description="La cuenta conservara su historial, pero no admitira movimientos nuevos mientras este inactiva." icon="i-lucide-triangle-alert" variant="subtle" /></div></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="statusLoading" @click="statusOpen = false" /><UButton :color="selected?.estado === 'activa' ? 'error' : 'success'" :label="selected?.estado === 'activa' ? 'Inactivar cuenta' : 'Activar cuenta'" :loading="statusLoading" @click="changeStatus" /></div></template>
  </UModal>
</template>
