<script setup lang="ts">
import { reactive, ref, computed, h } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { TableColumn } from '@nuxt/ui'
import type { Factura, Cliente, Producto, DetalleFactura } from '../../types'
import FormField from '../../components/FormField.vue'

const props = defineProps<{
  factura: Factura
  clientes: Cliente[]
  productos: Producto[]
}>()

const state = reactive({
  numeroFactura: props.factura.numeroFactura,
  serie: props.factura.serie,
  clienteId: props.factura.clienteId,
  fechaEmision: props.factura.fechaEmision.split(' ')[0],
  subtotal: props.factura.subtotal,
  igv: props.factura.igv,
  descuento: props.factura.descuento,
  total: props.factura.total,
  estado: props.factura.estado,
  observaciones: props.factura.observaciones || '',
  detalles: (props.factura.detalles || []) as DetalleFactura[]
})

const page = usePage()
const backendErrors = computed(() => page.props.errors || {})

const errors = computed(() => {
  const result: Record<string, string> = {}
  Object.keys(backendErrors.value).forEach(key => {
    const error = backendErrors.value[key]
    result[key] = Array.isArray(error) ? error[0] : error
  })
  return result
})

const isLoading = ref(false)

const clientesOptions = computed(() => {
  return props.clientes.map(cliente => ({
    label: `${cliente.razonSocial} - ${cliente.numeroDocumento}`,
    value: cliente.id
  }))
})

const estadoOptions = [
  { label: 'Emitida', value: 'emitida' },
  { label: 'Pagada', value: 'pagada' },
  { label: 'Anulada', value: 'anulada' }
]

const getProducto = (idOrName: string) => {
  return props.productos.find(p => p.id === idOrName || p.nombre === idOrName)
}

const columns: TableColumn<DetalleFactura>[] = [
  {
    id: 'producto',
    accessorKey: 'productoId',
    header: 'Producto',
    cell: ({ row }) => getProducto(row.original.productoId)?.nombre || ''
  },
  {
    id: 'precioUnitario',
    accessorKey: 'precioUnitario',
    header: 'Precio',
    cell: ({ row }) => h('div', { class: 'text-right' }, `$ ${row.original.precioUnitario.toFixed(2)}`)
  },
  {
    id: 'cantidad',
    accessorKey: 'cantidad',
    header: 'Cantidad',
    cell: ({ row }) => h('div', { class: 'text-right' }, row.original.cantidad.toString())
  },
  {
    id: 'descuento',
    accessorKey: 'descuento',
    header: 'Descuento',
    cell: ({ row }) => h('div', { class: 'text-right' }, `$ ${row.original.descuento.toFixed(2)}`)
  },
  {
    id: 'subtotal',
    accessorKey: 'subtotal',
    header: 'Subtotal',
    cell: ({ row }) => h('div', { class: 'text-right' }, h('span', { class: 'font-semibold' }, `$ ${row.original.subtotal.toFixed(2)}`))
  }
]

const handleSubmit = () => {
  isLoading.value = true

  router.put(route('facturas.update', props.factura.id), {
    estado: state.estado,
    observaciones: state.observaciones
  }, {
    onSuccess: () => {},
    onFinish: () => {
      isLoading.value = false
    },
    onError: () => {
      isLoading.value = false
    }
  })
}

const handleCancel = () => {
  router.visit(route('facturas.index'))
}
</script>

<template>
  <UDashboardPanel>
    <template #header>
      <UDashboardNavbar title="Editar Factura">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-6">
        <div class="mb-6">
          <h2 class="text-2xl font-semibold">Editar Factura</h2>
          <p class="text-sm text-muted mt-1">Puede modificar el estado y las observaciones de la factura</p>
        </div>

        <form @submit.prevent="handleSubmit" class="space-y-6">
          <div class="grid grid-cols-4 gap-8">
            <FormField label="Número de Factura" name="numeroFactura" size="xl">
              <UInput
                :model-value="state.numeroFactura"
                icon="i-lucide-file-text"
                size="xl"
                disabled
                class="w-full"
              />
            </FormField>

            <FormField label="Serie" name="serie" size="xl">
              <UInput
                :model-value="state.serie"
                icon="i-lucide-hash"
                size="xl"
                disabled
                class="w-full"
              />
            </FormField>

            <FormField label="Cliente" name="clienteId" size="xl" class="col-span-2">
              <USelect
                :model-value="state.clienteId"
                :items="clientesOptions"
                size="xl"
                disabled
                class="w-full"
              />
            </FormField>
          </div>

          <div class="grid grid-cols-2 gap-8">
            <FormField label="Fecha de Emisión" name="fechaEmision" size="xl">
              <UInput
                :model-value="state.fechaEmision"
                type="date"
                size="xl"
                disabled
                class="w-full"
              />
            </FormField>

            <FormField label="Estado" name="estado" required size="xl" :error="errors.estado">
              <USelect
                v-model="state.estado"
                :items="estadoOptions"
                size="xl"
                class="w-full"
              />
            </FormField>
          </div>

          <div class="border rounded-lg p-4 space-y-4">
            <h3 class="text-lg font-semibold">Productos</h3>

            <UTable
              v-if="state.detalles.length > 0"
              :data="state.detalles"
              :columns="columns"
              class="shrink-0"
              :ui="{
                base: 'table-fixed border-separate border-spacing-0',
                thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
                tbody: '[&>tr]:last:[&>td]:border-b-0',
                th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
                td: 'border-b border-default',
                separator: 'h-0'
              }"
            />
            <div v-else class="text-center py-8 text-sm text-muted">
              No hay productos en esta factura
            </div>
          </div>

          <div class="flex justify-end">
            <div class="w-80 space-y-2 bg-muted/30 p-4 rounded-lg">
              <div class="flex justify-between text-sm">
                <span>Subtotal:</span>
                <span class="font-semibold">$ {{ state.subtotal.toFixed(2) }}</span>
              </div>
              <div class="flex justify-between text-sm text-red-600">
                <span>Descuento:</span>
                <span class="font-semibold">- $ {{ state.descuento.toFixed(2) }}</span>
              </div>
              <div class="flex justify-between text-sm">
                <span>IGV (18%):</span>
                <span class="font-semibold">$ {{ state.igv.toFixed(2) }}</span>
              </div>
              <div class="flex justify-between text-lg font-bold border-t pt-2">
                <span>Total:</span>
                <span class="text-primary">$ {{ state.total.toFixed(2) }}</span>
              </div>
            </div>
          </div>

          <FormField label="Observaciones" name="observaciones" size="xl" :error="errors.observaciones">
            <UTextarea
              v-model="state.observaciones"
              placeholder="Ingrese observaciones adicionales..."
              :rows="3"
              size="xl"
              class="w-full"
            />
          </FormField>

          <div class="bg-muted/50 rounded-lg p-4 text-sm">
            <p class="text-muted">
              <strong>ID:</strong> {{ factura.id }}
            </p>
            <p class="text-muted mt-1">
              <strong>Creado:</strong> {{ new Date(factura.createdAt).toLocaleString('es-ES') }}
            </p>
            <p class="text-muted mt-1">
              <strong>Actualizado:</strong> {{ new Date(factura.updatedAt).toLocaleString('es-ES') }}
            </p>
          </div>

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
              label="Actualizar Factura"
              icon="i-lucide-save"
              :loading="isLoading"
            />
          </div>
        </form>
      </div>
    </template>
  </UDashboardPanel>
</template>
