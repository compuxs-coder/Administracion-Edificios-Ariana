<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { LecturaConsumoFormData, LecturaConsumoOption, UltimaLecturaConsumo } from '../../types'

const props = defineProps<{
  edificios: Array<{ id: string, nombre: string }>
  departamentos: LecturaConsumoOption[]
  conceptos: LecturaConsumoOption[]
  ultimasLecturas: UltimaLecturaConsumo[]
  edificioSeleccionado?: string | null
}>()

const now = new Date()
const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-')
const currentPeriod = today.slice(0, 7)
const selectedBuilding = props.edificios.some(item => item.id === props.edificioSeleccionado)
  ? props.edificioSeleccionado ?? ''
  : (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : '')
const form = reactive<LecturaConsumoFormData>({
  edificio_id: selectedBuilding,
  departamento_id: '',
  concepto_cobro_id: '',
  periodo: currentPeriod,
  fecha_lectura: today,
  lectura_anterior: '',
  lectura_actual: '',
  observacion: ''
})
const errors = ref<Record<string, string>>({})
const loading = ref(false)
const departments = computed(() => props.departamentos
  .filter(item => item.edificioId === form.edificio_id)
  .map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id })))
const concepts = computed(() => props.conceptos
  .filter(item => item.edificioId === form.edificio_id)
  .map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id })))
const ultimaLectura = computed(() => props.ultimasLecturas.find(item =>
  item.edificioId === form.edificio_id
  && item.departamentoId === form.departamento_id
  && item.conceptoId === form.concepto_cobro_id
) ?? null)

watch(() => [form.edificio_id, form.departamento_id, form.concepto_cobro_id], () => {
  form.lectura_anterior = ultimaLectura.value?.lecturaActual ?? ''
})
watch(() => form.periodo, periodo => {
  if (periodo && !form.fecha_lectura.startsWith(periodo)) form.fecha_lectura = `${periodo}-01`
})

const changeBuilding = () => {
  form.departamento_id = ''
  form.concepto_cobro_id = ''
  form.lectura_anterior = ''
}
const submit = () => {
  if (!form.edificio_id) return
  const { edificio_id: _building, ...payload } = form
  router.post(route('lecturas.store', form.edificio_id), payload, {
    onStart: () => { loading.value = true },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="lecturas-consumo-create">
    <template #header><UDashboardNavbar title="Registrar lectura"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <form class="space-y-6" @submit.prevent="submit">
          <UAlert color="warning" description="La lectura quedará en el historial y no podrá editarse ni eliminarse. Verifique los valores antes de registrar." icon="i-lucide-history" variant="subtle" />
          <div class="grid gap-4 md:grid-cols-2">
            <UFormField label="Edificio" required :error="errors.edificioId"><USelect v-model="form.edificio_id" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" @update:model-value="changeBuilding" /></UFormField>
            <UFormField label="Departamento" required :error="errors.departamentoId"><USelect v-model="form.departamento_id" :items="departments" placeholder="Seleccione departamento" required size="xl" /></UFormField>
            <UFormField label="Concepto de consumo" required :error="errors.conceptoCobroId"><USelect v-model="form.concepto_cobro_id" :items="concepts" placeholder="Seleccione concepto" required size="xl" /></UFormField>
            <UFormField label="Período" required :error="errors.periodo"><UInput v-model="form.periodo" type="month" required size="xl" /></UFormField>
            <UFormField label="Fecha de lectura" required :error="errors.fechaLectura"><UInput v-model="form.fecha_lectura" type="date" :min="form.periodo ? `${form.periodo}-01` : undefined" :max="form.periodo ? `${form.periodo}-31` : undefined" required size="xl" /></UFormField>
            <div class="hidden md:block" />
            <UFormField label="Lectura anterior" required :hint="ultimaLectura ? 'Tomada de la última lectura válida' : 'Línea base inicial'" :error="errors.lecturaAnterior"><UInput v-model="form.lectura_anterior" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?" placeholder="0.0000" :readonly="Boolean(ultimaLectura)" required size="xl" /></UFormField>
            <UFormField label="Lectura actual" required :error="errors.lecturaActual"><UInput v-model="form.lectura_actual" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?" placeholder="0.0000" required size="xl" /></UFormField>
          </div>
          <UAlert v-if="ultimaLectura" color="info" :description="`Última lectura: ${ultimaLectura.lecturaActual} ${ultimaLectura.unidad}, período ${ultimaLectura.periodo}. El nuevo período debe ser posterior.`" icon="i-lucide-gauge" variant="subtle" />
          <UAlert v-else-if="form.departamento_id && form.concepto_cobro_id" color="info" description="Esta es la primera lectura de la secuencia. Ingrese la línea base en lectura anterior." icon="i-lucide-info" variant="subtle" />
          <UFormField label="Observación" hint="Opcional" :error="errors.observacion"><UTextarea v-model="form.observacion" :rows="3" maxlength="2000" /></UFormField>
          <p class="text-xs text-muted">El consumo no se recibe desde el navegador. El servidor calcula y almacena lectura actual menos lectura anterior.</p>
          <div class="flex justify-end gap-3 border-t border-default pt-5"><UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="router.visit(route('lecturas.index'))" /><UButton icon="i-lucide-save" label="Registrar lectura" type="submit" :loading="loading" :disabled="!form.edificio_id || !form.departamento_id || !form.concepto_cobro_id" /></div>
        </form>
      </div>
    </template>
  </UDashboardPanel>
</template>
