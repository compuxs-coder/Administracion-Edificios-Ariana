<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ConceptoForm from '../../components/conceptos/ConceptoForm.vue'
import type { ConceptoCobro, ConceptoCobroFormData } from '../../types'

const props = defineProps<{ concepto: ConceptoCobro }>()
const loading = ref(false)
const errors = ref<Record<string, string>>({})
const submit = (data: ConceptoCobroFormData) => router.put(route('conceptos.update', [props.concepto.edificioId, props.concepto.id]), data, { onStart: () => { loading.value = true }, onError: responseErrors => { errors.value = responseErrors }, onFinish: () => { loading.value = false } })
</script>

<template>
  <UDashboardPanel id="conceptos-edit"><template #header><UDashboardNavbar :title="`Editar · ${concepto.codigo}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template><template #body><div class="mx-auto w-full max-w-3xl p-4 sm:p-6"><ConceptoForm :initial="{ edificio_id: concepto.edificioId, codigo: concepto.codigo, nombre: concepto.nombre, descripcion: concepto.descripcion ?? '', tipo: concepto.tipo, periodicidad: concepto.periodicidad, forma_calculo: concepto.formaCalculo, estado: concepto.estado }" :errors="errors" :loading="loading" submit-label="Guardar cambios" @submit="submit" @cancel="router.visit(route('conceptos.show', [concepto.edificioId, concepto.id]))" /></div></template></UDashboardPanel>
</template>
