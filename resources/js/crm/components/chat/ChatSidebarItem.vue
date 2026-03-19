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
  grid-template-columns: 56px minmax(0, 1fr);
  gap: 12px;
  padding: 14px 12px;
  border: 1px solid transparent;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.72);
  text-align: left;
  transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
}

.chat-item:hover {
  transform: translateY(-1px);
  border-color: rgba(148, 163, 184, 0.18);
  background: rgba(255, 255, 255, 0.94);
  box-shadow: 0 14px 28px -24px rgba(15, 23, 42, 0.48);
}

.chat-item.is-active {
  border-color: rgba(14, 165, 233, 0.24);
  background:
    radial-gradient(circle at top left, rgba(14, 165, 233, 0.12), transparent 38%),
    linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.94));
  box-shadow: 0 20px 36px -30px rgba(14, 165, 233, 0.48);
}

.avatar-shell {
  position: relative;
  width: 56px;
  height: 56px;
}

.avatar-img,
.avatar-fallback {
  width: 56px;
  height: 56px;
  border-radius: 18px;
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
  right: -4px;
  bottom: -4px;
  width: 22px;
  height: 22px;
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
  font-weight: 800;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.name-stack p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.meta-stack {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
  flex-shrink: 0;
}

.stage-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 5px 10px;
  border-radius: 999px;
  background: rgba(14, 165, 233, 0.12);
  color: #0369a1;
  font-size: 11px;
  font-weight: 800;
}

.time-label {
  color: #94a3b8;
  font-size: 12px;
  font-weight: 700;
}

.preview-text {
  margin: 0;
  min-width: 0;
  color: #334155;
  font-size: 13px;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
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
