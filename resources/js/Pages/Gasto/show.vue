<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Gasto } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{ gasto: Gasto }>()
const { can } = useBuildingPermissions()
const registerOpen = ref(false)
const cancelOpen = ref(false)
const motivo = ref('')
const loading = ref(false)
const error = ref('')
const stateColor = (state: string) => state === 'registrado' ? 'success' : state === 'borrador' ? 'warning' : 'neutral'
const register = () => router.patch(route('gastos.register', [props.gasto.edificioId, props.gasto.id]), {}, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { registerOpen.value = false },
  onError: errors => { error.value = String(errors.estado ?? errors.monto ?? 'No fue posible registrar el gasto.') },
  onFinish: () => { loading.value = false }
})
const cancel = () => router.patch(route('gastos.cancel', [props.gasto.edificioId, props.gasto.id]), { motivo: motivo.value }, {
  preserveScroll: true,
  onStart: () => { loading.value = true },
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { error.value = String(errors.motivo ?? errors.estado ?? 'No fue posible anular el gasto.') },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="gastos-show">
    <template #header>
      <UDashboardNavbar :title="gasto.numero ? `Gasto ${gasto.numero}` : 'Gasto en borrador'">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><div class="flex flex-wrap gap-2"><UButton v-if="gasto.estado === 'borrador' && can('gastos.gestionar', gasto.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('gastos.edit', [gasto.edificioId, gasto.id]))" /><UButton v-if="gasto.estado === 'borrador' && can('gastos.gestionar', gasto.edificioId)" icon="i-lucide-badge-check" label="Registrar" @click="error = ''; registerOpen = true" /><UButton v-if="gasto.estado === 'registrado' && can('gastos.anular', gasto.edificioId)" color="error" icon="i-lucide-ban" label="Anular" variant="outline" @click="error = ''; cancelOpen = true" /></div></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UCard>
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">{{ gasto.edificio }}</p><h1 class="mt-1 font-mono text-xl font-semibold text-highlighted">{{ gasto.numero || 'Sin número hasta su registro' }}</h1></div><UBadge :color="stateColor(gasto.estado)" :label="gasto.estado" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Proveedor</dt><dd class="mt-1 font-medium">{{ gasto.proveedor }}</dd><dd v-if="gasto.proveedorSnapshot" class="font-mono text-xs text-muted">{{ gasto.proveedorSnapshot.identificacion }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Fecha del gasto</dt><dd class="mt-1">{{ gasto.fechaGasto }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Contrato</dt><dd class="mt-1 font-mono">{{ gasto.contratoSnapshot?.referencia || 'Sin contrato' }}</dd></div>
            <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs uppercase text-muted">Concepto</dt><dd class="mt-1 whitespace-pre-line">{{ gasto.concepto }}</dd></div>
          </dl>
        </UCard>
        <div class="grid gap-6 lg:grid-cols-[1fr_1fr]">
          <UCard><template #header><p class="font-semibold text-highlighted">Valor</p></template><p class="text-xs uppercase text-muted">Total registrado</p><p class="mt-2 font-mono text-3xl font-semibold text-highlighted">${{ gasto.monto }}</p><p v-if="gasto.referencia" class="mt-4 text-sm text-muted">Referencia: <span class="text-highlighted">{{ gasto.referencia }}</span></p></UCard>
          <UCard><template #header><p class="font-semibold text-highlighted">Pago</p></template><dl class="grid gap-4 sm:grid-cols-2"><div><dt class="text-xs uppercase text-muted">Tipo</dt><dd class="mt-1 capitalize">{{ gasto.tipoPago }}</dd></div><div><dt class="text-xs uppercase text-muted">Estado</dt><dd class="mt-1 capitalize">{{ gasto.estadoPago || 'Pendiente de registro' }}</dd></div><div v-if="gasto.tipoPago === 'credito'"><dt class="text-xs uppercase text-muted">Vencimiento</dt><dd class="mt-1">{{ gasto.fechaVencimiento }}</dd></div><div v-if="gasto.pagadoAt"><dt class="text-xs uppercase text-muted">Marcado pagado</dt><dd class="mt-1">{{ gasto.pagadoAt }}</dd></div></dl></UCard>
        </div>
        <UCard v-if="gasto.cuentaPorPagarId"><template #header><div><p class="font-semibold text-highlighted">Cuenta por pagar</p><p class="mt-1 text-xs text-muted">Generada automáticamente al registrar el gasto a crédito.</p></div></template><UButton color="neutral" icon="i-lucide-landmark" label="Consultar cuenta por pagar" variant="outline" @click="router.visit(route('cuentas-por-pagar.show', [gasto.edificioId, gasto.cuentaPorPagarId]))" /></UCard>
        <UCard v-if="gasto.observaciones || gasto.estado === 'anulado'"><template #header><p class="font-semibold text-highlighted">Notas y trazabilidad</p></template><p v-if="gasto.observaciones" class="whitespace-pre-line text-sm text-muted">{{ gasto.observaciones }}</p><UAlert v-if="gasto.estado === 'anulado'" :class="gasto.observaciones ? 'mt-5' : ''" color="neutral" :description="gasto.motivoAnulacion || 'Sin motivo registrado.'" icon="i-lucide-ban" variant="subtle" /></UCard>
        <UCard><template #header><p class="font-semibold text-highlighted">Trazabilidad</p></template><dl class="grid gap-5 sm:grid-cols-3"><div><dt class="text-xs uppercase text-muted">Creado</dt><dd class="mt-1">{{ gasto.createdAt || 'No disponible' }}</dd></div><div><dt class="text-xs uppercase text-muted">Registrado</dt><dd class="mt-1">{{ gasto.registradoAt || 'Pendiente' }}</dd></div><div v-if="gasto.estado === 'anulado'"><dt class="text-xs uppercase text-muted">Anulado</dt><dd class="mt-1">{{ gasto.anuladoAt }}</dd></div></dl></UCard>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="registerOpen" :close="!loading" :dismissible="!loading" title="Registrar gasto" description="Después de registrarlo no podrá editarse."><template #body><div class="space-y-4"><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UAlert v-if="gasto.tipoPago === 'credito'" color="info" description="Se generará una cuenta por pagar por el total del gasto." icon="i-lucide-landmark" variant="subtle" /><dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-xs uppercase text-muted">Proveedor</dt><dd class="mt-1">{{ gasto.proveedor }}</dd></div><div><dt class="text-xs uppercase text-muted">Total</dt><dd class="mt-1 font-mono">${{ gasto.monto }}</dd></div></dl></div></template><template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="registerOpen = false" /><UButton label="Registrar gasto" :loading="loading" @click="register" /></div></template></UModal>
  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Anular gasto" description="La cuenta por pagar asociada quedará anulada y se conservará la trazabilidad."><template #body><div class="space-y-4"><UAlert v-if="error" color="error" :description="error" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo" required><UTextarea v-model="motivo" :rows="3" required /></UFormField></div></template><template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="cancelOpen = false" /><UButton color="error" label="Anular gasto" :disabled="!motivo" :loading="loading" @click="cancel" /></div></template></UModal>
</template>
