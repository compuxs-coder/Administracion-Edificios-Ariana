import { ref, onMounted, watch } from 'vue';

export function useDarkMode() {
    const isDark = ref(false);

    const toggleDarkMode = () => {
        isDark.value = !isDark.value;
        updateDOM();
        localStorage.setItem('darkMode', isDark.value);
    };

    const updateDOM = () => {
        if (isDark.value) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    };

    onMounted(() => {
        // Leer preferencia guardada o usar preferencia del sistema
        const savedMode = localStorage.getItem('darkMode');
        if (savedMode !== null) {
            isDark.value = savedMode === 'true';
        } else {
            isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        updateDOM();
    });

    return {
        isDark,
        toggleDarkMode,
    };
}
