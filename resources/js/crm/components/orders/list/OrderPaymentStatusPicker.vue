<script setup>
import { computed, ref, watch } from 'vue';
import { updateOrderPaymentStatus } from '@/crm/api/orders';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { getPaymentClass, getPaymentIcon, paymentLabels } from '@/crm/utils/orderDisplay';
import { paymentSummary } from '@/crm/utils/orderPayment';

const props = defineProps({
  order: { type: Object, required: true },
  variant: { type: String, default: 'select', validator: (value) => ['select', 'buttons'].includes(value) },
});
const statuses = ['unpaid', 'prepayment', 'paid', 'refund'];
const normalize = (status) => status === 'prepaid' ? 'prepayment' : status || '';
const selectedStatus = ref(normalize(props.order.payment_status));
const saving = ref(false);
const requestedStatus = ref(null);
const failed = ref(false);
const { toast, showToast, closeToast, runAction } = useToast();
const currentStatus = computed(() => normalize(props.order.payment_status));

watch(currentStatus, (status) => { selectedStatus.value = status; });

async function saveStatus(status) {
  if (saving.value || !statuses.includes(status) || status === currentStatus.value) return;
  saving.value = true;
  requestedStatus.value = status;
  const order = props.order;

  try {
    const { data } = await updateOrderPaymentStatus(order.id, status);
    const saved = data?.data;
    if (saved?.payment_status !== status || !Object.hasOwn(saved, 'payment')) throw new Error('Некоректна відповідь сервера.');

    // Перераховуємо підсумок із серверного платежу, а не зі старої суми у списку.
    const updated = {
      ...order,
      payment_status: saved.payment_status,
      payment: saved.payment,
      paid_amount: saved.payment?.paid_amount ?? null,
    };
    const confirmed = {
      payment_status: saved.payment_status,
      payment_status_label: paymentLabels[saved.payment_status],
      payment: saved.payment,
      ...paymentSummary(updated),
    };
    Object.assign(order, confirmed);
    if (props.order !== order && String(props.order.id) === String(order.id)) Object.assign(props.order, confirmed);
    failed.value = false;
    showToast({
      type: 'success', title: 'Статус оплати збережено',
      messages: [`Замовлення №${order.order_number || order.id}: ${paymentLabels[saved.payment_status]}.`],
    });
  } catch (error) {
    failed.value = true;
    const details = error.response?.data;
    const messages = Object.values(details?.errors || {}).flat();
    if (!messages.length) messages.push(details?.message || 'Не вдалося зберегти зміну. Перевірте з’єднання та повторіть спробу.');
    showToast({
      type: 'error', title: 'Статус оплати не збережено',
      messages: [`Замовлення №${order.order_number || order.id}, вибрано «${paymentLabels[status]}».`, ...messages],
      actionLabel: 'Повторити', onAction: () => saveStatus(status),
    });
  } finally {
    // До підтвердження сервера в замовленні залишається попередній статус.
    selectedStatus.value = currentStatus.value;
    saving.value = false;
    requestedStatus.value = null;
  }
}
</script>

<template>
  <div class="payment-status-picker" @click.stop @keydown.stop>
    <div
      v-if="variant === 'buttons'"
      class="payment-status-buttons"
      :class="{ 'has-error': failed }"
      role="group"
      aria-label="Статус оплати"
      :aria-invalid="failed || undefined"
    >
      <span class="payment-status-label" aria-hidden="true">Оплата:</span>
      <button
        v-for="status in statuses"
        :key="status"
        type="button"
        class="payment-status-button"
        :class="currentStatus === status ? ['is-active', getPaymentClass(status)] : []"
        :data-status="status"
        :aria-pressed="currentStatus === status"
        :disabled="saving"
        @click="saveStatus(status)"
      >
        <span
          v-if="saving && requestedStatus === status"
          class="spinner-border spinner-border-sm payment-button-icon"
          role="status"
          aria-label="Збереження статусу оплати"
        ></span>
        <i v-else class="bi payment-button-icon" :class="getPaymentIcon(status)" aria-hidden="true"></i>
        <span>{{ paymentLabels[status] }}</span>
      </button>
    </div>
    <div v-else class="payment-status-control" :class="[getPaymentClass(currentStatus), { 'has-error': failed }]">
      <span v-if="saving" class="spinner-border spinner-border-sm payment-status-icon" role="status" aria-label="Збереження статусу оплати"></span>
      <i v-else class="bi payment-status-icon" :class="getPaymentIcon(currentStatus)" aria-hidden="true"></i>
      <select
        v-model="selectedStatus"
        class="payment-pill"
        :class="getPaymentClass(currentStatus)"
        aria-label="Статус оплати"
        :aria-invalid="failed || undefined"
        :disabled="saving"
        @change="saveStatus(selectedStatus)"
      >
        <option v-if="!statuses.includes(selectedStatus)" value="" disabled>Не вказано</option>
        <option v-for="status in statuses" :key="status" :value="status">{{ paymentLabels[status] }}</option>
      </select>
      <i class="bi bi-chevron-down payment-status-arrow" aria-hidden="true"></i>
    </div>
    <Toast v-bind="toast" @close="closeToast" @action="runAction" />
  </div>
</template>

<style scoped>
.payment-status-picker { flex-shrink: 0; min-width: 0; }
.payment-status-buttons { display: flex; align-items: center; gap: 6px; max-width: 100%; padding: 0 2px; border-radius: 7px; overflow-x: auto; scrollbar-width: thin; }
.payment-status-label { margin-right: 2px; color: #64748b; font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
.payment-status-button { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; gap: 6px; height: 28px; padding: 0 9px; border: 1px solid #d8e1ec; border-radius: 7px; background: #fff; color: #475569; font: inherit; font-size: 0.78rem; font-weight: 600; line-height: 1; white-space: nowrap; cursor: pointer; transition: border-color 0.15s, background-color 0.15s; }
.payment-status-button:not(.is-active):hover { border-color: #94a3b8; background-color: #f8fafc; }
.payment-status-button:focus-visible { outline: 2px solid #3b82f6; outline-offset: -2px; }
.payment-status-button:disabled { cursor: wait; }
.payment-status-button.is-active[data-status="refund"] { background-color: #f3e8ff !important; border-color: #d8b4fe !important; color: #6b21a8 !important; }
.payment-button-icon { flex-shrink: 0; width: 14px; height: 14px; font-size: 14px; }
.payment-button-icon.bi { display: inline-flex; align-items: center; justify-content: center; }
.payment-status-control { position: relative; display: inline-flex; align-items: center; border-radius: 999px; }
.payment-pill { appearance: none; min-height: 28px; width: 160px; padding: 4px 28px 4px 30px; border: 1px solid #e2e8f0; border-radius: inherit; background-color: #f1f5f9; color: #475569; font: inherit; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
.payment-pill:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
.payment-pill:disabled { cursor: wait; opacity: 0.7; }
.payment-pill option { background: #fff; color: #212529; }
.payment-status-icon, .payment-status-arrow { position: absolute; pointer-events: none; font-size: 0.8rem; }
.payment-status-icon { left: 10px; width: 13px; height: 13px; }
.payment-status-arrow { right: 10px; font-size: 0.65rem; }
.has-error { outline: 2px solid #dc3545; outline-offset: 2px; }
</style>
