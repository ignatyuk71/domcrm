import axios from 'axios';

export function getConversations() {
  return axios.get('/api/chat/list');
}

export function getMessages(customerId) {
  return axios.get(`/api/chat/${customerId}/messages`);
}

export function sendMessage(payload) {
  if (payload instanceof FormData) {
    return axios.post('/api/chat/send', payload, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  }
  return axios.post('/api/chat/send', payload);
}

export function markRead(customerId) {
  return axios.post('/api/chat/mark-read', { customer_id: customerId });
}

export function forceSync(customerId) {
  return axios.post(`/api/chat/${customerId}/sync`);
}

export function fetchNewMessages(threadId, sinceId) {
  return axios
    .get(`/api/chat/threads/${threadId}/messages/updates`, {
      params: { since_id: sinceId },
    })
    .then((res) => res.data);
}

export function getTemplates() {
  return axios.get('/api/chat/templates');
}
