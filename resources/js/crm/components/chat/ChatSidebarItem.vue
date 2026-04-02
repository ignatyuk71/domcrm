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
          <div v-if="originLabel" class="origin-row">
            <span class="origin-chip" :class="originClass">
              <i v-if="originIcon" class="bi" :class="originIcon"></i>
              {{ originLabel }}
            </span>
            <span class="origin-meta">{{ originMetaLine }}</span>
          </div>
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
import {
  resolveOriginBadgeClass,
  resolveOriginContext,
  resolveOriginMeta,
  resolveOriginSummaryLine,
} from '@/crm/utils/chatOrigin';

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

const originContext = computed(() => resolveOriginContext(props.item));
const originMeta = computed(() => resolveOriginMeta(originContext.value));
const originLabel = computed(() => originMeta.value.label || '');
const originIcon = computed(() => originMeta.value.icon || '');
const originClass = computed(() => resolveOriginBadgeClass(originContext.value, 'origin'));
const originMetaLine = computed(() => resolveOriginSummaryLine(originContext.value, props.item.platform));

const previewText = computed(() => {
  const lastMessage = String(props.item.last_message || '').trim();
  if (lastMessage !== '') {
    if (originMetaLine.value && lastMessage === originMetaLine.value) {
      return 'Нове повідомлення';
    }

    return lastMessage;
  }

  return originMetaLine.value || 'Вкладення';
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
  grid-template-columns: 44px minmax(0, 1fr);
  gap: 10px;
  padding: 10px 12px;
  border: none;
  border-radius: 4px;
  background: transparent;
  text-align: left;
  margin: 0 6px 2px;
  transition: background 0.18s ease, box-shadow 0.18s ease;
}

.chat-item:hover {
  background: #f8fafc;
}

.chat-item.is-active {
  background: #ffffff;
  box-shadow: inset 3px 0 0 #2563eb, 0 0 0 1px rgba(37, 99, 235, 0.08);
}

.avatar-shell {
  position: relative;
  width: 44px;
  height: 44px;
}

.avatar-img,
.avatar-fallback {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 14px;
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
  right: -3px;
  bottom: -3px;
  width: 18px;
  height: 18px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid rgba(255, 255, 255, 0.95);
  color: #fff;
  box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
}

.platform-badge.is-messenger {
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
}

.platform-badge.is-instagram {
  background: linear-gradient(135deg, #e11d48, #f97316 60%, #9333ea);
}

.platform-badge i {
  font-size: 8px;
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
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.preview-stack {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.content-top h4 {
  margin: 0;
  color: #0f172a;
  font-size: 13px;
  font-weight: 800;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}

.time-label {
  color: #94a3b8;
  font-size: 10px;
  font-weight: 700;
  flex-shrink: 0;
}

.preview-text {
  margin: 0;
  min-width: 0;
  color: #475569;
  font-size: 11px;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.origin-chip {
  width: fit-content;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  justify-content: center;
  min-height: 20px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
}

.origin-chip i {
  font-size: 9px;
}

.origin-row {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 5px;
}

.origin-meta {
  min-width: 0;
  color: #64748b;
  font-size: 10px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
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

.origin-chip.origin-comment {
  background: #eef2ff;
  color: #3730a3;
}

.meta-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  align-self: center;
}

.stage-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 20px;
  padding: 0 8px;
  border-radius: 999px;
  font-size: 9px;
  font-weight: 800;
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
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #f8fafc;
  font-size: 10px;
  font-weight: 800;
  flex-shrink: 0;
  box-shadow: none;
}

@media (max-width: 768px) {
  .chat-item {
    margin: 0 4px 2px;
    padding: 9px 10px;
  }
}
</style>
