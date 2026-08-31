<script setup lang="ts">
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { CarteraDetalleItem, CarteraResumen } from '../../types'

type Option = { id: string, nombre: string, edificioId: string }
const props = defineProps<{
  cartera: { data: CarteraDetalleItem[], meta: { total: number, currentPage: number, lastPage: number, perPage: number }, summary: CarteraResumen }
  filters: Record<string, string | null | undefined>
  edificios: Array<{ id: string, nombre: string }>
  departamentos: Array<{ id: string, edificioId: string, codigo: string, nombre: string }>
  propietarios: Array<{ id: string, nombre: string }>
  torres: Option[]
  pisos: Array<Option & { torreId: string }>
  conceptos: Option[]
}>()

const filters = reactive({
  edificio_id: props.filters.edificio_id ?? '', torre_id: props.filters.torre_id ?? '', piso_id: props.filters.piso_id ?? '', departamento_id: props.filters.departamento_id ?? '', propietario_id: props.filters.propietario_id ?? '', concepto_id: props.filters.concepto_id ?? '', buscar: props.filters.buscar ?? '', situacion: props.filters.situacion ?? '', estado: props.filters.estado ?? '', antiguedad: props.filters.antiguedad ?? '', periodo: props.filters.periodo ?? '', fecha: props.filters.fecha ?? '', saldo_min: props.filters.saldo_min ?? '', saldo_max: props.filters.saldo_max ?? '', orden: props.filters.orden ?? 'neto_desc',
})
const selectItems = (items: Array<{ id: string, nombre: string }>, empty: string) => [{ label: empty, value: '' }, ...items.map(item => ({ label: item.nombre, value: item.id }))]
const towers = computed(() => props.torres.filter(item => !filters.edificio_id || item.edificioId === filters.edificio_id))
const floors = computed(() => props.pisos.filter(item => (!filters.edificio_id || item.edificioId === filters.edificio_id) && (!filters.torre_id || item.torreId === filters.torre_id)))
const departments = computed(() => props.departamentos.filter(item => !filters.edificio_id || item.edificioId === filters.edificio_id).map(item => ({ id: item.id, nombre: `${item.codigo} · ${item.nombre}` })))
const owners = computed(() => props.propietarios)
const concepts = computed(() => props.conceptos.filter(item => !filters.edificio_id || item.edificioId === filters.edificio_id))
const reload = (page = 1) => router.get(route('cartera.index'), { ...filters, page }, { preserveState: true, preserveScroll: true, replace: true })
const clear = () => { Object.assign(filters, { edificio_id: '', torre_id: '', piso_id: '', departamento_id: '', propietario_id: '', concepto_id: '', buscar: '', situacion: '', estado: '', antiguedad: '', periodo: '', fecha: '', saldo_min: '', saldo_max: '', orden: 'neto_desc' }); reload() }
const onBuildingChange = () => { filters.torre_id = ''; filters.piso_id = ''; filters.departamento_id = ''; filters.concepto_id = ''; reload() }
</script>

<template>
  <UDashboardPanel id="cartera">
    <template #header><UDashboardNavbar title="Cartera"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton icon="i-lucide-hand-coins" label="Registrar pago" @click="router.visit(route('pagos.create'))" /></template></UDashboardNavbar></template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UAlert color="info" description="Vista derivada de cargos, pagos y saldo a favor. No admite edición manual." icon="i-lucide-info" variant="subtle" />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><UCard v-for="item in [{ label: 'Cartera total', value: cartera.summary.saldoPendiente }, { label: 'Vencido', value: cartera.summary.saldoVencido }, { label: 'No vencido', value: cartera.summary.saldoNoVencido }, { label: 'Saldos a favor', value: cartera.summary.saldoFavor }, { label: 'Morosidad', value: `${cartera.summary.morosidadPorcentaje}%` }]" :key="item.label"><p class="text-xs uppercase text-muted">{{ item.label }}</p><p class="mt-2 font-mono text-lg font-semibold text-highlighted">{{ item.label === 'Morosidad' ? item.value : `$${item.value}` }}</p></UCard></div>
        <UCard>
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <USelect v-model="filters.edificio_id" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" placeholder="Edificio" @update:model-value="onBuildingChange" />
            <USelect v-model="filters.torre_id" :items="selectItems(towers, 'Todas las torres')" placeholder="Torre" @update:model-value="filters.piso_id = ''; reload()" />
            <USelect v-model="filters.piso_id" :items="selectItems(floors, 'Todos los pisos')" placeholder="Piso" @update:model-value="reload()" />
            <USelect v-model="filters.departamento_id" :items="selectItems(departments, 'Todos los departamentos')" placeholder="Departamento" @update:model-value="reload()" />
            <USelect v-model="filters.propietario_id" :items="selectItems(owners, 'Todos los titulares')" placeholder="Titular" @update:model-value="reload()" />
            <USelect v-model="filters.concepto_id" :items="selectItems(concepts, 'Todos los conceptos')" placeholder="Concepto" @update:model-value="reload()" />
            <UInput v-model="filters.buscar" placeholder="Departamento o titular" @change="reload()" />
            <UInput v-model="filters.periodo" type="month" placeholder="Período" @change="reload()" />
            <UInput v-model="filters.fecha" type="date" placeholder="Fecha de corte" @change="reload()" />
            <UInput v-model="filters.saldo_min" type="number" step="0.0001" placeholder="Saldo neto mínimo" @change="reload()" />
            <UInput v-model="filters.saldo_max" type="number" step="0.0001" placeholder="Saldo neto máximo" @change="reload()" />
            <USelect v-model="filters.situacion" :items="[{ label: 'Todas las situaciones', value: '' }, { label: 'Con deuda', value: 'con_deuda' }, { label: 'Vencidos', value: 'vencidos' }, { label: 'Saldo a favor', value: 'con_favor' }]" placeholder="Situación" @update:model-value="reload()" />
            <USelect v-model="filters.estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Al día', value: 'al_dia' }, { label: 'Moroso', value: 'moroso' }, { label: 'Saldo a favor', value: 'saldo_a_favor' }]" placeholder="Estado" @update:model-value="reload()" />
            <USelect v-model="filters.antiguedad" :items="[{ label: 'Toda antigüedad', value: '' }, { label: '1 a 30 días', value: '1_a_30' }, { label: '31 a 60 días', value: '31_a_60' }, { label: '61 a 90 días', value: '61_a_90' }, { label: 'Más de 90 días', value: 'mas_de_90' }]" placeholder="Antigüedad" @update:model-value="reload()" />
            <USelect v-model="filters.orden" :items="[{ label: 'Mayor saldo neto', value: 'neto_desc' }, { label: 'Mayor vencido', value: 'vencido_desc' }, { label: 'Departamento', value: 'codigo_asc' }]" placeholder="Orden" @update:model-value="reload()" />
            <UButton color="neutral" variant="outline" label="Limpiar filtros" @click="clear" />
          </div>
        </UCard>
        <div class="overflow-x-auto rounded-xl border border-default"><table class="w-full min-w-[1100px] text-left text-sm"><thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Departamento</th><th class="p-3">Titular(es)</th><th class="p-3 text-right">Pendiente</th><th class="p-3 text-right">Vencido</th><th class="p-3 text-right">A favor</th><th class="p-3">Antigüedad</th><th class="p-3">Estado</th><th class="p-3">Último pago</th><th class="p-3" /></tr></thead><tbody><tr v-for="item in cartera.data" :key="item.departamentoId" class="border-t border-default"><td class="p-3"><p class="font-medium">{{ item.departamento }}</p><p class="text-xs text-muted">{{ item.edificio }} · {{ item.torre }} {{ item.piso }}</p></td><td class="p-3">{{ item.propietarios.map(owner => owner.nombre).join(', ') || 'Sin titular' }}</td><td class="p-3 text-right font-mono">${{ item.saldoPendiente }}</td><td class="p-3 text-right font-mono">${{ item.saldoVencido }}</td><td class="p-3 text-right font-mono">${{ item.saldoFavor }}</td><td class="p-3">{{ item.antiguedad.replaceAll('_', ' ') }}</td><td class="p-3"><UBadge :color="item.estado === 'moroso' ? 'error' : item.estado === 'saldo_a_favor' ? 'success' : 'neutral'" variant="subtle">{{ item.estado.replaceAll('_', ' ') }}</UBadge></td><td class="p-3">{{ item.ultimoPago || 'Sin pago' }}</td><td class="p-3"><UButton icon="i-lucide-file-text" label="Estado" size="xs" variant="outline" @click="router.visit(route('cartera.show', [item.edificioId, item.departamentoId]))" /></td></tr><tr v-if="cartera.data.length === 0"><td class="p-6 text-center text-muted" colspan="9">No hay departamentos que coincidan con los filtros.</td></tr></tbody></table></div>
        <div class="flex items-center justify-between text-sm text-muted"><span>{{ cartera.meta.total }} resultados</span><div class="flex gap-2"><UButton label="Anterior" :disabled="cartera.meta.currentPage <= 1" variant="outline" @click="reload(cartera.meta.currentPage - 1)" /><UButton label="Siguiente" :disabled="cartera.meta.currentPage >= cartera.meta.lastPage" variant="outline" @click="reload(cartera.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
