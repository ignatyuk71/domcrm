<template>
  <form class="chat-composer" @submit.prevent="handleSend">
    <textarea
      v-model="draft"
      rows="1"
      placeholder="Напишіть повідомлення..."
    ></textarea>
    <button type="submit" :disabled="!draft.trim() || sending">
      Надіслати
    </button>
  </form>
</template>

<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
  sending: { type: Boolean, default: false },
});

const emit = defineEmits(['send']);
const draft = ref('');

function handleSend() {
  const text = draft.value.trim();
  if (!text) return;
  emit('send', text);
  draft.value = '';
}

watch(
  () => props.sending,
  (val) => {
    if (val) return;
  }
);
</script>
