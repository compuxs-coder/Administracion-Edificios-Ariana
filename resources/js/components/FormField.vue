<script setup lang="ts">
import { watchEffect } from 'vue'

interface Props {
  label?: string
  name: string
  error?: string
  required?: boolean
  hint?: string
}

const props = defineProps<Props>()

watchEffect(() => {
  if (props.error) {
    console.log(`FormField ${props.name} tiene error:`, props.error)
  }
})
</script>

<template>
  <div class="space-y-2">
    <label v-if="label" :for="name" class="block text-sm font-medium text-foreground">
      {{ label }}
      <span v-if="required" style="color: #dc2626;">*</span>
    </label>

    <slot />

    <p v-if="hint && !error" class="text-sm text-muted">
      {{ hint }}
    </p>

    <p v-if="error" class="text-sm font-medium" style="color: #dc2626; margin-top: 0.25rem;">
      {{ error }}
    </p>
  </div>
</template>
