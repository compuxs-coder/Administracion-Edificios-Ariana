<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type {
  AnexoEstructura,
  Edificio,
  EstructuraEdificio,
  EstadoEstructura,
  PisoEstructura,
  TorreEstructura
} from '../../types'

type Section = 'jerarquia' | 'parqueaderos' | 'bodegas'
type EditorType = 'torre' | 'piso' | 'parqueadero' | 'bodega'

const props = defineProps<{ edificio: Edificio, estructura: EstructuraEdificio }>()
const section = ref<Section>('jerarquia')
const editorType = ref<EditorType | null>(null)
const isEditorOpen = ref(false)
const isSaving = ref(false)
const isStatusModalOpen = ref(false)
const isChangingStatus = ref(false)
const editorErrors = ref<Record<string, string>>({})
const statusError = ref('')
const statusTarget = ref<{
  tipo: EditorType
  id: string
  label: string
  estado: EstadoEstructura
} | null>(null)

const form = reactive({
  id: null as string | null,
  torre_id: null as string | null,
  codigo: '',
  nombre: '',
  descripcion: '',
  numero: '',
  orden: 0,
  ubicacion: ''
})

const activeTorres = computed(() => props.estructura.torres.filter(torre => torre.estado === 'activo'))
const torreItems = computed(() => activeTorres.value.map(torre => ({ label: torre.nombre, value: torre.id })))
const currentAnexos = computed(() => section.value === 'parqueaderos'
  ? props.estructura.parqueaderos
  : props.estructura.bodegas)

const editorTitle = computed(() => {
  const action = form.id ? 'Editar' : 'Crear'
  const labels: Record<EditorType, string> = {
    torre: 'torre',
    piso: 'piso',
    parqueadero: 'parqueadero',
    bodega: 'bodega'
  }
  return editorType.value ? `${action} ${labels[editorType.value]}` : ''
})

const resetForm = () => {
  Object.assign(form, {
    id: null,
    torre_id: null,
    codigo: '',
    nombre: '',
    descripcion: '',
    numero: '',
    orden: 0,
    ubicacion: ''
  })
}

const openCreate = (type: EditorType, torreId: string | null = null) => {
  editorErrors.value = {}
  resetForm()
  editorType.value = type
  form.torre_id = torreId ?? (activeTorres.value.length === 1 ? activeTorres.value[0]?.id ?? null : null)
  if (type === 'piso' && torreId) {
    const torre = props.estructura.torres.find(item => item.id === torreId)
    form.orden = (torre?.pisos.reduce((max, piso) => Math.max(max, piso.orden), 0) ?? 0) + 1
  }
  isEditorOpen.value = true
}

const editTorre = (torre: TorreEstructura) => {
  editorErrors.value = {}
  resetForm()
  editorType.value = 'torre'
  Object.assign(form, {
    id: torre.id,
    codigo: torre.codigo,
    nombre: torre.nombre,
    descripcion: torre.descripcion ?? ''
  })
  isEditorOpen.value = true
}

const editPiso = (piso: PisoEstructura) => {
  editorErrors.value = {}
  resetForm()
  editorType.value = 'piso'
  Object.assign(form, {
    id: piso.id,
    torre_id: piso.torreId,
    numero: piso.numero,
    nombre: piso.nombre ?? '',
    orden: piso.orden
  })
  isEditorOpen.value = true
}

const editAnexo = (type: 'parqueadero' | 'bodega', anexo: AnexoEstructura) => {
  editorErrors.value = {}
  resetForm()
  editorType.value = type
  Object.assign(form, {
    id: anexo.id,
    torre_id: anexo.torreId,
    codigo: anexo.codigo,
    ubicacion: anexo.ubicacion ?? ''
  })
  isEditorOpen.value = true
}

const submitEditor = () => {
  if (!editorType.value) return

  const routes = {
    torre: {
      store: 'edificios.torres.store',
      update: 'edificios.torres.update',
      data: { codigo: form.codigo, nombre: form.nombre, descripcion: form.descripcion || null }
    },
    piso: {
      store: 'edificios.pisos.store',
      update: 'edificios.pisos.update',
      data: { torre_id: form.torre_id, numero: form.numero, nombre: form.nombre || null, orden: form.orden }
    },
    parqueadero: {
      store: 'edificios.parqueaderos.store',
      update: 'edificios.parqueaderos.update',
      data: { torre_id: form.torre_id, codigo: form.codigo, ubicacion: form.ubicacion || null }
    },
    bodega: {
      store: 'edificios.bodegas.store',
      update: 'edificios.bodegas.update',
      data: { torre_id: form.torre_id, codigo: form.codigo, ubicacion: form.ubicacion || null }
    }
  }[editorType.value]

  const options = {
    preserveScroll: true,
    onStart: () => { isSaving.value = true },
    onSuccess: () => {
      isEditorOpen.value = false
      resetForm()
    },
    onError: errors => { editorErrors.value = errors },
    onFinish: () => { isSaving.value = false }
  }

  if (form.id) {
    router.put(route(routes.update, [props.edificio.id, form.id]), routes.data, options)
  } else {
    router.post(route(routes.store, props.edificio.id), routes.data, options)
  }
}

const confirmStatus = (
  tipo: EditorType,
  id: string,
  label: string,
  estado: EstadoEstructura
) => {
  statusError.value = ''
  statusTarget.value = { tipo, id, label, estado }
  isStatusModalOpen.value = true
}

const changeStatus = () => {
  if (!statusTarget.value) return
  const nextStatus: EstadoEstructura = statusTarget.value.estado === 'activo' ? 'inactivo' : 'activo'

  router.patch(route('edificios.estructura.estado', [
    props.edificio.id,
    statusTarget.value.tipo,
    statusTarget.value.id
  ]), { estado: nextStatus }, {
    preserveScroll: true,
    onStart: () => { isChangingStatus.value = true },
    onSuccess: () => {
      isStatusModalOpen.value = false
      statusTarget.value = null
    },
    onError: errors => { statusError.value = String(errors.estado ?? 'No fue posible cambiar el estado.') },
    onFinish: () => { isChangingStatus.value = false }
  })
}

const operationalLabel = (estado: AnexoEstructura['estadoOperativo']) => ({
  disponible: 'Disponible',
  asignado: 'Asignado',
  inactivo: 'Inactivo'
}[estado])
</script>

<template>
  <UDashboardPanel id="estructura-edificio">
    <template #header>
      <UDashboardNavbar :title="`Estructura · ${edificio.nombre}`">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            color="neutral"
            icon="i-lucide-arrow-left"
            label="Volver"
            aria-label="Volver al edificio"
            variant="outline"
            :ui="{ label: 'hidden sm:inline' }"
            @click="router.visit(route('edificios.show', edificio.id))"
          />
          <UButton
            color="primary"
            icon="i-lucide-plus"
            label="Nuevo departamento"
            aria-label="Crear departamento"
            :disabled="edificio.estado === 'inactivo'"
            :ui="{ label: 'hidden sm:inline' }"
            @click="router.visit(route('departamentos.create', { edificio: edificio.id }))"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 sm:p-6">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          <UCard v-for="item in [
            { label: 'Torres', value: estructura.resumen.torres, icon: 'i-lucide-building-2' },
            { label: 'Pisos', value: estructura.resumen.pisos, icon: 'i-lucide-layers-3' },
            { label: 'Departamentos', value: estructura.resumen.departamentos, icon: 'i-lucide-door-open' },
            { label: 'Parqueaderos libres', value: estructura.resumen.parqueaderosDisponibles, icon: 'i-lucide-car-front' },
            { label: 'Bodegas libres', value: estructura.resumen.bodegasDisponibles, icon: 'i-lucide-archive' }
          ]" :key="item.label" :ui="{ body: 'p-4 sm:p-4' }">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-muted">{{ item.label }}</p>
                <p class="mt-1 text-2xl font-semibold text-highlighted">{{ item.value }}</p>
              </div>
              <div class="rounded-xl bg-primary/10 p-2.5 text-primary">
                <UIcon :name="item.icon" class="size-5" />
              </div>
            </div>
          </UCard>
        </div>

        <div class="flex flex-col gap-3 border-b border-default sm:flex-row sm:items-center sm:justify-between">
          <div class="flex gap-1 overflow-x-auto pb-3">
            <UButton
              v-for="item in [
                { value: 'jerarquia', label: 'Torres y pisos', icon: 'i-lucide-network' },
                { value: 'parqueaderos', label: 'Parqueaderos', icon: 'i-lucide-car-front' },
                { value: 'bodegas', label: 'Bodegas', icon: 'i-lucide-archive' }
              ]"
              :key="item.value"
              :color="section === item.value ? 'primary' : 'neutral'"
              :icon="item.icon"
              :label="item.label"
              :aria-pressed="section === item.value"
              :variant="section === item.value ? 'soft' : 'ghost'"
              @click="section = item.value as Section"
            />
          </div>
          <UButton
            v-if="section === 'jerarquia'"
            class="mb-3"
            icon="i-lucide-plus"
            label="Nueva torre"
            @click="openCreate('torre')"
          />
          <UButton
            v-else
            class="mb-3"
            icon="i-lucide-plus"
            :label="section === 'parqueaderos' ? 'Nuevo parqueadero' : 'Nueva bodega'"
            @click="openCreate(section === 'parqueaderos' ? 'parqueadero' : 'bodega')"
          />
        </div>

        <div v-if="section === 'jerarquia'" class="grid gap-5 lg:grid-cols-2">
          <UCard v-for="torre in estructura.torres" :key="torre.id" class="overflow-hidden">
            <template #header>
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <h2 class="truncate text-lg font-semibold text-highlighted">{{ torre.nombre }}</h2>
                    <UBadge v-if="torre.esPredeterminada" color="primary" label="Principal" variant="subtle" />
                    <UBadge
                      :color="torre.estado === 'activo' ? 'success' : 'neutral'"
                      :label="torre.estado === 'activo' ? 'Activa' : 'Inactiva'"
                      variant="subtle"
                    />
                  </div>
                  <p class="mt-1 text-sm text-muted">{{ torre.codigo }}<span v-if="torre.descripcion"> · {{ torre.descripcion }}</span></p>
                </div>
                <div class="flex shrink-0 gap-1">
                  <UButton color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar torre" @click="editTorre(torre)" />
                  <UButton
                    v-if="!torre.esPredeterminada"
                    :color="torre.estado === 'activo' ? 'error' : 'success'"
                    :icon="torre.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'"
                    variant="ghost"
                    :aria-label="torre.estado === 'activo' ? 'Inactivar torre' : 'Activar torre'"
                    @click="confirmStatus('torre', torre.id, torre.nombre, torre.estado)"
                  />
                </div>
              </div>
            </template>

            <div class="space-y-2">
              <div
                v-for="piso in torre.pisos"
                :key="piso.id"
                class="flex items-center justify-between gap-3 rounded-lg border border-default px-3 py-2.5"
              >
                <div class="flex min-w-0 items-center gap-3">
                  <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-elevated font-mono text-sm font-semibold text-highlighted">
                    {{ piso.numero }}
                  </div>
                  <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-highlighted">{{ piso.nombre || `Piso ${piso.numero}` }}</p>
                    <p class="text-xs text-muted">{{ piso.departamentos }} departamento(s) · orden {{ piso.orden }}</p>
                  </div>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                  <UBadge :color="piso.estado === 'activo' ? 'success' : 'neutral'" :label="piso.estado" variant="subtle" />
                  <UButton color="neutral" icon="i-lucide-pencil" variant="ghost" aria-label="Editar piso" @click="editPiso(piso)" />
                  <UButton
                    :color="piso.estado === 'activo' ? 'error' : 'success'"
                    :icon="piso.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'"
                    variant="ghost"
                    aria-label="Cambiar estado del piso"
                    @click="confirmStatus('piso', piso.id, piso.nombre || `Piso ${piso.numero}`, piso.estado)"
                  />
                </div>
              </div>

              <div v-if="torre.pisos.length === 0" class="rounded-lg border border-dashed border-default p-5 text-center text-sm text-muted">
                Esta torre todavía no tiene pisos.
              </div>
            </div>

            <template #footer>
              <UButton
                color="neutral"
                icon="i-lucide-plus"
                label="Agregar piso"
                variant="outline"
                :disabled="torre.estado === 'inactivo'"
                @click="openCreate('piso', torre.id)"
              />
            </template>
          </UCard>
        </div>

        <UCard v-else>
          <div class="overflow-x-auto">
            <table class="w-full min-w-3xl text-left text-sm">
              <thead class="border-b border-default text-xs uppercase tracking-wide text-muted">
                <tr>
                  <th class="px-3 py-3 font-medium">Código</th>
                  <th class="px-3 py-3 font-medium">Ubicación</th>
                  <th class="px-3 py-3 font-medium">Torre</th>
                  <th class="px-3 py-3 font-medium">Disponibilidad</th>
                  <th class="px-3 py-3 font-medium">Asignado a</th>
                  <th class="px-3 py-3 text-right font-medium">Acciones</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-default">
                <tr v-for="anexo in currentAnexos" :key="anexo.id">
                  <td class="px-3 py-3 font-mono font-medium text-highlighted">{{ anexo.codigo }}</td>
                  <td class="px-3 py-3 text-muted">{{ anexo.ubicacion || 'Sin detalle' }}</td>
                  <td class="px-3 py-3 text-muted">{{ anexo.torre || 'Área común' }}</td>
                  <td class="px-3 py-3">
                    <UBadge
                      :color="anexo.estadoOperativo === 'disponible' ? 'success' : anexo.estadoOperativo === 'asignado' ? 'warning' : 'neutral'"
                      :label="operationalLabel(anexo.estadoOperativo)"
                      variant="subtle"
                    />
                  </td>
                  <td class="px-3 py-3 text-muted">{{ anexo.departamento?.codigo || '—' }}</td>
                  <td class="px-3 py-3">
                    <div class="flex justify-end gap-1">
                      <UButton
                        color="neutral"
                        icon="i-lucide-pencil"
                        variant="ghost"
                        aria-label="Editar anexo"
                        @click="editAnexo(section === 'parqueaderos' ? 'parqueadero' : 'bodega', anexo)"
                      />
                      <UButton
                        :color="anexo.estado === 'activo' ? 'error' : 'success'"
                        :icon="anexo.estado === 'activo' ? 'i-lucide-circle-pause' : 'i-lucide-circle-play'"
                        variant="ghost"
                        aria-label="Cambiar estado del anexo"
                        @click="confirmStatus(section === 'parqueaderos' ? 'parqueadero' : 'bodega', anexo.id, anexo.codigo, anexo.estado)"
                      />
                    </div>
                  </td>
                </tr>
                <tr v-if="currentAnexos.length === 0">
                  <td colspan="6" class="px-3 py-10 text-center text-muted">
                    No hay {{ section }} registrados.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="isEditorOpen"
    :close="!isSaving"
    :dismissible="!isSaving"
    :title="editorTitle"
    description="Los códigos se normalizan en mayúsculas y son únicos dentro del edificio."
  >
    <template #body>
      <form id="estructura-editor" class="space-y-4" @submit.prevent="submitEditor">
        <UFormField v-if="editorType !== 'torre'" label="Torre" name="torre_id" :required="editorType === 'piso'" :error="editorErrors.torreId">
          <USelect
            v-model="form.torre_id"
            class="w-full"
            :items="editorType === 'piso' ? torreItems : [{ label: 'Área común', value: null }, ...torreItems]"
            placeholder="Seleccione una torre"
            :required="editorType === 'piso'"
            size="xl"
          />
        </UFormField>

        <template v-if="editorType === 'torre'">
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Código" name="codigo" required :error="editorErrors.codigo">
              <UInput v-model="form.codigo" class="w-full" placeholder="TORRE-A" required size="xl" />
            </UFormField>
            <UFormField label="Nombre" name="nombre" required :error="editorErrors.nombre">
              <UInput v-model="form.nombre" class="w-full" placeholder="Torre A" required size="xl" />
            </UFormField>
          </div>
          <UFormField label="Descripción" name="descripcion" hint="Opcional" :error="editorErrors.descripcion">
            <UTextarea v-model="form.descripcion" class="w-full" :rows="3" placeholder="Referencia interna de la torre" />
          </UFormField>
        </template>

        <template v-else-if="editorType === 'piso'">
          <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Número o código" name="numero" required :error="editorErrors.numero">
              <UInput v-model="form.numero" class="w-full" placeholder="PB, 1, 2..." required size="xl" />
            </UFormField>
            <UFormField label="Orden" name="orden" required :error="editorErrors.orden">
              <UInput v-model.number="form.orden" class="w-full" type="number" min="-20" max="300" required size="xl" />
            </UFormField>
          </div>
          <UFormField label="Nombre descriptivo" name="nombre" hint="Opcional" :error="editorErrors.nombre">
            <UInput v-model="form.nombre" class="w-full" placeholder="Planta baja" size="xl" />
          </UFormField>
        </template>

        <template v-else>
          <UFormField label="Código" name="codigo" required :error="editorErrors.codigo">
            <UInput v-model="form.codigo" class="w-full" placeholder="P-001" required size="xl" />
          </UFormField>
          <UFormField label="Ubicación" name="ubicacion" hint="Opcional" :error="editorErrors.ubicacion">
            <UInput v-model="form.ubicacion" class="w-full" placeholder="Subsuelo 1, sector norte" size="xl" />
          </UFormField>
        </template>
      </form>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton color="neutral" label="Cancelar" variant="outline" :disabled="isSaving" @click="isEditorOpen = false" />
        <UButton form="estructura-editor" icon="i-lucide-save" label="Guardar" type="submit" :loading="isSaving" />
      </div>
    </template>
  </UModal>

  <UModal
    v-model:open="isStatusModalOpen"
    :close="!isChangingStatus"
    :dismissible="!isChangingStatus"
    title="Cambiar estado"
    :description="statusTarget ? `¿Confirma cambiar el estado de ${statusTarget.label}?` : ''"
  >
    <template #body>
      <UAlert v-if="statusError" color="error" icon="i-lucide-circle-alert" :description="statusError" role="alert" variant="subtle" />
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-3">
        <UButton color="neutral" label="Cancelar" variant="outline" :disabled="isChangingStatus" @click="isStatusModalOpen = false" />
        <UButton
          :color="statusTarget?.estado === 'activo' ? 'error' : 'success'"
          :label="statusTarget?.estado === 'activo' ? 'Inactivar' : 'Activar'"
          :loading="isChangingStatus"
          @click="changeStatus"
        />
      </div>
    </template>
  </UModal>
</template>
