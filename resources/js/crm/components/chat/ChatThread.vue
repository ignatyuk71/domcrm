<template>
  <div class="chat-thread-wrapper">
    <header class="chat-thread-header">
      <!-- ЛІВА ЧАСТИНА: Меню (моб) + Аватар + Інфо -->
      <div class="header-left-group">
        <button
          type="button"
          class="chat-thread-list-btn"
          title="Список чатів"
          @click="$emit('open-list')"
        >
          <i class="bi bi-list"></i>
        </button>

        <div class="chat-thread-user">
          <div class="chat-thread-avatar">
            <img v-if="activeChat?.customer_avatar" :src="activeChat.customer_avatar" alt="avatar" />
            <span v-else class="chat-thread-avatar-fallback">
              {{ (activeChat?.customer_name || '?').charAt(0).toUpperCase() }}
            </span>
          </div>
          
          <div class="chat-thread-meta">
            <h2 class="chat-thread-title">{{ activeChat?.customer_name || 'Чат' }}</h2>
            
            <div class="meta-row">
              <div class="chat-thread-platform" v-if="activeChat?.platform">
                <i :class="activeChat.platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger'"></i>
                <span>{{ activeChat.platform === 'instagram' ? 'Instagram' : 'Facebook' }}</span>
              </div>
              <span v-if="activeChat?.last_message_time" class="chat-time-separator">• {{ activeChat.last_message_time }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ПРАВА ЧАСТИНА: Кнопки дій (Оновити + Профіль) -->
      <div class="chat-thread-actions">
        <div class="stage-control">
          <span class="stage-label">Етап</span>
          <select
            v-model="localStage"
            class="stage-select"
            :disabled="!activeChat?.conversation_id"
            @change="commitStage"
          >
            <option v-for="option in stageOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <button
          type="button"
          class="action-btn-pill"
          :disabled="tagsLoading || !activeChat?.conversation_id"
          @click="openTags"
        >
          Теги
          <span v-if="activeChat?.tags?.length" class="tag-count">({{ activeChat.tags.length }})</span>
        </button>

        <button
          type="button"
          class="action-btn-icon"
          :class="{ 'is-syncing': isSyncing }"
          :disabled="isSyncing || loading"
          title="Оновити історію"
          @click="$emit('force-sync')"
        >
          <i class="bi bi-arrow-clockwise"></i>
        </button>

        <button
          type="button"
          class="action-btn-icon"
          title="Профіль клієнта"
          @click="$emit('open-profile')"
        >
          <i class="bi bi-person-circle"></i>
        </button>
      </div>
    </header>

    <div ref="threadBody" class="chat-thread-body">
      
      <div v-if="loading" class="chat-loading">
        <div class="spinner"></div>
        <span>Завантаження історії...</span>
      </div>

      <div v-else-if="!messages.length" class="chat-thread-empty">
        Немає повідомлень
      </div>

      <template v-else>
        <ChatMessage
          v-for="message in messages"
          :key="message.id"
          :message="message"
          :is-mine="message.direction === 'outbound'"
        />
      </template>
    </div>

    <ChatInput
      :disabled="isSending || loading"
      @send="$emit('send', { ...$event, customer_id: activeChat?.customer_id })"
    />

    <Teleport to="body">
      <div v-if="isTagsOpen">
        <div class="modal-backdrop fade show bg-dark bg-opacity-25"></div>
        <div class="modal fade show d-block" tabindex="-1" @click.self="closeTags">
          <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
              <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
                <h5 class="modal-title fw-bold text-dark fs-5">Теги чату</h5>
                <button type="button" class="btn-close shadow-none" @click="closeTags"></button>
              </div>

              <div class="modal-body px-4 py-3">
                <div v-if="tagsLoading" class="text-center py-4">
                  <div class="spinner-border text-primary" role="status"></div>
                </div>

                <div v-else class="d-flex flex-column gap-2">
                  <div
                    v-for="tag in conversationTags"
                    :key="tag.id"
                    class="tag-option"
                    :class="{ selected: tempTagIds.includes(tag.id) }"
                    @click="toggleTag(tag.id)"
                  >
                    <span class="tag-chip" :style="getTagStyle(tag.color)">
                      <i v-if="tag.icon" :class="['bi', tag.icon]"></i>
                      {{ tag.name }}
                    </span>
                    <i v-if="tempTagIds.includes(tag.id)" class="bi bi-check-lg text-primary"></i>
                  </div>

                  <div v-if="!conversationTags.length" class="text-muted small fst-italic">
                    Тегів ще немає
                  </div>
                </div>
              </div>

              <div class="modal-footer border-top-0 px-4 pb-4 pt-2">
                <div class="d-grid gap-2 w-100">
                  <button type="button" class="btn btn-primary fw-bold rounded-3" @click="saveTags">
                    Зберегти
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ChatMessage from './ChatMessage.vue';
import ChatInput from './ChatInput.vue';

const props = defineProps({
  activeChat: { type: Object, default: null },
  conversationTags: { type: Array, default: () => [] },
  tagsLoading: { type: Boolean, default: false },
  messages: { type: Array, default: () => [] },
  isSending: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  isSyncing: { type: Boolean, default: false },
});

const emit = defineEmits(['send', 'force-sync', 'open-list', 'open-profile', 'update-stage', 'update-tags']);

const threadBody = ref(null);
const isTagsOpen = ref(false);
const tempTagIds = ref([]);
const localStage = ref('');

// Узгоджені етапи для чату (UI + backend)
const stageOptions = [
  { value: '', label: 'Без етапу' },
  { value: 'new', label: 'Новий' },
  { value: 'waiting_reply', label: 'Чекаємо відповідь' },
  { value: 'order_confirmed', label: 'Замовлення підтверджене' },
  { value: 'done', label: 'Виконано' },
  { value: 'closed', label: 'Закрито' },
];

const activeTagIds = computed(() => {
  return (props.activeChat?.tags || []).map((tag) => tag.id);
});

function scrollToBottom() {
  if (!threadBody.value) return;
  threadBody.value.scrollTop = threadBody.value.scrollHeight;
}

const openTags = () => {
  tempTagIds.value = [...activeTagIds.value];
  isTagsOpen.value = true;
};

const closeTags = () => {
  isTagsOpen.value = false;
};

const toggleTag = (id) => {
  const idx = tempTagIds.value.indexOf(id);
  if (idx === -1) tempTagIds.value.push(id);
  else tempTagIds.value.splice(idx, 1);
};

const saveTags = () => {
  emit('update-tags', {
    conversationId: props.activeChat?.conversation_id,
    tagIds: [...tempTagIds.value],
  });
  closeTags();
};

const commitStage = () => {
  emit('update-stage', {
    conversationId: props.activeChat?.conversation_id,
    stage: localStage.value || null,
  });
};

const getTagStyle = (color) => {
  if (!color) return {};
  const hex = color.startsWith('#') && color.length === 4
    ? `#${color.slice(1).split('').map((c) => c + c).join('')}`
    : color;
  if (hex.startsWith('#') && hex.length === 7) {
    return {
      backgroundColor: `${hex}1a`,
      color: hex,
      borderColor: `${hex}33`,
    };
  }
  return { color: hex, borderColor: hex };
};

onMounted(() => {
  scrollToBottom();
});

watch(
  () => props.messages.length,
  async () => {
    await nextTick();
    scrollToBottom();
  }
);

watch(
  () => props.activeChat?.stage,
  (value) => {
    localStage.value = value ?? '';
  },
  { immediate: true }
);

watch(
  () => props.loading,
  async (newVal) => {
    if (!newVal) {
      await nextTick();
      scrollToBottom();
    }
  }
);
</script>

<style scoped>
.chat-thread-wrapper {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: #fff;
  position: relative;
}

/* --- HEADER --- */
.chat-thread-header {
  padding: 12px 20px;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between; /* Розносить ліву і праву групи по краях */
  gap: 8px;
  background: #ffffff;
  height: 64px;
  flex-shrink: 0;
}

/* Ліва група (Меню + Аватар + Текст) */
.header-left-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1; /* Займає весь вільний простір зліва */
  min-width: 0; /* Для обрізки тексту */
  overflow: hidden;
}

.chat-thread-user {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

.chat-thread-avatar {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  overflow: hidden;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.chat-thread-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.chat-thread-avatar-fallback {
  font-weight: 700;
  color: #64748b;
  font-size: 1rem;
}

.chat-thread-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0; /* Важливо для обрізки */
  overflow: hidden;
}

.chat-thread-title {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.meta-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.chat-thread-platform {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.chat-time-separator {
  white-space: nowrap;
  opacity: 0.7;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Кнопка списку (мобільна) */
.chat-thread-list-btn {
  display: none; /* Прихована на десктопі */
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #475569;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
}
.chat-thread-list-btn:hover { background: #f8fafc; }

/* ПРАВА ЧАСТИНА: Кнопки дій */
.chat-thread-actions {
  display: flex;
  align-items: center;
  gap: 8px; /* Відступ між кнопками */
  flex-shrink: 0;
  margin-left: auto; /* Притискає праву частину вправо */
}

.stage-control {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #fff;
}

.stage-label {
  font-size: 11px;
  color: #94a3b8;
}

.stage-select {
  border: none;
  background: transparent;
  font-size: 12px;
  color: #334155;
  outline: none;
  cursor: pointer;
}

.action-btn-pill {
  padding: 6px 10px;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}

.action-btn-pill:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

.tag-count {
  margin-left: 4px;
  font-weight: 600;
  color: #64748b;
}

.action-btn-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1px solid #e2e8f0; /* Легка рамка */
  background: #fff;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}

.action-btn-icon:hover {
  background: #f1f5f9;
  color: #3b82f6;
  border-color: #cbd5e1;
}

.action-btn-icon:active {
  transform: scale(0.96);
}

.action-btn-icon.is-syncing i {
  animation: spin 1s linear infinite;
}

.tag-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  border: 1px solid transparent;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.tag-option:hover {
  background: #f8fafc;
}

.tag-option.selected {
  background: #f0f7ff;
  border-color: #dbeafe;
}

.tag-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 600;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #64748b;
}

/* --- BODY --- */
.chat-thread-body {
  flex: 1;
  padding: 16px 20px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  background: #f8fafc; /* Трохи світліший фон для чату */
}

.chat-thread-empty {
  color: #94a3b8;
  text-align: center;
  margin-top: 40px;
  font-size: 0.9rem;
}

/* LOADING */
.chat-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  gap: 12px;
  font-size: 0.9rem;
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* --- MOBILE ADAPTATION --- */
@media (max-width: 768px) {
  .chat-thread-header {
    padding: 8px 12px; /* Зменшені відступи по краях */
    height: 56px;      /* Трохи менша висота шапки */
    gap: 8px;
  }

  .chat-thread-list-btn {
    display: inline-flex; /* Показуємо кнопку меню */
    width: 32px;
    height: 32px;
  }
  
  .header-left-group {
    gap: 8px;
  }

  .chat-thread-user {
    gap: 8px;
  }

  .chat-thread-avatar {
    width: 36px;
    height: 36px;
  }

  .chat-thread-title {
    font-size: 14px;
    max-width: 140px; /* Обмеження ширини імені на малих екранах */
  }

  .action-btn-icon {
    width: 32px;
    height: 32px;
  }

  .stage-label {
    display: none;
  }

  .stage-select {
    font-size: 11px;
  }

  /* Ховаємо час на дуже вузьких екранах */
  .chat-time-separator {
    display: none; 
  }
  @media (min-width: 360px) {
    .chat-time-separator { display: inline; }
  }
}
</style>
