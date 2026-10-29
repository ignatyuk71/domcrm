import { createApp } from 'vue';
import SandboxPage from './SandboxPage.vue';

const root = document.getElementById('gpt-sandbox-root');
if (root) createApp(SandboxPage).mount(root);
