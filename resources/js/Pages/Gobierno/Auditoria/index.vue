<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, usePoll } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type {
  EdificioAuditoriaOption,
  EventosAuditoriaPaginados,
  ExportacionAuditoriaResumen,
  FormatoExportacionAuditoria,
  FuenteAuditoriaCodigo,
  FuenteAuditoriaOption,
  LimitesExportacionAuditoria
} from '../../../types'
import { exportStatusLabel, hasActiveExports, utcInputToIso } from './exportacion'

const props = defineProps<{
  eventos: EventosAuditoriaPaginados
  edificios: EdificioAuditoriaOption[]
  fuentes: FuenteAuditoriaOption[]
  politicaRetencion: { configurada: boolean, eliminacionAutomatica: boolean }
  filters: { edificios: string[], fuente?: FuenteAuditoriaCodigo | null, tipo?: string | null }
  exportaciones: ExportacionAuditoriaResumen[]
  alcanceVigente: boolean
  huellaAlcance: string
  puedeExportar: boolean
  limitesExportacion: LimitesExportacionAuditoria
}>()

const edificiosSeleccionados = ref([...props.filters.edificios])
const fuente = ref<FuenteAuditoriaCodigo | ''>(props.filters.fuente ?? '')
const tipo = ref(props.filters.tipo ?? '')
const loading = ref(false)
const exporting = ref(false)
const exportErrors = ref<Record<string, string>>({})
const formatoExportacion = ref<FormatoExportacionAuditoria>('csv')
const now = new Date()
const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000))
const desdeExportacion = ref(sevenDaysAgo.toISOString().slice(0, 16))
const hastaExportacion = ref(now.toISOString().slice(0, 16))
const idempotencyKey = ref(crypto.randomUUID())
const acceptedScopeFingerprint = ref(props.huellaAlcance)
const scopeInvalidated = ref(false)
const fuenteSeleccionada = computed(() => props.fuentes.find(item => item.codigo === fuente.value))
const tipos = computed(() => fuenteSeleccionada.value?.tipos ?? [])
const edificiosItems = computed(() => props.edificios.map(item => ({ label: item.nombre, value: item.id })))
const zonasPorEdificio = computed(() => new Map(props.edificios.map(item => [item.id, item.timezone])))
const fuentesItems = computed(() => [
  { label: 'Todas las fuentes', value: '' },
  ...props.fuentes.map(item => ({ label: item.nombre, value: item.codigo }))
])
const scopeVisible = computed(() => props.alcanceVigente && !scopeInvalidated.value)
const activeExports = computed(() => scopeVisible.value && hasActiveExports(props.exportaciones))
const firstExportError = computed(() => Object.values(exportErrors.value)[0] ?? null)
const canRequestExport = computed(() => props.puedeExportar
  && edificiosSeleccionados.value.length > 0
  && desdeExportacion.value !== ''
  && hastaExportacion.value !== ''
  && !exporting.value)

const sourceColor: Record<FuenteAuditoriaCodigo, 'primary' | 'info' | 'success' | 'warning' | 'neutral'> = {
  accesos: 'primary',
  operaciones: 'warning',
  mantenimiento: 'success',
  areas_comunes: 'info',
  comunicacion: 'neutral'
}
const statusColor: Record<ExportacionAuditoriaResumen['status'], 'neutral' | 'warning' | 'info' | 'success' | 'error'> = {
  pendiente: 'warning',
  procesando: 'info',
  completada: 'success',
  fallida: 'error',
  expirada: 'neutral'
}

usePoll(5000, {
  only: ['alcanceVigente', 'huellaAlcance', 'puedeExportar']
}, { keepAlive: false })
const exportPoll = usePoll(5500, {
  only: ['exportaciones']
}, { autoStart: false, keepAlive: false })
watch(activeExports, active => active ? exportPoll.start() : exportPoll.stop(), { immediate: true })
watch(() => props.huellaAlcance, fingerprint => {
  if (fingerprint !== acceptedScopeFingerprint.value) scopeInvalidated.value = true
})
watch([formatoExportacion, desdeExportacion, hastaExportacion, edificiosSeleccionados, fuente, tipo], () => {
  idempotencyKey.value = crypto.randomUUID()
}, { deep: true })

const reload = (page = 1, replace = false) => router.get(route('gobierno.auditoria.index'), {
  edificios: edificiosSeleccionados.value,
  fuente: fuente.value || undefined,
  tipo: tipo.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onSuccess: () => {
    acceptedScopeFingerprint.value = props.huellaAlcance
    scopeInvalidated.value = false
  },
  onFinish: () => { loading.value = false }
})

const changeSource = () => {
  tipo.value = ''
  reload(1, true)
}

const changeBuildings = () => {
  if (!edificiosSeleccionados.value.length) {
    edificiosSeleccionados.value = props.edificios.map(item => item.id)
  }
  fuente.value = ''
  tipo.value = ''
  reload(1, true)
}

const formatDate = (value: string, buildingId: string) => new Intl.DateTimeFormat('es-EC', {
  dateStyle: 'medium',
  timeStyle: 'medium',
  timeZone: zonasPorEdificio.value.get(buildingId) ?? 'UTC'
}).format(new Date(value))

const retentionLabel = (days: number | null) => days === null
  ? 'Retención sin definir'
  : `Retención declarada: ${days} días`

const requestExport = () => {
  if (!canRequestExport.value) return
  router.post(route('gobierno.auditoria.exportaciones.store'), {
    idempotency_key: idempotencyKey.value,
    formato: formatoExportacion.value,
    edificios: edificiosSeleccionados.value,
    fuente: fuente.value || null,
    tipo: tipo.value || null,
    desde_utc: utcInputToIso(desdeExportacion.value),
    hasta_utc: utcInputToIso(hastaExportacion.value)
  }, {
    preserveScroll: true,
    onStart: () => {
      exporting.value = true
      exportErrors.value = {}
    },
    onSuccess: () => {
      idempotencyKey.value = crypto.randomUUID()
    },
    onError: errors => {
      exportErrors.value = errors
    },
    onFinish: () => { exporting.value = false }
  })
}

const downloadExport = (id: string) => window.location.assign(route('gobierno.auditoria.exportaciones.download', id))
const formatUtcDate = (value: string) => new Intl.DateTimeFormat('es-EC', {
  dateStyle: 'medium',
  timeStyle: 'short',
  timeZone: 'UTC'
}).format(new Date(value))

const exportFailureLabel = (code: string | null) => code === 'LimiteFilasExportacionExcedido'
  ? 'El resultado superó el límite del formato.'
  : 'No fue posible generar el archivo. Intente nuevamente.'
</script>

<template>
  <UDashboardPanel id="gobierno-auditoria">
    <template #header>
      <UDashboardNavbar title="Centro de auditoría">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UBadge
            :color="puedeExportar ? 'primary' : 'neutral'"
            :icon="puedeExportar ? 'i-lucide-file-down' : 'i-lucide-lock-keyhole'"
            :label="puedeExportar ? 'Exportación controlada' : 'Sólo lectura'"
            variant="subtle"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UAlert
          v-if="!scopeVisible"
          color="warning"
          icon="i-lucide-shield-alert"
          title="El alcance dejó de estar autorizado"
          description="Los datos anteriores se ocultaron porque cambió su acceso. Vuelva a abrir el Centro de auditoría para cargar el alcance vigente."
          variant="subtle"
        />
        <template v-else>
        <section class="overflow-hidden rounded-2xl border border-primary/20 bg-gradient-to-br from-primary/10 via-default to-default p-5 sm:p-7">
          <div class="max-w-3xl">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Gobierno y trazabilidad</p>
            <h1 class="mt-3 text-2xl font-semibold text-highlighted sm:text-3xl">Actividad administrativa verificable, sin duplicar la historia</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-muted">La vista combina las bitácoras inmutables autorizadas. Los identificadores internos, contenidos y datos personales permanecen minimizados.</p>
          </div>
        </section>

        <UCard>
          <div class="grid gap-4 lg:grid-cols-[1.4fr_1fr_1fr]">
            <UFormField label="Edificios autorizados" help="Puede consultar uno o varios edificios con permiso explícito.">
              <USelectMenu
                v-model="edificiosSeleccionados"
                class="w-full"
                :items="edificiosItems"
                label-key="label"
                value-key="value"
                multiple
                placeholder="Seleccione edificios"
                @update:model-value="changeBuildings"
              />
            </UFormField>
            <UFormField label="Fuente">
              <USelect v-model="fuente" class="w-full" :items="fuentesItems" @update:model-value="changeSource" />
            </UFormField>
            <UFormField label="Tipo de evento">
              <USelect
                v-model="tipo"
                class="w-full"
                :disabled="!fuente"
                :items="[{ label: 'Todos los tipos', value: '' }, ...tipos.map(item => ({ label: item.nombre, value: item.codigo }))]"
                @update:model-value="reload(1, true)"
              />
            </UFormField>
          </div>
        </UCard>

        <section class="grid overflow-hidden rounded-2xl border border-default bg-default lg:grid-cols-[minmax(0,1.05fr)_minmax(22rem,.95fr)]">
          <div class="border-b border-default p-5 lg:border-b-0 lg:border-r sm:p-6">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Salida verificable</p>
                <h2 class="mt-2 text-lg font-semibold text-highlighted">Preparar exportación</h2>
                <p class="mt-1 text-sm leading-6 text-muted">El alcance replica los filtros visibles. Las fechas se interpretan en UTC y el archivo privado expira en {{ limitesExportacion.ttlHours }} horas.</p>
              </div>
              <div class="rounded-xl bg-primary/10 p-3 text-primary"><UIcon name="i-lucide-shield-check" class="size-5" /></div>
            </div>

            <UAlert
              v-if="!puedeExportar"
              class="mt-5"
              color="warning"
              icon="i-lucide-lock"
              title="Permiso requerido en todo el alcance"
              description="Seleccione únicamente edificios donde tenga gobierno.exportar."
              variant="subtle"
            />
            <UAlert v-if="firstExportError" class="mt-5" color="error" icon="i-lucide-circle-alert" :description="firstExportError" variant="subtle" />

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
              <UFormField label="Desde (UTC)">
                <UInput v-model="desdeExportacion" class="w-full" type="datetime-local" :disabled="!puedeExportar || exporting" />
              </UFormField>
              <UFormField label="Hasta (UTC)">
                <UInput v-model="hastaExportacion" class="w-full" type="datetime-local" :disabled="!puedeExportar || exporting" />
              </UFormField>
            </div>
            <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
              <UFormField label="Formato" class="sm:w-52">
                <USelect
                  v-model="formatoExportacion"
                  class="w-full"
                  :items="[{ label: `CSV · hasta ${limitesExportacion.csvMaxRows.toLocaleString('es-EC')}`, value: 'csv' }, { label: `PDF · hasta ${limitesExportacion.pdfMaxRows.toLocaleString('es-EC')}`, value: 'pdf' }]"
                  :disabled="!puedeExportar || exporting"
                />
              </UFormField>
              <UButton
                icon="i-lucide-file-clock"
                label="Encolar exportación"
                :loading="exporting"
                :disabled="!canRequestExport"
                @click="requestExport"
              />
            </div>
            <p class="mt-4 text-xs text-muted">Rango máximo: {{ limitesExportacion.maxRangeDays }} días. La generación y la descarga vuelven a validar sus permisos.</p>
          </div>

          <div class="bg-elevated/35 p-5 sm:p-6" aria-live="polite">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-muted">Actividad reciente</p>
                <h2 class="mt-2 text-lg font-semibold text-highlighted">Archivos y cola</h2>
              </div>
              <UBadge v-if="activeExports" color="info" icon="i-lucide-loader-circle" label="Actualizando" variant="subtle" />
            </div>
            <div v-if="exportaciones.length" class="mt-5 space-y-3">
              <article v-for="item in exportaciones.slice(0, 5)" :key="item.id" class="rounded-xl border border-default bg-default/80 p-4">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <UBadge :color="statusColor[item.status]" :label="exportStatusLabel[item.status]" variant="subtle" />
                      <span class="font-mono text-xs font-semibold uppercase text-muted">{{ item.format }}</span>
                    </div>
                    <p class="mt-2 truncate text-sm font-medium text-highlighted">{{ item.buildings.join(', ') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ formatUtcDate(item.from) }} – {{ formatUtcDate(item.until) }} UTC</p>
                    <p v-if="item.status === 'procesando'" class="mt-2 text-xs text-info">{{ item.processed.toLocaleString('es-EC') }} evento(s) procesado(s)</p>
                    <p v-if="item.status === 'fallida'" class="mt-2 text-xs text-error">{{ exportFailureLabel(item.errorCode) }}</p>
                    <p v-if="item.downloadable && item.expiresAt" class="mt-2 text-xs text-muted">Disponible hasta {{ formatUtcDate(item.expiresAt) }} UTC</p>
                  </div>
                  <UButton
                    v-if="item.downloadable"
                    aria-label="Descargar exportación"
                    color="neutral"
                    icon="i-lucide-download"
                    size="sm"
                    variant="outline"
                    @click="downloadExport(item.id)"
                  />
                </div>
              </article>
            </div>
            <div v-else class="mt-5 rounded-xl border border-dashed border-default px-5 py-8 text-center">
              <UIcon name="i-lucide-inbox" class="mx-auto size-7 text-muted" />
              <p class="mt-2 text-sm font-medium text-highlighted">Aún no hay exportaciones</p>
              <p class="mt-1 text-xs text-muted">Las solicitudes aparecerán aquí sin exponer rutas internas.</p>
            </div>
          </div>
        </section>

        <div class="flex flex-col gap-3 rounded-xl border border-default bg-elevated/40 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-center gap-2 text-muted">
            <UIcon name="i-lucide-database" class="size-4 text-primary" />
            <span>{{ eventos.meta.total }} evento(s) nativo(s) encontrado(s)</span>
          </div>
          <div class="flex items-center gap-2 text-muted">
            <UIcon name="i-lucide-history" class="size-4" />
            <span>{{ politicaRetencion.configurada ? 'Retención declarada por configuración' : 'Política de retención pendiente' }} · sin eliminación automática</span>
          </div>
        </div>

        <section class="relative" :class="loading ? 'opacity-60' : ''" aria-live="polite">
          <div class="absolute bottom-4 left-[1.15rem] top-4 hidden w-px bg-default sm:block" />
          <div class="space-y-3">
            <article v-for="event in eventos.data" :key="event.id" class="relative rounded-2xl border border-default bg-default p-4 transition hover:border-primary/30 sm:ml-10 sm:p-5">
              <span class="absolute -left-[2.35rem] top-6 hidden size-3 rounded-full border-2 border-default bg-primary ring-4 ring-default sm:block" />
              <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <UBadge :color="sourceColor[event.fuente]" :label="event.fuenteLabel" variant="subtle" />
                    <UBadge color="neutral" :label="event.edificio" variant="outline" />
                  </div>
                  <h2 class="mt-3 text-base font-semibold text-highlighted">{{ event.tipoLabel }}</h2>
                  <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 xl:grid-cols-3">
                    <div><dt class="text-xs text-muted">Actor</dt><dd class="mt-1">{{ event.actor ?? 'Identidad restringida' }}</dd></div>
                    <div><dt class="text-xs text-muted">Recurso</dt><dd class="mt-1">{{ event.recursoTipo }}<span v-if="event.recursoReferencia" class="font-mono text-xs text-muted"> · {{ event.recursoReferencia }}</span></dd></div>
                    <div><dt class="text-xs text-muted">Conservación</dt><dd class="mt-1">{{ retentionLabel(event.retencionDias) }}</dd></div>
                  </dl>
                </div>
                <time class="shrink-0 text-xs text-muted" :datetime="event.ocurridoAt">
                  {{ formatDate(event.ocurridoAt, event.edificioId) }} · {{ zonasPorEdificio.get(event.edificioId) ?? 'UTC' }}
                </time>
              </div>
            </article>
            <div v-if="!eventos.data.length" class="rounded-2xl border border-dashed border-default p-12 text-center">
              <UIcon name="i-lucide-shield-search" class="mx-auto size-8 text-muted" />
              <p class="mt-3 font-medium text-highlighted">No hay eventos visibles</p>
              <p class="mt-1 text-sm text-muted">Revise los edificios o filtros seleccionados.</p>
            </div>
          </div>
        </section>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-muted">Página {{ eventos.meta.currentPage }} de {{ eventos.meta.lastPage }}</p>
          <div class="flex gap-2">
            <UButton color="neutral" label="Anterior" variant="outline" :disabled="eventos.meta.currentPage <= 1 || loading" @click="reload(eventos.meta.currentPage - 1)" />
            <UButton color="neutral" label="Siguiente" variant="outline" :disabled="eventos.meta.currentPage >= eventos.meta.lastPage || loading" @click="reload(eventos.meta.currentPage + 1)" />
          </div>
        </div>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
