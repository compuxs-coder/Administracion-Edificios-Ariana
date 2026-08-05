import { usePage } from '@inertiajs/vue3'
import { watch } from 'vue'
import { useToast } from './nuxt-compat'

export function useFlash() {
  const page = usePage()
  const toast = useToast()

  watch(
    () => page.props.flash,
    (flash: any) => {
      if (!flash) return

      if (flash.success) {
        toast.add({
          title: 'Éxito',
          description: flash.success,
          color: 'green'
        })
      }

      if (flash.error) {
        toast.add({
          title: 'Error',
          description: flash.error,
          color: 'red'
        })
      }

      if (flash.info) {
        toast.add({
          title: 'Información',
          description: flash.info,
          color: 'blue'
        })
      }

      if (flash.warning) {
        toast.add({
          title: 'Advertencia',
          description: flash.warning,
          color: 'yellow'
        })
      }
    },
    { deep: true, immediate: true }
  )
}
