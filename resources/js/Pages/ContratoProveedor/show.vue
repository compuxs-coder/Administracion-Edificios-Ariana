<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ContratoProveedor } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ contrato: ContratoProveedor }>()
const { can } = useBuildingPermissions()
const registerOpen = ref(false)
const cancelOpen = ref(false)
const motivo = ref('')
const loading = ref(false)
const error = ref('')
const stateColor = (state: string) => state === 'registrado' ? 'success' : state === 'borrador' ? 'warning' : 'neutral'
const register = () => router.patch(route('contratos-proveedor.register', [props.contrato.edificioId, props.contrato.id]), {}, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { registerOpen.value = false },
  onError: errors => { error.value = String(errors.estado ?? 'No fue posible registrar el contrato.') },
  onFinish: () => { loading.value = false }
})
const cancel = () => router.patch(route('contratos-proveedor.cancel', [props.contrato.edificioId, props.contrato.id]), { motivo: motivo.value }, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? 'No fue posible anular el contrato.') },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="contratos-proveedor-show">
    <template #header><UDashboardNavbar :title="`Contrato ${contrato.referencia}`"><template #leading><UDashboardSidebarCollapse /></template><template #right><div class="flex flex-wrap gap-2"><UButton v-if="contrato.estado === 'borrador' && can('gastos.gestionar', contrato.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('contratos-proveedor.edit', [contrato.edificioId, contrato.id]))" /><UButton v-if="contrato.estado === 'borrador' && can('gastos.gestionar', contrato.edificioId)" icon="i-lucide-badge-check" label="Registrar" @click="error = ''; registerOpen = true" /><UButton v-if="contrato.estado === 'registrado' && can('gastos.anular', contrato.edificioId)" color="error" icon="i-lucide-ban" label="Anular" variant="outline" @click="error = ''; cancelOpen = true" /></div></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UCard><template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">{{ contrato.edificio }}</p><h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ contrato.referencia }}</h1></div><UBadge :color="stateColor(contrato.estado)" :label="contrato.estado" variant="subtle" /></div></template><dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><div><dt class="text-xs uppercase text-muted">Proveedor</dt><dd class="mt-1 font-medium">{{ contrato.proveedor }}</dd><dd v-if="contrato.proveedorSnapshot" class="font-mono text-xs text-muted">{{ contrato.proveedorSnapshot.identificacion }}</dd></div><div><dt class="text-xs uppercase text-muted">Fecha de inicio</dt><dd class="mt-1">{{ contrato.fechaInicio }}</dd></div><div><dt class="text-xs uppercase text-muted">Fecha final</dt><dd class="mt-1">{{ contrato.fechaFin || 'Vigencia indefinida' }}</dd></div><div><dt class="text-xs uppercase text-muted">Monto total</dt><dd class="mt-1 font-mono text-lg">{{ contrato.montoTotal === null ? 'No definido' : `$${contrato.montoTotal}` }}</dd></div><div class="sm:col-span-2"><dt class="text-xs uppercase text-muted">Objeto</dt><dd class="mt-1 whitespace-pre-line">{{ contrato.objeto }}</dd></div></dl></UCard>
        <UCard v-if="contrato.observaciones"><template #header><p class="font-semibold text-highlighted">Observaciones</p></template><p class="whitespace-pre-line text-sm text-muted">{{ contrato.observaciones }}</p></UCard>
        <UCard><template #header><p class="font-semibold text-highlighted">Trazabilidad</p></template><dl class="grid gap-5 sm:grid-cols-2"><div><dt class="text-xs uppercase text-muted">Creado</dt><dd class="mt-1">{{ contrato.createdAt || 'No disponible' }}</dd></div><div><dt class="text-xs uppercase text-muted">Registrado</dt><dd class="mt-1">{{ contrato.registradoAt || 'Pendiente' }}</dd></div><div v-if="contrato.estado === 'anulado'"><dt class="text-xs uppercase text-muted">Anulado</dt><dd class="mt-1">{{ contrato.anuladoAt }}</dd></div><div v-if="contrato.estado === 'anulado'"><dt class="text-xs uppercase text-muted">Motivo</dt><dd class="mt-1">{{ contrato.motivoAnulacion || 'Sin motivo registrado' }}</dd></div></dl></UCard>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="registerOpen" :close="!loading" :dismissible="!loading" title="Registrar contrato" description="Después de registrarlo no podrá editarse."><template #body><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><p class="text-sm text-muted">Se registrará el contrato <span class="font-mono text-highlighted">{{ contrato.referencia }}</span><span v-if="contrato.montoTotal"> por ${{ contrato.montoTotal }}</span>.</p></template><template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="registerOpen = false" /><UButton label="Registrar contrato" :loading="loading" @click="register" /></div></template></UModal>
  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Anular contrato" description="La anulación conserva toda la trazabilidad."><template #body><div class="space-y-4"><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo" required><UTextarea v-model="motivo" :rows="3" required /></UFormField></div></template><template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="cancelOpen = false" /><UButton color="error" label="Anular contrato" :disabled="!motivo" :loading="loading" @click="cancel" /></div></template></UModal>
</template>
