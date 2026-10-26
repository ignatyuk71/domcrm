<script setup>
import Toast from './Toast.vue';
import { useToast } from '../../composables/useToast';

const { toast, showToast, closeToast, runAction } = useToast();
const errors = new Map();

function showErrors() {
    const notifications = [...errors.values()];
    const latest = notifications.at(-1);
    showToast({ ...latest, duration: 0, messages: notifications.flatMap(notification => notification.messages) });
}

function showNotification(notification) {
    // Кожна помилка живе до виправлення саме цієї операції або явного закриття.
    if (notification.type === 'error') errors.set(notification.context, notification);
    else errors.delete(notification.context);
    if (errors.size) showErrors();
    else showToast(notification);
}

function dismiss() {
    errors.clear();
    closeToast();
}

defineExpose({ showNotification });
</script>

<template>
    <Toast v-bind="toast" @close="dismiss" @action="runAction" />
</template>
