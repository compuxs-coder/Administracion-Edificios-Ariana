<script setup lang="ts">
import { reactive } from 'vue'
import type { EdificioFormData } from '../../types'

const props = withDefaults(defineProps<{
  initial?: Partial<EdificioFormData>
  errors?: Record<string, string>
  loading?: boolean
  submitLabel?: string
}>(), {
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  submitLabel: 'Guardar edificio'
})

const emit = defineEmits<{
  submit: [data: EdificioFormData]
  cancel: []
}>()

const state = reactive<EdificioFormData>({
  nombre: props.initial.nombre ?? '',
  ruc: props.initial.ruc ?? '',
  direccion: props.initial.direccion ?? '',
  ciudad: props.initial.ciudad ?? '',
  telefono: props.initial.telefono ?? '',
  correo: props.initial.correo ?? '',
  responsable: props.initial.responsable ?? ''
})

const handleSubmit = () => {
  emit('submit', { ...state })
}
</script>

<template>
  <form class="space-y-6" @submit.prevent="handleSubmit">
    <div class="grid gap-6 md:grid-cols-2">
      <UFormField
        label="Nombre"
        name="nombre"
        required
        :error="errors.nombre"
      >
        <UInput
          v-model="state.nombre"
          class="w-full"
          icon="i-lucide-building-2"
          placeholder="Edificio Ariana"
          required
          size="xl"
        />
      </UFormField>

      <UFormField
        label="RUC"
        name="ruc"
        hint="Opcional"
        :error="errors.ruc"
      >
        <UInput
          v-model="state.ruc"
          class="w-full"
          icon="i-lucide-badge-info"
          placeholder="Identificación tributaria"
          size="xl"
        />
      </UFormField>

      <UFormField
        label="Dirección"
        name="direccion"
        required
        :error="errors.direccion"
      >
        <UInput
          v-model="state.direccion"
          class="w-full"
          icon="i-lucide-map-pin"
          placeholder="Calle, número y referencia"
          required
          size="xl"
        />
      </UFormField>

      <UFormField
        label="Ciudad"
        name="ciudad"
        required
        :error="errors.ciudad"
      >
        <UInput
          v-model="state.ciudad"
          class="w-full"
          icon="i-lucide-map"
          placeholder="Ciudad"
          required
          size="xl"
        />
      </UFormField>

      <UFormField
        label="Teléfono"
        name="telefono"
        hint="Opcional"
        :error="errors.telefono"
      >
        <UInput
          v-model="state.telefono"
          class="w-full"
          icon="i-lucide-phone"
          placeholder="Teléfono de administración"
          size="xl"
        />
      </UFormField>

      <UFormField
        label="Correo"
        name="correo"
        hint="Opcional"
        :error="errors.correo"
      >
        <UInput
          v-model="state.correo"
          class="w-full"
          icon="i-lucide-mail"
          placeholder="administracion@edificio.com"
          size="xl"
          type="email"
        />
      </UFormField>
    </div>

    <UFormField
      label="Administrador o responsable"
      name="responsable"
      hint="Opcional"
      :error="errors.responsable"
    >
      <UInput
        v-model="state.responsable"
        class="w-full"
        icon="i-lucide-user-round-check"
        placeholder="Nombre de la persona responsable"
        size="xl"
      />
    </UFormField>

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton
        color="neutral"
        label="Cancelar"
        type="button"
        variant="outline"
        :disabled="loading"
        @click="emit('cancel')"
      />
      <UButton
        color="primary"
        icon="i-lucide-save"
        type="submit"
        :label="submitLabel"
        :loading="loading"
      />
    </div>
  </form>
</template>
