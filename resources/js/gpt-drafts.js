import { createApp } from 'vue';
import GptDraftsPage from './crm/pages/settings/GptDraftsPage.vue';

// Окрема точка входу не змінює ресурси робочих сторінок CRM.
const root = document.getElementById('crm-settings-gpt');
if (root) createApp(GptDraftsPage).mount(root);
