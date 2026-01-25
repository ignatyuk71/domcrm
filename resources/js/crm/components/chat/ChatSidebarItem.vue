<template>
  <button
    type="button"
    class="chat-item"
    :class="{ 'is-active': isActive, 'is-unread': item.unread_count > 0 }"
    @click="$emit('select', item)"
  >
    <div class="avatar-container">
      <img 
        v-if="item.customer_avatar" 
        :src="item.customer_avatar" 
        class="avatar-img" 
        alt="User" 
      />
      <div v-else class="avatar-placeholder" :class="platformColorClass">
        {{ (item.customer_name || '?').charAt(0).toUpperCase() }}
      </div>

      <div class="platform-badge">
        <i :class="platformIconClass"></i>
      </div>
    </div>

    <div class="info-container">
      <div class="info-row-top">
        <h4 class="chat-name">{{ item.customer_name || 'Невідомий клієнт' }}</h4>
        <div class="meta-right">
          <span v-if="stageLabel" class="stage-badge" :class="stageClass">
            {{ stageLabel }}
          </span>
          <span class="chat-time">{{ formattedTime }}</span>
        </div>
      </div>

      <div class="info-row-bottom">
        <p class="chat-preview">
          {{ item.last_message || 'Вкладення' }}
        </p>
        
        <span v-if="item.unread_count > 0" class="unread-count">
          {{ item.unread_count > 99 ? '99+' : item.unread_count }}
        </span>
      </div>

      <div v-if="visibleTags.length" class="info-row-tags">
        <span
          v-for="tag in visibleTags"
          :key="tag.id"
          class="tag-chip"
          :style="getTagStyle(tag.color)"
        >
          {{ tag.name }}
        </span>
        <span v-if="extraTagsCount" class="tag-chip tag-chip-more">
          +{{ extraTagsCount }}
        </span>
      </div>
    </div>
  </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  item: { type: Object, required: true },
  isActive: { type: Boolean, default: false },
});

defineEmits(['select']);

// --- Логіка іконок та кольорів ---
const platformIconClass = computed(() => {
  return props.item.platform === 'instagram' 
    ? 'bi bi-instagram instagram-icon' 
    : 'bi bi-messenger messenger-icon';
});

const platformColorClass = computed(() => {
  return props.item.platform === 'instagram' ? 'bg-instagram' : 'bg-messenger';
});

const stageLabel = computed(() => {
  const stage = props.item.stage || '';
  const map = {
    new: 'Новий',
    waiting_reply: 'Чекаємо',
    order_confirmed: 'Підтверджено',
    done: 'Виконано',
    closed: 'Закрито',
  };
  return map[stage] || '';
});

const stageClass = computed(() => {
  const stage = props.item.stage || '';
  return stage ? `stage-${stage}` : '';
});

const tagList = computed(() => props.item.tags || []);
const visibleTags = computed(() => tagList.value.slice(0, 2));
const extraTagsCount = computed(() => Math.max(0, tagList.value.length - 2));

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

// --- Розумне форматування часу ---
// Замість "2026-01-16 15:09:04" покаже "15:09" або "16 січ"
const formattedTime = computed(() => {
  if (!props.item.last_message_time) return '';
  
  const date = new Date(props.item.last_message_time);
  const now = new Date();
  
  const isToday = date.getDate() === now.getDate() && 
                  date.getMonth() === now.getMonth() && 
                  date.getFullYear() === now.getFullYear();

  return isToday
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString('uk-UA', { day: '2-digit', month: 'short' });
});
</script>

<style scoped>
/* Основний контейнер картки */
.chat-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px; /* Відступ між аватаром і текстом */
  padding: 8px 10px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
  text-align: left;
  outline: none;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.chat-item:hover {
  background-color: #f1f5f9;
}

.chat-item.is-active {
  background-color: #eff6ff; /* Світло-блакитний фон */
  border-color: #bfdbfe;     /* Легка рамка */
}

/* --- АВАТАР --- */
.avatar-container {
  position: relative;
  flex-shrink: 0;
  width: 48px;
  height: 48px;
}

.avatar-img, 
.avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  font-weight: 600;
  color: white;
}

.bg-instagram { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); }
.bg-messenger { background: linear-gradient(45deg, #00c6ff, #0072ff); }

/* Значок платформи (маленький кружечок) */
.platform-badge {
  position: absolute;
  bottom: -1px;
  right: -1px;
  width: 18px;
  height: 18px;
  background: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 4px rgba(0,0,0,0.15);
  border: 2px solid white;
}

.instagram-icon { color: #d62976; font-size: 10px; }
.messenger-icon { color: #0072ff; font-size: 10px; }

/* --- ТЕКСТОВА ЧАСТИНА --- */
.info-container {
  flex: 1;
  min-width: 0; /* Магія CSS: дозволяє text-overflow працювати всередині flex */
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.info-row-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.meta-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.chat-name {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-right: 8px;
}

.chat-time {
  font-size: 12px;
  color: #94a3b8;
  white-space: nowrap;
  flex-shrink: 0;
}

.info-row-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  height: 20px; /* Фіксуємо висоту рядка */
}

.info-row-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
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

.tag-chip-more {
  background: #f1f5f9;
}

.stage-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
  background: #eef2ff;
  color: #4f46e5;
}

.stage-new { background: #eef2ff; color: #4f46e5; }
.stage-waiting_reply { background: #fef3c7; color: #92400e; }
.stage-order_confirmed { background: #dbeafe; color: #1d4ed8; }
.stage-done { background: #dcfce7; color: #166534; }
.stage-closed { background: #f1f5f9; color: #475569; }

.chat-preview {
  margin: 0;
  font-size: 13px;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1; /* Займає все доступне місце */
  margin-right: 8px;
}

/* Якщо чат активний або непрочитаний - текст темніший */
.chat-item.is-unread .chat-name,
.chat-item.is-unread .chat-preview {
  color: #0f172a;
  font-weight: 600;
}

/* Лічильник непрочитаних */
.unread-count {
  background-color: #3b82f6;
  color: white;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0 6px;
  border-radius: 10px;
  min-width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
}

@media (max-width: 768px) {
  .chat-item {
    padding: 12px 12px;
    min-height: 76px;
  }

  .avatar-container {
    width: 52px;
    height: 52px;
  }

  .info-row-tags {
    display: none;
  }

  .stage-badge {
    font-size: 9px;
    padding: 2px 4px;
  }
}
</style>
