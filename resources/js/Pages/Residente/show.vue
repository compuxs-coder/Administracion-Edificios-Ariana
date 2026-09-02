<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Residente, TipoOcupacion } from '../../types'

const props = defineProps<{ residente: Residente }>()
const section = ref<'actuales' | 'historial'>('actuales')
const statusOpen = ref(false)
const statusLoading = ref(false)
const statusError = ref('')
const typeLabels: Record<TipoOcupacion, string> = {
  propietario_ocupante: 'Propietario ocupante',
  arrendatario: 'Arrendatario',
  otro: 'Otro'
}

const formatDate = (date: string) => new Intl.DateTimeFormat('es', {
  dateStyle: 'medium',
  timeZone: 'UTC'
}).format(new Date(`${date}T00:00:00Z`))

const openStatus = () => {
  statusError.value = ''
  statusOpen.value = true
}

const changeStatus = () => {
  router.patch(route('residentes.estado', props.residente.id), {
    estado: props.residente.estado === 'activo' ? 'inactivo' : 'activo'
  }, {
    preserveScroll: true,
    onStart: () => { statusLoading.value = true },
    onSuccess: () => { statusOpen.value = false },
    onError: errors => { statusError.value = String(errors.estado ?? 'No fue posible cambiar el estado.') },
    onFinish: () => { statusLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="residentes-show">
    <template #header>
      <UDashboardNavbar :title="residente.nombre">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <div v-if="residente.puedeGestionar" class="flex gap-2">
            <UButton color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" :ui="{ label: 'hidden sm:inline' }" @click="router.visit(route('residentes.edit', residente.id))" />
            <UButton :color="residente.estado === 'activo' ? 'error' : 'success'" :icon="residente.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" :label="residente.estado === 'activo' ? 'Inactivar' : 'Activar'" :ui="{ label: 'hidden sm:inline' }" variant="outline" @click="openStatus" />
          </div>
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
          <UCard>
            <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">Datos del residente</p><h1 class="mt-1 text-xl font-semibold text-highlighted">{{ residente.nombre }}</h1></div><UBadge :color="residente.estado === 'activo' ? 'success' : 'neutral'" :label="residente.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></div></template>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
              <div><dt class="text-xs uppercase tracking-wide text-muted">Identificación</dt><dd class="mt-1 font-mono text-sm text-highlighted">{{ residente.tipoIdentificacion.toUpperCase() }} · {{ residente.identificacion }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Teléfono</dt><dd class="mt-1 text-sm text-highlighted">{{ residente.telefono || 'No registrado' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Celular</dt><dd class="mt-1 text-sm text-highlighted">{{ residente.celular || 'No registrado' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Correo</dt><dd class="mt-1 break-all text-sm text-highlighted">{{ residente.correo || 'No registrado' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Dirección</dt><dd class="mt-1 text-sm text-highlighted">{{ residente.direccion || 'No registrada' }}</dd></div>
              <div v-if="residente.observaciones" class="sm:col-span-2"><dt class="text-xs uppercase tracking-wide text-muted">Observaciones</dt><dd class="mt-1 whitespace-pre-line text-sm text-highlighted">{{ residente.observaciones }}</dd></div>
            </dl>
          </UCard>
          <UCard><template #header><p class="font-medium text-highlighted">Resumen</p></template><div class="flex items-center gap-4"><div class="rounded-xl bg-primary/10 p-3 text-primary"><UIcon name="i-lucide-house" class="size-6" /></div><div><p class="text-3xl font-semibold text-highlighted">{{ residente.ocupacionesActuales?.length ?? 0 }}</p><p class="text-sm text-muted">Ocupaciones actuales visibles</p></div></div></UCard>
        </div>

        <UCard>
          <template #header><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-medium text-highlighted">Ocupaciones</p><p class="mt-1 text-xs text-muted">Departamentos actuales e historial conservado.</p></div><div class="flex gap-1"><UButton :color="section === 'actuales' ? 'primary' : 'neutral'" label="Actuales" :variant="section === 'actuales' ? 'soft' : 'ghost'" :aria-pressed="section === 'actuales'" @click="section = 'actuales'" /><UButton :color="section === 'historial' ? 'primary' : 'neutral'" label="Historial" :variant="section === 'historial' ? 'soft' : 'ghost'" :aria-pressed="section === 'historial'" @click="section = 'historial'" /></div></div></template>
          <div class="overflow-x-auto">
            <table class="w-full min-w-5xl text-left text-sm">
              <thead class="border-b border-default text-xs uppercase tracking-wide text-muted"><tr><th class="px-3 py-3 font-medium">Edificio</th><th class="px-3 py-3 font-medium">Ubicación</th><th class="px-3 py-3 font-medium">Departamento</th><th class="px-3 py-3 font-medium">Tipo</th><th class="px-3 py-3 font-medium">Vigencia</th><th class="px-3 py-3 font-medium">Estado</th></tr></thead>
              <tbody class="divide-y divide-default">
                <tr v-for="item in section === 'actuales' ? residente.ocupacionesActuales : residente.historialOcupaciones" :key="item.id">
                  <td class="px-3 py-3 font-medium text-highlighted">{{ item.edificio }}</td>
                  <td class="px-3 py-3 text-muted">{{ item.torre }} · {{ item.piso }}</td>
                  <td class="px-3 py-3"><UButton color="neutral" :label="item.departamento" variant="link" @click="router.visit(route('departamentos.show', [item.edificioId, item.departamentoId]))" /></td>
                  <td class="px-3 py-3"><p class="text-highlighted">{{ typeLabels[item.tipoOcupacion] }}</p><p v-if="item.observaciones" class="mt-1 max-w-xs truncate text-xs text-muted" :title="item.observaciones">{{ item.observaciones }}</p></td>
                  <td class="px-3 py-3 text-muted">{{ formatDate(item.fechaInicio) }}<span v-if="item.fechaFin"> – {{ formatDate(item.fechaFin) }}</span></td>
                  <td class="px-3 py-3"><UBadge :color="item.estado === 'activa' ? 'success' : 'neutral'" :label="item.estado === 'activa' ? 'Activa' : 'Finalizada'" variant="subtle" /></td>
                </tr>
                <tr v-if="!(section === 'actuales' ? residente.ocupacionesActuales : residente.historialOcupaciones)?.length"><td colspan="6" class="px-3 py-10 text-center text-muted">No hay ocupaciones en esta sección.</td></tr>
              </tbody>
            </table>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="statusOpen"
    :close="!statusLoading"
    :dismissible="!statusLoading"
    :title="residente.estado === 'activo' ? 'Inactivar residente' : 'Activar residente'"
    :description="residente.estado === 'activo' ? 'El residente no puede inactivarse mientras tenga ocupaciones activas.' : `¿Confirma activar a ${residente.nombre}?`"
  >
    <template #body><UAlert v-if="statusError" color="error" :description="statusError" icon="i-lucide-circle-alert" role="alert" variant="subtle" /></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="statusLoading" @click="statusOpen = false" /><UButton :color="residente.estado === 'activo' ? 'error' : 'success'" :label="residente.estado === 'activo' ? 'Inactivar' : 'Activar'" :loading="statusLoading" @click="changeStatus" /></div></template>
  </UModal>
</template>
