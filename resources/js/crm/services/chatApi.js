import axios from 'axios';

export function getConversations(page = 1) {
  return axios.get('/api/chat/list', { params: { page } });
}

export function getMessages(customerId) {
  return axios.get(`/api/chat/${customerId}/messages`);
}

export function sendMessage(payload) {
  return axios.post('/api/chat/send', payload);
}
