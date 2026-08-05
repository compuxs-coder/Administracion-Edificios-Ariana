<script setup lang="ts">
import { reactive } from 'vue'

const state = reactive({
  name: '',
  email: '',
  bio: ''
})
</script>

<template>
  <UCard>
    <template #header>
      <h3 class="text-lg font-semibold">Perfil General</h3>
      <p class="text-sm text-muted">Administra tu información personal</p>
    </template>

    <div class="space-y-4">
      <UFormField label="Nombre" name="name">
        <UInput v-model="state.name" placeholder="Tu nombre" />
      </UFormField>

      <UFormField label="Email" name="email">
        <UInput v-model="state.email" type="email" placeholder="tu@email.com" />
      </UFormField>

      <UFormField label="Biografía" name="bio">
        <UTextarea v-model="state.bio" placeholder="Escribe algo sobre ti..." :rows="4" />
      </UFormField>
    </div>

    <template #footer>
      <div class="flex justify-end">
        <UButton label="Guardar Cambios" color="primary" />
      </div>
    </template>
  </UCard>
</template>
