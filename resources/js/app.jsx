import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import axios from 'axios';
import { createRoot } from 'react-dom/client';

// Our own JSON calls (scanner, roster import, person search) are XHR: Laravel answers them in JSON.
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

createInertiaApp({
    title: (title) => (title ? `${title} · Attendance` : 'Attendance'),
    // Lazy: phones on mobile data only download the scanner, never the dashboard's charts.
    resolve: (name) => import.meta.glob('./Pages/**/*.jsx')[`./Pages/${name}.jsx`](),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    defaults: {
        // The Laravel adapter (v3) embeds the first page in <script data-page type="application/json">.
        future: { useScriptElementForInitialPage: true },
    },
    progress: { color: '#1f3864' },
});
