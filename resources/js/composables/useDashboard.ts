import { ref } from 'vue'
import { createSharedComposable } from '@vueuse/core'

const _useDashboard = () => {
  const isNotificationsSlideoverOpen = ref(false)

  // Keyboard shortcuts - simplified for non-Nuxt environment
  // You can add keyboard shortcuts using @vueuse/core's useMagicKeys if needed

  return {
    isNotificationsSlideoverOpen
  }
}

export const useDashboard = createSharedComposable(_useDashboard)
