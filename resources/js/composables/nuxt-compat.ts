/**
 * Compatibility layer for Nuxt composables in Laravel/Inertia environment
 */
import { ref, computed } from 'vue'
import { router as inertiaRouter, usePage } from '@inertiajs/vue3'
import { useToast as useVueToast } from 'vue-toastification'

/**
 * useRoute - Returns current page URL
 */
export function useRoute() {
  const page = usePage()

  return computed(() => ({
    path: page.url,
    fullPath: page.url,
    params: {},
    query: {}
  }))
}

/**
 * useRouter - Navigation wrapper for Inertia
 */
export function useRouter() {
  return {
    push: (url: string) => inertiaRouter.visit(url),
    replace: (url: string) => inertiaRouter.visit(url, { replace: true }),
    back: () => window.history.back()
  }
}

/**
 * useToast - Toast notifications
 */
export function useToast() {
  const toast = useVueToast()

  return {
    add: (options: { title: string; description?: string; color?: string; duration?: number }) => {
      const type = options.color === 'error' || options.color === 'red' ? 'error' : 'success'
      toast[type](options.description || options.title, {
        timeout: options.duration || 5000
      })
    }
  }
}

/**
 * useFetch - Simple data fetching (returns mock data for now)
 * Replace with actual API calls as needed
 */
export async function useFetch<T>(url: string, options?: { lazy?: boolean }) {
  const data = ref<T | null>(null)
  const status = ref<'idle' | 'pending' | 'success' | 'error'>('idle')
  const error = ref(null)

  // Mock data - replace with actual API calls
  // For now, return empty array to prevent errors
  if (options?.lazy) {
    status.value = 'success'
    data.value = [] as T
  }

  return {
    data,
    status,
    error,
    refresh: async () => {
      // Implement actual API call here
    }
  }
}

/**
 * useTemplateRef - Template ref helper
 */
export function useTemplateRef<T = any>(key: string) {
  return ref<T>()
}

/**
 * useCookie - Cookie management
 */
export function useCookie(name: string) {
  const getCookie = () => {
    const value = `; ${document.cookie}`
    const parts = value.split(`; ${name}=`)
    if (parts.length === 2) return parts.pop()?.split(';').shift()
    return undefined
  }

  const setCookie = (newValue: string) => {
    document.cookie = `${name}=${newValue}; path=/; max-age=31536000`
  }

  const cookieValue = ref(getCookie())

  return computed({
    get: () => cookieValue.value,
    set: (newValue: string) => {
      cookieValue.value = newValue
      setCookie(newValue)
    }
  })
}

/**
 * defineShortcuts - Keyboard shortcuts (placeholder)
 */
export function defineShortcuts(shortcuts: Record<string, () => void>) {
  // Placeholder - implement with @vueuse/core if needed
  console.log('Keyboard shortcuts:', shortcuts)
}

/**
 * resolveComponent - Component resolution helper
 */
export function resolveComponent(name: string) {
  // This is handled by Vue's built-in resolveComponent
  // Just return the name for now
  return name
}
