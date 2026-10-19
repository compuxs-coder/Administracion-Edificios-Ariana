<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { ComunicadosPaginados, EdificioComunicacionOption, EstadoComunicado } from '../../types'
import { useBuildingPermissions } from '../../composables/useBuildingPermissions'

const props = defineProps<{
  comunicados: ComunicadosPaginados
  filters: { buscar?: string | null, edificio_id?: string | null, estado?: EstadoComunicado | null }
  edificios: EdificioComunicacionOption[]
}>()
const buscar = ref(props.filters.buscar ?? '')
const edificioId = ref(props.filters.edificio_id ?? '')
const estado = ref(props.filters.estado ?? '')
const loading = ref(false)
const { can, canAny } = useBuildingPermissions()
let timer: ReturnType<typeof setTimeout> | undefined

const stateLabel: Record<EstadoComunicado, string> = { borrador: 'Borrador', publicado: 'Publicado', archivado: 'Archivado' }
const stateColor = (value: EstadoComunicado): 'warning' | 'success' | 'neutral' => ({ borrador: 'warning', publicado: 'success', archivado: 'neutral' })[value]
const audience = (values: string[]) => values.length === 2 ? 'Propietarios y residentes' : values[0] === 'propietarios' ? 'Propietarios' : 'Residentes'
const formatDate = (value: string | null) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Sin publicar'
const reload = (page = 1, replace = false) => router.get(route('comunicados.index'), {
  buscar: buscar.value || undefined,
  edificio_id: edificioId.value || undefined,
  estado: estado.value || undefined,
  page
}, {
  preserveState: true,
  preserveScroll: true,
  replace,
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
watch(buscar, () => {
  clearTimeout(timer)
  timer = setTimeout(() => reload(1, true), 300)
})
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <UDashboardPanel id="comunicados">
    <template #header>
      <UDashboardNavbar title="Comunicados">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="canAny('comunicados.gestionar')" icon="i-lucide-megaphone" label="Nuevo comunicado" @click="router.visit(route('comunicados.create'))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="flex h-full flex-col gap-5 p-4 sm:p-6">
        <UCard>
          <div class="grid gap-3 md:grid-cols-3">
            <UInput v-model="buscar" aria-label="Buscar comunicados" icon="i-lucide-search" maxlength="100" placeholder="Título o contenido" />
            <USelect v-model="edificioId" aria-label="Filtrar por edificio" :items="[{ label: 'Todos los edificios', value: '' }, ...edificios.map(item => ({ label: item.nombre, value: item.id }))]" @update:model-value="reload(1, true)" />
            <USelect v-model="estado" aria-label="Filtrar por estado" :items="[{ label: 'Todos los estados', value: '' }, { label: 'Borradores', value: 'borrador' }, { label: 'Publicados', value: 'publicado' }, { label: 'Archivados', value: 'archivado' }]" @update:model-value="reload(1, true)" />
          </div>
        </UCard>

        <div class="grid gap-4 xl:grid-cols-2" :class="loading ? 'opacity-60' : ''">
          <article v-for="message in comunicados.data" :key="message.id" class="group rounded-2xl border border-default bg-default p-5 transition hover:border-primary/40 hover:shadow-sm">
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0"><p class="text-xs font-medium uppercase tracking-wide text-primary">{{ message.edificio }}</p><h2 class="mt-2 line-clamp-2 text-lg font-semibold text-highlighted">{{ message.titulo }}</h2></div>
              <UBadge :color="stateColor(message.estado)" :label="stateLabel[message.estado]" variant="subtle" />
            </div>
            <p class="mt-3 line-clamp-2 whitespace-pre-line text-sm leading-6 text-muted">{{ message.cuerpo }}</p>
            <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-default pt-4 text-sm">
              <div><dt class="text-xs text-muted">Audiencia</dt><dd class="mt-1 font-medium">{{ audience(message.audiencias) }}</dd></div>
              <div><dt class="text-xs text-muted">Publicación</dt><dd class="mt-1">{{ formatDate(message.publicadoAt) }}</dd></div>
              <div><dt class="text-xs text-muted">Documentos</dt><dd class="mt-1">{{ message.documentosCount }}</dd></div>
              <div><dt class="text-xs text-muted">Entregas</dt><dd class="mt-1">{{ message.estado === 'borrador' ? 'Pendiente' : (message.totalEntregas ?? 'Restringido') }}</dd></div>
            </dl>
            <div class="mt-5 flex justify-end gap-2"><UButton color="neutral" icon="i-lucide-eye" label="Ver" variant="outline" @click="router.visit(route('comunicados.show', [message.edificioId, message.id]))" /><UButton v-if="message.estado === 'borrador' && can('comunicados.gestionar', message.edificioId)" color="neutral" icon="i-lucide-pencil" label="Editar" variant="ghost" @click="router.visit(route('comunicados.edit', [message.edificioId, message.id]))" /></div>
          </article>
          <p v-if="!comunicados.data.length" class="col-span-full rounded-2xl border border-dashed border-default p-12 text-center text-muted">No hay comunicados que coincidan con los filtros.</p>
        </div>

        <div class="mt-auto flex flex-col gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted">{{ comunicados.meta.total }} comunicado(s) · página {{ comunicados.meta.currentPage }} de {{ comunicados.meta.lastPage }}</p><div class="flex gap-2"><UButton color="neutral" label="Anterior" variant="outline" :disabled="comunicados.meta.currentPage <= 1 || loading" @click="reload(comunicados.meta.currentPage - 1)" /><UButton color="neutral" label="Siguiente" variant="outline" :disabled="comunicados.meta.currentPage >= comunicados.meta.lastPage || loading" @click="reload(comunicados.meta.currentPage + 1)" /></div></div>
      </div>
    </template>
  </UDashboardPanel>
</template>
