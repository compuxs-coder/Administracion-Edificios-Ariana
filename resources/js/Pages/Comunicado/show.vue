<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ComunicadoDetalle, EstadoComunicado, EstadoEntregaComunicado, TipoEventoComunicacion } from '../../types'

const props = defineProps<{ comunicado: ComunicadoDetalle }>()
const loading = ref(false)
const publishOpen = ref(false)
const archiveOpen = ref(false)
const actionError = ref('')
const file = ref<File | null>(null)
const description = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const stateLabels: Record<EstadoComunicado, string> = { borrador: 'Borrador', publicado: 'Publicado', archivado: 'Archivado' }
const stateColor = (value: EstadoComunicado): 'warning' | 'success' | 'neutral' => ({ borrador: 'warning', publicado: 'success', archivado: 'neutral' })[value]
const deliveryLabels: Record<EstadoEntregaComunicado, string> = { pendiente: 'Pendiente', procesando: 'Procesando', reintento: 'Reintento', enviada: 'Enviada', fallida: 'Fallida' }
const deliveryColor = (value: EstadoEntregaComunicado): 'warning' | 'info' | 'success' | 'error' | 'neutral' => ({ pendiente: 'neutral', procesando: 'info', reintento: 'warning', enviada: 'success', fallida: 'error' })[value]
const eventLabels: Record<TipoEventoComunicacion, string> = {
  comunicado_creado: 'Borrador creado',
  comunicado_actualizado: 'Borrador actualizado',
  documento_agregado: 'Documento agregado',
  documento_retirado: 'Documento retirado',
  comunicado_publicado: 'Comunicado publicado',
  comunicado_archivado: 'Comunicado archivado',
  entrega_reintentada: 'Entrega reintentada'
}
const audience = (values: string[]) => values.length === 2 ? 'Propietarios y residentes' : values[0] === 'propietarios' ? 'Propietarios' : 'Residentes'
const formatDateTime = (value: string | null) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.comunicado.timezone }).format(new Date(value)) : 'No disponible'
const formatBytes = (value: number) => value >= 1024 * 1024 ? `${(value / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(value / 1024))} KB`
const eventDetail = (type: TipoEventoComunicacion, detail: Record<string, unknown> | null) => {
  if (!detail) return 'Sin detalle adicional.'
  if (type === 'comunicado_publicado' && typeof detail.entregas === 'number') return `${String(detail.entregas)} entrega(s), ${String(detail.omitidos ?? 0)} omitido(s) y ${String(detail.duplicados ?? 0)} duplicado(s).`
  if (type === 'comunicado_publicado') return 'Audiencia materializada al publicar.'
  if (type === 'comunicado_actualizado' && detail.cambios && typeof detail.cambios === 'object') return `Campos actualizados: ${Object.keys(detail.cambios as object).join(', ')}.`
  if (typeof detail.nombre === 'string') return detail.nombre
  if (typeof detail.entregaId === 'string') return `Entrega ${detail.entregaId}.`
  return 'Evento registrado con detalle estructurado.'
}
const pickFile = (event: Event) => {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}
const upload = () => {
  if (!file.value) return
  router.post(route('comunicados.documents.store', [props.comunicado.edificioId, props.comunicado.id]), {
    archivo: file.value,
    descripcion: description.value
  }, {
    forceFormData: true,
    preserveScroll: true,
    onStart: () => { loading.value = true; actionError.value = '' },
    onSuccess: () => {
      file.value = null
      description.value = ''
      if (fileInput.value) fileInput.value.value = ''
    },
    onError: errors => { actionError.value = String(errors.archivo ?? 'No fue posible adjuntar el documento.') },
    onFinish: () => { loading.value = false }
  })
}
const removeDocument = (documentId: string) => {
  if (!window.confirm('¿Retirar este documento del borrador?')) return
  router.delete(route('comunicados.documents.destroy', [props.comunicado.edificioId, props.comunicado.id, documentId]), {
    preserveScroll: true,
    onStart: () => { loading.value = true; actionError.value = '' },
    onError: errors => { actionError.value = String(errors.estado ?? 'No fue posible retirar el documento.') },
    onFinish: () => { loading.value = false }
  })
}
const downloadDocument = (documentId: string) => {
  window.location.assign(route('comunicados.documents.download', [props.comunicado.edificioId, props.comunicado.id, documentId]))
}
const publish = () => router.post(route('comunicados.publish', [props.comunicado.edificioId, props.comunicado.id]), {
  updated_at: props.comunicado.updatedAt
}, {
  preserveScroll: true,
  onStart: () => { loading.value = true; actionError.value = '' },
  onSuccess: () => { publishOpen.value = false },
  onError: errors => { actionError.value = String(errors.documentos ?? errors.destinatarios ?? errors.updatedAt ?? errors.estado ?? 'No fue posible publicar el comunicado.') },
  onFinish: () => { loading.value = false }
})
const archive = () => router.patch(route('comunicados.archive', [props.comunicado.edificioId, props.comunicado.id]), {}, {
  preserveScroll: true,
  onStart: () => { loading.value = true; actionError.value = '' },
  onSuccess: () => { archiveOpen.value = false },
  onError: errors => { actionError.value = String(errors.estado ?? 'No fue posible archivar el comunicado.') },
  onFinish: () => { loading.value = false }
})
const retry = (deliveryId: string) => router.post(route('comunicados.deliveries.retry', [props.comunicado.edificioId, props.comunicado.id, deliveryId]), {}, {
  preserveScroll: true,
  onStart: () => { loading.value = true; actionError.value = '' },
  onError: errors => { actionError.value = String(errors.entrega ?? 'No fue posible reintentar la entrega.') },
  onFinish: () => { loading.value = false }
})
const tracePage = (section: 'entregas' | 'omitidos', page: number) => router.get(route('comunicados.show', [props.comunicado.edificioId, props.comunicado.id]), {
  entregas_page: section === 'entregas' ? page : props.comunicado.entregasMeta?.currentPage ?? 1,
  omitidos_page: section === 'omitidos' ? page : props.comunicado.omitidosMeta?.currentPage ?? 1
}, {
  preserveScroll: true,
  preserveState: true,
  only: ['comunicado'],
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="comunicados-show">
    <template #header><UDashboardNavbar :title="comunicado.titulo"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <UAlert v-if="actionError" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" />
        <div class="flex flex-wrap justify-end gap-2">
          <UButton color="neutral" icon="i-lucide-list" label="Todos los comunicados" variant="outline" @click="router.visit(route('comunicados.index'))" />
          <UButton v-if="comunicado.estado === 'borrador' && comunicado.puedeGestionar" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('comunicados.edit', [comunicado.edificioId, comunicado.id]))" />
          <UButton v-if="comunicado.estado === 'borrador' && comunicado.puedePublicar" icon="i-lucide-send" label="Publicar" :disabled="!comunicado.vistaPrevia?.entregas" @click="actionError = ''; publishOpen = true" />
          <UButton v-if="comunicado.estado === 'publicado' && comunicado.puedePublicar" color="neutral" icon="i-lucide-archive" label="Archivar" variant="outline" @click="actionError = ''; archiveOpen = true" />
        </div>

        <UCard>
          <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div><p class="text-sm font-medium text-primary">{{ comunicado.edificio }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ comunicado.titulo }}</h1><p class="mt-2 text-xs text-muted">Versión {{ comunicado.version }} · {{ audience(comunicado.audiencias) }}</p></div>
              <UBadge :color="stateColor(comunicado.estado)" :label="stateLabels[comunicado.estado]" size="lg" variant="subtle" />
            </div>
          </template>
          <p class="whitespace-pre-line text-sm leading-7 text-toned">{{ comunicado.cuerpo }}</p>
          <dl class="mt-6 grid gap-5 border-t border-default pt-5 sm:grid-cols-3">
            <div><dt class="text-xs uppercase text-muted">Creado</dt><dd class="mt-1 text-sm">{{ formatDateTime(comunicado.createdAt) }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Publicado</dt><dd class="mt-1 text-sm">{{ formatDateTime(comunicado.publicadoAt) }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Zona horaria</dt><dd class="mt-1 font-mono text-sm">{{ comunicado.timezone }}</dd></div>
          </dl>
        </UCard>

        <section v-if="comunicado.estado === 'borrador' && comunicado.vistaPrevia" class="space-y-4">
          <div><h2 class="text-lg font-semibold text-highlighted">Vista previa de destinatarios</h2><p class="mt-1 text-sm text-muted">Conteo dinámico; se recalculará dentro de la transacción de publicación.</p></div>
          <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <UCard><p class="text-xs text-muted">Candidatos</p><p class="mt-2 text-2xl font-semibold">{{ comunicado.vistaPrevia.candidatos }}</p></UCard>
            <UCard><p class="text-xs text-muted">Propietarios</p><p class="mt-2 text-2xl font-semibold">{{ comunicado.vistaPrevia.propietarios }}</p></UCard>
            <UCard><p class="text-xs text-muted">Residentes</p><p class="mt-2 text-2xl font-semibold">{{ comunicado.vistaPrevia.residentes }}</p></UCard>
            <UCard><p class="text-xs text-muted">Correos únicos</p><p class="mt-2 text-2xl font-semibold text-primary">{{ comunicado.vistaPrevia.entregas }}</p></UCard>
            <UCard><p class="text-xs text-muted">Duplicados</p><p class="mt-2 text-2xl font-semibold">{{ comunicado.vistaPrevia.duplicados }}</p></UCard>
            <UCard><p class="text-xs text-muted">Omitidos</p><p class="mt-2 text-2xl font-semibold" :class="comunicado.vistaPrevia.omitidos ? 'text-warning' : ''">{{ comunicado.vistaPrevia.omitidos }}</p></UCard>
          </div>
          <UAlert v-if="!comunicado.vistaPrevia.entregas" color="warning" icon="i-lucide-triangle-alert" variant="subtle" description="No existe ningún correo válido en la audiencia vigente. El comunicado no puede publicarse todavía." />
        </section>

        <section v-if="comunicado.puedeVerEntregas && comunicado.estado !== 'borrador'" class="space-y-4">
          <div><h2 class="text-lg font-semibold text-highlighted">Estado de entregas</h2><p class="mt-1 text-sm text-muted">Cada dirección normalizada recibe un mensaje individual.</p></div>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <UCard v-for="status in (['pendiente', 'procesando', 'reintento', 'enviada', 'fallida'] as EstadoEntregaComunicado[])" :key="status"><div class="flex items-center justify-between gap-2"><div><p class="text-xs text-muted">{{ deliveryLabels[status] }}</p><p class="mt-2 text-2xl font-semibold">{{ comunicado.resumenEntregas?.[status] ?? 0 }}</p></div><UBadge :color="deliveryColor(status)" :label="deliveryLabels[status]" variant="subtle" /></div></UCard>
          </div>
        </section>

        <section class="space-y-4">
          <div><h2 class="text-lg font-semibold text-highlighted">Documentos privados</h2><p class="mt-1 text-sm text-muted">Los destinatarios reciben enlaces firmados temporales; las rutas físicas nunca se exponen.</p></div>
          <UCard v-if="comunicado.estado === 'borrador' && comunicado.puedeGestionar">
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
              <UFormField label="Archivo" hint="PDF, JPG o PNG; máximo acumulado 10 MB"><input ref="fileInput" class="block w-full rounded-lg border border-default bg-default px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-elevated file:px-3 file:py-1.5 file:text-sm" type="file" accept="application/pdf,image/jpeg,image/png" @change="pickFile" /></UFormField>
              <UFormField label="Descripción" hint="Opcional"><UInput v-model="description" class="w-full" maxlength="500" placeholder="Finalidad del documento" /></UFormField>
              <UButton icon="i-lucide-paperclip" label="Adjuntar" :loading="loading" :disabled="!file" @click="upload" />
            </div>
          </UCard>
          <div v-if="comunicado.documentos.length" class="grid gap-3 sm:grid-cols-2">
            <UCard v-for="document in comunicado.documentos" :key="document.id">
              <div class="flex items-start gap-3"><div class="rounded-lg bg-elevated p-2"><UIcon :name="document.mimeType === 'application/pdf' ? 'i-lucide-file-text' : 'i-lucide-image'" class="size-5 text-primary" /></div><div class="min-w-0 flex-1"><p class="truncate font-medium text-highlighted">{{ document.nombre }}</p><p class="mt-1 text-xs text-muted">{{ formatBytes(document.tamanoBytes) }}<span v-if="document.descripcion"> · {{ document.descripcion }}</span></p></div></div>
              <div class="mt-4 flex justify-end gap-2"><UButton color="neutral" icon="i-lucide-download" label="Descargar" variant="outline" @click="downloadDocument(document.id)" /><UButton v-if="comunicado.estado === 'borrador' && comunicado.puedeGestionar" color="error" icon="i-lucide-trash-2" label="Retirar" variant="ghost" :disabled="loading" @click="removeDocument(document.id)" /></div>
            </UCard>
          </div>
          <p v-else class="rounded-xl border border-dashed border-default p-8 text-center text-sm text-muted">Este comunicado no tiene documentos adjuntos.</p>
        </section>

        <section v-if="comunicado.puedeVerEntregas && comunicado.estado !== 'borrador'" class="space-y-4">
          <div><h2 class="text-lg font-semibold text-highlighted">Trazabilidad sensible</h2><p class="mt-1 text-sm text-muted">Direcciones y errores visibles únicamente con el permiso de entregas.</p></div>
          <div class="overflow-x-auto rounded-xl border border-default">
            <table class="w-full min-w-4xl text-left text-sm"><thead class="bg-elevated/50 text-xs uppercase tracking-wide text-muted"><tr><th class="p-3">Destino</th><th class="p-3">Estado</th><th class="p-3 text-center">Intentos</th><th class="p-3">Último resultado</th><th class="p-3 text-right">Acción</th></tr></thead><tbody class="divide-y divide-default"><tr v-for="delivery in comunicado.entregas ?? []" :key="delivery.id"><td class="p-3"><p class="font-medium">{{ delivery.destino }}</p><p class="text-xs text-muted">{{ delivery.destinatariosCount }} identidad(es)</p></td><td class="p-3"><UBadge :color="deliveryColor(delivery.estado)" :label="deliveryLabels[delivery.estado]" variant="subtle" /></td><td class="p-3 text-center">{{ delivery.intentos }}</td><td class="max-w-md p-3 text-xs text-muted"><span v-if="delivery.estado === 'enviada'">Enviado {{ formatDateTime(delivery.enviadoAt) }}</span><span v-else-if="delivery.errorDetalle">{{ delivery.errorCodigo }} · {{ delivery.errorDetalle }}</span><span v-else>{{ formatDateTime(delivery.ultimoIntentoAt) }}</span></td><td class="p-3 text-right"><UButton v-if="delivery.estado === 'fallida' && comunicado.puedePublicar" color="error" icon="i-lucide-rotate-cw" label="Reintentar" variant="ghost" :loading="loading" @click="retry(delivery.id)" /></td></tr><tr v-if="!(comunicado.entregas ?? []).length"><td class="p-8 text-center text-muted" colspan="5">No hay entregas registradas.</td></tr></tbody></table>
          </div>
          <div v-if="comunicado.entregasMeta && comunicado.entregasMeta.lastPage > 1" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ comunicado.entregasMeta.total }} entrega(s) · página {{ comunicado.entregasMeta.currentPage }} de {{ comunicado.entregasMeta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="comunicado.entregasMeta.currentPage <= 1 || loading" @click="tracePage('entregas', comunicado.entregasMeta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="comunicado.entregasMeta.currentPage >= comunicado.entregasMeta.lastPage || loading" @click="tracePage('entregas', comunicado.entregasMeta.currentPage + 1)" /></div></div>
          <UCard v-if="(comunicado.omitidos ?? []).length"><template #header><div><p class="font-semibold text-highlighted">Destinatarios omitidos</p><p class="mt-1 text-xs text-muted">Identidades sin correo o con una dirección inválida al publicar.</p></div></template><div class="grid gap-3 sm:grid-cols-2"><div v-for="recipient in comunicado.omitidos" :key="recipient.id" class="rounded-lg border border-default p-3"><p class="font-medium">{{ recipient.nombre }}</p><p class="mt-1 text-xs text-muted">{{ recipient.email || 'Sin correo' }} · {{ recipient.motivo === 'sin_correo' ? 'correo ausente' : 'correo inválido' }}</p></div></div></UCard>
          <div v-if="comunicado.omitidosMeta && comunicado.omitidosMeta.lastPage > 1" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ comunicado.omitidosMeta.total }} omitido(s) · página {{ comunicado.omitidosMeta.currentPage }} de {{ comunicado.omitidosMeta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="comunicado.omitidosMeta.currentPage <= 1 || loading" @click="tracePage('omitidos', comunicado.omitidosMeta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="comunicado.omitidosMeta.currentPage >= comunicado.omitidosMeta.lastPage || loading" @click="tracePage('omitidos', comunicado.omitidosMeta.currentPage + 1)" /></div></div>
        </section>

        <UCard>
          <template #header><div><p class="font-semibold text-highlighted">Bitácora</p><p class="mt-1 text-xs text-muted">Historial inmutable de contenido, documentos, publicación y acciones manuales.</p></div></template>
          <div v-if="comunicado.bitacora.length" class="relative ml-2 border-l border-default pl-6"><div v-for="entry in comunicado.bitacora" :key="entry.id" class="relative min-w-0 pb-6 last:pb-0"><span class="absolute -left-[1.78rem] top-1 size-3 rounded-full border-2 border-default bg-primary"></span><p class="font-medium">{{ eventLabels[entry.tipo] }}</p><p class="mt-1 break-words text-sm text-muted">{{ eventDetail(entry.tipo, entry.detalle) }}</p><p class="mt-1 text-xs text-dimmed">{{ entry.actorNombre }} · {{ formatDateTime(entry.createdAt) }}</p></div></div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="publishOpen" :close="!loading" :dismissible="!loading" title="Publicar comunicado" description="Esta acción congelará el contenido, los documentos y la audiencia. No podrá deshacerse.">
    <template #body><div class="space-y-4"><UAlert color="warning" icon="i-lucide-lock-keyhole" variant="subtle" description="Los cambios posteriores en Propiedad no alterarán este envío." /><dl v-if="comunicado.vistaPrevia" class="grid grid-cols-2 gap-4 text-sm"><div><dt class="text-muted">Correos únicos</dt><dd class="mt-1 text-xl font-semibold">{{ comunicado.vistaPrevia.entregas }}</dd></div><div><dt class="text-muted">Omitidos</dt><dd class="mt-1 text-xl font-semibold">{{ comunicado.vistaPrevia.omitidos }}</dd></div></dl><UAlert v-if="actionError" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /></div></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="publishOpen = false" /><UButton icon="i-lucide-send" label="Publicar y encolar" :loading="loading" @click="publish" /></div></template>
  </UModal>

  <UModal v-model:open="archiveOpen" :close="!loading" :dismissible="!loading" title="Archivar comunicado" description="El comunicado conservará su contenido, documentos y trazabilidad, pero saldrá de la vista activa.">
    <template #body><UAlert v-if="actionError" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /></template>
    <template #footer><div class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="archiveOpen = false" /><UButton color="neutral" icon="i-lucide-archive" label="Archivar" :loading="loading" @click="archive" /></div></template>
  </UModal>
</template>
