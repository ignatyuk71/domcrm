<template>
  <section class="chat-funnel">
    <div class="funnel-toolbar">
      <div>
        <h1 class="funnel-title">Воронка чатів</h1>
        <p class="funnel-subtitle">Швидкий огляд етапів без пошуку в переписках.</p>
      </div>
      <div class="funnel-actions">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Пошук по імені або тексту..."
          class="funnel-search"
        />
        <button type="button" class="funnel-btn" :disabled="isLoading" @click="loadFunnel">
          Оновити
        </button>
        <a href="/messenger" class="funnel-link">Список чатів</a>
      </div>
    </div>

    <div v-if="isLoading" class="funnel-loading">
      <div class="spinner"></div>
      <span>Завантаження...</span>
    </div>

    <div v-else class="funnel-board">
      <div
        v-for="column in columns"
        :key="column.key"
        class="funnel-column"
        :class="{ 'is-drop-target': dragOverKey === column.key }"
        :style="{ '--stage-color': column.color, '--stage-bg': column.bg }"
        @dragover.prevent="handleDragOver(column.key)"
        @dragleave="handleDragLeave(column.key)"
        @drop.prevent="handleDrop(column.key)"
      >
        <div class="column-header">
          <span>{{ column.label }}</span>
          <span class="column-count">{{ filteredGroups[column.key].length }}</span>
        </div>

        <div class="column-body">
          <div v-if="!filteredGroups[column.key].length" class="column-empty">
            Немає чатів
          </div>

          <div
            v-for="item in filteredGroups[column.key]"
            :key="item.conversation_id || item.customer_id"
            class="funnel-card"
            :class="{ dragging: draggingId === item.conversation_id }"
            role="button"
            tabindex="0"
            draggable="true"
            @click="openChat(item)"
            @keydown.enter="openChat(item)"
            @dragstart="handleDragStart($event, item, column.key)"
            @dragend="handleDragEnd"
          >
            <div class="card-top">
              <span class="card-name">{{ item.customer_name }}</span>
              <span class="card-time">{{ formatTime(item.last_message_time) }}</span>
            </div>
            <div class="card-preview">
              {{ item.last_message || 'Вкладення' }}
            </div>
            <div class="card-meta">
              <span class="platform-pill" :class="platformClass(item.platform)">
                <i :class="platformIcon(item.platform)"></i>
              </span>
              <span v-if="item.unread_count > 0" class="unread-pill">
                {{ item.unread_count > 99 ? '99+' : item.unread_count }}
              </span>
            </div>
            <div v-if="item.tags?.length" class="card-tags">
              <span
                v-for="tag in item.tags.slice(0, 3)"
                :key="tag.id"
                class="tag-chip"
                :style="getTagStyle(tag.color)"
              >
                {{ tag.name }}
              </span>
              <span v-if="item.tags.length > 3" class="tag-chip tag-more">
                +{{ item.tags.length - 3 }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { getChatFunnel, updateConversationStage } from '@/crm/services/chatApi';

const searchQuery = ref('');
const isLoading = ref(false);
const groups = ref({});
const draggingId = ref(null);
const dragItem = ref(null);
const dragFromStage = ref(null);
const dragOverKey = ref(null);

const columns = [
  { key: 'new', label: 'Новий', color: '#4f46e5', bg: '#eef2ff' },
  { key: 'waiting_reply', label: 'Чекаємо відповідь', color: '#92400e', bg: '#fef3c7' },
  { key: 'order_confirmed', label: 'Замовлення підтверджене', color: '#1d4ed8', bg: '#dbeafe' },
  { key: 'done', label: 'Виконано', color: '#166534', bg: '#dcfce7' },
  { key: 'closed', label: 'Закрито', color: '#475569', bg: '#f1f5f9' },
];

const filteredGroups = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();
  const result = {};

  columns.forEach((column) => {
    const items = groups.value[column.key] || [];
    if (!query) {
      result[column.key] = items;
      return;
    }
    result[column.key] = items.filter((item) => {
      return (
        (item.customer_name || '').toLowerCase().includes(query) ||
        (item.last_message || '').toLowerCase().includes(query)
      );
    });
  });

  return result;
});

const loadFunnel = async () => {
  isLoading.value = true;
  try {
    const { data } = await getChatFunnel();
    groups.value = data?.data || {};
  } catch (e) {
    console.error('Не вдалося завантажити воронку', e);
  } finally {
    isLoading.value = false;
  }
};

const moveCard = (item, newStage) => {
  const fromKeys = Object.keys(groups.value);
  fromKeys.forEach((key) => {
    const list = groups.value[key] || [];
    const index = list.findIndex((entry) => entry.conversation_id === item.conversation_id);
    if (index !== -1) {
      list.splice(index, 1);
    }
  });

  if (!groups.value[newStage]) {
    groups.value[newStage] = [];
  }
  item.stage = newStage;
  groups.value[newStage].unshift(item);
};

const handleDragStart = (event, item, stageKey) => {
  if (!item.conversation_id) return;
  draggingId.value = item.conversation_id;
  dragItem.value = item;
  dragFromStage.value = stageKey;
  event.dataTransfer.effectAllowed = 'move';
};

const handleDragEnd = () => {
  draggingId.value = null;
  dragItem.value = null;
  dragFromStage.value = null;
  dragOverKey.value = null;
};

const handleDragOver = (stageKey) => {
  dragOverKey.value = stageKey;
};

const handleDragLeave = (stageKey) => {
  if (dragOverKey.value === stageKey) {
    dragOverKey.value = null;
  }
};

const handleDrop = async (stageKey) => {
  if (!dragItem.value || !dragItem.value.conversation_id) {
    handleDragEnd();
    return;
  }

  const item = dragItem.value;
  const fromStage = dragFromStage.value;
  if (fromStage === stageKey) {
    handleDragEnd();
    return;
  }

  moveCard(item, stageKey);
  handleDragEnd();

  try {
    await updateConversationStage(item.conversation_id, stageKey);
  } catch (e) {
    console.error('Не вдалося змінити етап', e);
    moveCard(item, fromStage);
  }
};

const openChat = (item) => {
  const params = new URLSearchParams({
    customer_id: item.customer_id,
    platform: item.platform,
  });
  window.location.href = `/messenger?${params.toString()}`;
};

const platformIcon = (platform) => {
  return platform === 'instagram' ? 'bi bi-instagram' : 'bi bi-messenger';
};

const platformClass = (platform) => {
  return platform === 'instagram' ? 'platform-instagram' : 'platform-messenger';
};

const formatTime = (time) => {
  if (!time) return '';
  const date = new Date(time);
  const now = new Date();
  const isToday =
    date.getDate() === now.getDate() &&
    date.getMonth() === now.getMonth() &&
    date.getFullYear() === now.getFullYear();

  return isToday
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString('uk-UA', { day: '2-digit', month: 'short' });
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
  loadFunnel();
});
</script>

<style scoped>
.chat-funnel {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.funnel-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
}

.funnel-title {
  font-size: 20px;
  font-weight: 700;
  margin: 0;
  color: #0f172a;
}

.funnel-subtitle {
  margin: 4px 0 0;
  font-size: 13px;
  color: #64748b;
}

.funnel-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.funnel-search {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 6px 10px;
  font-size: 13px;
  min-width: 220px;
}

.funnel-btn {
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 10px;
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.funnel-btn:disabled {
  opacity: 0.6;
  cursor: default;
}

.funnel-link {
  font-size: 13px;
  font-weight: 600;
  color: #2563eb;
  text-decoration: none;
}

.funnel-loading {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #64748b;
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.funnel-board {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}

.funnel-column {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-top: 3px solid var(--stage-color, #e2e8f0);
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  min-height: 220px;
  overflow: hidden;
}

.funnel-column.is-drop-target {
  box-shadow: inset 0 0 0 2px var(--stage-color, #94a3b8);
}

.column-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-bottom: 1px solid #e2e8f0;
  font-weight: 700;
  color: #1e293b;
  background: var(--stage-bg, #f8fafc);
}

.column-count {
  font-size: 12px;
  font-weight: 600;
  color: var(--stage-color, #64748b);
}

.column-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  overflow-y: auto;
}

.column-empty {
  font-size: 12px;
  color: #94a3b8;
  text-align: center;
  padding: 16px 0;
}

.funnel-card {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.funnel-card.dragging {
  opacity: 0.5;
}

.funnel-card:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
}

.card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.card-name {
  font-weight: 700;
  font-size: 13px;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-time {
  font-size: 11px;
  color: #94a3b8;
  flex-shrink: 0;
}

.card-preview {
  font-size: 12px;
  color: #64748b;
  margin-top: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
}

.platform-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 11px;
  color: #fff;
}

.platform-messenger {
  background: linear-gradient(45deg, #00c6ff, #0072ff);
}

.platform-instagram {
  background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
}

.unread-pill {
  background: #3b82f6;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
}

.card-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.tag-chip {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 6px;
  background: #f8fafc;
  color: #64748b;
  border: 1px solid #e2e8f0;
}

.tag-more {
  background: #f1f5f9;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
