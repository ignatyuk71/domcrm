<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import ExpenseDialog from './ExpenseDialog.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { fetchExpenseBalance, topUpExpenseBalance, expenseError } from '@/crm/services/expensesApi';
import { minor, money, requestKey } from './expenseUtils';

const props = defineProps({ accounts: { type: Array, required: true }, refreshKey: { type: Number, default: 0 } });
const emit = defineEmits(['updated']);
const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const report = ref(null), loading = ref(true), saving = ref(false), adding = ref(false), errors = ref({}), formElement = ref(null);
const form = reactive({ amount: '', account_id: '', note: '' });
const sources = computed(() => props.accounts.filter(account => account.system_key !== 'balance'));
const balance = computed(() => report.value?.balance);
const defaultSource = () => sources.value.find(account => account.name === 'Каса')?.id || sources.value[0]?.id || '';
const dirty = computed(() => !!form.amount || !!form.note.trim() || String(form.account_id) !== String(defaultSource()));
let key, sequence = 0, disposed = false;
function applyReport(data) { report.value = data; emit('updated', data.balance); }
async function load() {
  const request = ++sequence; loading.value = true;
  try {
    const { data } = await fetchExpenseBalance();
    if (!disposed && request === sequence) applyReport(data);
  } catch (error) {
    if (!disposed && request === sequence) showToast({ type: 'error', title: 'Не вдалося оновити баланс', messages: [expenseError(error)], actionLabel: 'Повторити', onAction: load });
  } finally { if (!disposed && request === sequence) loading.value = false; }
}
function openTopUp() {
  form.amount = ''; form.account_id = defaultSource(); form.note = ''; errors.value = {}; key = requestKey(); adding.value = true;
}
function closeTopUp() {
  if (saving.value || (dirty.value && !window.confirm('Закрити форму й відкинути незбережене поповнення?'))) return;
  adding.value = false;
}
async function save() {
  if (saving.value) return;
  const amount = minor(form.amount); errors.value = {};
  if (amount === null || amount <= 0n || amount > 999999999n) errors.value.amount = ['Вкажіть додатну суму до 9 999 999,99 грн, до двох знаків після коми.'];
  if (!form.account_id) errors.value.account_id = ['Оберіть, куди надійшли гроші.'];
  if (Object.keys(errors.value).length) {
    showToast({ type: 'error', title: 'Перевірте дані', messages: Object.values(errors.value).flat(), actionLabel: 'Виправити', onAction: () => formElement.value?.querySelector('[aria-invalid="true"]')?.focus() });
    return;
  }
  saving.value = true;
  try {
    const { data } = await topUpExpenseBalance({ amount: String(form.amount).replace(',', '.'), account_id: form.account_id, note: form.note.trim() || null, idempotency_key: key });
    // Підтверджений залишок беремо з відповіді: повторний GET не повторює поповнення.
    ++sequence; applyReport(data); loading.value = false; adding.value = false;
    closeToast(); showToast({ type: 'success', title: 'Баланс поповнено', messages: [`Зараховано ${money(form.amount.replace(',', '.'))}.`] });
  } catch (error) {
    errors.value = error?.response?.data?.errors || {};
    showToast({ type: 'error', title: 'Не вдалося поповнити баланс', messages: [error?.response?.status === 409 ? error.response.data.message : expenseError(error)],
      ...(error?.response?.status !== 409 ? { actionLabel: 'Повторити', onAction: save } : {}) });
  } finally { saving.value = false; }
}
function beforeUnload(event) { if (adding.value && (dirty.value || saving.value)) { event.preventDefault(); event.returnValue = ''; } }
watch(() => props.refreshKey, () => load());
onMounted(() => { load(); window.addEventListener('beforeunload', beforeUnload); });
onBeforeUnmount(() => { disposed = true; ++sequence; window.removeEventListener('beforeunload', beforeUnload); });
</script>

<template>
  <section class="expense-card expense-balance" aria-label="Баланс коштів для витрат" :aria-busy="loading">
    <div class="expense-balance-overview">
      <div class="expense-balance-heading"><h2><i class="bi bi-wallet2" aria-hidden="true"></i> Баланс</h2><button class="expense-button primary" :disabled="!balance || saving" @click="openTopUp"><i class="bi bi-plus-lg" aria-hidden="true"></i> Поповнити баланс</button></div>
      <span class="expense-balance-caption">Доступно для оплат</span><strong class="expense-balance-amount">{{ balance ? money(balance.amount) : '—' }}</strong>
      <div class="expense-balance-totals"><div><span>Надійшло</span><strong>{{ balance ? money(balance.incoming) : '—' }}</strong></div><div><span>Витрачено</span><strong>{{ balance ? money(balance.spent) : '—' }}</strong></div></div>
      <p class="expense-balance-help">У витраті виберіть джерело оплати «Баланс». Кошти спишуться після збереження оплати.</p>
    </div>
  </section>
  <ExpenseDialog v-if="adding" title="Поповнення балансу" :busy="saving" @close="closeTopUp">
    <form ref="formElement" class="expense-form expense-balance-form" @submit.prevent="save">
      <fieldset class="expense-fields" :disabled="saving">
        <label>Сума, грн <input v-model="form.amount" name="balance_amount" inputmode="decimal" required placeholder="0,00" :aria-invalid="!!errors.amount"></label>
        <label>Куди надійшли гроші <select v-model="form.account_id" name="balance_account_id" required :aria-invalid="!!errors.account_id"><option value="" disabled>Оберіть джерело</option><option v-for="source in sources" :key="source.id" :value="source.id">{{ source.name }}</option></select></label>
        <label>Коментар <textarea v-model="form.note" name="balance_note" rows="3" maxlength="5000" placeholder="Від кого або за що отримані гроші" :aria-invalid="!!errors.note"></textarea><small>Коментар необов’язковий</small></label>
      </fieldset>
      <footer class="expense-form-footer"><button type="button" class="expense-button" :disabled="saving" @click="closeTopUp">Скасувати</button><button type="submit" class="expense-button primary" :disabled="saving">{{ saving ? 'Збереження…' : 'Додати на баланс' }}</button></footer>
    </form>
  </ExpenseDialog>
  <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
</template>
