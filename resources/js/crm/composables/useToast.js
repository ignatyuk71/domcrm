import { onBeforeUnmount, reactive } from 'vue';

// Одна подія оновлює toast, а не створює чергу однакових повідомлень.
export function useToast() {
    const toast = reactive({ show: false, title: '', messages: [], type: 'info', actionLabel: '', secondaryLabel: '' });
    let timer, action, secondary;
    function closeToast() { clearTimeout(timer); toast.show = false; action = null; secondary = null; }
    function showToast({ title, messages, type = 'info', duration = type === 'success' ? 3500 : 0,
        actionLabel = '', onAction = null, secondaryLabel = '', onSecondary = null }) {
        clearTimeout(timer);
        Object.assign(toast, { show: true, title, messages, type, actionLabel, secondaryLabel });
        action = onAction; secondary = onSecondary;
        if (duration > 0) timer = setTimeout(closeToast, duration);
    }
    const runAction = () => { const callback = action; closeToast(); return callback?.(); };
    const runSecondary = () => { const callback = secondary; closeToast(); return callback?.(); };
    onBeforeUnmount(() => clearTimeout(timer));
    return { toast, showToast, closeToast, runAction, runSecondary };
}
