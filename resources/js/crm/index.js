import { createApp } from 'vue';
// import { createPinia } from 'pinia';
import OrderCreatePage from './pages/orders/OrderCreatePage.vue';
import OrderEditPage from './pages/orders/OrderEditPage.vue';
import OrderListPage from './pages/orders/OrderListPage.vue';
import ProductListPage from './pages/products/ProductListPage.vue';
import ProductFormPage from './pages/products/ProductFormPage.vue';
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

// Auto-mount if element exists
function autoMount() {
    mountOrderCreate();
    mountOrderEdit();
    mountOrderList();
    mountProductList();
    mountProductForm();
    mountPackingList();
    mountPackingWorkspace();
}

if (document.readyState !== 'loading') {
    autoMount();
} else {
    document.addEventListener('DOMContentLoaded', autoMount);
}
