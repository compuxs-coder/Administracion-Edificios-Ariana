import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

interface AccessProps {
  auth?: {
    access?: {
      byBuilding?: Record<string, string[]>
      any?: string[]
    }
  }
}

export const useBuildingPermissions = () => {
  const page = usePage<AccessProps>()
  const byBuilding = computed(() => page.props.auth?.access?.byBuilding ?? {})
  const any = computed(() => page.props.auth?.access?.any ?? [])

  return {
    can: (permission: string, buildingId: string) => byBuilding.value[buildingId]?.includes(permission) ?? false,
    canAny: (permission: string) => any.value.includes(permission)
  }
}
