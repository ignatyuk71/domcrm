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
