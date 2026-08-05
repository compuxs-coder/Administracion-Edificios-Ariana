import './bootstrap';
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { Ziggy } from './ziggy';
import { createRouter, createWebHistory } from 'vue-router';
import Toast from 'vue-toastification';
import 'vue-toastification/dist/index.css';

// Importar layout de Nuxt UI Dashboard
import DefaultLayout from './layouts/default.vue';

// Importar composables de compatibilidad con Nuxt
import * as nuxtCompat from './composables/nuxt-compat';

// Importar plugin de Nuxt UI
import NuxtUIPlugin from './plugins/nuxt-ui';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Crear router vacío que sincroniza con Inertia
const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/:pathMatch(.*)*', component: { template: '<div></div>' } }
    ]
});

// Hacer disponibles los composables de Nuxt globalmente
if (typeof window !== 'undefined') {
    window.useRoute = nuxtCompat.useRoute;
    window.useRouter = nuxtCompat.useRouter;
    window.useToast = nuxtCompat.useToast;
    window.useFetch = nuxtCompat.useFetch;
    window.useTemplateRef = nuxtCompat.useTemplateRef;
    window.useCookie = nuxtCompat.useCookie;
    window.defineShortcuts = nuxtCompat.defineShortcuts;
    window.resolveComponent = nuxtCompat.resolveComponent;
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const page = resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
        // Aplicar el layout de Nuxt UI a todas las páginas, excepto si la página define layout: null
        page.then((module) => {
            if (module.default.layout === undefined) {
                module.default.layout = DefaultLayout;
            }
        });
        return page;
    },
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(router)
            .use(Toast)
            .use(NuxtUIPlugin)
            .use(ZiggyVue, Ziggy)
            .mount(el);
    },
    progress: {
        color: '#10b981',
    },
});
