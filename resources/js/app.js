import './bootstrap';
import * as bootstrap from 'bootstrap';

// Стилі
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';

// Робимо Bootstrap API доступним для inline-скриптів у Blade.
window.bootstrap = bootstrap;
