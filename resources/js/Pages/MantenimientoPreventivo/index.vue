<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EdificioOperacionOption, EstadoPlanMantenimientoPreventivo, PaginationMeta, PlanMantenimientoPreventivoResumen, UnidadRecurrenciaMantenimiento } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ planes: { data: PlanMantenimientoPreventivoResumen[], meta: PaginationMeta }, filters: { buscar?: string | null, edificio_id?: string | null, estado?: EstadoPlanMantenimientoPreventivo | null, unidad_recurrencia?: UnidadRecurrenciaMantenimiento | null }, edificios: EdificioOperacionOption[] }>()
const buscar = ref(props.filters.buscar ?? ''); const edificioId = ref(props.filters.edificio_id ?? ''); const estado = ref(props.filters.estado ?? ''); const unidad = ref(props.filters.unidad_recurrencia ?? ''); const loading = ref(false)
const { can, canAny } = useBuildingPermissions(); let timer: ReturnType<typeof setTimeout> | undefined
const unitLabels: Record<UnidadRecurrenciaMantenimiento, string> = { diaria: 'día(s)', semanal: 'semana(s)', mensual: 'mes(es)', anual: 'año(s)' }
const buildingName = (id: string) => props.edificios.find(item => item.id === id)?.nombre ?? 'Edificio no disponible'
const reload = (page = 1, replace = false) => router.get(route('mantenimiento-preventivo.index'), { buscar: buscar.value || undefined, edificio_id: edificioId.value || undefined, estado: estado.value || undefined, unidad_recurrencia: unidad.value || undefined, page }, { preserveState: true, preserveScroll: true, replace, onStart: () => { loading.value = true }, onFinish: () => { loading.value = false } })
watch(buscar, () => { clearTimeout(timer); timer = setTimeout(() => reload(1, true), 300) }); onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <UDashboardPanel id="mantenimiento-preventivo">
    <template #header><UDashboardNavbar title="Mantenimiento preventivo"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton v-if="canAny('mantenimiento_preventivo.gestionar')" icon="i-lucide-plus" label="Nuevo plan" @click="router.visit(route('mantenimiento-preventivo.create'))" /></template></UDashboardNavbar></template>
    <template #body><div class="flex h-full flex-col gap-5 p-4 sm:p-6">
      <div class="rounded-2xl border border-primary/20 bg-gradient-to-br from-primary/10 via-default to-default p-5 sm:p-6"><div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Programación operativa</p><h1 class="mt-2 text-2xl font-semibold text-highlighted">Rutinas que se convierten en trabajo trazable</h1><p class="mt-2 max-w-3xl text-sm text-muted">El scheduler genera órdenes reportadas sin asignar responsables ni crear movimientos financieros.</p></div><UButton color="neutral" icon="i-lucide-clipboard-wrench" label="Ver órdenes" variant="outline" @click="router.visit(route('operaciones.index'))" /></div></div>
      <UCard><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><UInput v-model="buscar" aria-label="Buscar planes" icon="i-lucide-search" maxlength="100" placeholder="Código, título o descripción" /><USelect v-model="edificioId" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="reload(1, true)" /><USelect v-model="estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activo', value: 'activo' }, { label: 'Inactivo', value: 'inactivo' }]" @update:model-value="reload(1, true)" /><USelect v-model="unidad" :items="[{ label: 'Todas las frecuencias', value: '' }, { label: 'Diaria', value: 'diaria' }, { label: 'Semanal', value: 'semanal' }, { label: 'Mensual', value: 'mensual' }, { label: 'Anual', value: 'anual' }]" @update:model-value="reload(1, true)" /></div></UCard>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" :class="loading ? 'opacity-60' : ''">
        <article v-for="plan in planes.data" :key="plan.id" class="group flex min-h-64 flex-col rounded-2xl border border-default bg-default p-5 transition hover:border-primary/40 hover:shadow-lg">
          <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="font-mono text-sm font-semibold text-primary">{{ plan.codigo }}</p><h2 class="mt-1 line-clamp-2 text-lg font-semibold text-highlighted">{{ plan.titulo }}</h2><p class="mt-1 text-xs text-muted">{{ buildingName(plan.edificioId) }}</p></div><UBadge :color="plan.estado === 'activo' ? 'success' : 'neutral'" :label="plan.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></div>
          <div class="mt-5 grid grid-cols-2 gap-3 text-sm"><div class="rounded-xl bg-elevated/50 p-3"><p class="text-xs text-muted">Recurrencia</p><p class="mt-1 font-medium">Cada {{ plan.intervaloRecurrencia }} {{ unitLabels[plan.unidadRecurrencia] }}</p></div><div class="rounded-xl bg-elevated/50 p-3"><p class="text-xs text-muted">Próxima fecha</p><p class="mt-1 font-medium">{{ plan.proximaFechaProgramada || 'Sin programar' }}</p></div></div>
          <UAlert v-if="plan.ocurrenciasBloqueadas" class="mt-4" color="error" icon="i-lucide-triangle-alert" :description="`${plan.ocurrenciasBloqueadas} ocurrencia(s) requieren atención`" variant="subtle" />
          <div class="mt-auto flex justify-end gap-2 pt-5"><UButton v-if="plan.estado === 'inactivo' && can('mantenimiento_preventivo.gestionar', plan.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar plan" @click="router.visit(route('mantenimiento-preventivo.edit', [plan.edificioId, plan.id]))" /><UButton icon="i-lucide-arrow-right" label="Abrir plan" variant="soft" @click="router.visit(route('mantenimiento-preventivo.show', [plan.edificioId, plan.id]))" /></div>
        </article>
        <p v-if="!planes.data.length" class="col-span-full rounded-2xl border border-dashed border-default p-12 text-center text-muted">No hay planes preventivos que coincidan con los filtros.</p>
      </div>
      <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ planes.meta.total }} plan(es) · página {{ planes.meta.currentPage }} de {{ planes.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="planes.meta.currentPage <= 1 || loading" @click="reload(planes.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="planes.meta.currentPage >= planes.meta.lastPage || loading" @click="reload(planes.meta.currentPage + 1)" /></div></div>
    </div></template>
  </UDashboardPanel>
</template>
