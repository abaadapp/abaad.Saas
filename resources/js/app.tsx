import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'Abad POS';

// كل صفحات Inertia تُحمّل كسولًا — لا تدخل الحزمة الأولى إلا عند زيارتها
const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    resolve: async (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`صفحة Inertia غير موجودة: ./Pages/${name}.tsx`);
        }

        return (await page()).default;
    },

    setup({ el, App, props }) {
        if (el) {
            createRoot(el).render(<App {...props} />);
        }
    },

    progress: {
        color: '#7c3aed',
        showSpinner: false,
    },
});
