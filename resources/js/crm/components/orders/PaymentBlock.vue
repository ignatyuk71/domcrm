<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column gap-2">
      <label
        v-for="m in methods"
        :key="m.value"
        class="d-flex align-items-center gap-3 p-3 border rounded cursor-pointer transition-all position-relative"
        :class="local.method === m.value ? 'border-primary bg-primary-subtle text-primary' : 'border-light-subtle hover-bg-light text-dark'"
        style="cursor: pointer;"
      >
        <div class="form-check mb-0">
          <input class="form-check-input" type="radio" :value="m.value" v-model="local.method" />
        </div>
        <i :class="`bi ${m.icon} fs-5`"></i>
        <span class="fw-medium">{{ m.label }}</span>
      </label>
    </div>

    <div v-if="local.method === 'prepay'" class="p-3 bg-light rounded border">
      <div>
        <label class="form-label small fw-bold text-muted text-uppercase">Сума передоплати</label>
        <div class="input-group">
          <input 
            class="form-control" 
            :class="{ 'is-invalid': errors.prepay_amount }"
            v-model.number="local.prepay_amount" 
            type="number" 
            step="0.01" 
            placeholder="0.00"
          />
          <span class="input-group-text">{{ currency }}</span>
          <div class="invalid-feedback" v-if="errors.prepay_amount">{{ errors.prepay_amount }}</div>
        </div>
        <div class="form-text mt-2">
          Вкажи суму, яку клієнт вже оплатив.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, watch } from 'vue';

const props = defineProps({
  currency: { type: String, default: 'UAH' },
  errors: { type: Object, default: () => ({}) },
});

const model = defineModel({ type: Object, default: () => ({}) });
const local = reactive({ method: 'cod', currency: 'UAH', ...model.value });

const methods = [
  { value: 'cod', label: 'Накладений платіж', icon: 'bi-cash-coin' },
  { value: 'card', label: 'Банківська картка', icon: 'bi-credit-card-2-front' },
  { value: 'transfer', label: 'Банківський переказ', icon: 'bi-bank' },
  { value: 'prepay', label: 'Передоплата', icon: 'bi-wallet2' },
];

watch(
  () => ({ ...local }),
  (val) => { model.value = val; },
  { deep: true }
);
</script>

<style scoped>
.transition-all { transition: all 0.2s ease-in-out; }
.hover-bg-light:hover { background-color: #f8f9fa; border-color: #dee2e6 !important; }
</style>
