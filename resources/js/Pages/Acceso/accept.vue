<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

defineProps<{
  invitacion: {
    edificio: { id: string, nombre: string }
    rol: { codigo: string, nombre: string, descripcion: string }
    email: string
    expiraEn: string
  }
  token: string
}>()
const loading = ref(false)
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-primary-50 via-white to-slate-100 p-4 dark:from-primary-950 dark:via-slate-950 dark:to-slate-900">
    <UCard class="w-full max-w-lg shadow-xl">
      <template #header><div class="flex items-center gap-4"><div class="flex size-12 items-center justify-center rounded-xl bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300"><UIcon name="i-lucide-building-2" class="size-6" /></div><div><p class="text-sm text-muted">Invitación administrativa</p><h1 class="text-xl font-semibold text-highlighted">{{ invitacion.edificio.nombre }}</h1></div></div></template>
      <div class="space-y-5"><p class="text-sm text-muted">Esta invitación está dirigida a <strong class="text-highlighted">{{ invitacion.email }}</strong>.</p><div class="rounded-xl border border-default bg-elevated/40 p-4"><p class="text-xs font-medium uppercase tracking-wide text-muted">Rol asignado</p><p class="mt-1 font-semibold text-highlighted">{{ invitacion.rol.nombre }}</p><p class="mt-1 text-sm text-muted">{{ invitacion.rol.descripcion }}</p></div><p class="text-xs text-dimmed">Vence {{ new Intl.DateTimeFormat('es', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(invitacion.expiraEn)) }}.</p></div>
      <template #footer><div class="flex justify-end"><UButton icon="i-lucide-check" label="Aceptar invitación" :loading="loading" @click="router.post(route('invitaciones-edificio.accept', token), {}, { onStart: () => { loading = true }, onFinish: () => { loading = false } })" /></div></template>
    </UCard>
  </div>
</template>
