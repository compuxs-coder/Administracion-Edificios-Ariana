<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Proveedor } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

defineProps<{ proveedor: Proveedor }>()
const { can } = useBuildingPermissions()
</script>

<template>
  <UDashboardPanel id="proveedores-show">
    <template #header>
      <UDashboardNavbar :title="proveedor.nombreComercial || proveedor.nombre">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><div class="flex gap-2"><UButton v-if="can('gastos.gestionar', proveedor.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('proveedores.edit', [proveedor.edificioId, proveedor.id]))" /><UButton v-if="can('gastos.gestionar', proveedor.edificioId)" icon="i-lucide-file-plus-2" label="Nuevo contrato" @click="router.visit(route('contratos-proveedor.create', { edificio_id: proveedor.edificioId, proveedor_id: proveedor.id }))" /></div></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UCard>
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">{{ proveedor.edificio }}</p><h1 class="mt-1 text-xl font-semibold text-highlighted">{{ proveedor.nombre }}</h1><p v-if="proveedor.nombreComercial" class="mt-1 text-sm text-muted">{{ proveedor.nombreComercial }}</p></div><UBadge :color="proveedor.estado === 'activo' ? 'success' : 'neutral'" :label="proveedor.estado" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase tracking-wide text-muted">Identificación</dt><dd class="mt-1 font-mono">{{ proveedor.tipoIdentificacion.toUpperCase() }} · {{ proveedor.identificacion }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-muted">Tipo</dt><dd class="mt-1">{{ proveedor.tipoPersona === 'persona_natural' ? 'Persona natural' : 'Persona jurídica' }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-muted">Teléfono de identidad</dt><dd class="mt-1">{{ proveedor.celular || proveedor.telefonoIdentidad || 'No registrado' }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-muted">Correo de identidad</dt><dd class="mt-1 break-all">{{ proveedor.correoIdentidad || 'No registrado' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs uppercase tracking-wide text-muted">Dirección de identidad</dt><dd class="mt-1">{{ proveedor.direccionIdentidad || 'No registrada' }}</dd></div>
          </dl>
        </UCard>
        <UCard>
          <template #header><div><p class="font-semibold text-highlighted">Perfil comercial</p><p class="mt-1 text-xs text-muted">Datos locales para {{ proveedor.edificio }}.</p></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Contacto</dt><dd class="mt-1">{{ proveedor.contacto || 'No registrado' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Teléfono</dt><dd class="mt-1">{{ proveedor.telefono || 'No registrado' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Correo</dt><dd class="mt-1 break-all">{{ proveedor.correo || 'No registrado' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Dirección</dt><dd class="mt-1">{{ proveedor.direccion || 'No registrada' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Plazo de crédito</dt><dd class="mt-1">{{ proveedor.diasCredito === null ? 'No definido' : `${proveedor.diasCredito} días` }}</dd></div>
            <div v-if="proveedor.observaciones" class="sm:col-span-2 lg:col-span-3"><dt class="text-xs uppercase text-muted">Observaciones</dt><dd class="mt-1 whitespace-pre-line">{{ proveedor.observaciones }}</dd></div>
          </dl>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
