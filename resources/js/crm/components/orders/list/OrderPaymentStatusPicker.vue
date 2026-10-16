<script setup>
import { computed, ref, watch } from 'vue';
import { updateOrderPaymentStatus } from '@/crm/api/orders';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { getPaymentClass, getPaymentIcon, paymentLabels } from '@/crm/utils/orderDisplay';
import { paymentSummary } from '@/crm/utils/orderPayment';

const props = defineProps({ order: { type: Object, required: true } });
const statuses = ['unpaid', 'prepayment', 'paid', 'refund'];
const normalize = (status) => status === 'prepaid' ? 'prepayment' : status || '';
const selectedStatus = ref(normalize(props.order.payment_status));
const saving = ref(false);
const failed = ref(false);
const { toast, showToast, closeToast, runAction } = useToast();
const currentStatus = computed(() => normalize(props.order.payment_status));

watch(currentStatus, (status) => { selectedStatus.value = status; });

async function saveStatus(status) {
  if (saving.value || !statuses.includes(status) || status === currentStatus.value) return;
  saving.value = true;
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
  }
}
</script>

<template>
  <div class="payment-status-picker" @click.stop @keydown.stop>
    <div class="payment-status-control" :class="[getPaymentClass(currentStatus), { 'has-error': failed }]">
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
.payment-status-picker { flex-shrink: 0; }
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
