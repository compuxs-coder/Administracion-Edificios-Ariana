<script setup lang="ts">
import { reactive, ref } from 'vue'
import type { ComunicadoFormData, EdificioComunicacionOption } from '../../types'

const props = withDefaults(defineProps<{
  edificios: EdificioComunicacionOption[]
  selectedEdificioId?: string | null
  initial?: Partial<ComunicadoFormData>
  errors?: Record<string, string>
  loading?: boolean
  lockBuilding?: boolean
  submitLabel?: string
}>(), {
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  lockBuilding: false,
  submitLabel: 'Guardar borrador'
})

const emit = defineEmits<{
  submit: [data: ComunicadoFormData]
  cancel: []
}>()

const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  titulo: props.initial.titulo ?? '',
  cuerpo: props.initial.cuerpo ?? '',
  updated_at: props.initial.updated_at
})
const owners = ref(props.initial.audiencias?.includes('propietarios') ?? false)
const residents = ref(props.initial.audiencias?.includes('residentes') ?? false)

const submit = () => emit('submit', {
  edificio_id: state.edificio_id,
  titulo: state.titulo,
  cuerpo: state.cuerpo,
  audiencias: [owners.value ? 'propietarios' : null, residents.value ? 'residentes' : null].filter((value): value is 'propietarios' | 'residentes' => value !== null),
  ...(state.updated_at ? { updated_at: state.updated_at } : {})
})
</script>

<template>
  <form class="space-y-7" @submit.prevent="submit">
    <section class="space-y-4">
      <div>
        <p class="text-sm font-semibold text-highlighted">Contenido del comunicado</p>
        <p class="mt-1 text-xs text-muted">El contenido se enviará como texto seguro y quedará congelado al publicar.</p>
      </div>
      <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
        <USelect v-model="state.edificio_id" class="w-full" :disabled="lockBuilding" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" />
      </UFormField>
      <UFormField label="Título" name="titulo" required :error="errors.titulo">
        <UInput v-model="state.titulo" class="w-full" maxlength="180" placeholder="Ej. Mantenimiento programado del sistema eléctrico" required size="xl" />
      </UFormField>
      <UFormField label="Mensaje" name="cuerpo" required :error="errors.cuerpo">
        <UTextarea v-model="state.cuerpo" class="w-full" :rows="12" maxlength="30000" placeholder="Describa la información, fechas y recomendaciones para la comunidad." required />
      </UFormField>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Audiencia vigente</p>
        <p class="mt-1 text-xs text-muted">Los destinatarios se resolverán nuevamente al publicar usando la fecha local del edificio.</p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-default p-4" :class="owners ? 'bg-primary/5 ring-1 ring-primary' : ''">
          <UCheckbox v-model="owners" class="mt-0.5" />
          <span><span class="block font-medium text-highlighted">Propietarios</span><span class="mt-1 block text-xs text-muted">Titularidades vigentes y perfiles activos.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-default p-4" :class="residents ? 'bg-primary/5 ring-1 ring-primary' : ''">
          <UCheckbox v-model="residents" class="mt-0.5" />
          <span><span class="block font-medium text-highlighted">Residentes</span><span class="mt-1 block text-xs text-muted">Ocupaciones vigentes y perfiles activos.</span></span>
        </label>
      </div>
      <p v-if="errors.audiencias" class="text-sm text-error">{{ errors.audiencias }}</p>
      <UAlert color="info" icon="i-lucide-shield-check" variant="subtle" description="Una misma identidad o dirección de correo recibirá una sola entrega, aunque pertenezca a ambas audiencias o a varios departamentos." />
    </section>

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.titulo.trim() || !state.cuerpo.trim() || (!owners && !residents)" />
    </div>
  </form>
</template>
