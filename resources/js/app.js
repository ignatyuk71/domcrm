import './bootstrap';
import * as bootstrap from 'bootstrap';

// Стилі
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';

// Робимо Bootstrap API доступним для inline-скриптів у Blade.
window.bootstrap = bootstrap;

const teamPage = document.querySelector('[data-team-page]');
if (teamPage) {
    import('./team').then(({ initTeamPage }) => initTeamPage(teamPage, bootstrap));
}

// Blade-форми використовують спільний toast; помилки залишаються до дії користувача.
const flashToast = document.querySelector('[data-flash-toast]');
if (flashToast) {
    Promise.all([import('vue'), import('./crm/components/ui/FlashToast.vue')]).then(([{ createApp }, { default: FlashToast }]) => {
        createApp(FlashToast, { notification: JSON.parse(flashToast.dataset.notification) }).mount(flashToast);
    });
}
