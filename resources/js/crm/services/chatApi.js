import axios from 'axios';

export function getConversations(page = 1) {
  return axios.get('/api/chat/list', { params: { page } });
}

export function getChatFunnel() {
  return axios.get('/api/chat/funnel');
}

export function getConversationByCustomer(customerId, platform = null) {
  return axios.get(`/api/chat/conversations/by-customer/${customerId}`, {
    params: platform ? { platform } : {},
  });
}

export function getConversationTags() {
  return axios.get('/api/chat/tags');
}

export function updateConversationStage(conversationId, stage) {
  return axios.patch(`/api/chat/conversations/${conversationId}/stage`, { stage });
}

export function updateConversationTags(conversationId, tagIds) {
  return axios.patch(`/api/chat/conversations/${conversationId}/tags`, { tag_ids: tagIds });
}

export function getMessages(customerId, platform = null) {
  return axios.get(`/api/chat/${customerId}/messages`, {
    params: platform ? { platform } : {},
  });
}

export function sendMessage(payload) {
  if (payload instanceof FormData) {
    return axios.post('/api/chat/send', payload, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
  }
  return axios.post('/api/chat/send', payload);
}

export function markRead(customerId, platform = null) {
  return axios.post('/api/chat/mark-read', {
    customer_id: customerId,
    ...(platform ? { platform } : {}),
  });
}

export function forceSync(customerId, platform = null) {
  return axios.post(`/api/chat/${customerId}/sync`, {}, {
    params: platform ? { platform } : {},
  });
}

export function fetchNewMessages(threadId, sinceId, platform = null) {
  return axios
    .get(`/api/chat/threads/${threadId}/messages/updates`, {
      params: {
        since_id: sinceId,
        ...(platform ? { platform } : {}),
      },
    })
    .then((res) => res.data);
}

export function getTemplates() {
  return axios.get('/api/chat/templates');
}

export function getSavedFiles() {
  return axios.get('/api/saved-files');
}

export function uploadSavedFile(formData) {
  return axios.post('/api/saved-files', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
}

export function deleteSavedFile(id) {
  return axios.delete(`/api/saved-files/${id}`);
}
