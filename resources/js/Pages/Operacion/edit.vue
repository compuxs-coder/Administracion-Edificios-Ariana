<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import OrdenOperativaForm from '../../components/operaciones/OrdenOperativaForm.vue'
import type { ContratoOperacionOption, EdificioOperacionOption, ElementoOperacionOption, OrdenOperativa, OrdenOperativaFormData, ProveedorOperacionOption, ReportanteOperacionOption } from '../../types'

const props = defineProps<{
  orden: OrdenOperativa
  edificios: EdificioOperacionOption[]
  torres: ElementoOperacionOption[]
  pisos: ElementoOperacionOption[]
  departamentos: ElementoOperacionOption[]
  parqueaderos: ElementoOperacionOption[]
  bodegas: ElementoOperacionOption[]
  residentes: ReportanteOperacionOption[]
  proveedores: ProveedorOperacionOption[]
  contratos: ContratoOperacionOption[]
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<OrdenOperativaFormData>(() => ({
  edificio_id: props.orden.edificioId,
  tipo: props.orden.tipo,
  titulo: props.orden.titulo,
  descripcion: props.orden.descripcion,
  prioridad: props.orden.prioridad,
  fecha_objetivo: props.orden.fechaObjetivo ?? '',
  torre_id: props.orden.torreId ?? '',
  piso_id: props.orden.pisoId ?? '',
  departamento_id: props.orden.departamentoId ?? '',
  parqueadero_id: props.orden.parqueaderoId ?? '',
  bodega_id: props.orden.bodegaId ?? '',
  ubicacion_detalle: props.orden.ubicacionDetalle ?? '',
  reportante_residente_id: props.orden.reportanteResidenteId ?? '',
  proveedor_id: props.orden.proveedorId ?? '',
  contrato_id: props.orden.contratoId ?? ''
}))
const formOptions = computed(() => ({
  edificios: props.edificios,
  torres: props.torres,
  pisos: props.pisos,
  departamentos: props.departamentos,
  parqueaderos: props.parqueaderos,
  bodegas: props.bodegas,
  residentes: props.orden.reportanteSnapshot && !props.residentes.some(item => item.residenteId === props.orden.reportanteResidenteId)
    ? [...props.residentes, {
        edificioId: props.orden.edificioId,
        residenteId: props.orden.reportanteResidenteId!,
        nombre: props.orden.reportanteSnapshot.nombre,
        identificacion: props.orden.reportanteSnapshot.identificacion ?? 'No disponible'
      }]
    : props.residentes,
  proveedores: props.orden.proveedor && !props.proveedores.some(item => item.proveedorId === props.orden.proveedorId)
    ? [...props.proveedores, props.orden.proveedor]
    : props.proveedores,
  contratos: props.orden.contrato && !props.contratos.some(item => item.id === props.orden.contratoId)
    ? [...props.contratos, props.orden.contrato]
    : props.contratos,
  currentLocation: props.orden.ubicacion ?? null
}))
const submit = (data: OrdenOperativaFormData) => {
  if (!props.orden.puedeEditar) return
  const { tipo: _tipo, edificio_id: _building, ...payload } = data
  router.put(route('operaciones.update', [props.orden.edificioId, props.orden.id]), {
    ...payload,
    updated_at: props.orden.updatedAt
  }, {
    onStart: () => { loading.value = true },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="operaciones-edit">
    <template #header><UDashboardNavbar :title="`Editar · ${orden.numero}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><UAlert v-if="errors.estado || errors.updatedAt" class="mb-5" color="error" :description="errors.estado || errors.updatedAt" icon="i-lucide-circle-alert" variant="subtle" /><UCard><OrdenOperativaForm :key="orden.updatedAt || orden.id" v-bind="formOptions" :initial="initial" :errors="errors" :loading="loading" :lock-scope="true" :lock-type="true" submit-label="Actualizar orden" @cancel="router.visit(route('operaciones.show', [orden.edificioId, orden.id]))" @submit="submit" /></UCard></div></template>
  </UDashboardPanel>
</template>
