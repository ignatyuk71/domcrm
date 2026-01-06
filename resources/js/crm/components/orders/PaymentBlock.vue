<template>
  <div class="d-flex flex-column gap-3">
    
    <div class="d-flex flex-column gap-2">
      <div
        v-for="m in methods"
        :key="m.value"
        class="payment-option"
        :class="{ 'active': model.method === m.value }"
        @click="model.method = m.value"
      >
        <div class="d-flex align-items-center gap-3">
          <div class="icon-circle" :class="model.method === m.value ? 'bg-primary text-white' : 'bg-light text-secondary'">
            <i :class="['bi', m.icon]"></i>
          </div>
          
          <div class="d-flex flex-column">
            <span class="fw-semibold text-dark fs-6">{{ m.label }}</span>
            <span class="small text-muted" v-if="m.desc">{{ m.desc }}</span>
          </div>
        </div>

        <div class="check-icon">
          <i class="bi bi-check-circle-fill text-primary"></i>
        </div>

        <input class="d-none" type="radio" :value="m.value" v-model="model.method" />
      </div>
    </div>

    <div v-if="model.method === 'prepay'" class="prepay-section">
      <label class="form-label-custom mb-1">Сума передоплати</label>
      <div class="input-group input-group-modern" :class="{ 'is-invalid': errors.prepay_amount }">
        <input 
          class="form-control border-0 fw-bold" 
          v-model.number="model.prepay_amount" 
          type="number" 
          step="0.01" 
          placeholder="0.00"
        />
        <span class="input-group-text bg-transparent border-0 text-muted">{{ currency }}</span>
      </div>
      <div class="invalid-feedback d-block" v-if="errors.prepay_amount">{{ errors.prepay_amount }}</div>
      
      <div class="d-flex align-items-start gap-2 mt-2 text-muted small bg-light p-2 rounded">
        <i class="bi bi-info-circle mt-1"></i>
        <span>Вкажіть суму, яку клієнт вже сплатив. Залишок буде оформлено як накладений платіж.</span>
      </div>
    </div>

  </div>
</template>

<script setup>
const props = defineProps({
  currency: { type: String, default: 'UAH' },
  errors: { type: Object, default: () => ({}) },
});

// Використовуємо defineModel для двостороннього зв'язку (Vue 3.4+)
// Це автоматично оновлює form.payment у батьківському компоненті
const model = defineModel({ type: Object, default: () => ({ method: 'cod', prepay_amount: 0 }) });

const methods = [
  { value: 'cod', label: 'Накладений платіж', icon: 'bi-cash-stack', desc: 'Оплата при отриманні' },
  { value: 'card', label: 'На рахунок IBAN', icon: 'bi-bank', desc: 'Повна передоплата' },
  { value: 'prepay', label: 'Часткова передоплата', icon: 'bi-wallet2', desc: 'Аванс + накладений' },
];
</script>

<style scoped>
/* Payment Option Card */
.payment-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  cursor: pointer;
  background: #fff;
  transition: all 0.2s ease;
  user-select: none;
}

.payment-option:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

.payment-option.active {
  border-color: #6366f1;
  background: #eff6ff; /* Light indigo tint */
  box-shadow: 0 0 0 1px #6366f1;
}

/* Icon Circle */
.icon-circle {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  transition: all 0.2s;
}

/* Checkmark Animation */
.check-icon {
  opacity: 0;
  transform: scale(0.5);
  transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  font-size: 1.2rem;
}

.payment-option.active .check-icon {
  opacity: 1;
  transform: scale(1);
}

/* Prepay Section */
.prepay-section {
  animation: slideDown 0.3s ease-out;
  padding-top: 8px;
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Modern Input */
.form-label-custom {
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.input-group-modern {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background-color: #fcfcfc;
  overflow: hidden;
  transition: all 0.2s;
}

.input-group-modern:focus-within {
  border-color: #6366f1;
  background-color: #fff;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
}

.input-group-modern.is-invalid {
  border-color: #dc3545;
}
</style>