<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ProveedorForm from '../../components/proveedores/ProveedorForm.vue'
import type { Proveedor, ProveedorFormData } from '../../types'

const props = defineProps<{ proveedor: Proveedor }>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<ProveedorFormData>(() => ({
  edificio_id: props.proveedor.edificioId,
  tipo_persona: props.proveedor.tipoPersona,
  nombres: props.proveedor.nombres ?? '',
  apellidos: props.proveedor.apellidos ?? '',
  razon_social: props.proveedor.razonSocial ?? '',
  tipo_identificacion: props.proveedor.tipoIdentificacion,
  identificacion: props.proveedor.identificacion,
  telefono: props.proveedor.telefonoIdentidad ?? '',
  celular: props.proveedor.celular ?? '',
  correo: props.proveedor.correoIdentidad ?? '',
  direccion: props.proveedor.direccionIdentidad ?? '',
  nombre_comercial: props.proveedor.nombreComercial ?? '',
  contacto: props.proveedor.contacto ?? '',
  telefono_comercial: props.proveedor.telefono ?? '',
  correo_comercial: props.proveedor.correo ?? '',
  direccion_comercial: props.proveedor.direccion ?? '',
  dias_credito: props.proveedor.diasCredito === null ? '' : String(props.proveedor.diasCredito),
  observaciones: props.proveedor.observaciones ?? ''
}))
const submit = (data: ProveedorFormData) => router.put(route('proveedores.update', [props.proveedor.edificioId, props.proveedor.id]), {
  nombre_comercial: data.nombre_comercial,
  contacto: data.contacto,
  telefono_comercial: data.telefono_comercial,
  correo_comercial: data.correo_comercial,
  direccion_comercial: data.direccion_comercial,
  dias_credito: data.dias_credito,
  observaciones: data.observaciones
}, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="proveedores-edit">
    <template #header><UDashboardNavbar title="Editar proveedor"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="font-mono text-sm font-medium text-primary">{{ proveedor.identificacion }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ proveedor.nombre }}</h1><p class="mt-2 text-sm text-muted">Edite el perfil comercial local de {{ proveedor.edificio }}. La identidad compartida se muestra sólo como referencia.</p></div>
        <UCard><ProveedorForm :initial="initial" :errors="errors" :loading="loading" lock-identity submit-label="Actualizar proveedor" @cancel="router.visit(route('proveedores.show', [proveedor.edificioId, proveedor.id]))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
