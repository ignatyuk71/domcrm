import axios from 'axios';

export function getConversations() {
  return axios.get('/api/chat/list');
}

export function getMessages(customerId) {
  return axios.get(`/api/chat/${customerId}/messages`);
}

export function sendMessage(payload) {
  return axios.post('/api/chat/send', payload);
}

export function markRead(customerId) {
  return axios.post('/api/chat/mark-read', { customer_id: customerId });
}

export function forceSync(customerId) {
  return axios.post(`/api/chat/${customerId}/sync`);
}

export function refreshProfile(customerId) {
  return axios.post(`/api/chat/customers/${customerId}/refresh-profile`);
}

export function fetchNewMessages(threadId, sinceId) {
  return axios
    .get(`/api/chat/threads/${threadId}/messages/updates`, {
      params: { since_id: sinceId },
    })
    .then((res) => res.data);
}
