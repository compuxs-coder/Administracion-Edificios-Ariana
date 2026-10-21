<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type {
  EdificioAuditoriaOption,
  EventosAuditoriaPaginados,
  FuenteAuditoriaCodigo,
  FuenteAuditoriaOption
} from '../../../types'

const props = defineProps<{
  eventos: EventosAuditoriaPaginados
  edificios: EdificioAuditoriaOption[]
  fuentes: FuenteAuditoriaOption[]
  politicaRetencion: { configurada: boolean, eliminacionAutomatica: boolean }
  filters: { edificios: string[], fuente?: FuenteAuditoriaCodigo | null, tipo?: string | null }
}>()

const edificiosSeleccionados = ref([...props.filters.edificios])
const fuente = ref<FuenteAuditoriaCodigo | ''>(props.filters.fuente ?? '')
const tipo = ref(props.filters.tipo ?? '')
const loading = ref(false)
const fuenteSeleccionada = computed(() => props.fuentes.find(item => item.codigo === fuente.value))
const tipos = computed(() => fuenteSeleccionada.value?.tipos ?? [])
const edificiosItems = computed(() => props.edificios.map(item => ({ label: item.nombre, value: item.id })))
const zonasPorEdificio = computed(() => new Map(props.edificios.map(item => [item.id, item.timezone])))
const fuentesItems = computed(() => [
  { label: 'Todas las fuentes', value: '' },
  ...props.fuentes.map(item => ({ label: item.nombre, value: item.codigo }))
])

const sourceColor: Record<FuenteAuditoriaCodigo, 'primary' | 'info' | 'success' | 'warning' | 'neutral'> = {
  accesos: 'primary',
  operaciones: 'warning',
  mantenimiento: 'success',
  areas_comunes: 'info',
  comunicacion: 'neutral'
}

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
</script>

<template>
  <UDashboardPanel id="gobierno-auditoria">
    <template #header>
      <UDashboardNavbar title="Centro de auditoría">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right>
          <UBadge color="neutral" icon="i-lucide-lock-keyhole" label="Sólo lectura" variant="subtle" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
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
      </div>
    </template>
  </UDashboardPanel>
</template>
