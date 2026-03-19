<template>
  <teleport to="body" :disabled="embedded">
    <transition name="fade">
      <div v-if="open" class="payment-shell" :class="{ 'is-embedded': embedded }" @click.self="embedded ? null : handleClose()">
        <transition :name="embedded ? 'sidebar-slide' : 'modal-slide'">
          <div v-if="open" class="payment-panel" :class="{ 'is-embedded': embedded }">
            <header class="payment-header">
              <div class="payment-header-copy">
                <span class="payment-badge">Оплата</span>
                <h3>Фінансова конфігурація</h3>
              </div>
              <button class="payment-close-btn" type="button" :disabled="isSaving" :title="embedded ? 'Назад' : 'Закрити'" @click="handleClose">
                <i class="bi" :class="embedded ? 'bi-arrow-left' : 'bi-x-lg'"></i>
              </button>
            </header>

            <div class="payment-body custom-scrollbar">
              <section class="payment-section">
                <div class="section-label">Спосіб оплати</div>
                <div class="payment-methods">
                  <button
                    v-for="method in methods"
                    :key="method.value"
                    type="button"
                    class="payment-method-card"
                    :class="{ active: local.method === method.value }"
                    @click="local.method = method.value"
                  >
                    <span class="payment-method-icon">
                      <i class="bi" :class="method.icon"></i>
                    </span>
                    <span class="payment-method-copy">
                      <strong>{{ method.label }}</strong>
                      <small>{{ method.desc }}</small>
                    </span>
                    <i v-if="local.method === method.value" class="bi bi-check2-circle payment-method-check"></i>
                  </button>
                </div>
              </section>

              <transition name="slide-up">
                <section v-if="local.method === 'prepay'" class="payment-section">
                  <div class="section-label">Сума передоплати</div>
                  <div class="prepay-card">
                    <div class="prepay-meta">
                      <span>Залишок клієнт оплачує післяплатою</span>
                    </div>
                    <div class="prepay-field">
                      <input
                        v-model.number="local.prepay_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="prepay-input"
                        placeholder="0"
                      >
                      <span class="prepay-currency">{{ local.currency || 'UAH' }}</span>
                    </div>
                  </div>
                </section>
              </transition>
            </div>

            <footer class="payment-footer">
              <button class="payment-save-btn" type="button" :class="{ 'success-state': isSaved }" :disabled="isSaving || !local.method" @click="handleSave">
                <span v-if="!isSaved">Зберегти конфігурацію</span>
                <span v-else><i class="bi bi-check-circle-fill"></i> Готово</span>
              </button>
            </footer>
          </div>
        </transition>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { reactive, ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  embedded: { type: Boolean, default: false },
  modelValue: {
    type: Object,
    default: () => ({ method: '', prepay_amount: 0, currency: 'UAH' }),
  },
});

const emit = defineEmits(['close', 'save', 'update:modelValue']);

const local = reactive({
  method: '',
  prepay_amount: 0,
  currency: 'UAH',
});

const isSaving = ref(false);
const isSaved = ref(false);
let saveTimer = null;

const methods = [
  { value: 'cod', label: 'Накладений платіж', icon: 'bi-box-seam', desc: 'Оплата у відділенні пошти' },
  { value: 'card', label: 'Оплата на рахунок', icon: 'bi-credit-card', desc: 'Повна оплата за реквізитами' },
  { value: 'prepay', label: 'Часткова передоплата', icon: 'bi-pie-chart', desc: 'Аванс + накладений платіж' },
];

const syncFromModel = (val) => {
  local.method = val?.method || '';
  local.prepay_amount = Number(val?.prepay_amount || 0);
  local.currency = val?.currency || 'UAH';
};

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      isSaving.value = false;
      isSaved.value = false;
      syncFromModel(props.modelValue);
    }
  }
);

watch(
  () => props.modelValue,
  (val) => {
    if (props.open) syncFromModel(val);
  },
  { deep: true, immediate: true }
);

watch(
  () => local.method,
  (method) => {
    if (method !== 'prepay') {
      local.prepay_amount = 0;
    }
  }
);

watch(
  () => local.prepay_amount,
  (amount) => {
    if (amount < 0) local.prepay_amount = 0;
  }
);

watch(
  local,
  () => {
    emit('update:modelValue', {
      method: local.method || '',
      prepay_amount: local.method === 'prepay' ? Number(local.prepay_amount || 0) : 0,
      currency: local.currency || 'UAH',
    });
  },
  { deep: true }
);

const handleClose = () => {
  if (isSaving.value) return;
  emit('close');
};

const handleSave = () => {
  if (isSaving.value) return;
  isSaving.value = true;
  isSaved.value = true;

  const payload = {
    method: local.method || '',
    prepay_amount: local.method === 'prepay' ? Number(local.prepay_amount || 0) : 0,
    currency: local.currency || 'UAH',
  };

  saveTimer = setTimeout(() => {
    emit('update:modelValue', payload);
    emit('save', payload);
    emit('close');
    isSaving.value = false;
  }, 1200);
};

onBeforeUnmount(() => {
  if (saveTimer) clearTimeout(saveTimer);
});
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.modal-slide-enter-active,
.modal-slide-leave-active,
.sidebar-slide-enter-active,
.sidebar-slide-leave-active {
  transition: transform 0.24s ease, opacity 0.24s ease;
}

.modal-slide-enter-from,
.modal-slide-leave-to {
  opacity: 0;
  transform: translateY(24px);
}

.sidebar-slide-enter-from,
.sidebar-slide-leave-to {
  opacity: 0;
  transform: translateX(18px);
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

.payment-shell {
  position: fixed;
  inset: 0;
  z-index: 120000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(8px);
}

.payment-shell.is-embedded {
  position: absolute;
  z-index: 46;
  padding: 0;
  background: #ffffff;
  backdrop-filter: none;
  align-items: stretch;
  justify-content: stretch;
}

.payment-panel {
  width: min(100%, 520px);
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
}

.payment-panel.is-embedded {
  width: 100%;
  height: 100%;
  max-height: none;
  border-radius: 0;
  box-shadow: none;
}

.payment-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
  border-bottom: 1px solid #e5e7eb;
}

.payment-header-copy h3 {
  margin: 4px 0 0;
  font-size: 16px;
  line-height: 1.2;
  font-weight: 800;
  color: #0f172a;
}

.payment-badge {
  display: inline-flex;
  align-items: center;
  font-weight: 900;
  color: #10b981;
  font-size: 11px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.payment-close-btn {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #ffffff;
  color: #64748b;
  cursor: pointer;
  transition: all 0.2s ease;
}

.payment-close-btn:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
  color: #0f172a;
}

.payment-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 16px;
}

.payment-section {
  margin-bottom: 18px;
}

.section-label {
  margin-bottom: 8px;
  font-size: 11px;
  line-height: 1.2;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.payment-methods {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.payment-method-card {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #ffffff;
  color: #0f172a;
  cursor: pointer;
  transition: all 0.2s ease;
  box-sizing: border-box;
}

.payment-method-card:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
}

.payment-method-card.active {
  border-color: #8b5cf6;
  background: #f5f3ff;
  color: #5b21b6;
}

.payment-method-icon {
  width: 28px;
  flex-shrink: 0;
  text-align: center;
  font-size: 18px;
  color: #94a3b8;
}

.payment-method-card.active .payment-method-icon {
  color: #7c3aed;
}

.payment-method-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  text-align: left;
}

.payment-method-copy strong {
  font-size: 14px;
  line-height: 1.2;
  font-weight: 700;
}

.payment-method-copy small {
  font-size: 11px;
  line-height: 1.2;
  color: #64748b;
}

.payment-method-check {
  margin-left: auto;
  flex-shrink: 0;
  font-size: 18px;
  color: #7c3aed;
}

.prepay-card {
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #f8fafc;
}

.prepay-meta {
  margin-bottom: 10px;
}

.prepay-meta span {
  display: block;
  font-size: 11px;
  line-height: 1.35;
  color: #64748b;
}

.prepay-field {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 44px;
  padding: 0 14px;
  border: 1px solid #dbe3ef;
  border-radius: 12px;
  background: #ffffff;
}

.prepay-field:focus-within {
  border-color: #8b5cf6;
  box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12);
}

.prepay-input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  color: #0f172a;
  font-size: 15px;
  font-weight: 700;
  outline: none;
}

.prepay-input::placeholder {
  color: #94a3b8;
  font-weight: 500;
}

.prepay-currency {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}

.payment-footer {
  padding: 14px 16px;
  border-top: 1px solid #e5e7eb;
  background: #ffffff;
}

.payment-save-btn {
  width: 100%;
  min-height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: none;
  border-radius: 12px;
  background: #8b5cf6;
  color: #ffffff;
  font-size: 14px;
  font-weight: 800;
  cursor: pointer;
  transition: transform 0.18s ease, background-color 0.18s ease;
}

.payment-save-btn:hover {
  background: #7c3aed;
}

.payment-save-btn.success-state {
  background: #10b981;
}

.payment-save-btn:disabled {
  opacity: 0.72;
  cursor: not-allowed;
}

.custom-scrollbar::-webkit-scrollbar {
  width: 5px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 999px;
}

@media (max-width: 768px) {
  .payment-shell {
    padding: 0;
  }

  .payment-panel {
    width: 100%;
    height: 100dvh;
    max-height: 100dvh;
    border-radius: 0;
  }
}
</style>
