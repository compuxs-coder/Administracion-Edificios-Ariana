<script setup lang="ts">
import { computed, reactive } from 'vue'
import type { ProveedorFormData, TipoIdentificacion, TipoPersona } from '../../types'

const props = withDefaults(defineProps<{
  edificios?: Array<{ id: string, nombre: string }>
  selectedEdificioId?: string | null
  initial?: Partial<ProveedorFormData>
  errors?: Record<string, string>
  loading?: boolean
  requireEdificio?: boolean
  lockIdentity?: boolean
  submitLabel?: string
}>(), {
  edificios: () => [],
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  requireEdificio: false,
  lockIdentity: false,
  submitLabel: 'Guardar proveedor'
})

const emit = defineEmits<{
  submit: [data: ProveedorFormData]
  cancel: []
}>()

const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive<ProveedorFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  tipo_persona: props.initial.tipo_persona ?? 'persona_juridica',
  nombres: props.initial.nombres ?? '',
  apellidos: props.initial.apellidos ?? '',
  razon_social: props.initial.razon_social ?? '',
  tipo_identificacion: props.initial.tipo_identificacion ?? 'ruc',
  identificacion: props.initial.identificacion ?? '',
  telefono: props.initial.telefono ?? '',
  celular: props.initial.celular ?? '',
  correo: props.initial.correo ?? '',
  direccion: props.initial.direccion ?? '',
  nombre_comercial: props.initial.nombre_comercial ?? '',
  contacto: props.initial.contacto ?? '',
  telefono_comercial: props.initial.telefono_comercial ?? '',
  correo_comercial: props.initial.correo_comercial ?? '',
  direccion_comercial: props.initial.direccion_comercial ?? '',
  dias_credito: props.initial.dias_credito ?? '',
  observaciones: props.initial.observaciones ?? ''
})

const isNatural = computed(() => state.tipo_persona === 'persona_natural')
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
</script>

<template>
  <form class="space-y-7" @submit.prevent="emit('submit', { ...state })">
    <section v-if="requireEdificio" class="space-y-4">
      <div><p class="text-sm font-semibold text-highlighted">Alcance administrativo</p><p class="mt-1 text-xs text-muted">Los datos comerciales pertenecen al edificio seleccionado; la identidad se reutiliza globalmente.</p></div>
      <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
        <USelect v-model="state.edificio_id" class="w-full" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" />
      </UFormField>
    </section>

    <section class="space-y-4" :class="requireEdificio ? 'border-t border-default pt-6' : ''">
      <div><p class="text-sm font-semibold text-highlighted">Identidad global</p><p class="mt-1 text-xs text-muted">La identificación permite reconocer al mismo tercero en distintos edificios.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Tipo de persona" name="tipo_persona" required :error="errors.tipoPersona"><USelect v-model="state.tipo_persona" class="w-full" :disabled="lockIdentity" :items="tipoPersonaItems" required size="xl" /></UFormField>
        <UFormField label="Tipo de identificación" name="tipo_identificacion" required :error="errors.tipoIdentificacion"><USelect v-model="state.tipo_identificacion" class="w-full" :disabled="lockIdentity" :items="identificacionItems" required size="xl" /></UFormField>
        <UFormField label="Identificación" name="identificacion" required :error="errors.identificacion"><UInput v-model="state.identificacion" class="w-full" :disabled="lockIdentity" icon="i-lucide-id-card" required size="xl" /></UFormField>
        <template v-if="isNatural">
          <UFormField label="Nombres" name="nombres" required :error="errors.nombres"><UInput v-model="state.nombres" class="w-full" :disabled="lockIdentity" required size="xl" /></UFormField>
          <UFormField label="Apellidos" name="apellidos" required :error="errors.apellidos"><UInput v-model="state.apellidos" class="w-full" :disabled="lockIdentity" required size="xl" /></UFormField>
        </template>
        <UFormField v-else label="Razón social" name="razon_social" required :error="errors.razonSocial"><UInput v-model="state.razon_social" class="w-full" :disabled="lockIdentity" icon="i-lucide-building" required size="xl" /></UFormField>
        <UFormField label="Teléfono" name="telefono" hint="Opcional" :error="errors.telefono"><UInput v-model="state.telefono" class="w-full" :disabled="lockIdentity" icon="i-lucide-phone" size="xl" /></UFormField>
        <UFormField label="Celular" name="celular" hint="Opcional" :error="errors.celular"><UInput v-model="state.celular" class="w-full" :disabled="lockIdentity" icon="i-lucide-smartphone" size="xl" /></UFormField>
        <UFormField label="Correo" name="correo" hint="Opcional" :error="errors.correo"><UInput v-model="state.correo" class="w-full" :disabled="lockIdentity" icon="i-lucide-mail" type="email" size="xl" /></UFormField>
        <UFormField label="Dirección" name="direccion" hint="Opcional" :error="errors.direccion"><UInput v-model="state.direccion" class="w-full" :disabled="lockIdentity" icon="i-lucide-map-pin" size="xl" /></UFormField>
      </div>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Perfil comercial local</p><p class="mt-1 text-xs text-muted">Información operativa utilizada únicamente por este edificio.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Nombre comercial" name="nombre_comercial" hint="Opcional" :error="errors.nombreComercial"><UInput v-model="state.nombre_comercial" class="w-full" icon="i-lucide-store" size="xl" /></UFormField>
        <UFormField label="Persona de contacto" name="contacto" hint="Opcional" :error="errors.contacto"><UInput v-model="state.contacto" class="w-full" icon="i-lucide-contact" size="xl" /></UFormField>
        <UFormField label="Teléfono comercial" name="telefono_comercial" hint="Opcional" :error="errors.telefonoComercial"><UInput v-model="state.telefono_comercial" class="w-full" icon="i-lucide-phone-call" size="xl" /></UFormField>
        <UFormField label="Correo comercial" name="correo_comercial" hint="Opcional" :error="errors.correoComercial"><UInput v-model="state.correo_comercial" class="w-full" icon="i-lucide-at-sign" type="email" size="xl" /></UFormField>
        <UFormField label="Dirección comercial" name="direccion_comercial" hint="Opcional" :error="errors.direccionComercial"><UInput v-model="state.direccion_comercial" class="w-full" icon="i-lucide-map-pin" size="xl" /></UFormField>
        <UFormField label="Días de crédito" name="dias_credito" hint="Opcional" :error="errors.diasCredito"><UInput v-model="state.dias_credito" class="w-full" inputmode="numeric" min="0" type="number" size="xl" /></UFormField>
      </div>
      <UFormField label="Observaciones" name="observaciones" hint="Opcional" :error="errors.observaciones"><UTextarea v-model="state.observaciones" class="w-full" :rows="3" /></UFormField>
    </section>

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="requireEdificio && !state.edificio_id" />
    </div>
  </form>
</template>
