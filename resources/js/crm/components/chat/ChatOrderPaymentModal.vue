<template>
  <teleport to="body">
    <transition name="fade">
      <div v-if="open" class="payment-overlay">
        <div class="payment-modal">
          <div class="modal-header">
            <div class="header-title">
              <span class="payment-badge">Оплата</span>
              <h3>Фінансова конфігурація</h3>
            </div>
            <button class="btn-close" type="button" :disabled="isSaving" @click="handleClose">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="modal-body">
            <PaymentBlock v-model="local" currency="UAH" :errors="{}" />
          </div>

          <div class="modal-footer">
            <button class="btn-cancel" type="button" :disabled="isSaving" @click="handleClose">
              Скасувати
            </button>
            <button
              class="btn-save"
              type="button"
              :class="{ 'success-state': isSaved }"
              :disabled="isSaving"
              @click="handleSave"
            >
              <span v-if="!isSaved">Зберегти конфігурацію</span>
              <i v-else class="bi bi-check-circle-fill"></i>
            </button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { reactive, ref, watch, onBeforeUnmount } from 'vue';
import PaymentBlock from '@/crm/components/orders/PaymentBlock.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
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
.payment-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  z-index: 120000;
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(8px);
}

.payment-modal {
  width: 560px;
  max-width: 96vw;
  max-height: 92vh;
  background: #ffffff;
  border-radius: 24px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
}

.modal-header {
  padding: 18px 22px;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-title h3 {
  margin: 6px 0 0;
  font-size: 18px;
  font-weight: 800;
  color: #0f172a;
}

.payment-badge {
  display: inline-flex;
  align-items: center;
  font-weight: 900;
  color: #10b981;
  font-size: 12px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.btn-close {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: none;
  background: #f8fafc;
  color: #64748b;
  cursor: pointer;
}

.modal-body {
  padding: 20px 22px;
  overflow-y: auto;
}

.modal-footer {
  padding: 16px 22px;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.btn-cancel {
  height: 48px;
  padding: 0 18px;
  border-radius: 12px;
  border: none;
  background: #f8fafc;
  color: #64748b;
  cursor: pointer;
}

.btn-save {
  height: 48px;
  padding: 0 24px;
  border-radius: 14px;
  border: none;
  background: #8b5cf6;
  color: #ffffff;
  font-weight: 800;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  transition: background 0.2s ease;
}

.btn-save.success-state {
  background: #10b981;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 768px) {
  .payment-overlay {
    align-items: stretch;
    justify-content: stretch;
  }

  .payment-modal {
    width: 100%;
    height: 100%;
    max-width: 100%;
    max-height: 100%;
    border-radius: 0;
  }
}
</style>
