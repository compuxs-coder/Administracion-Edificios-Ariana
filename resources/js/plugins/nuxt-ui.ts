/**
 * Nuxt UI Plugin for Non-Nuxt Environment
 * Initializes Nuxt UI with proper color mode management
 */
import type { App } from 'vue'
import { updateCSSVariables } from '../composables/useAppConfig'

export default {
  install(app: App) {
    // Set color mode on initialization
    if (typeof window !== 'undefined') {
      // Listen for class changes on <html> element
      // useColorMode() from @vueuse/core handles adding/removing .dark class automatically
      const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
            // Re-apply CSS variables when .dark class is toggled
            const primary = localStorage.getItem('nuxt-ui-primary-color') || 'green'
            const neutral = localStorage.getItem('nuxt-ui-neutral-color') || 'zinc'
            updateCSSVariables(primary, neutral)
          }
        })
      })

      observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class']
      })
    }
  }
}
