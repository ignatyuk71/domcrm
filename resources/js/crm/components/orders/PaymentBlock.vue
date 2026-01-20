<template>
  <div class="payment-wrapper">
    
    <h6 class="text-uppercase text-secondary x-small fw-bold mb-3 ls-1">Спосіб оплати</h6>

    <div class="d-flex flex-column gap-3">
      <div
        v-for="m in methods"
        :key="m.value"
        class="payment-card position-relative overflow-hidden"
        :class="{ 'selected': model.method === m.value }"
        @click="model.method = m.value"
      >
        <div class="selection-bg"></div>

        <div class="d-flex align-items-center gap-3 position-relative z-1">
          
          <div class="icon-box" :class="model.method === m.value ? 'bg-primary text-white shadow-sm' : 'bg-light text-secondary'">
            <i :class="['bi', m.icon, 'fs-5']"></i>
          </div>
          
          <div class="flex-grow-1">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="fw-bold text-dark fs-6">{{ m.label }}</span>
              <div class="custom-radio">
                <div class="radio-dot"></div>
              </div>
            </div>
            <div class="small text-muted lh-sm">{{ m.desc }}</div>
          </div>
        </div>

        <input class="d-none" type="radio" :value="m.value" v-model="model.method" />
      </div>
    </div>

    <Transition name="expand">
      <div v-if="model.method === 'prepay'" class="mt-2 overflow-hidden">
        <div class="p-3 bg-light bg-opacity-50 border rounded-3 dashed-border">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label text-muted x-small fw-bold text-uppercase mb-0">Внесена сума</label>
            <span class="badge bg-white border text-secondary shadow-sm" style="font-size: 0.7rem;">Залишок: Накладений платіж</span>
          </div>
          
          <div class="input-group-currency" :class="{ 'is-invalid': errors.prepay_amount }">
            <input 
              class="form-control-currency" 
              v-model.number="model.prepay_amount" 
              type="number" 
              step="0.01" 
              placeholder="0"
              autofocus
            />
            <span class="currency-symbol">{{ currency }}</span>
          </div>
          
          <div class="invalid-feedback d-block small mt-1" v-if="errors.prepay_amount">
            <i class="bi bi-exclamation-circle me-1"></i>{{ errors.prepay_amount }}
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<script setup>
const props = defineProps({
  currency: { type: String, default: 'UAH' },
  errors: { type: Object, default: () => ({}) },
});

// Vue 3.4+ defineModel
const model = defineModel({ type: Object, default: () => ({ method: 'cod', prepay_amount: 0 }) });

const methods = [
  { value: 'cod', label: 'Накладений платіж', icon: 'bi-box-seam', desc: 'Оплата у відділенні пошти' },
  { value: 'card', label: 'Оплата на рахунок', icon: 'bi-credit-card', desc: 'Повна оплата за реквізитами' },
  { value: 'prepay', label: 'Часткова передоплата', icon: 'bi-pie-chart', desc: 'Аванс + накладений платіж' },
];
</script>

<style scoped>
/* GENERAL TYPOGRAPHY */
.x-small { font-size: 0.75rem; }
.ls-1 { letter-spacing: 0.05em; }

/* PAYMENT CARD */
.payment-card {
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 16px;
  cursor: pointer;
  background: #fff;
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  user-select: none;
}

.payment-card:hover {
  border-color: #cbd5e1;
  transform: translateY(-1px);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
}

.payment-card.selected {
  border-color: var(--bs-primary);
  box-shadow: 0 0 0 1px var(--bs-primary), 0 4px 12px rgba(var(--bs-primary-rgb), 0.15);
}

/* Background Animation */
.selection-bg {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: var(--bs-primary);
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}
.payment-card.selected .selection-bg { opacity: 0.04; }

/* ICON BOX */
.icon-box {
  width: 44px; height: 44px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.3s ease;
}
.payment-card:hover .icon-box:not(.bg-primary) {
  background-color: #f1f5f9 !important; /* Slightly darker grey on hover */
  color: #334155 !important;
}

/* CUSTOM RADIO */
.custom-radio {
  width: 20px; height: 20px;
  border: 2px solid #cbd5e1;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.2s;
}
.radio-dot {
  width: 10px; height: 10px;
  background-color: var(--bs-primary);
  border-radius: 50%;
  opacity: 0;
  transform: scale(0);
  transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.payment-card.selected .custom-radio {
  border-color: var(--bs-primary);
  background-color: #fff;
}
.payment-card.selected .radio-dot {
  opacity: 1;
  transform: scale(1);
}

/* PREPAY SECTION STYLES */
.dashed-border {
  border: 1px dashed #cbd5e1 !important;
  background-color: #f8fafc;
}

.input-group-currency {
  display: flex;
  align-items: baseline;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 12px;
  transition: all 0.2s;
}
.input-group-currency:focus-within {
  border-color: var(--bs-primary);
  box-shadow: 0 0 0 3px rgba(var(--bs-primary-rgb), 0.1);
}
.input-group-currency.is-invalid {
  border-color: #dc3545;
  background-color: #fff5f5;
}

.form-control-currency {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  font-weight: 700;
  color: #1e293b;
  width: 100%;
  padding: 0;
  outline: none;
  line-height: 1.2;
}
.form-control-currency::placeholder { color: #cbd5e1; font-weight: 500; }

.currency-symbol {
  font-size: 1rem;
  font-weight: 500;
  color: #94a3b8;
  margin-left: 8px;
}

/* Vue Transition: Expand */
.expand-enter-active,
.expand-leave-active {
  transition: all 0.3s ease-out;
  max-height: 200px;
  opacity: 1;
  transform: translateY(0);
}
.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
  transform: translateY(-10px);
}
</style>