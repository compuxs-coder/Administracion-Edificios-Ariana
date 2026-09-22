<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Desembolso, EstadoDesembolso, FormaDesembolso, PaginationMeta, ProveedorOption } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  desembolsos: { data: Desembolso[], meta: PaginationMeta }
  filters: {
    edificio_id?: string | null
    proveedor_id?: string | null
    fecha_desde?: string | null
    fecha_hasta?: string | null
    forma_pago?: FormaDesembolso | null
    estado?: EstadoDesembolso | null
  }
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
}>()

const edificioId = ref(props.filters.edificio_id ?? '')
const proveedorId = ref(props.filters.proveedor_id ?? '')
const fechaDesde = ref(props.filters.fecha_desde ?? '')
const fechaHasta = ref(props.filters.fecha_hasta ?? '')
const formaPago = ref(props.filters.forma_pago ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const { canAny } = useBuildingPermissions()

const providerItems = computed(() => {
  const items = props.proveedores.filter(item => !edificioId.value || item.edificioId === edificioId.value)
  const unique = edificioId.value ? items : [...new Map(items.map(item => [item.id, item])).values()]

  return unique.map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.id }))
})

const paymentLabels: Record<FormaDesembolso, string> = {
  efectivo: 'Efectivo',
  transferencia: 'Transferencia',
  deposito: 'Depósito',
  tarjeta: 'Tarjeta',
  cheque: 'Cheque',
  otro: 'Otro'
}

const reload = (page = 1, replace = false) => router.get(route('desembolsos.index'), {
  edificio_id: edificioId.value || undefined,
  proveedor_id: proveedorId.value || undefined,
  fecha_desde: fechaDesde.value || undefined,
  fecha_hasta: fechaHasta.value || undefined,
  forma_pago: formaPago.value || undefined,
  estado: estado.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})

const changeBuilding = () => {
  proveedorId.value = ''
  reload(1, true)
}
</script>

<template>
  <UDashboardPanel id="desembolsos">
    <template #header>
      <UDashboardNavbar title="Desembolsos">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton
            v-if="canAny('desembolsos.registrar')"
            icon="i-lucide-plus"
            label="Registrar desembolso"
            @click="router.visit(route('desembolsos.create'))"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <div class="rounded-xl border border-default bg-elevated/20 p-4">
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <USelect
              v-model="edificioId"
              :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]"
              size="xl"
              @update:model-value="changeBuilding"
            />
            <USelect
              v-model="proveedorId"
              :items="[{ label: 'Todos los proveedores', value: '' }, ...providerItems]"
              size="xl"
              @update:model-value="reload(1, true)"
            />
            <div class="grid grid-cols-2 gap-3">
              <UInput v-model="fechaDesde" type="date" size="xl" aria-label="Fecha desde" @change="reload(1, true)" />
              <UInput v-model="fechaHasta" type="date" size="xl" aria-label="Fecha hasta" @change="reload(1, true)" />
            </div>
            <USelect
              v-model="formaPago"
              :items="[{ label: 'Todas las formas', value: '' }, ...Object.entries(paymentLabels).map(([value, label]) => ({ label, value }))]"
              size="xl"
              @update:model-value="reload(1, true)"
            />
            <USelect
              v-model="estado"
              :items="[{ label: 'Todos los estados', value: '' }, { label: 'Registrado', value: 'registrado' }, { label: 'Anulado', value: 'anulado' }]"
              size="xl"
              @update:model-value="reload(1, true)"
            />
          </div>
        </div>

        <div class="space-y-3 md:hidden" :class="loading ? 'opacity-60' : ''">
          <article
            v-for="desembolso in desembolsos.data"
            :key="desembolso.id"
            class="rounded-xl border border-default p-4"
            @click="router.visit(route('desembolsos.show', [desembolso.edificioId, desembolso.id]))"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="font-mono font-semibold text-primary">{{ desembolso.numero }}</p>
                <p class="mt-1 text-sm font-medium text-highlighted">{{ desembolso.proveedor }}</p>
                <p class="text-xs text-muted">{{ desembolso.edificio }}</p>
              </div>
              <UBadge :color="desembolso.estado === 'registrado' ? 'success' : 'neutral'" :label="desembolso.estado" variant="subtle" />
            </div>
            <div class="mt-4 flex items-end justify-between gap-3 border-t border-default pt-3">
              <div class="text-sm text-muted">
                <p>{{ desembolso.fechaDesembolso }} · {{ paymentLabels[desembolso.formaPago] }}</p>
                <p>{{ desembolso.cantidadCuentas }} cuenta(s) aplicada(s)</p>
              </div>
              <p class="font-mono text-lg font-semibold text-highlighted">${{ desembolso.monto }}</p>
            </div>
          </article>
          <p v-if="!desembolsos.data.length" class="rounded-xl border border-dashed border-default p-10 text-center text-muted">
            No hay desembolsos que coincidan con los filtros.
          </p>
        </div>

        <div class="hidden overflow-x-auto rounded-xl border border-default md:block" :class="loading ? 'opacity-60' : ''">
          <table class="w-full min-w-5xl text-left text-sm">
            <thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted">
              <tr>
                <th class="p-3">Número</th>
                <th class="p-3">Fecha</th>
                <th class="p-3">Proveedor</th>
                <th class="p-3">Forma</th>
                <th class="p-3 text-center">Cuentas</th>
                <th class="p-3 text-right">Monto</th>
                <th class="p-3">Estado</th>
                <th class="p-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr
                v-for="desembolso in desembolsos.data"
                :key="desembolso.id"
                class="cursor-pointer hover:bg-elevated/30"
                @click="router.visit(route('desembolsos.show', [desembolso.edificioId, desembolso.id]))"
              >
                <td class="p-3"><p class="font-mono font-semibold text-primary">{{ desembolso.numero }}</p><p class="text-xs text-muted">{{ desembolso.edificio }}</p></td>
                <td class="p-3">{{ desembolso.fechaDesembolso }}</td>
                <td class="p-3"><p class="font-medium text-highlighted">{{ desembolso.proveedor }}</p><p class="font-mono text-xs text-muted">{{ desembolso.proveedorIdentificacion }}</p></td>
                <td class="p-3">{{ paymentLabels[desembolso.formaPago] }}</td>
                <td class="p-3 text-center">{{ desembolso.cantidadCuentas }}</td>
                <td class="p-3 text-right font-mono font-semibold">${{ desembolso.monto }}</td>
                <td class="p-3"><UBadge :color="desembolso.estado === 'registrado' ? 'success' : 'neutral'" :label="desembolso.estado" variant="subtle" /></td>
                <td class="p-3 text-right"><UButton color="neutral" icon="i-lucide-eye" variant="ghost" aria-label="Ver desembolso" /></td>
              </tr>
              <tr v-if="!desembolsos.data.length"><td class="p-10 text-center text-muted" colspan="8">No hay desembolsos que coincidan con los filtros.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">{{ desembolsos.meta.total }} desembolso(s) · página {{ desembolsos.meta.currentPage }} de {{ desembolsos.meta.lastPage }}</p>
          <div class="flex gap-2">
            <UButton color="neutral" label="Anterior" variant="outline" :disabled="desembolsos.meta.currentPage <= 1 || loading" @click="reload(desembolsos.meta.currentPage - 1)" />
            <UButton color="neutral" label="Siguiente" variant="outline" :disabled="desembolsos.meta.currentPage >= desembolsos.meta.lastPage || loading" @click="reload(desembolsos.meta.currentPage + 1)" />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
