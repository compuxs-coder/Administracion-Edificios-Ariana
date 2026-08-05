<script setup lang="ts">
import { reactive, computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import FormField from '../../components/FormField.vue'

// Definir que esta página no usa layout
defineOptions({
  layout: null
})

// Estado del formulario
const state = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
})

// Obtener errores de validación del backend
const page = usePage()
const backendErrors = computed(() => page.props.errors || {})

// Convertir errores de array a string (Laravel retorna arrays)
const errors = computed(() => {
  const result: Record<string, string> = {}
  Object.keys(backendErrors.value).forEach(key => {
    const error = backendErrors.value[key]
    result[key] = Array.isArray(error) ? error[0] : error
  })
  return result
})

// Loading state
const isLoading = ref(false)

// Submit handler
const handleSubmit = () => {
  isLoading.value = true

  router.post(route('register'), state, {
    onFinish: () => {
      isLoading.value = false
    },
    onError: (errors) => {
      console.error('Errores de validación:', errors)
    }
  })
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-background p-4">
    <div class="w-full max-w-md">
      <UCard>
        <template #header>
          <div class="flex items-center gap-3 mb-2">
            <div class="flex items-center justify-center w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-900">
              <i class="i-lucide-user-plus text-primary-600 dark:text-primary-400 text-xl"></i>
            </div>
            <div>
              <h2 class="text-2xl font-bold">Crear Cuenta</h2>
              <p class="text-sm text-muted">Completa el formulario para registrarte en el sistema</p>
            </div>
          </div>
        </template>

        <form @submit.prevent="handleSubmit" class="space-y-4">
          <FormField label="Nombre completo" name="name" required :error="errors.name">
            <UInput
              v-model="state.name"
              type="text"
              placeholder="Juan Pérez"
              icon="i-lucide-user"
              size="xl"
              class="w-full"
            />
          </FormField>

          <FormField label="Correo electrónico" name="email" required :error="errors.email">
            <UInput
              v-model="state.email"
              type="email"
              placeholder="tu@email.com"
              icon="i-lucide-mail"
              size="xl"
              class="w-full"
            />
          </FormField>

          <FormField label="Contraseña" name="password" required :error="errors.password">
            <UInput
              v-model="state.password"
              type="password"
              placeholder="••••••••"
              icon="i-lucide-lock"
              size="xl"
              class="w-full"
            />
          </FormField>

          <FormField label="Confirmar contraseña" name="password_confirmation" required :error="errors.password_confirmation">
            <UInput
              v-model="state.password_confirmation"
              type="password"
              placeholder="••••••••"
              icon="i-lucide-lock"
              size="xl"
              class="w-full"
            />
          </FormField>

          <UButton
            type="submit"
            color="primary"
            label="Registrarse"
            :loading="isLoading"
            block
            size="xl"
          />
        </form>

        <template #footer>
          <div class="text-center text-sm">
            <span class="text-muted">¿Ya tienes una cuenta?</span>
            <UButton
              :to="route('login')"
              variant="link"
              color="primary"
              label="Inicia sesión"
              :padded="false"
              class="ml-1"
            />
          </div>
        </template>
      </UCard>
    </div>
  </div>
</template>
