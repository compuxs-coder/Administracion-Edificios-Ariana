<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EstadoPago, FormaPago, Pago } from '../../types'

const props = defineProps<{
  pagos: { data: Pago[], meta: { total: number, currentPage: number, lastPage: number, perPage: number } }
  filters: { edificio_id?: string | null, departamento_id?: string | null, propietario_id?: string | null, fecha_desde?: string | null, fecha_hasta?: string | null, forma_pago?: FormaPago | null, estado?: EstadoPago | null }
  edificios: Array<{ id: string, nombre: string }>
  departamentos: Array<{ id: string, edificioId: string, codigo: string, nombre: string }>
  propietarios: Array<{ id: string, nombre: string, identificacion: string }>
}>()
const edificioId = ref(props.filters.edificio_id ?? '')
const departamentoId = ref(props.filters.departamento_id ?? '')
const propietarioId = ref(props.filters.propietario_id ?? '')
const fechaDesde = ref(props.filters.fecha_desde ?? '')
const fechaHasta = ref(props.filters.fecha_hasta ?? '')
const formaPago = ref(props.filters.forma_pago ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const departments = computed(() => props.departamentos.filter(item => !edificioId.value || item.edificioId === edificioId.value).map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id })))
const reload = (page = 1) => router.get(route('pagos.index'), { edificio_id: edificioId.value || undefined, departamento_id: departamentoId.value || undefined, propietario_id: propietarioId.value || undefined, fecha_desde: fechaDesde.value || undefined, fecha_hasta: fechaHasta.value || undefined, forma_pago: formaPago.value || undefined, estado: estado.value || undefined, page }, { preserveState: true, preserveScroll: true, replace: true, onStart: () => { loading.value = true }, onFinish: () => { loading.value = false } })
</script>

<template>
  <UDashboardPanel id="pagos"><template #header><UDashboardNavbar title="Pagos"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton icon="i-lucide-plus" label="Registrar pago" @click="router.visit(route('pagos.create'))" /></template></UDashboardNavbar></template><template #body><div class="flex h-full flex-col gap-5 p-4 sm:p-6"><div class="grid gap-3 xl:grid-cols-3"><USelect v-model="edificioId" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" size="xl" @update:model-value="departamentoId = ''; reload()" /><USelect v-model="departamentoId" :items="[{ label: 'Todos los departamentos', value: '' }, ...departments]" size="xl" @update:model-value="reload()" /><USelect v-model="propietarioId" :items="[{ label: 'Todos los propietarios', value: '' }, ...propietarios.map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.id }))]" size="xl" @update:model-value="reload()" /><UInput v-model="fechaDesde" type="date" size="xl" aria-label="Fecha desde" @change="reload()" /><UInput v-model="fechaHasta" type="date" size="xl" aria-label="Fecha hasta" @change="reload()" /><USelect v-model="formaPago" :items="[{ label: 'Todas las formas', value: '' }, { label: 'Efectivo', value: 'efectivo' }, { label: 'Transferencia', value: 'transferencia' }, { label: 'Depósito', value: 'deposito' }, { label: 'Tarjeta', value: 'tarjeta' }, { label: 'Cheque', value: 'cheque' }, { label: 'Otro', value: 'otro' }]" size="xl" @update:model-value="reload()" /><USelect v-model="estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Registrado', value: 'registrado' }, { label: 'Anulado', value: 'anulado' }]" size="xl" @update:model-value="reload()" /></div><div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''"><table class="w-full min-w-5xl text-left text-sm"><thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Número</th><th class="p-3">Fecha</th><th class="p-3">Departamento</th><th class="p-3">Propietario</th><th class="p-3 text-right">Recibido</th><th class="p-3 text-right">Aplicado</th><th class="p-3 text-right">Saldo a favor</th><th class="p-3">Forma</th><th class="p-3">Estado</th></tr></thead><tbody class="divide-y divide-default"><tr v-for="pago in pagos.data" :key="pago.id" class="cursor-pointer hover:bg-elevated/30" @click="router.visit(route('pagos.show', [pago.edificioId, pago.id]))"><td class="p-3 font-mono text-primary">{{ pago.numero }}</td><td class="p-3">{{ pago.fechaPago }}</td><td class="p-3">{{ pago.departamento }}</td><td class="p-3">{{ pago.propietario || 'Sin titular único' }}</td><td class="p-3 text-right font-mono">${{ pago.valorRecibido }}</td><td class="p-3 text-right font-mono">${{ pago.valorAplicado }}</td><td class="p-3 text-right font-mono">${{ pago.saldoFavor }}</td><td class="p-3 capitalize">{{ pago.formaPago }}</td><td class="p-3"><UBadge :color="pago.estado === 'registrado' ? 'success' : 'neutral'" :label="pago.estado" variant="subtle" /></td></tr><tr v-if="!pagos.data.length"><td class="p-8 text-center text-muted" colspan="9">No hay pagos para los filtros seleccionados.</td></tr></tbody></table></div><div v-if="pagos.meta.lastPage > 1" class="flex items-center justify-between text-sm text-muted"><span>{{ pagos.meta.total }} pago(s)</span><UPagination :page="pagos.meta.currentPage" :total="pagos.meta.total" :items-per-page="pagos.meta.perPage" @update:page="reload" /></div></div></template></UDashboardPanel>
</template>
