<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CuentaTesoreriaOption, EstadoConciliacionMovimiento, EstadoMovimientoTesoreria, MovimientoTesoreria, NaturalezaMovimientoTesoreria, PaginationMeta } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  movimientos: { data: MovimientoTesoreria[], meta: PaginationMeta }
  filters: {
    edificio_id?: string | null
    cuenta_id?: string | null
    fecha_desde?: string | null
    fecha_hasta?: string | null
    naturaleza?: NaturalezaMovimientoTesoreria | null
    estado?: EstadoMovimientoTesoreria | null
    estado_conciliacion?: EstadoConciliacionMovimiento | null
  }
  edificios: Array<{ id: string, nombre: string }>
  cuentas: CuentaTesoreriaOption[]
}>()

const edificioId = ref(props.filters.edificio_id ?? '')
const cuentaId = ref(props.filters.cuenta_id ?? '')
const fechaDesde = ref(props.filters.fecha_desde ?? '')
const fechaHasta = ref(props.filters.fecha_hasta ?? '')
const naturaleza = ref(props.filters.naturaleza ?? '')
const estado = ref(props.filters.estado ?? '')
const estadoConciliacion = ref(props.filters.estado_conciliacion ?? '')
const loading = ref(false)
const page = usePage()
const filterError = computed(() => String(page.props.errors?.fechaHasta ?? page.props.errors?.fechaDesde ?? ''))
const { canAny } = useBuildingPermissions()

const accountItems = computed(() => props.cuentas
  .filter(item => !edificioId.value || item.edificioId === edificioId.value)
  .map(item => ({ label: `${item.codigo} - ${item.nombre}`, value: item.id })))
const stateLabels: Record<EstadoConciliacionMovimiento, string> = {
  pendiente: 'Pendiente',
  conciliado: 'Conciliado',
  no_aplica: 'No aplica',
  anulado: 'Anulado'
}
const stateColor = (state: EstadoConciliacionMovimiento): 'warning' | 'success' | 'neutral' | 'error' => state === 'pendiente' ? 'warning' : state === 'conciliado' ? 'success' : state === 'anulado' ? 'error' : 'neutral'

const reload = (page = 1, replace = false) => router.get(route('movimientos-tesoreria.index'), {
  edificio_id: edificioId.value || undefined,
  cuenta_id: cuentaId.value || undefined,
  fecha_desde: fechaDesde.value || undefined,
  fecha_hasta: fechaHasta.value || undefined,
  naturaleza: naturaleza.value || undefined,
  estado: estado.value || undefined,
  estado_conciliacion: estadoConciliacion.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})

const changeBuilding = () => { cuentaId.value = ''; reload(1, true) }
</script>

<template>
  <UDashboardPanel id="movimientos-tesoreria">
    <template #header>
      <UDashboardNavbar title="Movimientos de tesoreria">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('movimientos_tesoreria.registrar')" icon="i-lucide-plus" label="Registrar movimiento" @click="router.visit(route('movimientos-tesoreria.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="rounded-xl border border-default bg-elevated/20 p-4">
          <UAlert v-if="filterError" class="mb-3" color="error" :description="filterError" icon="i-lucide-circle-alert" variant="subtle" />
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" size="xl" @update:model-value="changeBuilding" />
            <USelect v-model="cuentaId" aria-label="Filtrar por cuenta" :items="[{ label: 'Todas las cuentas', value: '' }, ...accountItems]" size="xl" @update:model-value="reload(1, true)" />
            <USelect v-model="naturaleza" aria-label="Filtrar por naturaleza" :items="[{ label: 'Ingresos y egresos', value: '' }, { label: 'Ingresos', value: 'ingreso' }, { label: 'Egresos', value: 'egreso' }]" size="xl" @update:model-value="reload(1, true)" />
            <UInput v-model="fechaDesde" type="date" size="xl" aria-label="Fecha desde" @change="reload(1, true)" />
            <UInput v-model="fechaHasta" type="date" size="xl" aria-label="Fecha hasta" @change="reload(1, true)" />
            <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Registrados y anulados', value: '' }, { label: 'Registrados', value: 'registrado' }, { label: 'Anulados', value: 'anulado' }]" size="xl" @update:model-value="reload(1, true)" />
            <USelect v-model="estadoConciliacion" aria-label="Filtrar por conciliacion" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Pendiente', value: 'pendiente' }, { label: 'Conciliado', value: 'conciliado' }, { label: 'No aplica', value: 'no_aplica' }, { label: 'Anulado', value: 'anulado' }]" size="xl" @update:model-value="reload(1, true)" />
          </div>
        </div>

        <div class="space-y-3 md:hidden" :class="loading ? 'opacity-60' : ''">
          <button v-for="movimiento in movimientos.data" :key="movimiento.id" class="w-full rounded-xl border border-default p-4 text-left" type="button" @click="router.visit(route('movimientos-tesoreria.show', [movimiento.edificioId, movimiento.cuentaId, movimiento.id]))">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0"><p class="font-mono text-sm font-semibold text-primary">{{ movimiento.referencia || 'Sin referencia' }}</p><p class="truncate font-medium text-highlighted">{{ movimiento.cuentaCodigo }} - {{ movimiento.cuenta }}</p><p class="text-xs text-muted">{{ movimiento.edificio }}</p></div>
              <UBadge :color="stateColor(movimiento.estadoConciliacion)" :label="stateLabels[movimiento.estadoConciliacion]" variant="subtle" />
            </div>
            <div class="mt-4 flex items-end justify-between gap-3 border-t border-default pt-3"><div class="text-sm text-muted"><p>{{ movimiento.fechaMovimiento }}</p><p class="capitalize">{{ movimiento.naturaleza }}</p></div><p class="font-mono text-lg font-semibold" :class="movimiento.naturaleza === 'ingreso' ? 'text-success' : 'text-error'">{{ movimiento.naturaleza === 'ingreso' ? '+' : '-' }}${{ movimiento.monto }}</p></div>
          </button>
          <p v-if="!movimientos.data.length" class="rounded-xl border border-dashed border-default p-10 text-center text-muted">No hay movimientos que coincidan con los filtros.</p>
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-default md:block" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Fecha</th><th class="p-3">Cuenta</th><th class="p-3">Referencia</th><th class="p-3">Naturaleza</th><th class="p-3 text-right">Monto</th><th class="p-3">Conciliacion</th><th class="p-3">Estado</th><th class="p-3" /></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="movimiento in movimientos.data" :key="movimiento.id" class="cursor-pointer hover:bg-elevated/30" @click="router.visit(route('movimientos-tesoreria.show', [movimiento.edificioId, movimiento.cuentaId, movimiento.id]))">
                <td class="p-3">{{ movimiento.fechaMovimiento }}</td>
                <td class="p-3"><p class="font-mono font-semibold text-primary">{{ movimiento.cuentaCodigo }}</p><p class="text-xs text-muted">{{ movimiento.cuenta }} - {{ movimiento.edificio }}</p></td>
                <td class="p-3"><p>{{ movimiento.referencia || 'Sin referencia' }}</p><p class="max-w-xs truncate text-xs text-muted">{{ movimiento.descripcion || 'Sin descripcion' }}</p></td>
                <td class="p-3"><UBadge :color="movimiento.naturaleza === 'ingreso' ? 'success' : 'error'" :label="movimiento.naturaleza === 'ingreso' ? 'Ingreso' : 'Egreso'" variant="outline" /></td>
                <td class="p-3 text-right font-mono font-semibold" :class="movimiento.naturaleza === 'ingreso' ? 'text-success' : 'text-error'">{{ movimiento.naturaleza === 'ingreso' ? '+' : '-' }}${{ movimiento.monto }}</td>
                <td class="p-3"><UBadge :color="stateColor(movimiento.estadoConciliacion)" :label="stateLabels[movimiento.estadoConciliacion]" variant="subtle" /></td>
                <td class="p-3"><UBadge :color="movimiento.estado === 'registrado' ? 'success' : 'neutral'" :label="movimiento.estado === 'registrado' ? 'Registrado' : 'Anulado'" variant="subtle" /></td>
                <td class="p-3 text-right"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver movimiento" /></td>
              </tr>
              <tr v-if="!movimientos.data.length"><td class="p-10 text-center text-muted" colspan="8">No hay movimientos que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">{{ movimientos.meta.total }} movimiento(s) - pagina {{ movimientos.meta.currentPage }} de {{ movimientos.meta.lastPage }}</p>
          <div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="movimientos.meta.currentPage <= 1 || loading" @click="reload(movimientos.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="movimientos.meta.currentPage >= movimientos.meta.lastPage || loading" @click="reload(movimientos.meta.currentPage + 1)" /></div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
