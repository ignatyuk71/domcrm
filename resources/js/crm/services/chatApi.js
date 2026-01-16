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

export function fetchNewMessages(threadId, sinceId) {
  return axios
    .get(`/api/chat/threads/${threadId}/messages/updates`, {
      params: { since_id: sinceId },
    })
    .then((res) => res.data);
}
