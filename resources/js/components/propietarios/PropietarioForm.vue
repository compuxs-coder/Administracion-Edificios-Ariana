<script setup lang="ts">
import { computed, reactive } from 'vue'
import type { PropietarioFormData, TipoIdentificacion, TipoPersona } from '../../types'

const props = withDefaults(defineProps<{
  edificios?: Array<{ id: string, nombre: string }>
  selectedEdificioId?: string | null
  initial?: Partial<PropietarioFormData>
  errors?: Record<string, string>
  loading?: boolean
  requireEdificio?: boolean
  submitLabel?: string
}>(), {
  edificios: () => [],
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  requireEdificio: false,
  submitLabel: 'Guardar propietario'
})

const emit = defineEmits<{
  submit: [data: PropietarioFormData]
  cancel: []
}>()

const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive<PropietarioFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  tipo_persona: props.initial.tipo_persona ?? 'persona_natural',
  nombres: props.initial.nombres ?? '',
  apellidos: props.initial.apellidos ?? '',
  razon_social: props.initial.razon_social ?? '',
  tipo_identificacion: props.initial.tipo_identificacion ?? 'cedula',
  identificacion: props.initial.identificacion ?? '',
  telefono: props.initial.telefono ?? '',
  celular: props.initial.celular ?? '',
  correo: props.initial.correo ?? '',
  direccion: props.initial.direccion ?? '',
  observaciones: props.initial.observaciones ?? ''
})

const isNatural = computed(() => state.tipo_persona === 'persona_natural')
const edificioItems = computed(() => props.edificios.map(item => ({ label: item.nombre, value: item.id })))
const tipoPersonaItems: Array<{ label: string, value: TipoPersona }> = [
  { label: 'Persona natural', value: 'persona_natural' },
  { label: 'Persona jurídica', value: 'persona_juridica' }
]
const identificacionItems: Array<{ label: string, value: TipoIdentificacion }> = [
  { label: 'Cédula', value: 'cedula' },
  { label: 'RUC', value: 'ruc' },
  { label: 'Pasaporte', value: 'pasaporte' },
  { label: 'Otro', value: 'otro' }
]

const submit = () => emit('submit', { ...state })
</script>

<template>
  <form class="space-y-7" @submit.prevent="submit">
    <section v-if="requireEdificio" class="space-y-4">
      <div>
        <p class="text-sm font-semibold text-highlighted">Directorio administrativo</p>
        <p class="mt-1 text-xs text-muted">El edificio define qué administradores pueden consultar inicialmente este propietario.</p>
      </div>
      <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
        <USelect v-model="state.edificio_id" class="w-full" :items="edificioItems" placeholder="Seleccione edificio" required size="xl" />
      </UFormField>
    </section>

    <section class="space-y-4" :class="requireEdificio ? 'border-t border-default pt-6' : ''">
      <div>
        <p class="text-sm font-semibold text-highlighted">Identidad</p>
        <p class="mt-1 text-xs text-muted">La identificación es única dentro de su tipo y evita registros duplicados.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Tipo de persona" name="tipo_persona" required :error="errors.tipoPersona">
          <USelect v-model="state.tipo_persona" class="w-full" :items="tipoPersonaItems" required size="xl" />
        </UFormField>
        <UFormField label="Tipo de identificación" name="tipo_identificacion" required :error="errors.tipoIdentificacion">
          <USelect v-model="state.tipo_identificacion" class="w-full" :items="identificacionItems" required size="xl" />
        </UFormField>
        <UFormField label="Identificación" name="identificacion" required :error="errors.identificacion">
          <UInput v-model="state.identificacion" class="w-full" icon="i-lucide-id-card" placeholder="Número de identificación" required size="xl" />
        </UFormField>
        <template v-if="isNatural">
          <UFormField label="Nombres" name="nombres" required :error="errors.nombres">
            <UInput v-model="state.nombres" class="w-full" icon="i-lucide-user" placeholder="Nombres" required size="xl" />
          </UFormField>
          <UFormField label="Apellidos" name="apellidos" required :error="errors.apellidos">
            <UInput v-model="state.apellidos" class="w-full" icon="i-lucide-user-round" placeholder="Apellidos" required size="xl" />
          </UFormField>
        </template>
        <UFormField v-else label="Razón social" name="razon_social" required :error="errors.razonSocial">
          <UInput v-model="state.razon_social" class="w-full" icon="i-lucide-building" placeholder="Razón social" required size="xl" />
        </UFormField>
      </div>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Contacto</p>
        <p class="mt-1 text-xs text-muted">Registre únicamente información de contacto verificada.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Teléfono" name="telefono" hint="Opcional" :error="errors.telefono">
          <UInput v-model="state.telefono" class="w-full" icon="i-lucide-phone" placeholder="Teléfono fijo" size="xl" />
        </UFormField>
        <UFormField label="Celular" name="celular" hint="Opcional" :error="errors.celular">
          <UInput v-model="state.celular" class="w-full" icon="i-lucide-smartphone" placeholder="Teléfono móvil" size="xl" />
        </UFormField>
        <UFormField label="Correo electrónico" name="correo" hint="Opcional" :error="errors.correo">
          <UInput v-model="state.correo" class="w-full" icon="i-lucide-mail" placeholder="propietario@correo.com" type="email" size="xl" />
        </UFormField>
        <UFormField label="Dirección" name="direccion" hint="Opcional" :error="errors.direccion">
          <UInput v-model="state.direccion" class="w-full" icon="i-lucide-map-pin" placeholder="Dirección de contacto" size="xl" />
        </UFormField>
      </div>
      <UFormField label="Observaciones" name="observaciones" hint="Opcional" :error="errors.observaciones">
        <UTextarea v-model="state.observaciones" class="w-full" :rows="3" placeholder="Notas administrativas" />
      </UFormField>
    </section>

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="requireEdificio && !state.edificio_id" />
    </div>
  </form>
</template>
