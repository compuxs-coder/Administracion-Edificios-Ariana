<script setup lang="ts">
import { ref, computed, h, resolveComponent } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { TableColumn } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { Factura } from '../../types'
import { useToast, useTemplateRef } from '../../composables/nuxt-compat'

const UButton = resolveComponent('UButton')
const UBadge = resolveComponent('UBadge')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const props = defineProps<{
  facturas: {
    data: Factura[]
    links: any[]
    meta: any
  }
  stats?: {
    total: number
    emitidas: number
    pagadas: number
    anuladas: number
  }
}>()

const toast = useToast()
const table = useTemplateRef('table')

const columnFilters = ref([{
  id: 'numeroFactura',
  value: ''
}])
const columnVisibility = ref()
const rowSelection = ref({})

const data = ref<Factura[]>(props.facturas.data)
const status = ref<'idle' | 'pending' | 'success' | 'error'>('success')
const isDeleteModalOpen = ref(false)
const facturaToDelete = ref<Factura | null>(null)

// Función para formatear moneda
const formatCurrency = (value: number) => {
  return new Intl.NumberFormat('es-PE', {
    style: 'currency',
    currency: 'PEN'
  }).format(value)
}

// Función para formatear fecha
const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('es-ES')
}

// Obtener color del badge según estado
const getEstadoColor = (estado: string) => {
  const colors = {
    emitida: 'blue',
    pagada: 'green',
    anulada: 'red'
  }
  return colors[estado as keyof typeof colors] || 'gray'
}

const handleCreate = () => {
  router.visit(route('facturas.create'))
}

const handleEdit = (factura: Factura) => {
  router.visit(route('facturas.edit', factura.id))
}

const confirmDelete = (factura: Factura) => {
  facturaToDelete.value = factura
  isDeleteModalOpen.value = true
}

const handleDelete = () => {
  if (!facturaToDelete.value) return

  router.delete(route('facturas.destroy', facturaToDelete.value.id), {
    onSuccess: () => {
      // Actualizar la lista local
      data.value = data.value.filter(f => f.id !== facturaToDelete.value?.id)

      toast.add({
        title: 'Factura eliminada',
        description: 'La factura ha sido eliminada exitosamente',
        color: 'success'
      })
      isDeleteModalOpen.value = false
      facturaToDelete.value = null
    },
    onError: () => {
      toast.add({
        title: 'Error',
        description: 'No se pudo eliminar la factura',
        color: 'error'
      })
      isDeleteModalOpen.value = false
    }
  })
}

function getRowItems(row: Row<Factura>) {
  return [
    {
      type: 'label',
      label: 'Acciones'
    },
    {
      label: 'Copiar número de factura',
      icon: 'i-lucide-copy',
      onSelect() {
        navigator.clipboard.writeText(row.original.numeroFactura)
        toast.add({
          title: 'Copiado al portapapeles',
          description: 'Número de factura copiado'
        })
      }
    },
    {
      type: 'separator'
    },
    {
      label: 'Editar factura',
      icon: 'i-lucide-pencil',
      onSelect() {
        handleEdit(row.original)
      }
    },
    {
      type: 'separator'
    },
    {
      label: 'Eliminar factura',
      icon: 'i-lucide-trash',
      color: 'error',
      onSelect() {
        confirmDelete(row.original)
      }
    }
  ]
}

const columns: TableColumn<Factura>[] = [
  {
    id: 'numeroFactura',
    accessorKey: 'numeroFactura',
    header: 'Número',
    cell: ({ row }) => h('span', { class: 'font-medium' }, row.original.numeroFactura)
  },
  {
    id: 'serie',
    accessorKey: 'serie',
    header: 'Serie',
    cell: ({ row }) => row.original.serie
  },
  {
    id: 'fechaEmision',
    accessorKey: 'fechaEmision',
    header: 'Fecha Emisión',
    cell: ({ row }) => formatDate(row.original.fechaEmision)
  },
  {
    id: 'total',
    accessorKey: 'total',
    header: 'Total',
    cell: ({ row }) => h('div', { class: 'text-right' }, h('span', { class: 'font-semibold' }, formatCurrency(row.original.total)))
  },
  {
    id: 'estado',
    accessorKey: 'estado',
    header: 'Estado',
    cell: ({ row }) => {
      return h(UBadge, {
        color: getEstadoColor(row.original.estado),
        variant: 'subtle'
      }, () => row.original.estado.charAt(0).toUpperCase() + row.original.estado.slice(1))
    }
  },
  {
    id: 'actions',
    cell: ({ row }) => {
      return h(
        'div',
        { class: 'text-right' },
        h(
          UDropdownMenu,
          {
            content: {
              align: 'end'
            },
            items: getRowItems(row)
          },
          () =>
            h(UButton, {
              icon: 'i-lucide-ellipsis-vertical',
              color: 'neutral',
              variant: 'ghost',
              class: 'ml-auto'
            })
        )
      )
    }
  }
]

const numeroFactura = computed({
  get: (): string => {
    return (table.value?.tableApi?.getColumn('numeroFactura')?.getFilterValue() as string) || ''
  },
  set: (value: string) => {
    table.value?.tableApi?.getColumn('numeroFactura')?.setFilterValue(value || undefined)
  }
})

const pagination = ref({
  pageIndex: 0,
  pageSize: 10
})
</script>

<template>
  <UDashboardPanel id="facturas">
    <template #header>
      <UDashboardNavbar title="Facturas">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            label="Nueva Factura"
            color="primary"
            icon="i-lucide-plus"
            @click="handleCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Estadísticas -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-muted/50 rounded-lg p-4">
          <p class="text-sm text-muted">Total Facturas</p>
          <p class="text-2xl font-bold">{{ stats?.total || 0 }}</p>
        </div>
        <div class="bg-blue-500/10 rounded-lg p-4">
          <p class="text-sm text-muted">Emitidas</p>
          <p class="text-2xl font-bold text-blue-600">{{ stats?.emitidas || 0 }}</p>
        </div>
        <div class="bg-green-500/10 rounded-lg p-4">
          <p class="text-sm text-muted">Pagadas</p>
          <p class="text-2xl font-bold text-green-600">{{ stats?.pagadas || 0 }}</p>
        </div>
        <div class="bg-red-500/10 rounded-lg p-4">
          <p class="text-sm text-muted">Anuladas</p>
          <p class="text-2xl font-bold text-red-600">{{ stats?.anuladas || 0 }}</p>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="numeroFactura"
          class="w-full sm:w-1/2"
          size="xl"
          icon="i-lucide-search"
          placeholder="Buscar por número de factura..."
        />
      </div>

      <UTable
        ref="table"
        v-model:column-filters="columnFilters"
        v-model:column-visibility="columnVisibility"
        v-model:row-selection="rowSelection"
        v-model:pagination="pagination"
        :pagination-options="{
          getPaginationRowModel: getPaginationRowModel()
        }"
        class="shrink-0"
        :data="data"
        :columns="columns"
        :loading="status === 'pending'"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
          separator: 'h-0'
        }"
      />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ table?.tableApi?.getFilteredSelectedRowModel().rows.length || 0 }} de
          {{ table?.tableApi?.getFilteredRowModel().rows.length || 0 }} fila(s) seleccionada(s).
        </div>

        <div class="flex items-center gap-1.5">
          <UPagination
            :default-page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
            :items-per-page="table?.tableApi?.getState().pagination.pageSize"
            :total="table?.tableApi?.getFilteredRowModel().rows.length"
            @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <!-- Modal de confirmación de eliminación -->
  <UModal
    v-model:open="isDeleteModalOpen"
    title="Confirmar eliminación"
    :description="`¿Está seguro de que desea eliminar la factura ${facturaToDelete?.numeroFactura}? Esta acción no se puede deshacer.`"
  >
    <template #footer>
      <div class="flex justify-end gap-3">
        <UButton
          label="Eliminar"
          color="primary"
          variant="solid"
          icon="i-lucide-trash"
          @click="handleDelete"
        />
        <UButton
          label="Cancelar"
          color="neutral"
          variant="subtle"
          @click="isDeleteModalOpen = false"
        />
      </div>
    </template>
  </UModal>
</template>
