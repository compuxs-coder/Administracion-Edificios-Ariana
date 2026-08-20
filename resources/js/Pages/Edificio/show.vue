<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Edificio, EstadoEdificio } from '../../types'

const props = defineProps<{ edificio: Edificio }>()
const isStatusModalOpen = ref(false)
const isChangingStatus = ref(false)

const nextStatus = computed<EstadoEdificio>(() => (
  props.edificio.estado === 'activo' ? 'inactivo' : 'activo'
))

const statusAction = computed(() => (
  nextStatus.value === 'activo' ? 'activar' : 'inactivar'
))

const changeStatus = () => {
  router.patch(route('edificios.estado', props.edificio.id), {
    estado: nextStatus.value
  }, {
    preserveScroll: true,
    onStart: () => { isChangingStatus.value = true },
    onSuccess: () => { isStatusModalOpen.value = false },
    onFinish: () => { isChangingStatus.value = false }
  })
}

const formatDate = (value: string) => new Intl.DateTimeFormat('es', {
  dateStyle: 'medium',
  timeStyle: 'short'
}).format(new Date(value))
</script>

<template>
  <UDashboardPanel id="edificios-show">
    <template #header>
      <UDashboardNavbar :title="edificio.nombre">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            color="neutral"
            icon="i-lucide-pencil"
            label="Editar"
            variant="outline"
            @click="router.visit(route('edificios.edit', edificio.id))"
          />
          <UButton
            :color="edificio.estado === 'activo' ? 'error' : 'success'"
            :icon="edificio.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'"
            :label="edificio.estado === 'activo' ? 'Inactivar' : 'Activar'"
            variant="subtle"
            @click="isStatusModalOpen = true"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto grid w-full max-w-5xl gap-6 p-4 sm:p-6 lg:grid-cols-[2fr_1fr]">
        <UCard>
          <template #header>
            <div class="flex items-center justify-between gap-4">
              <div>
                <p class="text-sm text-muted">Información general</p>
                <h1 class="mt-1 text-xl font-semibold text-highlighted">{{ edificio.nombre }}</h1>
              </div>
              <UBadge
                :color="edificio.estado === 'activo' ? 'success' : 'neutral'"
                :label="edificio.estado === 'activo' ? 'Activo' : 'Inactivo'"
                variant="subtle"
              />
            </div>
          </template>

          <dl class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">RUC</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ edificio.ruc || 'No registrado' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Ciudad</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ edificio.ciudad }}</dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Dirección</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ edificio.direccion }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Teléfono</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ edificio.telefono || 'No registrado' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Correo</dt>
              <dd class="mt-1 break-all text-sm text-highlighted">{{ edificio.correo || 'No registrado' }}</dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Responsable</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ edificio.responsable || 'No registrado' }}</dd>
            </div>
          </dl>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">Trazabilidad</p>
          </template>
          <dl class="space-y-5">
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Identificador</dt>
              <dd class="mt-1 break-all font-mono text-xs text-highlighted">{{ edificio.id }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Creado</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ formatDate(edificio.createdAt) }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-muted">Actualizado</dt>
              <dd class="mt-1 text-sm text-highlighted">{{ formatDate(edificio.updatedAt) }}</dd>
            </div>
          </dl>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="isStatusModalOpen"
    :close="!isChangingStatus"
    :dismissible="!isChangingStatus"
    :title="`${statusAction.charAt(0).toUpperCase() + statusAction.slice(1)} edificio`"
    :description="`¿Confirma que desea ${statusAction} ${edificio.nombre}?`"
  >
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton
          color="neutral"
          label="Cancelar"
          variant="outline"
          :disabled="isChangingStatus"
          @click="isStatusModalOpen = false"
        />
        <UButton
          :color="nextStatus === 'activo' ? 'success' : 'error'"
          :label="nextStatus === 'activo' ? 'Activar' : 'Inactivar'"
          :loading="isChangingStatus"
          @click="changeStatus"
        />
      </div>
    </template>
  </UModal>
</template>
