<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type {
  AsignacionOrdenOperativa,
  BitacoraOrdenOperativa,
  EstadoOrdenOperativa,
  MiembroOperacionOption,
  OrdenOperativa,
  PrioridadOrdenOperativa,
  ProveedorOperacionOption,
  TipoEventoOrdenOperativa,
  TipoResponsableOrdenOperativa
} from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  orden: OrdenOperativa
  miembros: MiembroOperacionOption[]
  proveedores: ProveedorOperacionOption[]
}>()
const { can } = useBuildingPermissions()
const loading = ref(false)
const actionError = ref('')
const assignOpen = ref(false)
const cancelOpen = ref(false)
const reopenOpen = ref(false)
const responsibleType = ref<TipoResponsableOrdenOperativa>('usuario')
const responsibleId = ref('')
const reason = ref('')
const actionDescription = ref('')
const evidenceDescription = ref('')
const evidenceFile = ref<File | null>(null)
const evidenceInput = ref<HTMLInputElement | null>(null)
type ErrorTarget = 'transition' | 'assign' | 'cancel' | 'reopen' | 'action' | 'evidence'
const errorTarget = ref<ErrorTarget>('transition')

const stateLabels: Record<EstadoOrdenOperativa, string> = {
  reportada: 'Reportada',
  en_revision: 'En revisión',
  en_progreso: 'En progreso',
  resuelta: 'Resuelta',
  cerrada: 'Cerrada',
  cancelada: 'Cancelada'
}
const stateColor = (value: EstadoOrdenOperativa): 'info' | 'warning' | 'primary' | 'success' | 'neutral' | 'error' => ({
  reportada: 'info',
  en_revision: 'warning',
  en_progreso: 'primary',
  resuelta: 'success',
  cerrada: 'neutral',
  cancelada: 'error'
})[value]
const priorityLabels: Record<PrioridadOrdenOperativa, string> = { baja: 'Baja', media: 'Media', alta: 'Alta', critica: 'Crítica' }
const eventLabels: Record<TipoEventoOrdenOperativa, string> = {
  creacion: 'Orden creada',
  cambio_datos: 'Datos actualizados',
  cambio_estado: 'Estado actualizado',
  asignacion: 'Responsable asignado',
  reasignacion: 'Responsable reasignado',
  cancelacion: 'Orden cancelada',
  reapertura: 'Orden reabierta',
  evidencia: 'Evidencia adjuntada',
  actuacion_manual: 'Actuación registrada'
}
const nextState = computed<EstadoOrdenOperativa | null>(() => ({
  reportada: 'en_revision',
  en_revision: 'en_progreso',
  en_progreso: 'resuelta',
  resuelta: 'cerrada',
  cerrada: null,
  cancelada: null
})[props.orden.estado] as EstadoOrdenOperativa | null)
const canTransition = computed(() => Boolean(nextState.value) && can('operaciones.cambiar_estado', props.orden.edificioId))
const canAssign = computed(() => props.orden.estado !== 'cancelada' && can('operaciones.asignar', props.orden.edificioId))
const canCancel = computed(() => ['reportada', 'en_revision', 'en_progreso'].includes(props.orden.estado) && can('operaciones.cancelar', props.orden.edificioId))
const canReopen = computed(() => ['resuelta', 'cerrada'].includes(props.orden.estado) && can('operaciones.reabrir', props.orden.edificioId))
const canContribute = computed(() => props.orden.puedeAportar && can('operaciones.gestionar', props.orden.edificioId))
const responsibleItems = computed(() => responsibleType.value === 'usuario'
  ? props.miembros.filter(item => item.edificioId === props.orden.edificioId).map(item => ({ label: `${item.nombre} · ${item.correo}`, value: item.id }))
  : props.proveedores
      .filter(item => item.edificioId === props.orden.edificioId && (!props.orden.proveedorId || item.proveedorId === props.orden.proveedorId))
      .map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.proveedorId })))

const responsibleName = (assignment: AsignacionOrdenOperativa | null): string => {
  if (!assignment) return 'Sin responsable'
  return assignment.responsableNombre
}
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('es-EC') : 'No disponible'
const formatBytes = (value: number) => value < 1024 * 1024 ? `${Math.max(1, Math.round(value / 1024))} KB` : `${(value / 1024 / 1024).toFixed(1)} MB`
const auditFieldLabels: Record<string, string> = {
  titulo: 'Título',
  descripcion: 'Descripción',
  prioridad: 'Prioridad',
  fechaObjetivo: 'Fecha objetivo',
  ubicacionDetalle: 'Detalle de ubicación',
  reportanteResidenteId: 'Reportante',
  proveedorId: 'Proveedor',
  contratoId: 'Contrato'
}
const auditValue = (value: unknown): string => {
  if (value === null || value === '') return 'vacío'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}
const eventDetail = (entry: BitacoraOrdenOperativa): string => {
  if (!entry.detalle) return 'Sin detalle adicional'
  if (typeof entry.detalle.descripcion === 'string') return entry.detalle.descripcion
  if (typeof entry.detalle.motivo === 'string') return entry.detalle.motivo
  if (typeof entry.detalle.resumen === 'string') return entry.detalle.resumen
  if (typeof entry.detalle.estadoNuevo === 'string') return `Nuevo estado: ${stateLabels[entry.detalle.estadoNuevo as EstadoOrdenOperativa] ?? entry.detalle.estadoNuevo}`
  if (typeof entry.detalle.nombre === 'string') return String(entry.detalle.nombre)
  if (typeof entry.detalle.responsableNombre === 'string') return `Responsable: ${entry.detalle.responsableNombre}`
  if (typeof entry.detalle.tipoResponsable === 'string') return `Responsable ${entry.detalle.tipoResponsable}`
  if (entry.detalle.cambios && typeof entry.detalle.cambios === 'object') {
    return Object.entries(entry.detalle.cambios as Record<string, { anterior: unknown, nuevo: unknown }>)
      .map(([field, values]) => `${auditFieldLabels[field] ?? field}: ${auditValue(values.anterior)} → ${auditValue(values.nuevo)}`)
      .join(' · ')
  }
  if (entry.detalle.datos) return 'Datos iniciales de la orden registrados.'
  return 'Cambio registrado con detalle estructurado'
}
const requestState = (target: ErrorTarget) => ({
  preserveScroll: true,
  onStart: () => { loading.value = true; actionError.value = ''; errorTarget.value = target },
  onFinish: () => { loading.value = false }
})
const transition = () => {
  if (!nextState.value) return
  router.patch(route('operaciones.transition', [props.orden.edificioId, props.orden.id]), { estado: nextState.value }, {
    ...requestState('transition'),
    onError: errors => { actionError.value = String(errors.responsable ?? errors.estado ?? 'No fue posible cambiar el estado.') }
  })
}
const openAssign = () => {
  responsibleType.value = props.orden.responsableActual?.tipoResponsable ?? 'usuario'
  responsibleId.value = ''
  actionError.value = ''
  errorTarget.value = 'assign'
  assignOpen.value = true
}
const assign = () => router.post(route('operaciones.assign', [props.orden.edificioId, props.orden.id]), {
  tipo_responsable: responsibleType.value,
  responsable_id: responsibleId.value
}, {
  ...requestState('assign'),
  onSuccess: () => { assignOpen.value = false },
  onError: errors => { actionError.value = String(errors.tipoResponsable ?? errors.responsableId ?? errors.proveedorId ?? errors.estado ?? 'No fue posible asignar el responsable.') }
})
const openReasonModal = (kind: 'cancel' | 'reopen') => {
  reason.value = ''
  actionError.value = ''
  errorTarget.value = kind
  if (kind === 'cancel') cancelOpen.value = true
  else reopenOpen.value = true
}
const cancel = () => router.patch(route('operaciones.cancel', [props.orden.edificioId, props.orden.id]), { motivo: reason.value }, {
  ...requestState('cancel'),
  onSuccess: () => { cancelOpen.value = false },
  onError: errors => { actionError.value = String(errors.motivo ?? errors.estado ?? 'No fue posible cancelar la orden.') }
})
const reopen = () => router.patch(route('operaciones.reopen', [props.orden.edificioId, props.orden.id]), { motivo: reason.value }, {
  ...requestState('reopen'),
  onSuccess: () => { reopenOpen.value = false },
  onError: errors => { actionError.value = String(errors.motivo ?? errors.responsable ?? errors.estado ?? 'No fue posible reabrir la orden.') }
})
const storeAction = () => router.post(route('operaciones.actions.store', [props.orden.edificioId, props.orden.id]), { descripcion: actionDescription.value }, {
  ...requestState('action'),
  onSuccess: () => { actionDescription.value = '' },
  onError: errors => { actionError.value = String(errors.descripcion ?? errors.estado ?? 'No fue posible registrar la actuación.') }
})
const selectEvidence = (event: Event) => {
  const input = event.target as HTMLInputElement
  const selected = input.files?.[0] ?? null
  if (selected && ((selected.type !== '' && !['application/pdf', 'image/jpeg', 'image/png'].includes(selected.type)) || selected.size > 10 * 1024 * 1024)) {
    evidenceFile.value = null
    input.value = ''
    errorTarget.value = 'evidence'
    actionError.value = selected.size > 10 * 1024 * 1024
      ? 'El archivo no puede superar 10 MB.'
      : 'El archivo debe ser PDF, JPG o PNG.'
    return
  }
  actionError.value = ''
  evidenceFile.value = selected
}
const storeEvidence = () => {
  if (!evidenceFile.value) return
  router.post(route('operaciones.evidence.store', [props.orden.edificioId, props.orden.id]), {
    archivo: evidenceFile.value,
    descripcion: evidenceDescription.value
  }, {
    ...requestState('evidence'),
    forceFormData: true,
    onSuccess: () => {
      evidenceFile.value = null
      evidenceDescription.value = ''
      if (evidenceInput.value) evidenceInput.value.value = ''
    },
    onError: errors => { actionError.value = String(errors.archivo ?? errors.descripcion ?? errors.estado ?? 'No fue posible adjuntar la evidencia.') }
  })
}
const downloadEvidence = (id: string) => {
  window.location.assign(route('operaciones.evidence.download', [props.orden.edificioId, props.orden.id, id]))
}
</script>

<template>
  <UDashboardPanel id="operaciones-show">
    <template #header><UDashboardNavbar :title="orden.numero"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <UAlert v-if="actionError && errorTarget === 'transition'" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" />
        <div class="flex flex-wrap justify-end gap-2">
          <UButton v-if="orden.puedeEditar && can('operaciones.gestionar', orden.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('operaciones.edit', [orden.edificioId, orden.id]))" />
          <UButton v-if="canAssign" color="neutral" icon="i-lucide-user-round-check" :label="orden.responsableActual ? 'Reasignar' : 'Asignar responsable'" variant="outline" @click="openAssign" />
          <UButton v-if="canTransition" icon="i-lucide-arrow-right" :label="`Pasar a ${stateLabels[nextState!]}`" :loading="loading" @click="transition" />
          <UButton v-if="canReopen" color="warning" icon="i-lucide-rotate-ccw" label="Reabrir" variant="outline" @click="openReasonModal('reopen')" />
          <UButton v-if="canCancel" color="error" icon="i-lucide-ban" label="Cancelar orden" variant="outline" @click="openReasonModal('cancel')" />
        </div>

        <UCard>
          <template #header><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm text-muted">{{ orden.edificio || orden.edificioId }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ orden.titulo }}</h1><div class="mt-2 flex flex-wrap gap-2"><UBadge color="neutral" :label="orden.tipo === 'incidencia' ? 'Incidencia' : 'Solicitud'" variant="outline" /><UBadge color="neutral" :label="`Prioridad ${priorityLabels[orden.prioridad].toLowerCase()}`" variant="subtle" /></div></div><UBadge :color="stateColor(orden.estado)" :label="stateLabels[orden.estado]" size="lg" variant="subtle" /></div></template>
          <p class="break-words whitespace-pre-line text-sm leading-6 text-muted">{{ orden.descripcion }}</p>
          <dl class="mt-6 grid gap-5 border-t border-default pt-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs uppercase text-muted">Fecha objetivo</dt><dd class="mt-1">{{ orden.fechaObjetivo || 'Sin fecha' }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Responsable vigente</dt><dd class="mt-1 font-medium">{{ responsibleName(orden.responsableActual) }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Ubicación</dt><dd class="mt-1">{{ orden.ubicacion?.etiqueta || orden.ubicacion?.detalle || 'No disponible' }}</dd><dd v-if="orden.ubicacion?.etiqueta && orden.ubicacion.detalle" class="text-xs text-muted">{{ orden.ubicacion.detalle }}</dd></div>
            <div><dt class="text-xs uppercase text-muted">Creada</dt><dd class="mt-1">{{ formatDate(orden.createdAt) }}</dd></div>
          </dl>
        </UCard>

        <div class="grid gap-6 lg:grid-cols-2">
          <UCard><template #header><p class="font-semibold text-highlighted">Origen del reporte</p></template><div v-if="orden.reportanteSnapshot" class="space-y-1 text-sm"><p class="font-medium">{{ orden.reportanteSnapshot.nombre }}</p><p class="text-muted">{{ orden.reportanteSnapshot.tipoIdentificacion }} · {{ orden.reportanteSnapshot.identificacion }}</p><p v-if="orden.reportanteSnapshot.telefono" class="text-muted">{{ orden.reportanteSnapshot.telefono }}</p><p v-if="orden.reportanteSnapshot.correo" class="text-muted">{{ orden.reportanteSnapshot.correo }}</p></div><p v-else class="text-sm text-muted">Reporte registrado directamente por la administración.</p></UCard>
          <UCard><template #header><p class="font-semibold text-highlighted">Proveedor asociado</p></template><div v-if="orden.proveedor" class="space-y-1 text-sm"><p class="font-medium">{{ orden.proveedor.nombre }}</p><p class="text-muted">{{ orden.proveedor.identificacion }}</p><p v-if="orden.contrato" class="pt-2 font-mono text-xs text-primary">{{ orden.contrato.referencia }} · {{ orden.contrato.objeto }}</p></div><p v-else class="text-sm text-muted">La orden no tiene proveedor ni contrato asociado.</p></UCard>
        </div>

        <UCard v-if="canContribute"><template #header><div><p class="font-semibold text-highlighted">Registrar actuación</p><p class="mt-1 text-xs text-muted">La entrada se agrega a la bitácora y no puede editarse ni eliminarse.</p></div></template><div class="space-y-3"><UAlert v-if="actionError && errorTarget === 'action'" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Descripción" required><UTextarea v-model="actionDescription" :rows="3" maxlength="5000" /></UFormField><div class="flex justify-end"><UButton icon="i-lucide-notebook-pen" label="Registrar actuación" :disabled="!actionDescription.trim()" :loading="loading" @click="storeAction" /></div></div></UCard>

        <UCard v-if="canContribute"><template #header><div><p class="font-semibold text-highlighted">Adjuntar evidencia</p><p class="mt-1 text-xs text-muted">PDF, JPG o PNG de hasta 10 MB. El archivo quedará en almacenamiento privado.</p></div></template><div class="space-y-4"><UAlert v-if="actionError && errorTarget === 'evidence'" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /><div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end"><UFormField label="Archivo" required><input ref="evidenceInput" aria-label="Archivo de evidencia" class="block w-full rounded-lg border border-default bg-default px-3 py-2 text-sm" type="file" accept="application/pdf,image/jpeg,image/png" @change="selectEvidence"></UFormField><UFormField label="Descripción" hint="Opcional"><UInput v-model="evidenceDescription" maxlength="500" /></UFormField><UButton icon="i-lucide-paperclip" label="Adjuntar" :disabled="!evidenceFile" :loading="loading" @click="storeEvidence" /></div></div></UCard>

        <UCard><template #header><div><p class="font-semibold text-highlighted">Evidencias</p><p class="mt-1 text-xs text-muted">Los archivos permanecen en el historial después del cierre.</p></div></template><div v-if="orden.evidencias?.length" class="divide-y divide-default"><div v-for="evidencia in orden.evidencias" :key="evidencia.id" class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="break-all font-medium">{{ evidencia.nombre }}</p><p class="text-xs text-muted">{{ formatBytes(evidencia.tamanoBytes) }} · {{ formatDate(evidencia.createdAt) }}</p><p v-if="evidencia.descripcion" class="mt-1 break-words text-sm text-muted">{{ evidencia.descripcion }}</p></div><UButton color="neutral" icon="i-lucide-download" label="Descargar" variant="outline" @click="downloadEvidence(evidencia.id)" /></div></div><p v-else class="text-sm text-muted">No hay evidencias adjuntas.</p></UCard>

        <UCard><template #header><div><p class="font-semibold text-highlighted">Historial de responsables</p><p class="mt-1 text-xs text-muted">Cada intervalo de asignación permanece registrado.</p></div></template><div v-if="orden.asignaciones?.length" class="divide-y divide-default"><div v-for="asignacion in orden.asignaciones" :key="asignacion.id" class="grid gap-2 py-4 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto]"><div><p class="font-medium">{{ responsibleName(asignacion) }}</p><p class="text-xs text-muted">{{ asignacion.tipoResponsable === 'usuario' ? 'Usuario interno' : 'Proveedor' }}</p></div><div class="text-xs text-muted sm:text-right"><p>Desde {{ formatDate(asignacion.fechaInicio) }}</p><p>{{ asignacion.fechaFin ? `Hasta ${formatDate(asignacion.fechaFin)}` : 'Asignación vigente' }}</p></div></div></div><p v-else class="text-sm text-muted">Todavía no se ha asignado un responsable.</p></UCard>

        <UCard><template #header><div><p class="font-semibold text-highlighted">Bitácora</p><p class="mt-1 text-xs text-muted">Historial append-only de actuaciones y cambios.</p></div></template><div v-if="orden.bitacora?.length" class="relative ml-2 border-l border-default pl-6"><div v-for="entrada in orden.bitacora" :key="entrada.id" class="relative min-w-0 pb-6 last:pb-0"><span class="absolute -left-[1.78rem] top-1 size-3 rounded-full border-2 border-default bg-primary"></span><p class="font-medium">{{ eventLabels[entrada.tipo] }}</p><p class="mt-1 break-words text-sm text-muted">{{ eventDetail(entrada) }}</p><p class="mt-1 text-xs text-dimmed">{{ entrada.actorNombre }} · {{ formatDate(entrada.createdAt) }}</p></div></div><p v-else class="text-sm text-muted">No hay eventos registrados.</p></UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="assignOpen" :close="!loading" :dismissible="!loading" title="Asignar responsable" description="La asignación vigente se cerrará y la nueva quedará en el historial.">
    <template #body><div class="space-y-4"><UAlert v-if="actionError && errorTarget === 'assign'" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Tipo de responsable" required><USelect v-model="responsibleType" class="w-full" :items="[{ label: 'Usuario interno', value: 'usuario' }, { label: 'Proveedor', value: 'proveedor' }]" @update:model-value="responsibleId = ''" /></UFormField><UFormField label="Responsable" required><USelect v-model="responsibleId" class="w-full" :items="responsibleItems" placeholder="Seleccione responsable" /></UFormField><UAlert v-if="responsibleType === 'proveedor'" color="info" :description="orden.proveedorId ? 'Sólo puede asignarse el proveedor ya asociado a la orden.' : 'Al confirmar, el proveedor seleccionado también quedará asociado a la orden.'" icon="i-lucide-info" variant="subtle" /></div></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="assignOpen = false" /><UButton label="Guardar asignación" :disabled="!responsibleId" :loading="loading" @click="assign" /></div></template>
  </UModal>

  <UModal v-model:open="cancelOpen" :close="!loading" :dismissible="!loading" title="Cancelar orden" description="La cancelación es terminal y cerrará la asignación vigente.">
    <template #body><div class="space-y-4"><UAlert v-if="actionError && errorTarget === 'cancel'" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo" required><UTextarea v-model="reason" :rows="3" maxlength="2000" /></UFormField></div></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="cancelOpen = false" /><UButton color="error" label="Cancelar orden" :disabled="!reason.trim()" :loading="loading" @click="cancel" /></div></template>
  </UModal>

  <UModal v-model:open="reopenOpen" :close="!loading" :dismissible="!loading" title="Reabrir orden" description="La orden volverá a en progreso y conservará el episodio anterior.">
    <template #body><div class="space-y-4"><UAlert v-if="actionError && errorTarget === 'reopen'" color="error" :description="actionError" icon="i-lucide-circle-alert" variant="subtle" /><UFormField label="Motivo" required><UTextarea v-model="reason" :rows="3" maxlength="2000" /></UFormField></div></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Volver" variant="outline" :disabled="loading" @click="reopenOpen = false" /><UButton color="warning" label="Reabrir orden" :disabled="!reason.trim()" :loading="loading" @click="reopen" /></div></template>
  </UModal>
</template>
