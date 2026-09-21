<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import ExpenseDialog from './ExpenseDialog.vue';
import Toast from '@/crm/components/ui/Toast.vue';
import { useToast } from '@/crm/composables/useToast';
import { createExpense, updateExpense, createExpensePayment, updateExpensePayment, uploadExpenseReceipts, createExpenseDictionary, fetchExpense, expenseError } from '@/crm/services/expensesApi';
import { money, minor, requestKey } from './expenseUtils';

const props = defineProps({ mode: { type: String, required: true }, meta: { type: Object, required: true }, expense: Object, payment: Object });
const emit = defineEmits(['close', 'saved', 'changed', 'dictionary']);
const { toast, showToast, closeToast, runAction, runSecondary } = useToast();
const currentExpense = ref(props.expense), savedDetail = ref(null), savedPaymentId = ref(null);
const busy = ref(false), errors = ref({}), files = ref([]), fileInput = ref(null), formElement = ref(null), conflict = ref(false);
const key = requestKey();
const paymentMode = computed(() => ['add-payment', 'edit-payment'].includes(props.mode));
const withPayment = computed(() => props.mode === 'new-paid' || paymentMode.value);
const title = computed(() => ({ 'new-paid': 'Додати оплату', 'new-plan': 'Запланувати витрату', 'edit-expense': 'Редагувати витрату', 'add-payment': 'Внести оплату', 'edit-payment': 'Редагувати оплату' }[props.mode]));
const form = reactive({
  title: props.expense?.title || '', recipient: props.expense?.recipient || '', category_id: props.expense?.category_id || '',
  group_id: props.expense?.group_id || '', account_id: props.payment?.account?.id || props.expense?.account_id || '',
  amount: props.payment?.amount || (props.mode === 'add-payment' ? props.expense?.remaining_amount : props.expense?.amount) || '',
  currency: props.expense?.currency || 'UAH', expected_exchange_rate: props.expense?.expected_exchange_rate || '1',
  exchange_rate: props.payment?.exchange_rate || props.expense?.expected_exchange_rate || '1',
  due_on: props.expense?.due_on || props.meta.today, paid_on: props.payment?.paid_on || props.meta.today,
  note: props.payment?.note || (paymentMode.value ? '' : props.expense?.note) || '',
});
const baseline = JSON.stringify(form);
const dictionary = reactive({ kind: '', name: '', color: '#6954df', note: '' });
const dirty = computed(() => JSON.stringify(form) !== baseline || files.value.length > 0 || !!dictionary.name);
const hasPaid = computed(() => Number(currentExpense.value?.paid_amount || 0) > 0);
const existingReceiptCount = computed(() => props.payment?.receipts?.length || 0);
const fieldError = name => errors.value[name] || errors.value[`payment.${name}`];
const fileError = computed(() => Object.keys(errors.value).some(name => name.startsWith('files')));
const maximumPayment = computed(() => {
  if (!paymentMode.value) return null;
  const remaining = minor(currentExpense.value?.remaining_amount) || 0n;
  const existing = props.mode === 'edit-payment' ? minor(currentExpense.value?.payments?.find(item => item.id === props.payment.id)?.amount || props.payment.amount) || 0n : 0n;
  return remaining + existing;
});
watch(() => form.currency, currency => { if (currency === 'UAH') { form.expected_exchange_rate = '1'; form.exchange_rate = '1'; } });
function fail(error, retry = save, heading = 'Не вдалося зберегти') {
  errors.value = error?.response?.data?.errors || errors.value;
  conflict.value = (error?.response?.status === 409 && !!currentExpense.value && !error?.response?.data?.message?.includes('ключ')) || conflict.value;
  showToast({ type: 'error', title: heading, messages: [error?.response?.status === 409 && !conflict.value ? (error.response.data?.message || 'Не вдалося повторити створення. Перевірте попередню оплату в реєстрі, перш ніж створювати нову.') : expenseError(error)], actionLabel: conflict.value ? 'Оновити версію' : 'Повторити', onAction: conflict.value ? refreshVersion : retry });
}
function validation(messages, fields = {}) {
  errors.value = fields;
  showToast({ type: 'error', title: 'Перевірте дані', messages, actionLabel: 'Виправити', onAction: () => formElement.value?.querySelector('[aria-invalid="true"]')?.focus() });
}
function selectFiles(event) {
  const selected = [...event.target.files];
  if (selected.length + files.value.length + existingReceiptCount.value > 10) {
    validation(['До однієї оплати можна додати не більше 10 квитанцій.'], { files: ['Забагато файлів.'] });
    event.target.value = ''; return;
  }
  const invalid = selected.filter(file => !['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024 || file.size === 0);
  if (invalid.length) {
    validation(['Оберіть фото JPG, PNG, WebP або PDF розміром до 10 МБ: ' + invalid.map(file => file.name).join(', ')], { files: ['Непідтримуваний файл.'] });
    event.target.value = ''; return;
  }
  files.value.push(...selected); event.target.value = '';
}
async function addDictionary() {
  if (!dictionary.name.trim() || busy.value) return;
  busy.value = true;
  try {
    const { data } = await createExpenseDictionary(dictionary.kind, { name: dictionary.name.trim(), ...(dictionary.kind === 'categories' ? { color: dictionary.color } : {}), ...(dictionary.kind === 'groups' ? { note: dictionary.note || null } : {}) });
    emit('dictionary', dictionary.kind, data.data);
    form[{ categories: 'category_id', accounts: 'account_id', groups: 'group_id' }[dictionary.kind]] = data.data.id;
    dictionary.kind = ''; dictionary.name = ''; dictionary.note = '';
  } catch (error) { fail(error, addDictionary, 'Не вдалося додати довідник'); }
  finally { busy.value = false; }
}
async function refreshVersion() {
  if (!currentExpense.value || busy.value) return;
  busy.value = true;
  try {
    const { data } = await fetchExpense(currentExpense.value.id);
    currentExpense.value = data.data; conflict.value = false;
    showToast({ type: 'info', title: 'Версію оновлено', messages: ['Ваші введені дані залишилися у формі. Перевірте суми перед повторним збереженням.'], duration: 0 });
  } catch (error) { fail(error, refreshVersion, 'Не вдалося оновити версію'); }
  finally { busy.value = false; }
}
async function save() {
  if (busy.value) return;
  if (!savedDetail.value) {
    const amount = minor(form.amount), fields = {};
    if (amount === null || amount <= 0n) fields.amount = ['Введіть додатну суму, не більше двох знаків після коми.'];
    if (!paymentMode.value && !form.title.trim()) fields.title = ['Вкажіть, за що платите.'];
    if (!paymentMode.value && !form.category_id) fields.category_id = ['Оберіть категорію.'];
    if (withPayment.value && !form.account_id) fields.account_id = ['Оберіть джерело оплати.'];
    if (withPayment.value && (!form.paid_on || form.paid_on > props.meta.today)) fields.paid_on = ['Дата оплати не може бути в майбутньому.'];
    if (!paymentMode.value && !form.due_on) fields.due_on = ['Вкажіть дату витрати.'];
    if (maximumPayment.value !== null && amount > maximumPayment.value) fields.amount = ['Сума перевищує неоплачений залишок.'];
    const rate = String(withPayment.value ? form.exchange_rate : form.expected_exchange_rate).replace(',', '.');
    if (!/^\d+(\.\d{1,6})?$/.test(String(rate)) || Number(rate) <= 0) fields[withPayment.value ? 'exchange_rate' : 'expected_exchange_rate'] = ['Введіть курс до гривні, до шести знаків після коми.'];
    if (Object.keys(fields).length) { validation(Object.values(fields).flat(), fields); return; }
    if (formElement.value && !formElement.value.reportValidity()) return;
  }
  busy.value = true; errors.value = {};
  try {
    // Після підтвердження сервера повторюємо лише вкладення, не створення оплати.
    if (!savedDetail.value) {
      const payment = { amount: String(form.amount).replace(',', '.'), account_id: form.account_id, paid_on: form.paid_on, exchange_rate: form.currency === 'UAH' ? '1' : String(form.exchange_rate).replace(',', '.'), note: form.note || null };
      let response;
      if (paymentMode.value) {
        const payload = { ...payment, version: currentExpense.value.version };
        response = props.mode === 'edit-payment'
          ? await updateExpensePayment(currentExpense.value.id, props.payment.id, payload)
          : await createExpensePayment(currentExpense.value.id, { ...payload, idempotency_key: key });
      } else {
        const payload = {
          title: form.title.trim(), recipient: form.recipient.trim() || null, category_id: form.category_id, group_id: form.group_id || null,
          account_id: form.account_id || null, amount: String(form.amount).replace(',', '.'), currency: form.currency,
          expected_exchange_rate: form.currency === 'UAH' ? '1' : String(withPayment.value ? form.exchange_rate : form.expected_exchange_rate).replace(',', '.'),
          due_on: props.mode === 'new-paid' ? form.paid_on : form.due_on, note: form.note || null,
        };
        response = props.mode === 'edit-expense'
          ? await updateExpense(currentExpense.value.id, { ...payload, version: currentExpense.value.version })
          : await createExpense({ ...payload, idempotency_key: key, ...(withPayment.value ? { payment } : {}) });
      }
      savedDetail.value = response.data.data;
      const oldIds = new Set(currentExpense.value?.payments?.map(item => item.id) || []);
      savedPaymentId.value = props.payment?.id || savedDetail.value.payments?.find(item => !oldIds.has(item.id))?.id;
      emit('changed', savedDetail.value);
    }
    if (files.value.length) {
      if (!savedPaymentId.value) throw new Error('Оплату збережено, але не знайдено її ідентифікатор для квитанцій. Відкрийте деталі витрати.');
      const result = await uploadExpenseReceipts(savedDetail.value.id, savedPaymentId.value, files.value);
      savedDetail.value = result.data.data;
      files.value = [];
    }
    closeToast(); emit('saved', savedDetail.value);
  } catch (error) {
    fail(error, save, savedDetail.value ? 'Оплату збережено, квитанції не завантажено' : 'Не вдалося зберегти');
  } finally { busy.value = false; }
}
function close() {
  if (busy.value) return;
  const message = savedDetail.value
    ? 'Оплату вже збережено. Закрити без завантаження вибраних квитанцій?'
    : 'Закрити форму й відкинути незбережені зміни?';
  if (dirty.value && !window.confirm(message)) return;
  emit('close');
}
function beforeUnload(event) { if (dirty.value || busy.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
</script>

<template>
  <ExpenseDialog :title="title" :busy="busy" @close="close">
    <form ref="formElement" class="expense-form" @submit.prevent="save">
      <p v-if="paymentMode" class="expense-form-context"><strong>{{ expense.title }}</strong><span>Залишилося: {{ money(currentExpense.remaining_amount, currentExpense.currency) }}</span></p>
      <fieldset :disabled="busy || !!savedDetail" class="expense-fields">
        <template v-if="!paymentMode">
          <label class="span-2">За що платимо <input v-model="form.title" name="title" maxlength="255" required placeholder="Наприклад, реклама Meta за вересень" :aria-invalid="!!fieldError('title')"></label>
          <label>Отримувач <input v-model="form.recipient" name="recipient" maxlength="255" placeholder="Компанія або людина" :aria-invalid="!!fieldError('recipient')"></label>
          <label>Категорія <span class="expense-select-with-action"><select v-model="form.category_id" name="category_id" required :aria-invalid="!!fieldError('category_id')"><option value="" disabled>Оберіть категорію</option><option v-for="item in meta.categories" :key="item.id" :value="item.id">{{ item.name }}</option></select><button type="button" class="expense-mini-add" aria-label="Створити категорію" @click="dictionary.kind = 'categories'">+</button></span></label>
          <label class="span-2">Група платежів <span class="expense-select-with-action"><select v-model="form.group_id" name="group_id" :aria-invalid="!!fieldError('group_id')"><option value="">Без групи</option><option v-for="item in meta.groups" :key="item.id" :value="item.id">{{ item.name }}</option></select><button type="button" class="expense-mini-add" aria-label="Створити групу" @click="dictionary.kind = 'groups'">+</button></span></label>
        </template>
        <label>{{ paymentMode ? 'Сума цієї оплати' : 'Сума' }} <input v-model="form.amount" name="amount" inputmode="decimal" required placeholder="0.00" :aria-invalid="!!fieldError('amount')"></label>
        <label>Валюта <select v-model="form.currency" name="currency" :disabled="paymentMode || hasPaid" :aria-invalid="!!fieldError('currency')"><option v-for="currency in meta.currencies" :key="currency">{{ currency }}</option></select></label>
        <label v-if="form.currency !== 'UAH'" class="span-2">{{ withPayment ? 'Курс оплати до гривні' : 'Очікуваний курс до гривні' }} <input v-if="withPayment" v-model="form.exchange_rate" name="exchange_rate" inputmode="decimal" required :aria-invalid="!!fieldError('exchange_rate')"><input v-else v-model="form.expected_exchange_rate" name="expected_exchange_rate" inputmode="decimal" required :aria-invalid="!!fieldError('expected_exchange_rate')"><small>Скільки гривень за 1 {{ form.currency }}. Курс вводиться вручну.</small></label>
        <label>{{ withPayment ? 'Звідки оплачено' : 'Очікуване джерело' }} <span class="expense-select-with-action"><select v-model="form.account_id" name="account_id" :required="withPayment" :aria-invalid="!!fieldError('account_id')"><option value="">{{ withPayment ? 'Оберіть рахунок' : 'Ще не визначено' }}</option><option v-for="item in meta.accounts" :key="item.id" :value="item.id">{{ item.name }}</option></select><button type="button" class="expense-mini-add" aria-label="Створити рахунок" @click="dictionary.kind = 'accounts'">+</button></span></label>
        <label v-if="withPayment">Дата оплати <input v-model="form.paid_on" name="paid_on" type="date" :max="meta.today" required :aria-invalid="!!fieldError('paid_on')"></label>
        <label v-else>Оплатити до <input v-model="form.due_on" name="due_on" type="date" required :aria-invalid="!!fieldError('due_on')"></label>
        <label class="span-2">Коментар <textarea v-model="form.note" name="note" rows="3" maxlength="5000" placeholder="Деталі, які допоможуть згадати цю витрату" :aria-invalid="!!fieldError('note')"></textarea></label>
      </fieldset>
      <section v-if="dictionary.kind" class="expense-dictionary" aria-label="Новий запис довідника">
        <strong>{{ { categories: 'Нова категорія', accounts: 'Новий рахунок', groups: 'Нова група' }[dictionary.kind] }}</strong>
        <label>Назва <input v-model="dictionary.name" maxlength="255" :disabled="busy" placeholder="Введіть назву" @keydown.enter.prevent="addDictionary"></label>
        <label v-if="dictionary.kind === 'categories'">Колір <input v-model="dictionary.color" type="color" :disabled="busy"></label>
        <label v-if="dictionary.kind === 'groups'">Опис <input v-model="dictionary.note" maxlength="5000" :disabled="busy"></label>
        <div class="expense-button-row"><button type="button" class="expense-button" :disabled="busy" @click="dictionary.kind = ''; dictionary.name = ''">Скасувати</button><button type="button" class="expense-button primary" :disabled="busy || !dictionary.name.trim()" @click="addDictionary">Додати</button></div>
      </section>
      <section v-if="withPayment" class="expense-receipt-upload" :class="{ invalid: fileError }">
        <div><strong>Квитанції до оплати</strong><small>Фото JPG, PNG, WebP або PDF · до 10 МБ · до 10 файлів</small></div>
        <input ref="fileInput" class="expense-file-input" tabindex="-1" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="busy" aria-label="Прикріпити квитанції" :aria-invalid="fileError" @change="selectFiles">
        <button type="button" class="expense-button" :disabled="busy" @click="fileInput.click()"><i class="bi bi-paperclip" aria-hidden="true"></i> Прикріпити файли</button>
        <ul v-if="files.length" class="expense-file-list"><li v-for="(file, index) in files" :key="index"><i class="bi bi-file-earmark" aria-hidden="true"></i><span>{{ file.name }}</span><button type="button" :disabled="busy" :aria-label="'Прибрати ' + file.name" @click="files.splice(index, 1)">×</button></li></ul>
      </section>
      <footer class="expense-form-footer"><button type="button" class="expense-button" :disabled="busy" @click="close">{{ savedDetail ? 'Закрити' : 'Скасувати' }}</button><button v-if="conflict" type="button" class="expense-button" :disabled="busy" @click="refreshVersion">Оновити версію</button><button type="submit" class="expense-button primary" :disabled="busy || !!dictionary.kind">{{ busy ? 'Збереження…' : savedDetail ? (files.length ? 'Повторити завантаження' : 'Готово') : 'Зберегти' }}</button></footer>
    </form>
  </ExpenseDialog>
  <Toast v-bind="toast" @close="closeToast" @action="runAction" @secondary="runSecondary" />
</template>
