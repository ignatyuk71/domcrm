<template>
  <button
    type="button"
    class="chat-item"
    :class="{ 'is-active': isActive, 'is-unread': item.unread_count > 0 }"
    @click="$emit('select', item)"
  >
    <div class="avatar-shell">
      <img
        v-if="safeAvatarUrl"
        :src="safeAvatarUrl"
        class="avatar-img"
        alt="Клієнт"
        @error="avatarFailed = true"
      >
      <div v-else class="avatar-fallback" :class="platformClass">
        {{ displayInitial }}
      </div>

      <span class="platform-badge" :class="platformClass">
        <i :class="platformIcon"></i>
      </span>
    </div>

    <div class="content-shell">
      <div class="content-top">
        <div class="name-stack">
          <h4>{{ item.customer_name || 'Невідомий клієнт' }}</h4>
          <p v-if="subtitle">{{ subtitle }}</p>
        </div>

        <div class="meta-stack">
          <span v-if="stageLabel" class="stage-chip">
            {{ stageLabel }}
          </span>
          <span class="time-label">{{ formattedTime }}</span>
        </div>
      </div>

      <div class="content-bottom">
        <p class="preview-text">{{ previewText }}</p>
        <span v-if="item.unread_count > 0" class="unread-pill">
          {{ item.unread_count > 99 ? '99+' : item.unread_count }}
        </span>
      </div>
    </div>
  </button>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  item: { type: Object, required: true },
  isActive: { type: Boolean, default: false },
});

defineEmits(['select']);

const avatarFailed = ref(false);

const safeAvatarUrl = computed(() => {
  if (avatarFailed.value) {
    return '';
  }

  return props.item.customer_avatar || props.item.fb_profile_pic || '';
});

const displayInitial = computed(() => (props.item.customer_name || '?').charAt(0).toUpperCase());

const platformClass = computed(() => (
  props.item.platform === 'instagram' ? 'is-instagram' : 'is-messenger'
));

const platformIcon = computed(() => (
  props.item.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'
));

const stageMap = {
  new: 'Новий',
  waiting_reply: 'Чекаємо відповідь',
  order_confirmed: 'Замовлення підтверджене',
  done: 'Виконано',
  closed: 'Закрито',
};

const stageLabel = computed(() => stageMap[props.item.stage] || '');

const subtitle = computed(() => {
  if (props.item.external_username) {
    return `@${String(props.item.external_username).replace(/^@/, '')}`;
  }

  return props.item.platform === 'instagram' ? 'Instagram Direct' : 'Messenger';
});

const previewText = computed(() => props.item.last_message || 'Вкладення');

const formattedTime = computed(() => {
  if (!props.item.last_message_time) {
    return '';
  }

  const date = new Date(props.item.last_message_time);
  const now = new Date();
  const sameDay = date.toDateString() === now.toDateString();

  if (sameDay) {
    return date.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
  }

  return date.toLocaleDateString('uk-UA', { day: '2-digit', month: 'short' });
});

watch(
  () => [props.item.customer_avatar, props.item.fb_profile_pic],
  () => {
    avatarFailed.value = false;
  }
);
</script>

<style scoped>
.chat-item {
  width: 100%;
  display: grid;
  grid-template-columns: 52px minmax(0, 1fr);
  gap: 12px;
  padding: 14px 16px;
  border: none;
  border-radius: 0;
  background: #fff;
  text-align: left;
  border-bottom: 1px solid #f3f4f6;
  transition: background 0.18s ease;
}

.chat-item:hover {
  background: #f9fafb;
}

.chat-item.is-active {
  background: #f3f4f6;
  box-shadow: inset 3px 0 0 #6366f1;
}

.avatar-shell {
  position: relative;
  width: 52px;
  height: 52px;
}

.avatar-img,
.avatar-fallback {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 18px;
  font-weight: 800;
}

.avatar-fallback.is-messenger {
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
}

.avatar-fallback.is-instagram {
  background: linear-gradient(135deg, #fb7185, #f97316 55%, #9333ea);
}

.platform-badge {
  position: absolute;
  right: -2px;
  bottom: -2px;
  width: 20px;
  height: 20px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
  color: #fff;
  box-shadow: 0 10px 18px -14px rgba(15, 23, 42, 0.7);
}

.platform-badge.is-messenger {
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
}

.platform-badge.is-instagram {
  background: linear-gradient(135deg, #e11d48, #f97316 60%, #9333ea);
}

.platform-badge i {
  font-size: 10px;
}

.content-shell {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.content-top,
.content-bottom {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.name-stack {
  min-width: 0;
}

.name-stack h4 {
  margin: 0;
  color: #0f172a;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.name-stack p {
  margin: 4px 0 0;
  color: #4b5563;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.meta-stack {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  flex-shrink: 0;
}

.stage-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 3px 10px;
  border-radius: 999px;
  background: #eef2ff;
  color: #4f46e5;
  font-size: 12px;
  font-weight: 700;
}

.time-label {
  color: #6b7280;
  font-size: 13px;
  font-weight: 500;
}

.preview-text {
  margin: 0;
  min-width: 0;
  color: #64748b;
  font-size: 14px;
  line-height: 1.45;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.unread-pill {
  min-width: 24px;
  height: 24px;
  padding: 0 8px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #0f172a;
  color: #f8fafc;
  font-size: 11px;
  font-weight: 800;
  flex-shrink: 0;
}
</style>
