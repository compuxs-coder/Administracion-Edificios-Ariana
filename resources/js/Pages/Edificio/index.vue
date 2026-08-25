<script setup lang="ts">
import { h, onBeforeUnmount, ref, resolveComponent, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { TableColumn } from '@nuxt/ui'
import type { Row } from '@tanstack/table-core'
import type { Edificio, EstadoEdificio } from '../../types'

const props = defineProps<{
  edificios: {
    data: Edificio[]
    meta: {
      total: number
      currentPage: number
      lastPage: number
      perPage: number
    }
  }
  filters: { buscar: string }
}>()

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const buscar = ref(props.filters.buscar)
const isLoading = ref(false)
const isStatusModalOpen = ref(false)
const isChangingStatus = ref(false)
const selected = ref<Edificio | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | undefined

const goToPage = (page: number, replace = false) => {
  router.get(route('edificios.index'), {
    buscar: buscar.value || undefined,
    page
  }, {
    preserveState: true,
    preserveScroll: true,
    replace,
    onStart: () => { isLoading.value = true },
    onFinish: () => { isLoading.value = false }
  })
}

watch(buscar, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => goToPage(1, true), 300)
})

onBeforeUnmount(() => clearTimeout(searchTimer))

const confirmStatusChange = (edificio: Edificio) => {
  selected.value = edificio
  isStatusModalOpen.value = true
}

const nextStatus = (edificio: Edificio): EstadoEdificio => (
  edificio.estado === 'activo' ? 'inactivo' : 'activo'
)

const changeStatus = () => {
  if (!selected.value) return

  router.patch(route('edificios.estado', selected.value.id), {
    estado: nextStatus(selected.value)
  }, {
    preserveScroll: true,
    onStart: () => { isChangingStatus.value = true },
    onSuccess: () => {
      isStatusModalOpen.value = false
      selected.value = null
    },
    onFinish: () => { isChangingStatus.value = false }
  })
}

function getRowItems(row: Row<Edificio>) {
  const edificio = row.original

  return [
    {
      label: 'Ver detalle',
      icon: 'i-lucide-eye',
      onSelect: () => router.visit(route('edificios.show', edificio.id))
    },
    {
      label: 'Editar',
      icon: 'i-lucide-pencil',
      onSelect: () => router.visit(route('edificios.edit', edificio.id))
    },
    {
      label: 'Estructura física',
      icon: 'i-lucide-network',
      onSelect: () => router.visit(route('edificios.estructura', edificio.id))
    },
    { type: 'separator' },
    {
      label: edificio.estado === 'activo' ? 'Inactivar' : 'Activar',
      icon: edificio.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play',
      color: edificio.estado === 'activo' ? 'error' : 'success',
      onSelect: () => confirmStatusChange(edificio)
    }
  ]
}

const columns: TableColumn<Edificio>[] = [
  {
    accessorKey: 'nombre',
    header: 'Edificio',
    cell: ({ row }) => h('div', { class: 'min-w-0' }, [
      h('p', { class: 'truncate font-medium text-highlighted' }, row.original.nombre),
      h('p', { class: 'truncate text-xs text-muted' }, row.original.direccion)
    ])
  },
  {
    accessorKey: 'ciudad',
    header: 'Ciudad'
  },
  {
    accessorKey: 'responsable',
    header: 'Responsable',
    cell: ({ row }) => row.original.responsable || 'Sin asignar'
  },
  {
    accessorKey: 'estado',
    header: 'Estado',
    cell: ({ row }) => h(UBadge, {
      color: row.original.estado === 'activo' ? 'success' : 'neutral',
      label: row.original.estado === 'activo' ? 'Activo' : 'Inactivo',
      variant: 'subtle'
    })
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        items: getRowItems(row),
        content: { align: 'end' }
      }, () => h(UButton, {
        icon: 'i-lucide-ellipsis-vertical',
        color: 'neutral',
        variant: 'ghost',
        'aria-label': `Acciones de ${row.original.nombre}`
      }))
    ])
  }
]
</script>

<template>
  <UDashboardPanel id="edificios">
    <template #header>
      <UDashboardNavbar title="Edificios">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            color="primary"
            icon="i-lucide-plus"
            label="Nuevo edificio"
            @click="router.visit(route('edificios.create'))"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
          <UInput
            v-model="buscar"
            class="w-full sm:max-w-md"
            icon="i-lucide-search"
            placeholder="Buscar por nombre, ciudad, RUC o responsable"
            size="xl"
            aria-label="Buscar edificios"
          />
          <p class="text-sm text-muted">{{ edificios.meta.total }} edificio(s) asignado(s)</p>
        </div>

        <UTable
          :columns="columns"
          :data="edificios.data"
          :empty="buscar ? 'No hay edificios que coincidan con la búsqueda.' : 'No hay edificios registrados.'"
          :loading="isLoading"
          class="min-h-48"
          :ui="{
            base: 'table-fixed border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
            td: 'border-b border-default'
          }"
        >
          <template #loading>
            <div class="py-8 text-center text-sm text-muted">Cargando edificios...</div>
          </template>
        </UTable>

        <div class="mt-auto flex items-center justify-between border-t border-default pt-4">
          <p class="text-sm text-muted">
            Página {{ edificios.meta.currentPage }} de {{ edificios.meta.lastPage }}
          </p>
          <div class="flex gap-2">
            <UButton
              color="neutral"
              icon="i-lucide-chevron-left"
              label="Anterior"
              variant="outline"
              :disabled="edificios.meta.currentPage <= 1 || isLoading"
              @click="goToPage(edificios.meta.currentPage - 1)"
            />
            <UButton
              color="neutral"
              icon="i-lucide-chevron-right"
              label="Siguiente"
              trailing
              variant="outline"
              :disabled="edificios.meta.currentPage >= edificios.meta.lastPage || isLoading"
              @click="goToPage(edificios.meta.currentPage + 1)"
            />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="isStatusModalOpen"
    :close="!isChangingStatus"
    :dismissible="!isChangingStatus"
    :title="selected?.estado === 'activo' ? 'Inactivar edificio' : 'Activar edificio'"
    :description="selected ? `¿Confirma el cambio de estado para ${selected.nombre}?` : ''"
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
          :color="selected?.estado === 'activo' ? 'error' : 'success'"
          :label="selected?.estado === 'activo' ? 'Inactivar' : 'Activar'"
          :loading="isChangingStatus"
          @click="changeStatus"
        />
      </div>
    </template>
  </UModal>
</template>
