<script setup lang="ts">
import { reactive, ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import FormField from '../../components/FormField.vue'

// Estado del formulario
const state = reactive({
  tipoDocumento: 'DNI',
  numeroDocumento: '',
  razonSocial: '',
  direccion: '',
  telefono: '',
  email: ''
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
  console.log('Errores procesados:', result)
  return result
})

// Loading state
const isLoading = ref(false)

// Submit handler
const handleSubmit = () => {
  isLoading.value = true

  router.post(route('clientes.store'), state, {
    onSuccess: () => {
      // La redirección la maneja Inertia automáticamente
    },
    onFinish: () => {
      isLoading.value = false
    },
    onError: (errors) => {
      console.error('Errores de validación:', errors)
      isLoading.value = false
    }
  })
}

const handleCancel = () => {
  router.visit(route('clientes.index'))
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar title="Crear Cliente">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-6">
        <div class="mb-6">
          <h2 class="text-2xl font-semibold">Nuevo Cliente</h2>
          <p class="text-sm text-muted mt-1">Complete los datos del cliente</p>
        </div>

        <form @submit.prevent="handleSubmit" class="space-y-6">
          <!-- Fila 1: Tipo y Número de Documento -->
          <div class="grid grid-cols-2 gap-8">
              <FormField label="Tipo de Documento" name="tipoDocumento" required :error="errors.tipoDocumento">
                <USelect
                  v-model="state.tipoDocumento"
                  :items="[
                    { label: 'DNI', value: 'DNI' },
                    { label: 'RUC', value: 'RUC' },
                    { label: 'CE', value: 'CE' },
                    { label: 'PASAPORTE', value: 'PASAPORTE' }
                  ]"
                  placeholder="Seleccione tipo de documento"
                  size="xl"
                  class="w-full"
                />
              </FormField>

              <FormField label="Número de Documento" name="numeroDocumento" required :error="errors.numeroDocumento">
                <UInput
                  v-model="state.numeroDocumento"
                  placeholder="Ingrese el número de documento"
                  icon="i-lucide-credit-card"
                  size="xl"
                  class="w-full"
                />
              </FormField>
            </div>

            <!-- Fila 2: Razón Social y Dirección -->
            <div class="grid grid-cols-2 gap-8">
              <FormField label="Razón Social" name="razonSocial" required :error="errors.razonSocial">
                <UInput
                  v-model="state.razonSocial"
                  placeholder="Ingrese la razón social"
                  icon="i-lucide-building"
                  size="xl"
                  class="w-full"
                />
              </FormField>

              <FormField label="Dirección" name="direccion" required :error="errors.direccion">
                <UInput
                  v-model="state.direccion"
                  placeholder="Ingrese la dirección"
                  icon="i-lucide-map-pin"
                  size="xl"
                  class="w-full"
                />
              </FormField>
            </div>

            <!-- Fila 3: Teléfono y Email -->
            <div class="grid grid-cols-2 gap-8">
              <FormField label="Teléfono" name="telefono" required :error="errors.telefono">
                <UInput
                  v-model="state.telefono"
                  placeholder="Ingrese el teléfono"
                  icon="i-lucide-phone"
                  size="xl"
                  class="w-full"
                />
              </FormField>

              <FormField label="Email" name="email" required :error="errors.email">
                <UInput
                  v-model="state.email"
                  type="email"
                  placeholder="cliente@ejemplo.com"
                  icon="i-lucide-mail"
                  size="xl"
                  class="w-full"
                />
              </FormField>
            </div>

          <!-- Botones -->
          <div class="flex justify-end gap-3 pt-4">
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              label="Cancelar"
              @click="handleCancel"
              :disabled="isLoading"
            />
            <UButton
              type="submit"
              color="primary"
              label="Guardar Cliente"
              icon="i-lucide-save"
              :loading="isLoading"
            />
          </div>
        </form>
      </div>
    </template>
  </UDashboardPanel>
</template>
