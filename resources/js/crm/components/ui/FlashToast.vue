<script setup>
import { onMounted } from 'vue';
import Toast from './Toast.vue';
import { useToast } from '../../composables/useToast';

const props = defineProps({ notification: { type: Object, required: true } });
const { toast, showToast, closeToast, runAction } = useToast();

onMounted(() => showToast({
    ...props.notification,
    actionLabel: props.notification.type === 'error' ? 'Виправити' : '',
    onAction: () => {
        const event = new CustomEvent('crm:focus-validation-error', { cancelable: true });
        if (document.dispatchEvent(event)) document.querySelector('[aria-invalid="true"], .is-invalid')?.focus();
    },
}));
</script>

<template>
    <Toast v-bind="toast" @close="closeToast" @action="runAction" />
</template>
