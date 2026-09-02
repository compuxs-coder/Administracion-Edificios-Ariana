<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type {
  Departamento,
  DepartamentoOcupacion,
  DepartamentoPropiedad,
  OcupacionDepartamento,
  TipoOcupacion,
  TitularidadDepartamento
} from '../../types'

const props = defineProps<{
  departamento: Departamento
  propiedad: DepartamentoPropiedad
  ocupacion: DepartamentoOcupacion
}>()
const localDate = (date: Date) => [
  date.getFullYear(),
  String(date.getMonth() + 1).padStart(2, '0'),
  String(date.getDate()).padStart(2, '0')
].join('-')
const dayAfter = (date: string) => {
  const [year, month, day] = date.split('-').map(Number)
  return localDate(new Date(year, month - 1, day + 1))
}
const today = localDate(new Date())
const assignOpen = ref(false)
const finalizeOpen = ref(false)
const transferOpen = ref(false)
const residentAssignOpen = ref(false)
const residentFinalizeOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string>>({})
const selectedOwnership = ref<TitularidadDepartamento | null>(null)
const selectedOccupancy = ref<OcupacionDepartamento | null>(null)
const section = ref<'actuales' | 'historial'>('actuales')
const occupancySection = ref<'actuales' | 'historial'>('actuales')

const assignForm = reactive({
  propietario_id: '',
  porcentaje: '',
  fecha_inicio: today,
  observaciones: ''
})
const finalizeForm = reactive({ fecha_fin: today, observaciones: '' })
const transferForm = reactive({
  fecha_transferencia: today,
  propietarios: [{ propietario_id: '', porcentaje: '100.000000' }],
  observaciones: ''
})
const residentAssignForm = reactive<{
  residente_id: string
  tipo_ocupacion: TipoOcupacion
  fecha_inicio: string
  observaciones: string
}>({
  residente_id: '',
  tipo_ocupacion: 'arrendatario',
  fecha_inicio: today,
  observaciones: ''
})
const residentFinalizeForm = reactive({ fecha_fin: today, observaciones: '' })

const remaining = computed(() => Math.max(0, 100 - Number(props.propiedad.participacionActual)))
const transferUnits = computed(() => transferForm.propietarios.reduce(
  (total, item) => total + Math.round(Number(item.porcentaje || 0) * 1_000_000),
  0
))
const transferTotal = computed(() => transferUnits.value / 1_000_000)
const transferMin = computed(() => {
  const starts = props.propiedad.actuales.map(item => item.fechaInicio).sort()
  return starts.length ? dayAfter(starts[starts.length - 1]) : today
})
const ownerItems = computed(() => props.propiedad.opciones.map(item => ({
  label: `${item.nombre} · ${item.identificacion}`,
  value: item.id
})))
const residentItems = computed(() => props.ocupacion.opciones.map(item => ({
  label: `${item.nombre} · ${item.identificacion}`,
  value: item.id
})))
const occupancyTypeItems: Array<{ label: string, value: TipoOcupacion }> = [
  { label: 'Propietario ocupante', value: 'propietario_ocupante' },
  { label: 'Arrendatario', value: 'arrendatario' },
  { label: 'Otro', value: 'otro' }
]
const occupancyTypeLabels: Record<TipoOcupacion, string> = {
  propietario_ocupante: 'Propietario ocupante',
  arrendatario: 'Arrendatario',
  otro: 'Otro'
}

const formatDate = (date: string) => new Intl.DateTimeFormat('es', {
  dateStyle: 'medium',
  timeZone: 'UTC'
}).format(new Date(`${date}T00:00:00Z`))

const resetErrors = () => { errors.value = {} }
const firstError = (...fields: string[]) => {
  for (const field of fields) {
    const match = Object.entries(errors.value).find(([key]) => key === field || key.startsWith(`${field}.`))
    if (match) return match[1]
  }
  return undefined
}
const rowError = (index: number, field: 'propietarioId' | 'porcentaje') => Object.entries(errors.value)
  .find(([key]) => key.toLowerCase() === `propietarios.${index}.${field}`.toLowerCase())?.[1]
const transferOwnerItems = (rowIndex: number) => ownerItems.value.map(item => ({
  ...item,
  disabled: transferForm.propietarios.some((row, index) => index !== rowIndex && row.propietario_id === item.value)
}))

const openAssign = () => {
  resetErrors()
  Object.assign(assignForm, { propietario_id: '', porcentaje: remaining.value.toFixed(6), fecha_inicio: today, observaciones: '' })
  assignOpen.value = true
}

const assignOwner = () => {
  router.post(route('departamentos.propietarios.assign', [props.departamento.edificioId, props.departamento.id]), assignForm, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onSuccess: () => { assignOpen.value = false },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}

const openFinalize = (ownership: TitularidadDepartamento) => {
  resetErrors()
  selectedOwnership.value = ownership
  Object.assign(finalizeForm, { fecha_fin: today, observaciones: ownership.observaciones ?? '' })
  finalizeOpen.value = true
}

const finalizeOwnership = () => {
  if (!selectedOwnership.value) return
  router.patch(route('departamentos.propietarios.finalize', [
    props.departamento.edificioId,
    props.departamento.id,
    selectedOwnership.value.id
  ]), finalizeForm, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onSuccess: () => { finalizeOpen.value = false; selectedOwnership.value = null },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}

const openTransfer = () => {
  resetErrors()
  Object.assign(transferForm, {
    fecha_transferencia: transferMin.value,
    propietarios: [{ propietario_id: '', porcentaje: '100.000000' }],
    observaciones: ''
  })
  transferOpen.value = true
}

const addTransferOwner = () => {
  if (transferForm.propietarios.length < 20) {
    transferForm.propietarios.push({ propietario_id: '', porcentaje: '0.000000' })
  }
}
const removeTransferOwner = (index: number) => {
  if (transferForm.propietarios.length > 1) transferForm.propietarios.splice(index, 1)
}
const transferOwnership = () => {
  router.post(route('departamentos.propietarios.transfer', [props.departamento.edificioId, props.departamento.id]), transferForm, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onSuccess: () => { transferOpen.value = false },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}

const openResidentAssign = () => {
  resetErrors()
  Object.assign(residentAssignForm, {
    residente_id: '',
    tipo_ocupacion: 'arrendatario',
    fecha_inicio: today,
    observaciones: ''
  })
  residentAssignOpen.value = true
}

const assignResident = () => {
  router.post(route('departamentos.residentes.assign', [
    props.departamento.edificioId,
    props.departamento.id
  ]), residentAssignForm, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onSuccess: () => { residentAssignOpen.value = false },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}

const openResidentFinalize = (occupancy: OcupacionDepartamento) => {
  resetErrors()
  selectedOccupancy.value = occupancy
  Object.assign(residentFinalizeForm, {
    fecha_fin: today,
    observaciones: occupancy.observaciones ?? ''
  })
  residentFinalizeOpen.value = true
}

const finalizeResident = () => {
  if (!selectedOccupancy.value) return
  router.patch(route('departamentos.residentes.finalize', [
    props.departamento.edificioId,
    props.departamento.id,
    selectedOccupancy.value.id
  ]), residentFinalizeForm, {
    preserveScroll: true,
    onStart: () => { loading.value = true },
    onSuccess: () => { residentFinalizeOpen.value = false; selectedOccupancy.value = null },
    onError: responseErrors => { errors.value = responseErrors },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="departamentos-show">
    <template #header>
      <UDashboardNavbar :title="`${departamento.codigo} · ${departamento.nombre}`">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UButton color="neutral" icon="i-lucide-pencil" label="Editar unidad" variant="outline" :ui="{ label: 'hidden sm:inline' }" @click="router.visit(route('departamentos.edit', [departamento.edificioId, departamento.id]))" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <UCard>
          <template #header><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-sm text-muted">Información de la unidad</p><h1 class="mt-1 text-xl font-semibold text-highlighted">{{ departamento.edificio }} · {{ departamento.codigo }}</h1></div><UBadge :color="departamento.estado === 'activo' ? 'success' : 'neutral'" :label="departamento.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></div></template>
          <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs uppercase tracking-wide text-muted">Torre</dt><dd class="mt-1 text-sm text-highlighted">{{ departamento.torre }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-muted">Piso</dt><dd class="mt-1 text-sm text-highlighted">{{ departamento.piso }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-muted">Alícuota</dt><dd class="mt-1 font-mono text-sm text-highlighted">{{ Number(departamento.alicuota).toFixed(6) }}%</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-muted">Anexos</dt><dd class="mt-1 text-sm text-highlighted">{{ departamento.parqueaderos.length }} parqueadero(s) · {{ departamento.bodegas.length }} bodega(s)</dd></div>
          </dl>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div><p class="font-medium text-highlighted">Propietarios</p><p class="mt-1 text-xs text-muted">Participación activa: {{ Number(propiedad.participacionActual).toFixed(6) }}%</p></div>
              <div class="flex flex-wrap gap-2">
                <UButton color="neutral" icon="i-lucide-arrow-right-left" label="Transferir" variant="outline" :disabled="!propiedad.actuales.length || departamento.estado === 'inactivo' || transferMin > today" @click="openTransfer" />
                <UButton icon="i-lucide-user-plus" label="Asignar propietario" :disabled="remaining <= 0 || departamento.estado === 'inactivo'" @click="openAssign" />
              </div>
            </div>
          </template>

          <div class="mb-4 flex gap-1 border-b border-default pb-3">
            <UButton :color="section === 'actuales' ? 'primary' : 'neutral'" label="Actuales" :variant="section === 'actuales' ? 'soft' : 'ghost'" :aria-pressed="section === 'actuales'" @click="section = 'actuales'" />
            <UButton :color="section === 'historial' ? 'primary' : 'neutral'" label="Historial" :variant="section === 'historial' ? 'soft' : 'ghost'" :aria-pressed="section === 'historial'" @click="section = 'historial'" />
          </div>

          <div class="overflow-x-auto">
            <table class="w-full min-w-4xl text-left text-sm">
              <thead class="border-b border-default text-xs uppercase tracking-wide text-muted"><tr><th class="px-3 py-3 font-medium">Propietario</th><th class="px-3 py-3 font-medium">Identificación</th><th class="px-3 py-3 font-medium">Participación</th><th class="px-3 py-3 font-medium">Vigencia</th><th class="px-3 py-3 font-medium">Estado</th><th class="px-3 py-3 text-right font-medium">Acciones</th></tr></thead>
              <tbody class="divide-y divide-default">
                <tr v-for="item in section === 'actuales' ? propiedad.actuales : propiedad.historial" :key="item.id">
                  <td class="px-3 py-3"><UButton color="neutral" :label="item.nombre" variant="link" @click="router.visit(route('propietarios.show', item.propietarioId))" /></td>
                  <td class="px-3 py-3 font-mono text-highlighted">{{ item.identificacion }}</td>
                  <td class="px-3 py-3 font-mono text-highlighted">{{ Number(item.porcentaje).toFixed(6) }}%</td>
                  <td class="px-3 py-3 text-muted">{{ formatDate(item.fechaInicio) }}<span v-if="item.fechaFin"> – {{ formatDate(item.fechaFin) }}</span></td>
                  <td class="px-3 py-3"><UBadge :color="item.estado === 'activa' ? 'success' : 'neutral'" :label="item.estado === 'activa' ? 'Activa' : 'Finalizada'" variant="subtle" /></td>
                  <td class="px-3 py-3"><div class="flex justify-end"><UButton v-if="item.estado === 'activa'" color="error" icon="i-lucide-circle-stop" label="Finalizar" variant="ghost" :disabled="dayAfter(item.fechaInicio) > today" @click="openFinalize(item)" /></div></td>
                </tr>
                <tr v-if="!(section === 'actuales' ? propiedad.actuales : propiedad.historial).length"><td colspan="6" class="px-3 py-10 text-center text-muted">No hay titularidades en esta sección.</td></tr>
              </tbody>
            </table>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div><p class="font-medium text-highlighted">Residentes</p><p class="mt-1 text-xs text-muted">Ocupantes actuales e historial de la unidad.</p></div>
              <UButton icon="i-lucide-user-plus" label="Asignar residente" :disabled="departamento.estado === 'inactivo' || !ocupacion.opciones.length" @click="openResidentAssign" />
            </div>
          </template>

          <div class="mb-4 flex gap-1 border-b border-default pb-3">
            <UButton :color="occupancySection === 'actuales' ? 'primary' : 'neutral'" label="Actuales" :variant="occupancySection === 'actuales' ? 'soft' : 'ghost'" :aria-pressed="occupancySection === 'actuales'" @click="occupancySection = 'actuales'" />
            <UButton :color="occupancySection === 'historial' ? 'primary' : 'neutral'" label="Historial" :variant="occupancySection === 'historial' ? 'soft' : 'ghost'" :aria-pressed="occupancySection === 'historial'" @click="occupancySection = 'historial'" />
          </div>

          <div class="overflow-x-auto">
            <table class="w-full min-w-5xl text-left text-sm">
              <thead class="border-b border-default text-xs uppercase tracking-wide text-muted"><tr><th class="px-3 py-3 font-medium">Residente</th><th class="px-3 py-3 font-medium">Identificación</th><th class="px-3 py-3 font-medium">Tipo</th><th class="px-3 py-3 font-medium">Vigencia</th><th class="px-3 py-3 font-medium">Estado</th><th class="px-3 py-3 text-right font-medium">Acciones</th></tr></thead>
              <tbody class="divide-y divide-default">
                <tr v-for="item in occupancySection === 'actuales' ? ocupacion.actuales : ocupacion.historial" :key="item.id">
                  <td class="px-3 py-3"><UButton color="neutral" :label="item.nombre" variant="link" @click="router.visit(route('residentes.show', item.residenteId))" /></td>
                  <td class="px-3 py-3 font-mono text-highlighted">{{ item.identificacion }}</td>
                  <td class="px-3 py-3"><p class="text-highlighted">{{ occupancyTypeLabels[item.tipoOcupacion] }}</p><p v-if="item.observaciones" class="mt-1 max-w-xs truncate text-xs text-muted" :title="item.observaciones">{{ item.observaciones }}</p></td>
                  <td class="px-3 py-3 text-muted">{{ formatDate(item.fechaInicio) }}<span v-if="item.fechaFin"> – {{ formatDate(item.fechaFin) }}</span></td>
                  <td class="px-3 py-3"><UBadge :color="item.estado === 'activa' ? 'success' : 'neutral'" :label="item.estado === 'activa' ? 'Activa' : 'Finalizada'" variant="subtle" /></td>
                  <td class="px-3 py-3"><div class="flex justify-end"><UButton v-if="item.estado === 'activa'" color="error" icon="i-lucide-circle-stop" label="Finalizar" variant="ghost" :disabled="dayAfter(item.fechaInicio) > today" @click="openResidentFinalize(item)" /></div></td>
                </tr>
                <tr v-if="!(occupancySection === 'actuales' ? ocupacion.actuales : ocupacion.historial).length"><td colspan="6" class="px-3 py-10 text-center text-muted">No hay ocupaciones en esta sección.</td></tr>
              </tbody>
            </table>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="assignOpen" :close="!loading" :dismissible="!loading" title="Asignar propietario" :description="`Participación disponible: ${remaining.toFixed(6)}%`">
    <template #body><form id="assign-owner" class="space-y-4" @submit.prevent="assignOwner">
      <UAlert v-if="!ownerItems.length" color="warning" description="No hay propietarios activos disponibles. Registre uno antes de continuar." icon="i-lucide-triangle-alert" variant="subtle" />
      <UAlert v-if="firstError('departamentoId')" color="error" :description="firstError('departamentoId')" icon="i-lucide-circle-alert" role="alert" variant="subtle" />
      <UFormField label="Propietario" name="propietario_id" required :error="firstError('propietarioId')"><USelect v-model="assignForm.propietario_id" class="w-full" :items="ownerItems" placeholder="Seleccione propietario" required size="xl" /></UFormField>
      <div class="grid gap-4 sm:grid-cols-2"><UFormField label="Participación (%)" name="porcentaje" required :error="firstError('porcentaje')"><UInput v-model="assignForm.porcentaje" class="w-full" type="number" min="0.000001" :max="remaining" step="0.000001" required size="xl" /></UFormField><UFormField label="Fecha desde" name="fecha_inicio" required :error="firstError('fechaInicio')"><UInput v-model="assignForm.fecha_inicio" class="w-full" type="date" :max="today" required size="xl" /></UFormField></div>
      <UFormField label="Observaciones" name="observaciones" :error="firstError('observaciones')"><UTextarea v-model="assignForm.observaciones" class="w-full" :rows="3" /></UFormField>
    </form></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="assignOpen = false" /><UButton form="assign-owner" label="Asignar" type="submit" :loading="loading" :disabled="!ownerItems.length" /></div></template>
  </UModal>

  <UModal v-model:open="finalizeOpen" :close="!loading" :dismissible="!loading" title="Finalizar titularidad" :description="selectedOwnership ? `Se conservará el historial de ${selectedOwnership.nombre}.` : ''">
    <template #body><form id="finalize-owner" class="space-y-4" @submit.prevent="finalizeOwnership"><UFormField label="Fecha final" name="fecha_fin" required :error="firstError('fechaFin')"><UInput v-model="finalizeForm.fecha_fin" class="w-full" type="date" :min="selectedOwnership ? dayAfter(selectedOwnership.fechaInicio) : undefined" :max="today" required size="xl" /></UFormField><UFormField label="Observaciones" name="observaciones" :error="firstError('observaciones')"><UTextarea v-model="finalizeForm.observaciones" class="w-full" :rows="3" /></UFormField></form></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="finalizeOpen = false" /><UButton color="error" form="finalize-owner" label="Finalizar" type="submit" :loading="loading" /></div></template>
  </UModal>

  <UModal v-model:open="transferOpen" :close="!loading" :dismissible="!loading" title="Transferir propiedad" description="Se finalizarán todas las titularidades actuales y se crearán las nuevas en una sola transacción.">
    <template #body><form id="transfer-owner" class="space-y-4" @submit.prevent="transferOwnership">
      <UAlert v-if="firstError('departamentoId')" color="error" :description="firstError('departamentoId')" icon="i-lucide-circle-alert" role="alert" variant="subtle" />
      <UFormField label="Fecha de transferencia" name="fecha_transferencia" required :error="firstError('fechaTransferencia')"><UInput v-model="transferForm.fecha_transferencia" class="w-full" type="date" :min="transferMin" :max="today" required size="xl" /></UFormField>
      <div class="space-y-3"><div class="flex items-center justify-between"><p class="text-sm font-medium text-highlighted">Nuevos propietarios</p><UBadge :color="transferTotal > 100 ? 'error' : 'primary'" :label="`${transferTotal.toFixed(6)}%`" variant="subtle" /></div>
        <div v-for="(item, index) in transferForm.propietarios" :key="index" class="grid gap-3 rounded-xl border border-default p-3 sm:grid-cols-[1fr_10rem_auto]"><UFormField :label="`Propietario ${index + 1}`" :name="`propietarios.${index}.propietario_id`" :error="rowError(index, 'propietarioId')"><USelect v-model="item.propietario_id" class="w-full" :items="transferOwnerItems(index)" placeholder="Propietario" :aria-label="`Propietario ${index + 1}`" required /></UFormField><UFormField label="Participación (%)" :name="`propietarios.${index}.porcentaje`" :error="rowError(index, 'porcentaje')"><UInput v-model="item.porcentaje" aria-label="Participación" type="number" min="0.000001" max="100" step="0.000001" required /></UFormField><UButton color="error" icon="i-lucide-x" variant="ghost" aria-label="Quitar propietario" :disabled="transferForm.propietarios.length === 1" @click="removeTransferOwner(index)" /></div>
        <p v-if="firstError('propietarios', 'porcentaje', 'propietarioId')" class="text-sm text-error" role="alert">{{ firstError('propietarios', 'porcentaje', 'propietarioId') }}</p><UButton color="neutral" icon="i-lucide-plus" label="Agregar copropietario" variant="outline" :disabled="transferForm.propietarios.length >= 20" @click="addTransferOwner" />
      </div>
      <UFormField label="Observaciones" name="observaciones" :error="firstError('observaciones')"><UTextarea v-model="transferForm.observaciones" class="w-full" :rows="3" /></UFormField>
    </form></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="transferOpen = false" /><UButton form="transfer-owner" icon="i-lucide-arrow-right-left" label="Registrar transferencia" type="submit" :loading="loading" :disabled="transferUnits !== 100000000" /></div></template>
  </UModal>

  <UModal v-model:open="residentAssignOpen" :close="!loading" :dismissible="!loading" title="Asignar residente" description="Registre el tipo y la fecha de inicio de la ocupación.">
    <template #body><form id="assign-resident" class="space-y-4" @submit.prevent="assignResident">
      <UAlert v-if="!residentItems.length" color="warning" description="No hay residentes activos disponibles. Registre uno antes de continuar." icon="i-lucide-triangle-alert" variant="subtle" />
      <UAlert v-if="firstError('departamentoId')" color="error" :description="firstError('departamentoId')" icon="i-lucide-circle-alert" role="alert" variant="subtle" />
      <UFormField label="Residente" name="residente_id" required :error="firstError('residenteId')"><USelect v-model="residentAssignForm.residente_id" class="w-full" :items="residentItems" placeholder="Seleccione residente" required size="xl" /></UFormField>
      <div class="grid gap-4 sm:grid-cols-2"><UFormField label="Tipo de ocupación" name="tipo_ocupacion" required :error="firstError('tipoOcupacion')"><USelect v-model="residentAssignForm.tipo_ocupacion" class="w-full" :items="occupancyTypeItems" required size="xl" /></UFormField><UFormField label="Fecha desde" name="fecha_inicio" required :error="firstError('fechaInicio')"><UInput v-model="residentAssignForm.fecha_inicio" class="w-full" type="date" :max="today" required size="xl" /></UFormField></div>
      <UFormField label="Observaciones" name="observaciones" :error="firstError('observaciones')"><UTextarea v-model="residentAssignForm.observaciones" class="w-full" :rows="3" /></UFormField>
    </form></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="residentAssignOpen = false" /><UButton form="assign-resident" label="Asignar" type="submit" :loading="loading" :disabled="!residentItems.length || departamento.estado === 'inactivo'" /></div></template>
  </UModal>

  <UModal v-model:open="residentFinalizeOpen" :close="!loading" :dismissible="!loading" title="Finalizar ocupación" :description="selectedOccupancy ? `Se conservará el historial de ${selectedOccupancy.nombre}.` : ''">
    <template #body><form id="finalize-resident" class="space-y-4" @submit.prevent="finalizeResident"><UFormField label="Fecha final" name="fecha_fin" required :error="firstError('fechaFin')"><UInput v-model="residentFinalizeForm.fecha_fin" class="w-full" type="date" :min="selectedOccupancy ? dayAfter(selectedOccupancy.fechaInicio) : undefined" :max="today" required size="xl" /></UFormField><UFormField label="Observaciones" name="observaciones" :error="firstError('observaciones')"><UTextarea v-model="residentFinalizeForm.observaciones" class="w-full" :rows="3" /></UFormField></form></template>
    <template #footer><div class="flex w-full justify-end gap-3"><UButton color="neutral" label="Cancelar" variant="outline" :disabled="loading" @click="residentFinalizeOpen = false" /><UButton color="error" form="finalize-resident" label="Finalizar" type="submit" :loading="loading" /></div></template>
  </UModal>
</template>
