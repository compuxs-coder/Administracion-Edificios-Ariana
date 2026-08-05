/**
 * Global app configuration composable
 * Manages primary and neutral color settings with dynamic CSS variable updates
 */
import { ref, watch } from 'vue'

interface AppColors {
  primary: string
  neutral: string
}

interface AppConfig {
  ui: {
    colors: AppColors
  }
}

// Initialize colors from localStorage or defaults
const getInitialColors = (): AppColors => {
  if (typeof window === 'undefined') {
    return { primary: 'green', neutral: 'zinc' }
  }
  return {
    primary: localStorage.getItem('nuxt-ui-primary-color') || 'green',
    neutral: localStorage.getItem('nuxt-ui-neutral-color') || 'zinc'
  }
}

// Update CSS custom properties when colors change
const updateCSSVariables = (primary: string, neutral: string) => {
  if (typeof window === 'undefined') return

  const root = document.documentElement
  const isDark = root.classList.contains('dark') ||
                 root.getAttribute('data-color-mode') === 'dark'

  // Update --ui-primary based on current theme
  const primaryShade = isDark ? '400' : '500'
  root.style.setProperty('--ui-primary', `var(--color-${primary}-${primaryShade})`)

  // Update all primary color shades for Nuxt UI components
  for (let shade = 50; shade <= 950; shade += (shade < 100 ? 50 : 100)) {
    root.style.setProperty(
      `--ui-color-primary-${shade}`,
      `var(--color-${primary}-${shade})`
    )
  }

  // Update all neutral color shades
  for (let shade = 50; shade <= 950; shade += (shade < 100 ? 50 : 100)) {
    root.style.setProperty(
      `--ui-color-neutral-${shade}`,
      `var(--color-${neutral}-${shade})`
    )
  }

  console.log(`✓ CSS variables updated: primary=${primary}-${primaryShade}, neutral=${neutral}`)
}

// Global reactive app config
const appConfig = ref<AppConfig>({
  ui: {
    colors: getInitialColors()
  }
})

// Watch for changes and save to localStorage + update CSS
watch(() => appConfig.value.ui.colors, (newColors) => {
  if (typeof window !== 'undefined') {
    localStorage.setItem('nuxt-ui-primary-color', newColors.primary)
    localStorage.setItem('nuxt-ui-neutral-color', newColors.neutral)
    updateCSSVariables(newColors.primary, newColors.neutral)
    console.log('Color theme changed to:', newColors.primary, '/', newColors.neutral)
  }
}, { deep: true })

// Initialize CSS variables on first load
if (typeof window !== 'undefined') {
  const initialColors = getInitialColors()
  updateCSSVariables(initialColors.primary, initialColors.neutral)
}

export function useAppConfig() {
  return appConfig
}

export { updateCSSVariables }
