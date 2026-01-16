<template>
  <button
    type="button"
    class="chat-item"
    :class="{ 'is-active': isActive, 'is-unread': item.unread_count > 0 }"
    @click="$emit('select', item)"
  >
    <div class="avatar-wrapper">
      <img 
        v-if="item.customer_avatar" 
        :src="item.customer_avatar" 
        class="avatar-img" 
        alt="User" 
      />
      <div v-else class="avatar-placeholder" :class="platformBgClass">
        {{ (item.customer_name || '?').charAt(0).toUpperCase() }}
      </div>

      <div class="platform-badge" :class="platformBgClass">
        <i :class="platformIconClass"></i>
      </div>
    </div>

    <div class="content-wrapper">
      <div class="row-top">
        <h4 class="chat-name">{{ item.customer_name || 'Невідомий' }}</h4>
        <span class="chat-time">{{ formattedTime }}</span>
      </div>

      <div class="row-bottom">
        <p class="chat-preview">
          {{ item.last_message || 'Вкладення' }}
        </p>
        
        <span v-if="item.unread_count > 0" class="unread-count">
          {{ item.unread_count }}
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

// --- Класи для платформ ---
const platformIconClass = computed(() => {
  // Використовуємо заповнені іконки (bi-instagram, bi-messenger)
  return props.item.platform === 'instagram' 
    ? 'bi bi-instagram' 
    : 'bi bi-messenger';
});

const platformBgClass = computed(() => {
  return props.item.platform === 'instagram' ? 'is-instagram' : 'is-messenger';
});

// --- Форматування часу (компактне) ---
const formattedTime = computed(() => {
  if (!props.item.last_message_time) return '';
  const date = new Date(props.item.last_message_time);
  const now = new Date();
  
  const isToday = date.getDate() === now.getDate() && 
                  date.getMonth() === now.getMonth() && 
                  date.getFullYear() === now.getFullYear();

  return isToday 
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) 
    : date.toLocaleDateString('uk-UA', { day: 'numeric', month: 'short' });
});
</script>

<style scoped>
/* Загальний контейнер */
.chat-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 12px; /* Зменшив відступи для компактності */
  background: transparent;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  text-align: left;
  transition: background-color 0.2s;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; /* Системний шрифт як на скріні */
}

.chat-item:hover {
  background-color: #f1f5f9;
}

.chat-item.is-active {
  background-color: #e0f2fe; /* Дуже світлий блакитний */
}

/* --- АВАТАР --- */
.avatar-wrapper {
  position: relative;
  width: 48px;
  height: 48px;
  flex-shrink: 0;
}

.avatar-img, .avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-weight: 600;
  font-size: 18px;
}

/* --- ІКОНКА ПЛАТФОРМИ (як на скріншоті) --- */
.platform-badge {
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 11px;
  border: 2px solid white; /* Біла обводка */
  box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

/* Кольори платформ */
.is-messenger {
  background-color: #0084ff; /* Messenger Blue */
}
.is-instagram {
  background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
}

/* --- ТЕКСТ --- */
.content-wrapper {
  flex: 1;
  min-width: 0; /* Дозволяє text-overflow працювати */
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 2px; /* Мінімальний відступ між ім'ям і текстом */
}

.row-top {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}

.chat-name {
  font-size: 15px; /* Трохи збільшив шрифт імені */
  font-weight: 700; /* Жирний шрифт як на скріні */
  color: #0f172a;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.chat-time {
  font-size: 12px;
  color: #94a3b8; /* Світло-сірий */
  font-weight: 400;
  flex-shrink: 0;
  margin-left: 8px;
}

.row-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.chat-preview {
  margin: 0;
  font-size: 13px; /* Зменшений шрифт повідомлення */
  color: #64748b; /* Сірий колір тексту */
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
  line-height: 1.2;
}

/* Якщо чат непрочитаний */
.chat-item.is-unread .chat-name {
  color: #000;
}
.chat-item.is-unread .chat-preview {
  font-weight: 600;
  color: #1e293b;
}

.unread-count {
  background-color: #0084ff;
  color: white;
  font-size: 10px;
  font-weight: 700;
  min-width: 16px;
  height: 16px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-left: 6px;
  padding: 0 4px;
}
</style>