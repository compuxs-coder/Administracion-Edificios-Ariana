<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import type { Propietario } from '../../types'

defineProps<{ propietario: Propietario }>()
const section = ref<'actuales' | 'historial'>('actuales')
const formatDate = (date: string) => new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`))
</script>

<template>
  <UDashboardPanel id="propietarios-show">
    <template #header>
      <UDashboardNavbar :title="propietario.nombre">
        <template #leading><UDashboardSidebarCollapse /></template>
        <template #right><UButton v-if="propietario.puedeEditarIdentidad" color="neutral" icon="i-lucide-pencil" label="Editar" variant="outline" @click="router.visit(route('propietarios.edit', propietario.id))" /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
          <UCard>
            <template #header><div class="flex items-start justify-between gap-4"><div><p class="text-sm text-muted">Datos del propietario</p><h1 class="mt-1 text-xl font-semibold text-highlighted">{{ propietario.nombre }}</h1></div><UBadge :color="propietario.estado === 'activo' ? 'success' : 'neutral'" :label="propietario.estado === 'activo' ? 'Activo' : 'Inactivo'" variant="subtle" /></div></template>
            <dl class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
              <div><dt class="text-xs uppercase tracking-wide text-muted">Tipo</dt><dd class="mt-1 text-sm text-highlighted">{{ propietario.tipoPersona === 'persona_natural' ? 'Persona natural' : 'Persona jurídica' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Identificación</dt><dd class="mt-1 font-mono text-sm text-highlighted">{{ propietario.tipoIdentificacion.toUpperCase() }} · {{ propietario.identificacion }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Teléfono</dt><dd class="mt-1 text-sm text-highlighted">{{ propietario.telefono || 'No registrado' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Celular</dt><dd class="mt-1 text-sm text-highlighted">{{ propietario.celular || 'No registrado' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Correo</dt><dd class="mt-1 break-all text-sm text-highlighted">{{ propietario.correo || 'No registrado' }}</dd></div>
              <div><dt class="text-xs uppercase tracking-wide text-muted">Dirección</dt><dd class="mt-1 text-sm text-highlighted">{{ propietario.direccion || 'No registrada' }}</dd></div>
              <div v-if="propietario.observaciones" class="sm:col-span-2"><dt class="text-xs uppercase tracking-wide text-muted">Observaciones</dt><dd class="mt-1 whitespace-pre-line text-sm text-highlighted">{{ propietario.observaciones }}</dd></div>
            </dl>
          </UCard>
          <UCard><template #header><p class="font-medium text-highlighted">Resumen</p></template><div class="flex items-center gap-4"><div class="rounded-xl bg-primary/10 p-3 text-primary"><UIcon name="i-lucide-house-key" class="size-6" /></div><div><p class="text-3xl font-semibold text-highlighted">{{ propietario.propiedadesActuales?.length ?? 0 }}</p><p class="text-sm text-muted">Propiedades actuales visibles</p></div></div></UCard>
        </div>

        <UCard>
          <template #header><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="font-medium text-highlighted">Propiedades</p><p class="mt-1 text-xs text-muted">Titularidades actuales e historial conservado.</p></div><div class="flex gap-1"><UButton :color="section === 'actuales' ? 'primary' : 'neutral'" label="Actuales" :variant="section === 'actuales' ? 'soft' : 'ghost'" :aria-pressed="section === 'actuales'" @click="section = 'actuales'" /><UButton :color="section === 'historial' ? 'primary' : 'neutral'" label="Historial" :variant="section === 'historial' ? 'soft' : 'ghost'" :aria-pressed="section === 'historial'" @click="section = 'historial'" /></div></div></template>
          <div class="overflow-x-auto"><table class="w-full min-w-4xl text-left text-sm"><thead class="border-b border-default text-xs uppercase tracking-wide text-muted"><tr><th class="px-3 py-3 font-medium">Edificio</th><th class="px-3 py-3 font-medium">Ubicación</th><th class="px-3 py-3 font-medium">Departamento</th><th class="px-3 py-3 font-medium">Participación</th><th class="px-3 py-3 font-medium">Vigencia</th></tr></thead><tbody class="divide-y divide-default">
            <tr v-for="item in section === 'actuales' ? propietario.propiedadesActuales : propietario.historialPropiedades" :key="item.id"><td class="px-3 py-3 font-medium text-highlighted">{{ item.edificio }}</td><td class="px-3 py-3 text-muted">{{ item.torre }} · {{ item.piso }}</td><td class="px-3 py-3"><UButton color="neutral" :label="item.departamento" variant="link" @click="router.visit(route('departamentos.show', [item.edificioId, item.departamentoId]))" /></td><td class="px-3 py-3 font-mono">{{ Number(item.porcentaje).toFixed(6) }}%</td><td class="px-3 py-3 text-muted">{{ formatDate(item.fechaInicio) }}<span v-if="item.fechaFin"> – {{ formatDate(item.fechaFin) }}</span></td></tr>
            <tr v-if="!(section === 'actuales' ? propietario.propiedadesActuales : propietario.historialPropiedades)?.length"><td colspan="5" class="px-3 py-10 text-center text-muted">No hay propiedades en esta sección.</td></tr>
          </tbody></table></div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
