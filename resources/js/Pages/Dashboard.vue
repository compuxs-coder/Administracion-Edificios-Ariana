<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import type { CarteraResumen } from '../types'
import { useBuildingPermissions } from '../composables/useBuildingPermissions'

defineProps<{ cartera: CarteraResumen }>()
const { canAny } = useBuildingPermissions()
const canSeeFinances = canAny('finanzas.ver')
</script>

<template>
    <UDashboardPanel id="home"
        ><template #header
            ><UDashboardNavbar title="Inicio"
                ><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template
        ><template #body
            ><div class="flex flex-col gap-6 p-4 sm:p-6">
                <section class="rounded-xl border border-default bg-default p-6">
                    <p class="text-sm font-medium text-primary">Administración de edificios</p>
                    <h1 class="mt-2 text-2xl font-semibold text-highlighted">{{ canSeeFinances ? 'Resumen financiero real' : 'Bienvenido' }}</h1>
                    <p class="mt-2 text-sm text-muted">
                        {{
                            canSeeFinances
                                ? 'Indicadores derivados de cargos, pagos y saldos a favor en los edificios autorizados.'
                                : 'Utiliza la navegación para consultar los edificios y módulos que tienes autorizados.'
                        }}
                    </p>
                </section>
                <template v-if="canSeeFinances"
                    ><section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <UCard
                            v-for="item in [
                                { label: 'Cartera pendiente', value: `$${cartera.saldoPendiente}` },
                                { label: 'Cartera vencida', value: `$${cartera.saldoVencido}` },
                                { label: 'Morosidad', value: `${cartera.morosidadPorcentaje}%` },
                                { label: 'Al día', value: cartera.departamentosAlDia },
                                { label: 'Con deuda', value: cartera.departamentosConDeuda },
                            ]"
                            :key="item.label"
                            ><p class="text-xs uppercase text-muted">{{ item.label }}</p>
                            <p class="mt-2 font-mono text-xl font-semibold text-highlighted">{{ item.value }}</p></UCard
                        >
                    </section>
                    <div><UButton icon="i-lucide-wallet-cards" label="Ver cartera" @click="router.visit('/cartera')" /></div
                ></template></div></template
    ></UDashboardPanel>
</template>
