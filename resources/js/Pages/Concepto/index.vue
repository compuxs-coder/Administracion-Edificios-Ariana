<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ConceptoCobro, EstadoConceptoCobro, FormaCalculoCobro, PeriodicidadCobro, TipoConceptoCobro } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
    conceptos: {
        data: ConceptoCobro[]
        meta: {
            total: number
            currentPage: number
            lastPage: number
            perPage: number
        }
    }
    filters: {
        buscar?: string | null
        edificio_id?: string | null
        tipo?: TipoConceptoCobro | null
        periodicidad?: PeriodicidadCobro | null
        forma_calculo?: FormaCalculoCobro | null
        estado?: EstadoConceptoCobro | null
    }
    edificios: Array<{ id: string; nombre: string }>
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const tipo = ref(props.filters.tipo ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const statusLoading = ref(false)
const statusOpen = ref(false)
const statusError = ref('')
const selected = ref<ConceptoCobro | null>(null)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const typeLabels: Record<TipoConceptoCobro, string> = {
    ordinario: 'Ordinario',
    extraordinario: 'Extraordinario',
    consumo: 'Consumo',
    multa: 'Multa',
    interes: 'Interés',
    otro: 'Otro',
}
const calculationLabels: Record<FormaCalculoCobro, string> = {
    valor_fijo: 'Valor fijo',
    por_alicuota: 'Por alícuota',
    porcentaje: 'Porcentaje',
    por_consumo: 'Por consumo',
    manual: 'Manual',
}
const periodicityLabels: Record<PeriodicidadCobro, string> = {
    mensual: 'Mensual',
    trimestral: 'Trimestral',
    semestral: 'Semestral',
    anual: 'Anual',
    unico: 'Único',
    manual: 'Manual',
}
const buildingItems = computed(() => [{ label: 'Todos los edificios', value: '' }, ...props.edificios.map((item) => ({ label: item.nombre, value: item.id }))])
const rateText = (concepto: ConceptoCobro) => {
    const tarifa = concepto.tarifaVigente
    if (!tarifa) return 'Sin tarifa vigente'
    if (tarifa.porcentaje !== null) return `${tarifa.porcentaje}%`
    if (tarifa.valor !== null) return tarifa.unidad ? `$${tarifa.valor} / ${tarifa.unidad}` : `$${tarifa.valor}`
    return 'Configuración manual'
}
const reload = (page = 1, replace = false) =>
    router.get(
        route('conceptos.index'),
        {
            buscar: buscar.value || undefined,
            edificio_id: edificioId.value || undefined,
            tipo: tipo.value || undefined,
            estado: estado.value || undefined,
            page,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace,
            onStart: () => {
                loading.value = true
            },
            onFinish: () => {
                loading.value = false
            },
        },
    )
watch(buscar, () => {
    clearTimeout(timer)
    timer = setTimeout(() => reload(1, true), 300)
})
onBeforeUnmount(() => clearTimeout(timer))
const confirmStatus = (concepto: ConceptoCobro) => {
    selected.value = concepto
    statusError.value = ''
    statusOpen.value = true
}
const changeStatus = () => {
    if (!selected.value) return
    router.patch(
        route('conceptos.estado', [selected.value.edificioId, selected.value.id]),
        { estado: selected.value.estado === 'activo' ? 'inactivo' : 'activo' },
        {
            preserveScroll: true,
            onStart: () => {
                statusLoading.value = true
            },
            onSuccess: () => {
                statusOpen.value = false
                selected.value = null
            },
            onError: (errors) => {
                statusError.value = String(errors.estado ?? 'No fue posible cambiar el estado.')
            },
            onFinish: () => {
                statusLoading.value = false
            },
        },
    )
}
</script>

<template>
    <UDashboardPanel id="conceptos"
        ><template #header
            ><UDashboardNavbar title="Conceptos de cobro"
                ><template #leading><UDashboardSidebarCollapse /></template
                ><template #right
                    ><UButton
                        v-if="canAny('conceptos.gestionar')"
                        icon="i-lucide-plus"
                        label="Nuevo concepto"
                        @click="router.visit(route('conceptos.create'))" /></template></UDashboardNavbar></template
        ><template #body
            ><div class="flex h-full flex-col gap-5 p-4 sm:p-6">
                <div class="grid gap-3 xl:grid-cols-[minmax(14rem,1fr)_14rem_12rem_12rem]">
                    <UInput v-model="buscar" aria-label="Buscar conceptos" icon="i-lucide-search" placeholder="Código o concepto" size="xl" /><USelect
                        v-model="edificioId"
                        aria-label="Filtrar por edificio"
                        :items="buildingItems"
                        size="xl"
                        @update:model-value="reload(1, true)"
                    /><USelect
                        v-model="tipo"
                        aria-label="Filtrar por tipo"
                        :items="[{ label: 'Todos los tipos', value: '' }, ...Object.entries(typeLabels).map(([value, label]) => ({ label, value }))]"
                        size="xl"
                        @update:model-value="reload(1, true)"
                    /><USelect
                        v-model="estado"
                        aria-label="Filtrar por estado"
                        :items="[
                            { label: 'Todos los estados', value: '' },
                            { label: 'Activos', value: 'activo' },
                            { label: 'Inactivos', value: 'inactivo' },
                        ]"
                        size="xl"
                        @update:model-value="reload(1, true)"
                    />
                </div>
                <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
                    <table class="w-full min-w-6xl text-left text-sm">
                        <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted">
                            <tr>
                                <th class="px-4 py-3 font-medium">Código</th>
                                <th class="px-4 py-3 font-medium">Concepto</th>
                                <th class="px-4 py-3 font-medium">Tipo</th>
                                <th class="px-4 py-3 font-medium">Periodicidad</th>
                                <th class="px-4 py-3 font-medium">Cálculo</th>
                                <th class="px-4 py-3 font-medium">Tarifa vigente</th>
                                <th class="px-4 py-3 font-medium">Estado</th>
                                <th class="px-4 py-3 text-right font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-default">
                            <tr v-for="concepto in conceptos.data" :key="concepto.id" class="hover:bg-elevated/30">
                                <td class="px-4 py-3">
                                    <p class="font-mono font-semibold text-highlighted">
                                        {{ concepto.codigo }}
                                    </p>
                                    <p class="text-xs text-muted">
                                        {{ concepto.edificio }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 font-medium text-highlighted">
                                    {{ concepto.nombre }}
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ typeLabels[concepto.tipo] }}
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ periodicityLabels[concepto.periodicidad] }}
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    {{ calculationLabels[concepto.formaCalculo] }}
                                </td>
                                <td class="px-4 py-3 font-mono text-highlighted">
                                    {{ rateText(concepto) }}
                                </td>
                                <td class="px-4 py-3">
                                    <UBadge :color="concepto.estado === 'activo' ? 'success' : 'neutral'" :label="concepto.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" />
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <UButton
                                            color="neutral"
                                            icon="i-lucide-eye"
                                            variant="ghost"
                                            aria-label="Ver concepto"
                                            @click="router.visit(route('conceptos.show', [concepto.edificioId, concepto.id]))"
                                        /><UButton
                                            v-if="can('conceptos.gestionar', concepto.edificioId)"
                                            color="neutral"
                                            icon="i-lucide-pencil"
                                            variant="ghost"
                                            aria-label="Editar concepto"
                                            @click="router.visit(route('conceptos.edit', [concepto.edificioId, concepto.id]))"
                                        /><UButton
                                            v-if="can('conceptos.gestionar', concepto.edificioId)"
                                            :color="concepto.estado === 'activo' ? 'error' : 'success'"
                                            :icon="concepto.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'"
                                            variant="ghost"
                                            aria-label="Cambiar estado"
                                            @click="confirmStatus(concepto)"
                                        />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!conceptos.data.length">
                                <td colspan="8" class="px-4 py-12 text-center text-muted">No hay conceptos que coincidan con los filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-muted">
                        {{ conceptos.meta.total }} concepto(s) · página {{ conceptos.meta.currentPage }} de
                        {{ conceptos.meta.lastPage }}
                    </p>
                    <div class="flex gap-2">
                        <UButton
                            color="neutral"
                            icon="i-lucide-chevron-left"
                            label="Anterior"
                            variant="outline"
                            :disabled="conceptos.meta.currentPage <= 1 || loading"
                            @click="reload(conceptos.meta.currentPage - 1)"
                        /><UButton
                            color="neutral"
                            icon="i-lucide-chevron-right"
                            label="Siguiente"
                            trailing
                            variant="outline"
                            :disabled="conceptos.meta.currentPage >= conceptos.meta.lastPage || loading"
                            @click="reload(conceptos.meta.currentPage + 1)"
                        />
                    </div>
                </div></div></template
    ></UDashboardPanel>
    <UModal
        v-model:open="statusOpen"
        :close="!statusLoading"
        :dismissible="!statusLoading"
        :title="selected?.estado === 'activo' ? 'Inactivar concepto' : 'Activar concepto'"
        :description="selected ? `¿Confirma cambiar el estado de ${selected.nombre}?` : ''"
        ><template #body><UAlert v-if="statusError" color="error" :description="statusError" icon="i-lucide-circle-alert" role="alert" variant="subtle" /></template
        ><template #footer
            ><div class="flex w-full justify-end gap-3">
                <UButton color="neutral" label="Cancelar" variant="outline" :disabled="statusLoading" @click="statusOpen = false" /><UButton
                    :color="selected?.estado === 'activo' ? 'error' : 'success'"
                    :label="selected?.estado === 'activo' ? 'Inactivar' : 'Activar'"
                    :loading="statusLoading"
                    @click="changeStatus"
                /></div></template
    ></UModal>
</template>
