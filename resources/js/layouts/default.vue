<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import type { NavigationMenuItem } from '@nuxt/ui'
import UserMenu from '../components/UserMenu.vue'
import { useAppConfig } from '../composables/useAppConfig'
import { useFlash } from '../composables/useFlash'
import { useBuildingPermissions } from '../composables/useBuildingPermissions'

const open = ref(false)
const appConfig = useAppConfig()
const { canAny } = useBuildingPermissions()

onMounted(() => {
  useFlash()
})

const navigateTo = (url: string) => {
  router.visit(url)
  open.value = false
}

const links = computed<NavigationMenuItem[][]>(() => [[{
  label: 'Inicio',
  icon: 'i-lucide-house',
  to: '/dashboard',
  onSelect: () => navigateTo('/dashboard')
}, {
  label: 'Edificios',
  icon: 'i-lucide-building-2',
  to: '/edificios',
  onSelect: () => navigateTo('/edificios')
}, {
  label: 'Departamentos',
  icon: 'i-lucide-panels-top-left',
  to: '/departamentos',
  onSelect: () => navigateTo('/departamentos'),
  hidden: !canAny('estructura.ver')
}, {
  label: 'Propietarios',
  icon: 'i-lucide-contact-round',
  to: '/propietarios',
  onSelect: () => navigateTo('/propietarios'),
  hidden: !canAny('propiedad.ver')
}, {
  label: 'Residentes',
  icon: 'i-lucide-users',
  to: '/residentes',
  onSelect: () => navigateTo('/residentes'),
  hidden: !canAny('propiedad.ver')
}, {
  label: 'Conceptos de cobro',
  icon: 'i-lucide-circle-dollar-sign',
  to: '/conceptos',
  onSelect: () => navigateTo('/conceptos'),
  hidden: !canAny('finanzas.ver')
}, {
  label: 'Lecturas de consumo',
  icon: 'i-lucide-gauge',
  to: '/lecturas',
  onSelect: () => navigateTo('/lecturas'),
  hidden: !canAny('finanzas.ver')
}, {
  label: 'Cargos',
  icon: 'i-lucide-receipt-text',
  to: '/cargos',
  onSelect: () => navigateTo('/cargos'),
  hidden: !canAny('finanzas.ver')
}, {
  label: 'Pagos',
  icon: 'i-lucide-hand-coins',
  to: '/pagos',
  onSelect: () => navigateTo('/pagos'),
  hidden: !canAny('finanzas.ver')
}, {
  label: 'Cartera',
  icon: 'i-lucide-wallet-cards',
  to: '/cartera',
  onSelect: () => navigateTo('/cartera'),
  hidden: !canAny('finanzas.ver')
}, {
  label: 'Clientes',
  icon: 'i-lucide-users-round',
  to: '/clientes',
  onSelect: () => navigateTo('/clientes')
}].filter(item => !item.hidden).map(({ hidden: _hidden, ...item }) => item)])

const groups = computed(() => [{
  id: 'links',
  label: 'Ir a',
  items: links.value.flat()
}])
</script>

<template>
  <UApp :primary="appConfig.ui.colors.primary" :neutral="appConfig.ui.colors.neutral">
    <UDashboardGroup unit="rem">
      <UDashboardSidebar
        id="default"
        v-model:open="open"
        collapsible
        resizable
        class="bg-elevated/25"
        :ui="{ footer: 'lg:border-t lg:border-default' }"
      >
        <template #header="{ collapsed }">
          <div class="flex min-h-10 items-center gap-2 px-2 text-highlighted">
            <UIcon name="i-lucide-building-2" class="size-5 shrink-0" />
            <span v-if="!collapsed" class="truncate font-semibold">Administración Ariana</span>
          </div>
        </template>

        <template #default="{ collapsed }">
          <UNavigationMenu
            :collapsed="collapsed"
            :items="links[0]"
            orientation="vertical"
            tooltip
            popover
          />
        </template>

        <template #footer="{ collapsed }">
          <UserMenu :collapsed="collapsed" />
        </template>
      </UDashboardSidebar>

      <UDashboardSearch :groups="groups" />

      <slot />

    </UDashboardGroup>
  </UApp>
</template>
