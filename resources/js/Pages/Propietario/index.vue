<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EstadoPropietario, Propietario, TipoPersona } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  propietarios: { data: Propietario[], meta: { total: number, currentPage: number, lastPage: number, perPage: number } }
  filters: { buscar?: string | null, tipo_persona?: TipoPersona | null, estado?: EstadoPropietario | null, edificio_id?: string | null }
  edificios: Array<{ id: string, nombre: string }>
}>()
const buscar = ref(props.filters.buscar ?? '')
const tipoPersona = ref(props.filters.tipo_persona ?? '')
const estado = ref(props.filters.estado ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const loading = ref(false)
const statusLoading = ref(false)
const statusOpen = ref(false)
const statusError = ref('')
const selected = ref<Propietario | null>(null)
const { canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const edificioItems = computed(() => [{ label: 'Todos los edificios', value: '' }, ...props.edificios.map(item => ({ label: item.nombre, value: item.id }))])
const reload = (page = 1, replace = false) => router.get(route('propietarios.index'), {
  buscar: buscar.value || undefined,
  tipo_persona: tipoPersona.value || undefined,
  estado: estado.value || undefined,
  edificio_id: edificioId.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})

watch(buscar, () => {
  clearTimeout(timer)
  timer = setTimeout(() => reload(1, true), 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const confirmStatus = (propietario: Propietario) => {
  selected.value = propietario
  statusError.value = ''
  statusOpen.value = true
}
const changeStatus = () => {
  if (!selected.value) return
  router.patch(route('propietarios.estado', selected.value.id), {
    estado: selected.value.estado === 'activo' ? 'inactivo' : 'activo'
  }, {
    preserveScroll: true,
    onStart: () => { statusLoading.value = true },
    onSuccess: () => { statusOpen.value = false; selected.value = null },
    onError: errors => { statusError.value = String(errors.estado ?? 'No fue posible cambiar el estado.') },
    onFinish: () => { statusLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="propietarios">
    <template #header>
      <UDashboardNavbar title="Propietarios">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('propiedad.gestionar')" icon="i-lucide-plus" label="Nuevo propietario" @click="router.visit(route('propietarios.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="grid gap-3 xl:grid-cols-[minmax(16rem,1fr)_13rem_12rem_13rem]">
          <UInput v-model="buscar" aria-label="Buscar propietarios" icon="i-lucide-search" placeholder="Identificación, nombre o razón social" size="xl" />
          <USelect v-model="tipoPersona" aria-label="Filtrar por tipo de persona" :items="[{ label: 'Todos los tipos', value: '' }, { label: 'Persona natural', value: 'persona_natural' }, { label: 'Persona jurídica', value: 'persona_juridica' }]" size="xl" @update:model-value="reload(1, true)" />
          <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activos', value: 'activo' }, { label: 'Inactivos', value: 'inactivo' }]" size="xl" @update:model-value="reload(1, true)" />
          <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="edificioItems" size="xl" @update:model-value="reload(1, true)" />
        </div>

        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted">
              <tr><th class="px-4 py-3 font-medium">Identificación</th><th class="px-4 py-3 font-medium">Propietario</th><th class="px-4 py-3 font-medium">Contacto</th><th class="px-4 py-3 font-medium">Propiedades</th><th class="px-4 py-3 font-medium">Estado</th><th class="px-4 py-3 text-right font-medium">Acciones</th></tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr v-for="propietario in propietarios.data" :key="propietario.id" class="hover:bg-elevated/30">
                <td class="px-4 py-3"><p class="font-mono font-semibold text-highlighted">{{ propietario.identificacion }}</p><p class="text-xs text-muted">{{ propietario.tipoIdentificacion.toUpperCase() }}</p></td>
                <td class="px-4 py-3"><p class="font-medium text-highlighted">{{ propietario.nombre }}</p><p class="text-xs text-muted">{{ propietario.tipoPersona === 'persona_natural' ? 'Persona natural' : 'Persona jurídica' }}</p></td>
                <td class="px-4 py-3"><p class="text-highlighted">{{ propietario.celular || propietario.telefono || 'Sin teléfono' }}</p><p class="text-xs text-muted">{{ propietario.correo || 'Sin correo' }}</p></td>
                <td class="px-4 py-3"><UBadge color="primary" :label="String(propietario.propiedadesActualesCount)" variant="subtle" /></td>
                <td class="px-4 py-3"><UBadge :color="propietario.estado === 'activo' ? 'success' : 'neutral'" :label="propietario.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></td>
                <td class="px-4 py-3"><div class="flex justify-end gap-1">
                  <UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver propietario" @click="router.visit(route('propietarios.show', propietario.id))" />
                   <UButton v-if="propietario.puedeGestionar" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar propietario" @click="router.visit(route('propietarios.edit', propietario.id))" />
                   <UButton v-if="propietario.puedeGestionar" :color="propietario.estado === 'activo' ? 'error' : 'success'" :icon="propietario.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" variant="ghost" aria-label="Cambiar estado" @click="confirmStatus(propietario)" />
                </div></td>
              </tr>
              <tr v-if="propietarios.data.length === 0"><td colspan="6" class="px-4 py-12 text-center text-muted">No hay propietarios que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">{{ propietarios.meta.total }} propietario(s) · página {{ propietarios.meta.currentPage }} de {{ propietarios.meta.lastPage }}</p>
          <div class="flex gap-2"><UButton color="neutral" icon="i-lucide-chevron-left" label="Anterior" variant="outline" :disabled="propietarios.meta.currentPage <= 1 || loading" @click="reload(propietarios.meta.currentPage - 1)" /><UButton color="neutral" icon="i-lucide-chevron-right" label="Siguiente" trailing variant="outline" :disabled="propietarios.meta.currentPage >= propietarios.meta.lastPage || loading" @click="reload(propietarios.meta.currentPage + 1)" /></div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="statusOpen" :close="!statusLoading" :dismissible="!statusLoading" :title="selected?.estado === 'activo' ? 'Inactivar propietario' : 'Activar propietario'" :description="selected ? `¿Confirma cambiar el estado de ${selected.nombre}?` : ''">
    <template #body><UAlert v-if="statusError" color="error" :description="statusError" icon="i-lucide-circle-alert" role="alert" variant="subtle" /></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="statusLoading" @click="statusOpen = false" /><UButton :color="selected?.estado === 'activo' ? 'error' : 'success'" :label="selected?.estado === 'activo' ? 'Inactivar' : 'Activar'" :loading="statusLoading" @click="changeStatus" /></div></template>
  </UModal>
</template>
