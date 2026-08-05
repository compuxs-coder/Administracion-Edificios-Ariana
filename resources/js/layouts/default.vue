<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import type { NavigationMenuItem } from '@nuxt/ui'
import TeamsMenu from '../components/TeamsMenu.vue'
import UserMenu from '../components/UserMenu.vue'
import { useAppConfig } from '../composables/useAppConfig'
import { useFlash } from '../composables/useFlash'

const open = ref(false)
const appConfig = useAppConfig()

onMounted(() => {
  console.log('Layout mounted with colors:', appConfig.value.ui.colors)
  // Initialize flash messages
  useFlash()
})

const navigateTo = (url: string) => {
  router.visit(url)
  open.value = false
}

const links = [[{
  label: 'Home',
  icon: 'i-lucide-house',
  to: '/dashboard',
  onSelect: () => navigateTo('/dashboard')
}, {
  label: 'Clientes',
  icon: 'i-lucide-users-round',
  to: '/clientes',
  onSelect: () => navigateTo('/clientes')
}, {
  label: 'Facturas',
  icon: 'i-lucide-file-text',
  to: '/facturas',
  onSelect: () => navigateTo('/facturas')
}]] satisfies NavigationMenuItem[][]

const groups = computed(() => [{
  id: 'links',
  label: 'Go to',
  items: links.flat()
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
          <TeamsMenu :collapsed="collapsed" />
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
