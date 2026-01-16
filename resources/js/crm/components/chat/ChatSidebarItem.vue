<template>
  <button
    type="button"
    class="chat-item"
    :class="{ 'is-active': isActive, 'is-unread': item.unread_count > 0 }"
    @click="$emit('select', item)"
  >
    <div class="chat-item-avatar-wrapper">
      <img 
        v-if="item.customer_avatar" 
        :src="item.customer_avatar" 
        class="avatar-img" 
        alt="Avatar" 
      />
      <div v-else class="avatar-fallback" :class="bgClass">
        {{ (item.customer_name || '?').charAt(0).toUpperCase() }}
      </div>

      <div class="platform-badge">
        <i :class="platformIcon"></i>
      </div>
    </div>

    <div class="chat-item-content">
      <div class="content-row-top">
        <h4 class="chat-name" :title="item.customer_name">
          {{ item.customer_name || 'Невідомий клієнт' }}
        </h4>
        <span class="chat-time">{{ formattedTime }}</span>
      </div>

      <div class="content-row-bottom">
        <p class="chat-preview">
          <span v-if="isMe" class="prefix">Ви:</span>
          {{ item.last_message || 'Вкладення' }}
        </p>
        
        <span v-if="item.unread_count > 0" class="unread-badge">
          {{ item.unread_count > 99 ? '99+' : item.unread_count }}
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

// Визначаємо іконку платформи
const platformIcon = computed(() => {
  return props.item.platform === 'instagram' 
    ? 'bi bi-instagram text-instagram' 
    : 'bi bi-messenger text-messenger';
});

// Генеруємо випадковий колір для фону букв (опціонально, зараз просто сірий/синій)
const bgClass = computed(() => {
  // Можна зробити логіку на основі ID, щоб колір був постійним
  return props.item.platform === 'instagram' ? 'bg-pink' : 'bg-blue';
});

// Перевірка, чи останнє повідомлення від нас (для префіксу "Ви:")
// Це залежить від того, чи повертає ваш API поле last_message_is_mine. 
// Якщо ні - можна поки прибрати v-if="isMe"
const isMe = computed(() => false); 

// Форматування дати
const formattedTime = computed(() => {
  if (!props.item.last_message_time) return '';
  
  const date = new Date(props.item.last_message_time);
  const now = new Date();
  const isToday = date.toDateString() === now.toDateString();

  return isToday 
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) 
    : date.toLocaleDateString('uk-UA', { day: 'numeric', month: 'short' });
});
</script>

<style scoped>
/* Основний контейнер */
.chat-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 12px;
  cursor: pointer;
  text-align: left;
  transition: all 0.2s ease;
  position: relative;
}

.chat-item:hover {
  background-color: #f8fafc; /* Світло-сірий при наведенні */
}

.chat-item.is-active {
  background-color: #eff6ff; /* Світло-блакитний активний */
  border-color: #dbeafe;
}

/* --- Аватар --- */
.chat-item-avatar-wrapper {
  position: relative;
  flex-shrink: 0;
}

.avatar-img, 
.avatar-fallback {
  width: 48px;
  height: 48px;
  border-radius: 50%; /* Круглий */
  object-fit: cover;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  font-weight: 600;
  color: #fff;
}

.avatar-img {
  border: 1px solid #e2e8f0;
}

/* Кольори заглушок */
.bg-blue { background: linear-gradient(135deg, #60a5fa, #3b82f6); }
.bg-pink { background: linear-gradient(135deg, #f472b6, #db2777); }

/* Іконка платформи */
.platform-badge {
  position: absolute;
  bottom: -2px;
  right: -2px;
  background: #fff;
  border-radius: 50%;
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  border: 2px solid #fff; /* Біла обводка, щоб відділити від аватара */
}

.text-instagram { color: #db2777; }
.text-messenger { color: #2563eb; }

/* --- Контент --- */
.chat-item-content {
  flex: 1;
  min-width: 0; /* Важливо для text-overflow */
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.content-row-top, 
.content-row-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

/* Ім'я */
.chat-name {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Час */
.chat-time {
  font-size: 0.75rem;
  color: #94a3b8;
  white-space: nowrap;
}

/* Текст повідомлення */
.chat-preview {
  margin: 0;
  font-size: 0.85rem;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
}

.chat-item.is-unread .chat-name {
  color: #0f172a; /* Темніший для непрочитаних */
}

.chat-item.is-unread .chat-preview {
  color: #334155;
  font-weight: 500;
}

.prefix {
  color: #94a3b8;
  font-weight: normal;
  margin-right: 2px;
}

/* Бейдж непрочитаних */
.unread-badge {
  background-color: #3b82f6;
  color: white;
  font-size: 0.7rem;
  font-weight: 700;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
</style>