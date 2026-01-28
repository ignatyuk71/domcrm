import http from './http';

export function listOrders(params = {}) {
    return http.get('/orders/list', { params });
}

export function deleteOrder(id) {
    return http.delete(`/orders/${id}`);
}

export function updateOrderTags(id, tagIds = []) {
    return http.patch(`/orders/${id}/tags`, { tag_ids: tagIds });
}

export function updateOrderStatus(id, statusId) {
    return http.patch(`/orders/${id}/status`, { status_id: statusId });
}

export function updateOrderComment(id, commentInternal) {
    return http.patch(`/orders/${id}/comment`, { comment_internal: commentInternal });
}

export function createOrder(payload) {
    return http.post('/orders', payload);
}

export function getOrder(id) {
    return http.get(`/orders/${id}`);
}

export function updateOrder(id, payload) {
    return http.put(`/orders/${id}`, payload);
}

// Backward-compat alias
export const fetchOrders = listOrders;
