import axios from 'axios';

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
