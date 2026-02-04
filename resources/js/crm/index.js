import { createApp } from 'vue';
// import { createPinia } from 'pinia';
import OrderCreatePage from './pages/orders/OrderCreatePage.vue';
import OrderEditPage from './pages/orders/OrderEditPage.vue';
import OrderListPage from './pages/orders/OrderListPage.vue';
import ProductListPage from './pages/products/ProductListPage.vue';
import ProductFormPage from './pages/products/ProductFormPage.vue';
import ChatPage from './pages/chat/ChatPage.vue';
import ChatFunnelPage from './pages/chat/ChatFunnelPage.vue';
import GalleryPage from './pages/gallery/GalleryPage.vue';
import CustomerListPage from './pages/customers/CustomerListPage.vue';
import FinancePage from './pages/finance/FinancePage.vue';
import SettingsCategoriesPage from './pages/settings/SettingsCategoriesPage.vue';
import SettingsColorsPage from './pages/settings/SettingsColorsPage.vue';
import SettingsTagsPage from './pages/settings/SettingsTagsPage.vue';
import SettingsStatusesPage from './pages/settings/SettingsStatusesPage.vue';
import SettingsNovaPoshtaPage from './pages/settings/SettingsNovaPoshtaPage.vue';
import './styles/crm.css';
import PackingListPage from '../Pages/Packing/PackingList.vue';
import PackingWorkspacePage from '../Pages/Packing/PackingWorkspace.vue';

export function mountOrderCreate(selector = '#crm-order-create') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(OrderCreatePage, {
        initialOrderId: el.dataset.orderId || null,
    });
    app.mount(el);
    return app;
}

export function mountOrderEdit(selector = '#crm-order-edit') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(OrderEditPage, {
        initialOrderId: el.dataset.orderId || null,
    });
    app.mount(el);
    return app;
}

export function mountProductList(selector = '#crm-product-list') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(ProductListPage);
    app.mount(el);
    return app;
}

export function mountProductForm(selector = '#crm-product-form') {
    const el = document.querySelector(selector);
    if (!el) return;

    const initialProduct = el.dataset.product ? JSON.parse(el.dataset.product) : null;
    const app = createApp(ProductFormPage, { initialProduct });
    app.mount(el);
    return app;
}

export function mountOrderList(selector = '#crm-order-list') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(OrderListPage);
    app.mount(el);
    return app;
}

export function mountCustomerList(selector = '#crm-customer-list') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(CustomerListPage);
    app.mount(el);
    return app;
}

export function mountPackingList(selector = '#packing-list') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(PackingListPage);
    app.mount(el);
    return app;
}

export function mountPackingWorkspace(selector = '#packing-workspace') {
    const el = document.querySelector(selector);
    if (!el) return;

    const raw = el.dataset.order || null;
    const order = raw ? JSON.parse(raw) : null;
    const app = createApp(PackingWorkspacePage, { order });
    app.mount(el);
    return app;
}

export function mountChat(selector = '#crm-chat') {
    const el = document.querySelector(selector);
    if (!el) {
        if (window.__CHAT_DEBUG) {
            console.warn('[chat] mount target not found', selector);
        }
        return;
    }

    const app = createApp(ChatPage);
    app.mount(el);
    if (window.__CHAT_DEBUG) {
        console.info('[chat] mounted', selector);
    }
    return app;
}

export function mountChatFunnel(selector = '#crm-chat-funnel') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(ChatFunnelPage);
    app.mount(el);
    return app;
}

export function mountGallery(selector = '#crm-gallery') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(GalleryPage);
    app.mount(el);
    return app;
}

export function mountFinance(selector = '#crm-finance') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(FinancePage);
    app.mount(el);
    return app;
}

export function mountSettingsCategories(selector = '#crm-settings-categories') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(SettingsCategoriesPage);
    app.mount(el);
    return app;
}

export function mountSettingsColors(selector = '#crm-settings-colors') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(SettingsColorsPage);
    app.mount(el);
    return app;
}

export function mountSettingsTags(selector = '#crm-settings-tags') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(SettingsTagsPage);
    app.mount(el);
    return app;
}

export function mountSettingsStatuses(selector = '#crm-settings-statuses') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(SettingsStatusesPage);
    app.mount(el);
    return app;
}

export function mountSettingsNovaPoshta(selector = '#crm-settings-nova-poshta') {
    const el = document.querySelector(selector);
    if (!el) return;

    const app = createApp(SettingsNovaPoshtaPage);
    app.mount(el);
    return app;
}

// Auto-mount if element exists
function autoMount() {
    mountOrderCreate();
    mountOrderEdit();
    mountOrderList();
    mountCustomerList();
    mountProductList();
    mountProductForm();
    mountPackingList();
    mountPackingWorkspace();
    mountChat();
    mountChatFunnel();
    mountGallery();
    mountFinance();
    mountSettingsCategories();
    mountSettingsColors();
    mountSettingsTags();
    mountSettingsStatuses();
    mountSettingsNovaPoshta();
}

if (document.readyState !== 'loading') {
    autoMount();
} else {
    document.addEventListener('DOMContentLoaded', autoMount);
}
