<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { EstadoProveedor, PaginationMeta, Proveedor } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  proveedores: { data: Proveedor[], meta: PaginationMeta }
  filters: { buscar?: string | null, edificio_id?: string | null, estado?: EstadoProveedor | null }
  edificios: Array<{ id: string, nombre: string }>
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const statusLoading = ref(false)
const statusOpen = ref(false)
const statusError = ref('')
const selected = ref<Proveedor | null>(null)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const buildingItems = computed(() => [{ label: 'Todos los edificios', value: '' }, ...props.edificios.map(item => ({ label: item.nombre, value: item.id }))])
const reload = (page = 1, replace = false) => router.get(route('proveedores.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  estado: estado.value || undefined,
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
const confirmStatus = (proveedor: Proveedor) => {
  selected.value = proveedor
  statusError.value = ''
  statusOpen.value = true
}
const changeStatus = () => {
  if (!selected.value) return
  router.patch(route('proveedores.estado', [selected.value.edificioId, selected.value.id]), {
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
  <UDashboardPanel id="proveedores">
    <template #header><UDashboardNavbar title="Proveedores"><template #leading><UDashboardSidebarCollapse /></template><template #right><UButton v-if="canAny('gastos.gestionar')" icon="i-lucide-plus" label="Nuevo proveedor" @click="router.visit(route('proveedores.create'))" /></template></UDashboardNavbar></template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="grid gap-3 lg:grid-cols-[minmax(16rem,1fr)_14rem_12rem]">
          <UInput v-model="buscar" aria-label="Buscar proveedores" icon="i-lucide-search" placeholder="Identificación, razón social o nombre comercial" size="xl" />
          <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="buildingItems" size="xl" @update:model-value="reload(1, true)" />
          <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Activos', value: 'activo' }, { label: 'Inactivos', value: 'inactivo' }]" size="xl" @update:model-value="reload(1, true)" />
        </div>
        <div class="overflow-x-auto rounded-xl border border-default" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Proveedor</th><th class="p-3">Edificio</th><th class="p-3">Contacto comercial</th><th class="p-3">Crédito</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr></thead>
            <tbody class="divide-y divide-default">
              <tr v-for="proveedor in proveedores.data" :key="proveedor.id" class="hover:bg-elevated/30">
                <td class="p-3"><p class="font-medium text-highlighted">{{ proveedor.nombreComercial || proveedor.nombre }}</p><p class="font-mono text-xs text-muted">{{ proveedor.tipoIdentificacion.toUpperCase() }} · {{ proveedor.identificacion }}</p></td>
                <td class="p-3 text-muted">{{ proveedor.edificio }}</td>
                <td class="p-3"><p>{{ proveedor.contacto || proveedor.nombre }}</p><p class="text-xs text-muted">{{ proveedor.correo || proveedor.telefono || 'Sin contacto local' }}</p></td>
                <td class="p-3 text-muted">{{ proveedor.diasCredito === null ? 'No definido' : `${proveedor.diasCredito} días` }}</td>
                <td class="p-3"><UBadge :color="proveedor.estado === 'activo' ? 'success' : 'neutral'" :label="proveedor.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></td>
                <td class="p-3"><div class="flex justify-end gap-1"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver proveedor" @click="router.visit(route('proveedores.show', [proveedor.edificioId, proveedor.id]))" /><UButton v-if="can('gastos.gestionar', proveedor.edificioId)" color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar proveedor" @click="router.visit(route('proveedores.edit', [proveedor.edificioId, proveedor.id]))" /><UButton v-if="can('gastos.gestionar', proveedor.edificioId)" :color="proveedor.estado === 'activo' ? 'error' : 'success'" :icon="proveedor.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'" variant="ghost" aria-label="Cambiar estado" @click="confirmStatus(proveedor)" /></div></td>
              </tr>
              <tr v-if="!proveedores.data.length"><td class="p-10 text-center text-muted" colspan="6">No hay proveedores que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ proveedores.meta.total }} proveedor(es) · página {{ proveedores.meta.currentPage }} de {{ proveedores.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" icon="i-lucide-chevron-left" label="Anterior" variant="outline" :disabled="proveedores.meta.currentPage <= 1 || loading" @click="reload(proveedores.meta.currentPage - 1)" /><UButton color="neutral" icon="i-lucide-chevron-right" label="Siguiente" trailing variant="outline" :disabled="proveedores.meta.currentPage >= proveedores.meta.lastPage || loading" @click="reload(proveedores.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="statusOpen" :close="!statusLoading" :dismissible="!statusLoading" :title="selected?.estado === 'activo' ? 'Inactivar proveedor' : 'Activar proveedor'" :description="selected ? `¿Confirma cambiar el estado de ${selected.nombre}?` : ''">
    <template #body><UAlert v-if="statusError" color="error" :description="statusError" icon="i-lucide-circle-alert" role="alert" variant="subtle" /></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="statusLoading" @click="statusOpen = false" /><UButton :color="selected?.estado === 'activo' ? 'error' : 'success'" :label="selected?.estado === 'activo' ? 'Inactivar' : 'Activar'" :loading="statusLoading" @click="changeStatus" /></div></template>
  </UModal>
</template>
