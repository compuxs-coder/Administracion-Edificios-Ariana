<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { CuentaTesoreriaFormData, TipoCuentaTesoreria } from '../../types'

const props = withDefaults(defineProps<{
  edificios: Array<{ id: string, nombre: string }>
  initial?: Partial<CuentaTesoreriaFormData>
  errors?: Record<string, string>
  loading?: boolean
  lockBuilding?: boolean
  lockIdentity?: boolean
  submitLabel?: string
}>(), {
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  lockBuilding: false,
  lockIdentity: false,
  submitLabel: 'Guardar cuenta'
})

const emit = defineEmits<{
  submit: [data: CuentaTesoreriaFormData]
  cancel: []
}>()

const state = reactive<CuentaTesoreriaFormData>({
  edificio_id: props.initial.edificio_id ?? (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  codigo: props.initial.codigo ?? '',
  nombre: props.initial.nombre ?? '',
  tipo: props.initial.tipo ?? 'bancaria',
  entidad_financiera: props.initial.entidad_financiera ?? '',
  tipo_cuenta_bancaria: props.initial.tipo_cuenta_bancaria ?? '',
  numero_cuenta: props.initial.numero_cuenta ?? ''
})

const isBank = computed(() => state.tipo === 'bancaria')
const typeItems: Array<{ label: string, value: TipoCuentaTesoreria }> = [
  { label: 'Cuenta bancaria', value: 'bancaria' },
  { label: 'Caja', value: 'caja' }
]

watch(() => state.tipo, tipo => {
  if (tipo !== 'caja') return
  state.entidad_financiera = ''
  state.tipo_cuenta_bancaria = ''
  state.numero_cuenta = ''
})
</script>

<template>
  <form class="space-y-7" @submit.prevent="emit('submit', { ...state })">
    <UAlert v-if="errors.default || errors.cuenta" color="error" :description="errors.default || errors.cuenta" icon="i-lucide-circle-alert" variant="subtle" />
    <section class="space-y-4">
      <div>
        <p class="text-sm font-semibold text-highlighted">Identificacion de la cuenta</p>
        <p class="mt-1 text-xs text-muted">La cuenta pertenece a un edificio y su codigo debe ser unico dentro de ese edificio.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
          <USelect v-model="state.edificio_id" class="w-full" :disabled="lockBuilding" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" />
        </UFormField>
        <UFormField label="Tipo" name="tipo" required :error="errors.tipo">
          <USelect v-model="state.tipo" class="w-full" :disabled="lockIdentity" :items="typeItems" required size="xl" />
        </UFormField>
        <UFormField label="Codigo" name="codigo" required :error="errors.codigo">
          <UInput v-model="state.codigo" class="w-full" maxlength="30" placeholder="BANCO-01" required size="xl" />
        </UFormField>
        <UFormField label="Nombre" name="nombre" required :error="errors.nombre">
          <UInput v-model="state.nombre" class="w-full" maxlength="120" placeholder="Cuenta operativa" required size="xl" />
        </UFormField>
      </div>
    </section>

    <section v-if="isBank" class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Datos bancarios</p>
        <p class="mt-1 text-xs text-muted">Estos campos son obligatorios para cuentas bancarias y no aplican a cajas.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Entidad financiera" name="entidad_financiera" required :error="errors.entidadFinanciera">
          <UInput v-model="state.entidad_financiera" class="w-full" :disabled="lockIdentity" maxlength="120" required size="xl" />
        </UFormField>
        <UFormField label="Tipo de cuenta bancaria" name="tipo_cuenta_bancaria" required :error="errors.tipoCuentaBancaria">
          <UInput v-model="state.tipo_cuenta_bancaria" class="w-full" :disabled="lockIdentity" maxlength="60" placeholder="Ahorros o corriente" required size="xl" />
        </UFormField>
        <UFormField class="md:col-span-2" label="Numero de cuenta" name="numero_cuenta" required :error="errors.numeroCuenta">
          <UInput v-model="state.numero_cuenta" class="w-full" :disabled="lockIdentity" autocomplete="off" maxlength="120" required size="xl" />
        </UFormField>
      </div>
    </section>

    <UAlert v-else color="info" description="Las cajas registran efectivo y no almacenan datos bancarios." icon="i-lucide-info" variant="subtle" />

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id" />
    </div>
  </form>
</template>
