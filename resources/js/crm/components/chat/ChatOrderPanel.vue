<template>
  <teleport to="body">
    <transition name="fade">
      <div class="offcanvas-backdrop" @click="handleClose"></div>
    </transition>

    <transition name="slide-global">
      <div class="order-offcanvas-global">
        <div class="offcanvas-header">
          <div class="header-left">
            <i class="bi bi-bag-check-fill header-icon"></i>
            <h3>Нове замовлення</h3>
          </div>
          <button class="btn-close-panel" type="button" @click="handleClose">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>

        <div class="offcanvas-body">
          <div class="empty-placeholder">
            <div class="placeholder-icon">
              <i class="bi bi-basket2"></i>
            </div>
            <p>Тут буде форма створення замовлення</p>
            <span>Пошук товарів, вибір доставки та оплати</span>
          </div>
        </div>

        <div class="offcanvas-footer">
          <button class="btn-save-modern" type="button" :disabled="!canSave" @click="handleSaved">
            Зберегти замовлення
          </button>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  customer: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const canSave = computed(() => false);

const handleClose = () => {
  emit('close');
};

const handleSaved = () => {
  emit('saved');
};
</script>

<style scoped>
/* === GLOBAL OFFCANVAS STYLES === */
.offcanvas-backdrop {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.3);
  z-index: 1000;
  backdrop-filter: blur(2px);
}

.order-offcanvas-global {
  position: fixed;
  top: 0;
  right: 0;
  width: 400px;
  max-width: 100%;
  height: 100vh;
  background: #ffffff;
  z-index: 1001;
  display: flex;
  flex-direction: column;
  box-shadow: -10px 0 30px rgba(0, 0, 0, 0.15);
}

/* HEADER */
.offcanvas-header {
  padding: 16px;
  border-bottom: 1px solid #edf2f7;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
}
.header-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.header-icon { color: #a78bfb; font-size: 18px; }
.offcanvas-header h3 {
  font-size: 16px;
  font-weight: 700;
  color: #1f2937;
  margin: 0;
}
.btn-close-panel {
  background: #f1f5f9;
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  color: #64748b;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: 0.2s;
}
.btn-close-panel:hover { background: #fee2e2; color: #ef4444; }

/* BODY */
.offcanvas-body {
  flex: 1;
  padding: 20px;
  overflow-y: auto;
  background: #f8fafc;
}

/* FOOTER */
.offcanvas-footer {
  padding: 16px;
  border-top: 1px solid #edf2f7;
  background: #ffffff;
}

/* PLACEHOLDER STATE */
.empty-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: #94a3b8;
  text-align: center;
  gap: 10px;
}
.placeholder-icon {
  width: 64px;
  height: 64px;
  background: #ffffff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: #cbd5e0;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
}
.empty-placeholder p { font-size: 15px; font-weight: 600; color: #475569; margin: 0; }
.empty-placeholder span { font-size: 13px; }

.btn-save-modern {
  background: #a78bfb;
  color: #ffffff;
  border: none;
  border-radius: 8px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  width: 100%;
  cursor: pointer;
  transition: all 0.3s ease;
}
.btn-save-modern:disabled {
  background: #e2e8f0;
  color: #94a3b8;
  cursor: not-allowed;
  box-shadow: none;
}

/* ANIMATION */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-global-enter-active, .slide-global-leave-active {
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.slide-global-enter-from, .slide-global-leave-to { transform: translateX(100%); }

@media (max-width: 768px) {
  .order-offcanvas-global {
    width: 100%;
  }

  input,
  select,
  textarea {
    font-size: 16px;
  }
}
</style>
