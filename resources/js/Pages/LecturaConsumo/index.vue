<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'
import type { LecturaConsumoOption, LecturasConsumoPaginadas } from '../../types'

const props = defineProps<{
  lecturas: LecturasConsumoPaginadas
  filters: { edificio_id?: string | null, departamento_id?: string | null, concepto_cobro_id?: string | null, periodo?: string | null }
  edificios: Array<{ id: string, nombre: string }>
  departamentos: LecturaConsumoOption[]
  conceptos: LecturaConsumoOption[]
}>()

const edificioId = ref(props.filters.edificio_id ?? '')
const departamentoId = ref(props.filters.departamento_id ?? '')
const conceptoId = ref(props.filters.concepto_cobro_id ?? '')
const periodo = ref(props.filters.periodo ?? '')
const loading = ref(false)
const { canAny } = useBuildingPermissions()
const departments = computed(() => props.departamentos
  .filter(item => !edificioId.value || item.edificioId === edificioId.value)
  .map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id })))
const concepts = computed(() => props.conceptos
  .filter(item => !edificioId.value || item.edificioId === edificioId.value)
  .map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id })))

const reload = (page = 1) => router.get(route('lecturas.index'), {
  edificio_id: edificioId.value || undefined,
  departamento_id: departamentoId.value || undefined,
  concepto_cobro_id: conceptoId.value || undefined,
  periodo: periodo.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace: true,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
const changeBuilding = () => {
  departamentoId.value = ''
  conceptoId.value = ''
  reload()
}
</script>

<template>
  <UDashboardPanel id="lecturas-consumo">
    <template #header>
      <UDashboardNavbar title="Lecturas de consumo">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('lecturas.registrar')" icon="i-lucide-plus" label="Registrar lectura" @click="router.visit(route('lecturas.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UAlert color="info" description="Las lecturas son acumulativas e inmutables. El consumo se calcula en el servidor como lectura actual menos lectura anterior." icon="i-lucide-shield-check" variant="subtle" />
        <div class="grid gap-3 xl:grid-cols-4">
          <USelect v-model="edificioId" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" placeholder="Edificio" size="xl" @update:model-value="changeBuilding" />
          <USelect v-model="departamentoId" :items="[{ label: 'Todos los departamentos', value: '' }, ...departments]" placeholder="Departamento" size="xl" @update:model-value="reload()" />
          <USelect v-model="conceptoId" :items="[{ label: 'Todos los conceptos', value: '' }, ...concepts]" placeholder="Concepto" size="xl" @update:model-value="reload()" />
          <UInput v-model="periodo" type="month" aria-label="Período" size="xl" @change="reload()" />
        </div>
        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-6xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="px-4 py-3">Período</th><th class="px-4 py-3">Departamento</th><th class="px-4 py-3">Concepto</th><th class="px-4 py-3">Fecha</th><th class="px-4 py-3">Anterior</th><th class="px-4 py-3">Actual</th><th class="px-4 py-3">Consumo</th><th class="px-4 py-3">Registró</th></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="lectura in lecturas.data" :key="lectura.id">
                <td class="px-4 py-3 font-mono text-highlighted">{{ lectura.periodo }}</td>
                <td class="px-4 py-3"><p class="font-medium text-highlighted">{{ lectura.departamento }}</p><p class="text-xs text-muted">{{ lectura.edificio }}</p></td>
                <td class="px-4 py-3"><p class="text-highlighted">{{ lectura.concepto }}</p><p class="text-xs text-muted">{{ lectura.codigoConcepto }}</p></td>
                <td class="px-4 py-3 text-muted">{{ lectura.fechaLectura }}</td>
                <td class="px-4 py-3 font-mono">{{ lectura.lecturaAnterior }}</td>
                <td class="px-4 py-3 font-mono">{{ lectura.lecturaActual }}</td>
                <td class="px-4 py-3 font-mono font-semibold text-highlighted">{{ lectura.consumo }} {{ lectura.unidad }}</td>
                <td class="px-4 py-3 text-muted">{{ lectura.registradoPor ?? 'Sistema' }}</td>
              </tr>
              <tr v-if="!lecturas.data.length"><td colspan="8" class="px-4 py-12 text-center text-muted">No hay lecturas con los filtros seleccionados.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="mt-auto flex items-center justify-between border-t border-default pt-4">
          <p class="text-sm text-muted">{{ lecturas.meta.total }} lectura(s) · página {{ lecturas.meta.currentPage }} de {{ lecturas.meta.lastPage }}</p>
          <div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="lecturas.meta.currentPage <= 1 || loading" @click="reload(lecturas.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="lecturas.meta.currentPage >= lecturas.meta.lastPage || loading" @click="reload(lecturas.meta.currentPage + 1)" /></div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
