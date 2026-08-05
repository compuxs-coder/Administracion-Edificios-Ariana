<script setup lang="ts">
import { reactive, ref, computed, h } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { TableColumn } from '@nuxt/ui'
import type { Cliente, Producto, DetalleFactura } from '../../types'
import FormField from '../../components/FormField.vue'

const props = defineProps<{
  clientes: Cliente[]
  productos: Producto[]
}>()

const state = reactive({
  numeroFactura: '',
  serie: 'F001',
  clienteId: '',
  fechaEmision: new Date().toISOString().split('T')[0],
  subtotal: 0,
  igv: 0,
  descuento: 0,
  total: 0,
  estado: 'emitida',
  detalles: [] as DetalleFactura[]
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
const productoSeleccionado = ref<Producto | null>(null)

const clientesOptions = computed(() => {
  return props.clientes.map(cliente => ({
    label: `${cliente.razonSocial} - ${cliente.numeroDocumento}`,
    value: cliente.id
  }))
})

const productosOptions = computed(() => {
  return props.productos.map(producto => ({
    ...producto,
    label: producto.nombre
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
    header: 'Cantidad'
  },
  {
    id: 'descuento',
    accessorKey: 'descuento',
    header: 'Descuento'
  },
  {
    id: 'subtotal',
    accessorKey: 'subtotal',
    header: 'Subtotal',
    cell: ({ row }) => h('div', { class: 'text-right' }, h('span', { class: 'font-semibold' }, `$ ${row.original.subtotal.toFixed(2)}`))
  },
  {
    id: 'acciones',
    header: 'Acción'
  }
]

const agregarProducto = () => {
  if (!productoSeleccionado.value) return

  const precio = productoSeleccionado.value.precioUnitario

  state.detalles.push({
    productoId: productoSeleccionado.value.id,
    cantidad: 1,
    precioUnitario: precio,
    descuento: 0,
    subtotal: precio
  })

  productoSeleccionado.value = null
  recalcularTotales()
}

const eliminarProducto = (index: number) => {
  state.detalles.splice(index, 1)
  recalcularTotales()
}

const actualizarSubtotalDetalle = (index: number) => {
  const detalle = state.detalles[index]
  detalle.subtotal = (detalle.precioUnitario * detalle.cantidad) - detalle.descuento
  recalcularTotales()
}

const recalcularTotales = () => {
  state.subtotal = state.detalles.reduce((sum, detalle) => sum + (detalle.precioUnitario * detalle.cantidad), 0)
  state.descuento = state.detalles.reduce((sum, detalle) => sum + detalle.descuento, 0)
  const subtotalConDescuento = state.subtotal - state.descuento
  state.igv = subtotalConDescuento * 0.18
  state.total = subtotalConDescuento + state.igv
}

const getDetalleIndex = (detalle: DetalleFactura) => {
  return state.detalles.findIndex(d => d.productoId === detalle.productoId)
}

const handleSubmit = () => {
  isLoading.value = true

  router.post(route('facturas.store'), state, {
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
      <UDashboardNavbar title="Nueva Factura">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="p-2">
        <div class="mb-1">
          <h2 class="text-2xl font-semibold">Nueva Factura</h2>
          <p class="text-sm text-muted mt-1">Complete los datos de la factura</p>
        </div>

        <form @submit.prevent="handleSubmit" class="space-y-6">
          <div class="grid grid-cols-4 gap-8">
            <FormField label="Número de Factura" name="numeroFactura" required :error="errors.numeroFactura">
              <UInput
                v-model="state.numeroFactura"
                placeholder="Ej: 001-00001"
                icon="i-lucide-file-text"
                size="xl"
                class="w-full"
              />
            </FormField>

            <FormField label="Serie" name="serie" required :error="errors.serie">
              <UInput
                v-model="state.serie"
                placeholder="Ej: F001"
                icon="i-lucide-hash"
                size="xl"
                class="w-full"
              />
            </FormField>

            <FormField label="Cliente" name="clienteId" required :error="errors.clienteId" class="col-span-2">
              <USelect
                v-model="state.clienteId"
                :items="clientesOptions"
                placeholder="Seleccione un cliente"
                size="xl"
                class="w-full col-span-2"
              />
            </FormField>
          </div>

          <div class="grid grid-cols-2 gap-8">
            <FormField label="Estado" name="estado" required :error="errors.estado">
              <USelect
                v-model="state.estado"
                :items="estadoOptions"
                size="xl"
                class="w-full"
              />
            </FormField>

            <FormField label="Fecha de Emisión" name="fechaEmision" required :error="errors.fechaEmision">
              <UInput
                v-model="state.fechaEmision"
                type="date"
                size="xl"
                class="w-full"
              />
            </FormField>
          </div>

          <div class="border rounded-lg p-4 space-y-4">
            <h3 class="text-lg font-semibold">Productos</h3>

            <div class="flex gap-4 items-end">
              <UFormField label="Seleccionar Producto" size="xl" class="flex-1">
                <USelectMenu v-model="productoSeleccionado" :items="productosOptions" class="w-full" placeholder="Buscar producto..." />
              </UFormField>

              <UButton
                type="button"
                icon="i-lucide-plus"
                color="primary"
                variant="solid"
                label="Agregar"
                @click="agregarProducto"
                :disabled="!productoSeleccionado"
                size="md"
              />
            </div>

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
            >
              <template #cantidad-cell="{ row }">
                <UInput
                  v-model.number="state.detalles[getDetalleIndex(row.original)].cantidad"
                  type="number"
                  min="1"
                  size="sm"
                  class="w-20"
                  @update:model-value="actualizarSubtotalDetalle(getDetalleIndex(row.original))"
                />
              </template>

              <template #descuento-cell="{ row }">
                <UInput
                  v-model.number="state.detalles[getDetalleIndex(row.original)].descuento"
                  type="number"
                  step="0.01"
                  min="0"
                  size="sm"
                  class="w-24"
                  @update:model-value="actualizarSubtotalDetalle(getDetalleIndex(row.original))"
                />
              </template>

              <template #acciones-cell="{ row }">
                <div class="text-center">
                  <UButton
                    icon="i-lucide-trash"
                    size="sm"
                    color="red"
                    variant="ghost"
                    @click="eliminarProducto(getDetalleIndex(row.original))"
                  />
                </div>
              </template>
            </UTable>

            <div v-else class="text-center py-8 text-sm text-muted border rounded-lg">
              No hay productos agregados. Seleccione un producto arriba para comenzar.
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
              label="Guardar Factura"
              icon="i-lucide-save"
              :loading="isLoading"
            />
          </div>
        </form>
      </div>
    </template>
  </UDashboardPanel>
</template>
