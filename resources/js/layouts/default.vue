<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import type { NavigationMenuItem } from '@nuxt/ui'
import UserMenu from '../components/UserMenu.vue'
import { useAppConfig } from '../composables/useAppConfig'
import { useFlash } from '../composables/useFlash'

const open = ref(false)
const appConfig = useAppConfig()

onMounted(() => {
  useFlash()
})

const navigateTo = (url: string) => {
  router.visit(url)
  open.value = false
}

const links = [[{
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
  onSelect: () => navigateTo('/departamentos')
}, {
  label: 'Clientes',
  icon: 'i-lucide-users-round',
  to: '/clientes',
  onSelect: () => navigateTo('/clientes')
}]] satisfies NavigationMenuItem[][]

const groups = computed(() => [{
  id: 'links',
  label: 'Ir a',
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
