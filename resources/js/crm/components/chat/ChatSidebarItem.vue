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
        <h4>{{ item.customer_name || 'Невідомий клієнт' }}</h4>
        <span class="time-label">{{ formattedTime }}</span>
      </div>

      <div class="content-bottom">
        <div class="preview-stack">
          <span v-if="originLabel" class="origin-chip" :class="originClass">{{ originLabel }}</span>
          <p class="preview-text">{{ previewText }}</p>
        </div>
        <div class="meta-right">
          <span v-if="stageLabel" class="stage-chip" :class="stageClass">
            {{ stageLabel }}
          </span>
          <span v-if="item.unread_count > 0" class="unread-pill">
            {{ item.unread_count > 99 ? '99+' : item.unread_count }}
          </span>
        </div>
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
  waiting_reply: 'Чекаємо',
  order_confirmed: 'Підтв.',
  done: 'Виконано',
  closed: 'Закрито',
};

const stageLabel = computed(() => stageMap[props.item.stage] || '');
const stageClass = computed(() => (
  props.item.stage ? `stage-${props.item.stage}` : ''
));

const originContext = computed(() => props.item.origin_context || null);
const originLabel = computed(() => {
  if (!originContext.value) {
    return '';
  }

  return originContext.value.object_type === 'ad'
    ? 'Реклама'
    : originContext.value.object_type === 'story'
      ? 'Сторіс'
      : originContext.value.object_type === 'reel'
        ? 'Reels'
        : 'Пост';
});
const originClass = computed(() => (
  originContext.value ? `origin-${originContext.value.object_type || 'comment'}` : ''
));

const previewText = computed(() => {
  if (originContext.value?.summary && (!props.item.last_message || props.item.last_message === originContext.value.summary)) {
    return originContext.value.summary;
  }

  return props.item.last_message || 'Вкладення';
});

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
  grid-template-columns: 48px minmax(0, 1fr);
  gap: 12px;
  padding: 10px 12px;
  border: none;
  border-radius: 0;
  background: #fff;
  text-align: left;
  border-bottom: 1px solid #e5e7eb;
  transition: background 0.18s ease, box-shadow 0.18s ease;
}

.chat-item:hover {
  background: #f7f8fa;
}

.chat-item.is-active {
  background: #f3f4f6;
  box-shadow: inset 3px 0 0 #1877f2;
}

.avatar-shell {
  position: relative;
  width: 48px;
  height: 48px;
}

.avatar-img,
.avatar-fallback {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 16px;
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
  width: 18px;
  height: 18px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
  color: #fff;
}

.platform-badge.is-messenger {
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
}

.platform-badge.is-instagram {
  background: linear-gradient(135deg, #e11d48, #f97316 60%, #9333ea);
}

.platform-badge i {
  font-size: 9px;
}

.content-shell {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.content-top,
.content-bottom {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}

.preview-stack {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.content-top h4 {
  margin: 0;
  color: #0f172a;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.15;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}

.time-label {
  color: #637381;
  font-size: 12px;
  font-weight: 500;
  flex-shrink: 0;
}

.preview-text {
  margin: 0;
  min-width: 0;
  color: #5c6b7a;
  font-size: 12px;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.origin-chip {
  width: fit-content;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 18px;
  padding: 0 6px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
}

.origin-chip.origin-post {
  background: #eff6ff;
  color: #1d4ed8;
}

.origin-chip.origin-story {
  background: #fef3c7;
  color: #b45309;
}

.origin-chip.origin-ad {
  background: #dcfce7;
  color: #15803d;
}

.origin-chip.origin-reel {
  background: #f5f3ff;
  color: #7c3aed;
}

.meta-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.stage-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 20px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
}

.stage-chip.stage-new {
  background: #eaf2ff;
  color: #2563eb;
}

.stage-chip.stage-waiting_reply {
  background: #fff7ed;
  color: #c2410c;
}

.stage-chip.stage-order_confirmed {
  background: #ecfeff;
  color: #0f766e;
}

.stage-chip.stage-done {
  background: #ecfdf3;
  color: #15803d;
}

.stage-chip.stage-closed {
  background: #f3f4f6;
  color: #4b5563;
}

.unread-pill {
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #0f172a;
  color: #f8fafc;
  font-size: 10px;
  font-weight: 800;
  flex-shrink: 0;
}
</style>
