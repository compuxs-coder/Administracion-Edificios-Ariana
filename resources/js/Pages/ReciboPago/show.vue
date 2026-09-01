<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ReciboPago } from '../../types'

const props = defineProps<{ recibo: ReciboPago }>()
const file = ref<File | null>(null)
const descripcion = ref('')
const evidenceError = ref('')
const uploading = ref(false)

const submitEvidence = () => {
  if (!file.value) {
    evidenceError.value = 'Seleccione un archivo PDF, JPG o PNG.'
    return
  }
  router.post(route('evidencias.store', [props.recibo.edificio.id, props.recibo.pago.id]), { archivo: file.value, descripcion: descripcion.value }, {
    forceFormData: true,
    preserveScroll: true,
    onStart: () => { uploading.value = true; evidenceError.value = '' },
    onError: errors => { evidenceError.value = String(errors.archivo ?? errors.pago ?? 'No fue posible adjuntar la evidencia.') },
    onSuccess: () => { file.value = null; descripcion.value = '' },
    onFinish: () => { uploading.value = false },
  })
}

const download = (id: string) => { window.location.assign(route('evidencias.download', [props.recibo.edificio.id, props.recibo.pago.id, id])) }
const print = () => window.print()
</script>

<template>
  <UDashboardPanel id="recibo-pago">
    <template #header><UDashboardNavbar :title="recibo.numero"><template #leading><UDashboardSidebarCollapse /></template><template #right><div class="flex gap-2"><UButton icon="i-lucide-arrow-left" label="Pago" color="neutral" variant="outline" @click="router.visit(route('pagos.show', [recibo.edificio.id, recibo.pago.id]))" /><UButton icon="i-lucide-printer" label="Imprimir" @click="print" /></div></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <UAlert v-if="recibo.estado === 'anulado'" color="warning" icon="i-lucide-ban" :description="`Recibo anulado${recibo.anuladoAt ? ` el ${recibo.anuladoAt}` : ''}. ${recibo.motivoAnulacion || ''}`" />
        <UCard>
          <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">Recibo de pago</p><h1 class="mt-1 font-mono text-2xl font-semibold text-highlighted">{{ recibo.numero }}</h1></div><UBadge :color="recibo.estado === 'emitido' ? 'success' : 'warning'" :label="recibo.estado" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><div><dt class="text-xs uppercase text-muted">Edificio</dt><dd class="mt-1">{{ recibo.edificio.nombre }}</dd></div><div><dt class="text-xs uppercase text-muted">Departamento</dt><dd class="mt-1">{{ recibo.departamento.codigo }} · {{ recibo.departamento.nombre }}</dd></div><div><dt class="text-xs uppercase text-muted">Fecha de pago</dt><dd class="mt-1">{{ recibo.fechaPago }}</dd></div><div><dt class="text-xs uppercase text-muted">Titular(es)</dt><dd class="mt-1">{{ recibo.titulares.map(item => item.nombre).join(', ') || 'Sin titular vigente' }}</dd></div><div><dt class="text-xs uppercase text-muted">Forma de pago</dt><dd class="mt-1 capitalize">{{ recibo.formaPago }}</dd></div><div><dt class="text-xs uppercase text-muted">Referencia</dt><dd class="mt-1">{{ recibo.referencia || 'Sin referencia' }}</dd></div><div><dt class="text-xs uppercase text-muted">Valor recibido</dt><dd class="mt-1 font-mono text-xl font-semibold">${{ recibo.valorRecibido }}</dd></div><div><dt class="text-xs uppercase text-muted">Emitido por</dt><dd class="mt-1">{{ recibo.emitidoPor || 'Usuario eliminado' }}</dd></div></dl>
        </UCard>
        <UCard><template #header><p class="font-semibold text-highlighted">Aplicación al emitir</p></template><div class="divide-y divide-default"><div v-for="item in recibo.aplicaciones" :key="item.cargoId" class="flex items-center justify-between gap-4 py-3"><div><p>{{ item.concepto }}</p><p class="text-xs text-muted">Período {{ item.periodo }} · vence {{ item.fechaVencimiento }}</p></div><span class="font-mono">${{ item.valorAplicado }}</span></div><p v-if="!recibo.aplicaciones.length" class="py-3 text-muted">El valor completo quedó como saldo a favor.</p></div></UCard>
        <UCard><template #header><p class="font-semibold text-highlighted">Evidencias de pago</p></template><div class="divide-y divide-default"><div v-for="evidencia in recibo.evidencias" :key="evidencia.id" class="flex items-center justify-between gap-4 py-3"><div><p>{{ evidencia.nombre }}</p><p class="text-xs text-muted">{{ evidencia.descripcion || evidencia.mimeType }} · {{ evidencia.subidoPor || 'Usuario eliminado' }}</p></div><UButton icon="i-lucide-download" label="Descargar" size="xs" variant="outline" @click="download(evidencia.id)" /></div><p v-if="!recibo.evidencias.length" class="py-3 text-muted">No hay evidencias adjuntas.</p></div><form v-if="recibo.estado === 'emitido'" class="mt-5 grid gap-3 border-t border-default pt-5 sm:grid-cols-[1fr_2fr_auto]" @submit.prevent="submitEvidence"><UInput accept="application/pdf,image/jpeg,image/png" type="file" required @change="file = ($event.target as HTMLInputElement).files?.[0] || null" /><UInput v-model="descripcion" maxlength="500" placeholder="Descripción opcional" /><UButton label="Adjuntar" type="submit" :loading="uploading" /><p v-if="evidenceError" class="text-sm text-error sm:col-span-3">{{ evidenceError }}</p></form></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
