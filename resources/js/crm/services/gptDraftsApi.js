import axios from 'axios';

const base = '/settings/gpt';
const client = axios.create({
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },
});
export const gptDraftsApi = {
    data: () => client.get(`${base}/data`).then(r => r.data),
    saveSettings: payload => client.put(`${base}/settings`, payload).then(r => r.data),
    create: payload => client.post(`${base}/drafts`, payload).then(r => r.data),
    upload: (files, onProgress) => {
        const form = new FormData();
        files.forEach(file => form.append('files[]', file));
        return client.post(`${base}/attachments`, form, {
            onUploadProgress: event => onProgress?.(event.total ? Math.round(event.loaded * 100 / event.total) : 0),
        }).then(r => r.data);
    },
    run: id => client.get(`${base}/drafts/${id}`).then(r => r.data),
    dialogue: id => client.get(`${base}/dialogues/${id}`).then(r => r.data),
};
