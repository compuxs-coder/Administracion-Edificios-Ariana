<script setup lang="ts">
import { router } from '@inertiajs/vue3'
</script>

<template>
  <UDashboardPanel id="home">
    <template #header>
      <UDashboardNavbar title="Inicio">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="grid gap-6 p-4 sm:p-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <section class="rounded-xl border border-default bg-default p-6 sm:p-8">
          <div class="mb-5 flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
            <UIcon name="i-lucide-building-2" class="size-6" />
          </div>
          <p class="mb-2 text-sm font-medium text-primary">Administración de edificios</p>
          <h1 class="text-2xl font-semibold text-highlighted sm:text-3xl">Base técnica preparada</h1>
          <p class="mt-3 max-w-2xl text-sm leading-6 text-muted sm:text-base">
            Los indicadores financieros se mostrarán cuando los módulos de edificios, cargos y pagos
            estén implementados. Este panel no presenta datos simulados.
          </p>
        </section>

        <section class="rounded-xl border border-default bg-elevated/40 p-6">
          <p class="text-sm font-medium text-highlighted">Módulo disponible</p>
          <p class="mt-2 text-sm leading-6 text-muted">
            La gestión de clientes heredada permanece accesible durante la transición al modelo de
            propietarios y proveedores.
          </p>
          <UButton
            class="mt-5"
            icon="i-lucide-users-round"
            label="Gestionar clientes"
            @click="router.visit('/clientes')"
          />
        </section>
      </div>
    </template>
  </UDashboardPanel>
</template>
