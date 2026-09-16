<template>
  <section class="sole-page" :aria-busy="loading">
    <header class="sole-heading">
      <div><h1>Запас підошви</h1><p>Ви вносите отримані партії. CRM рахує витрату, залишок і час до наступного замовлення.</p></div>
      <button class="btn sole-button" :disabled="loading || saving" aria-label="Оновити розрахунок" @click="load"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
    </header>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
    <p v-if="loading && !report" class="sole-empty" role="status">Рахуємо відправлені пари…</p>
    <template v-if="report">
      <div v-if="report.missing_categories.length" class="alert alert-warning">Не знайдено категорії CRM: {{ report.missing_categories.join(', ') }}.</div>
      <nav class="sole-categories" aria-label="Категорія підошви">
        <button v-for="item in report.categories" :key="item.id" type="button" :class="{ active: category?.id === item.id }" :aria-pressed="category?.id === item.id" @click="activeId = item.id">{{ item.name }}</button>
      </nav>
      <template v-if="category">
        <div class="sole-category-heading">
          <p v-if="category.counting_from">Облік від <strong>{{ date(category.counting_from) }}</strong> · {{ category.batches.length }} отриманих партій</p>
          <p v-else>Отримані партії ще не внесені</p>
          <div class="sole-actions">
            <button class="btn sole-button" :disabled="saving || loading" @click="openPlan"><i class="bi bi-sliders" aria-hidden="true"></i> Прогноз</button>
            <button class="btn btn-primary" :disabled="saving || loading" @click="openBatch()"><i class="bi bi-plus-lg" aria-hidden="true"></i> Додати партію</button>
          </div>
        </div>

        <form v-if="editor?.categoryId === category.id" class="sole-card sole-editor" @submit.prevent="save">
          <fieldset :disabled="saving">
            <legend>{{ editor.type === 'plan' ? 'Параметри прогнозу' : editor.batchId ? 'Редагувати отриману партію' : 'Нова отримана партія' }}</legend>
            <template v-if="editor.type === 'batch'">
              <p>Вкажіть дату приїзду та <strong>скільки пар підошви отримали</strong> кожного розміру. Залишок рахувати чи вписувати не потрібно.</p>
              <div class="sole-form-grid">
                <label>Дата приїзду партії<input v-model="form.received_on" type="date" class="form-control" required min="2020-01-01" :max="report.today" data-testid="received-on" /></label>
                <label>Примітка <span class="sole-optional">· необов’язково</span><input v-model="form.note" class="form-control" maxlength="500" placeholder="Наприклад, друга партія з Китаю" /></label>
              </div>
              <div class="sole-quantities">
                <label v-for="row in form.quantities" :key="row.size">Отримано {{ row.size }}<div class="input-group"><input v-model="row.quantity" type="number" class="form-control" required min="0" max="10000000" :data-size="row.size" /><span class="input-group-text">пар</span></div></label>
              </div>
              <p class="sole-hint">Якщо розміру не було в цій партії, залиште 0. Усього в партії: <strong>{{ number(batchTotal) }} пар</strong>.</p>
            </template>
            <template v-else>
              <p>Термін поставки потрібен лише для поради, коли замовляти. Залишок і приблизний час його витрати рахуються без нього.</p>
              <div class="sole-form-grid three">
                <label>Нова поставка з Китаю, днів<input v-model="form.lead_time_days" type="number" class="form-control" min="1" max="730" placeholder="Виробництво + доставка" data-testid="lead-time" /></label>
                <label>Додатковий запас часу, днів<input v-model="form.safety_days" type="number" class="form-control" required min="0" max="365" /></label>
                <label>Середня витрата за<select v-model="form.lookback_days" class="form-select"><option :value="0">Весь час від першої партії</option><option :value="30">Останні 30 повних днів</option><option :value="60">Останні 60 повних днів</option><option :value="90">Останні 90 повних днів</option></select></label>
              </div>
            </template>
            <div v-if="formError" class="alert alert-danger" role="alert">{{ formError }}</div>
            <div class="sole-actions"><button class="btn btn-primary" type="submit">{{ saving ? 'Зберігаємо…' : editor.type === 'batch' ? 'Зберегти партію' : 'Зберегти прогноз' }}</button><button class="btn sole-button" type="button" @click="editor = null">Скасувати</button></div>
          </fieldset>
        </form>

        <div v-if="!category.counting_from" class="sole-onboarding"><i class="bi bi-box-seam" aria-hidden="true"></i><div><strong>Додайте першу партію підошви</strong><p>Наприклад: приїхала 1 березня, отримали 2 000 пар. CRM сама відніме відправлені від цієї дати пари та покаже, скільки залишилося.</p></div></div>
        <div v-if="category.legacy_basis" class="alert alert-warning">Збережено раніше внесену базу обліку від {{ date(category.counting_from) }}. Вона враховується окремо від нових партій і не видається за закупівлю.</div>

        <section class="sole-summary" aria-label="Підсумок запасу категорії">
          <article><div><span>Отримано підошви</span><strong>{{ category.counting_from ? number(category.totals.received) : '—' }} <small>пар</small></strong></div><i class="bi bi-box-seam" aria-hidden="true"></i></article>
          <article><div><span>Витрачено за відправленнями</span><strong>{{ category.counting_from ? number(category.totals.consumed) : '—' }} <small>пар</small></strong></div><i class="bi bi-truck" aria-hidden="true"></i></article>
          <article class="remaining"><div><span>Залишилося підошви</span><strong>{{ category.counting_from ? number(category.totals.remaining) : '—' }} <small>пар</small></strong></div><i class="bi bi-layers" aria-hidden="true"></i></article>
          <article><div><span>Термін нової поставки</span><strong>{{ number(category.settings.lead_time_days) }} <small>днів</small></strong><button v-if="category.settings.lead_time_days === null" class="sole-link" @click="openPlan">Указати термін</button></div><i class="bi bi-calendar3" aria-hidden="true"></i></article>
        </section>

        <div v-if="category.next_order" class="sole-order-alert" :class="{ urgent: category.next_order.days_until_reorder === 0 }">
          <i class="bi bi-calendar-check" aria-hidden="true"></i>
          <div><strong>{{ category.next_order.days_until_reorder === 0 ? 'Нову партію варто замовляти вже зараз' : `Наступне замовлення — ${duration(category.next_order.days_until_reorder, true)}` }}</strong><p>Першим потребує поповнення розмір {{ category.next_order.size }}. Замовити до {{ date(category.next_order.reorder_date) }}, орієнтовне закінчення — {{ date(category.next_order.depletion_date) }}.</p></div>
        </div>
        <p v-if="category.counting_from" class="sole-rate-caption">{{ category.rate_days ? `Прогноз за середньою витратою з ${date(category.window_from)} по ${date(category.window_to)} · ${category.rate_days} повних днів` : 'Після першого повного дня відправлень з’явиться прогноз тривалості запасу.' }}</p>

        <section class="sole-sizes" aria-label="Залишки за розмірами">
          <article v-for="row in category.rows" :key="row.size" class="sole-size-card">
            <header><h2>{{ row.size }}</h2><span class="sole-status" :class="`sole-status-${row.status}`">{{ statusLabels[row.status] }}</span></header>
            <div class="sole-size-metrics"><div><span>Отримано</span><strong>{{ row.tracked ? number(row.received_quantity) : '—' }}</strong></div><div><span>Витрачено</span><strong>{{ row.tracked ? number(row.consumed) : '—' }}</strong></div><div><span>Залишок</span><strong :class="{ negative: row.remaining < 0 }">{{ number(row.remaining) }}</strong></div></div>
            <div class="sole-progress" role="progressbar" :aria-label="`Залишок розміру ${row.size}`" :aria-valuenow="row.remaining_percent" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: `${row.remaining_percent}%` }"></span></div>
            <div class="sole-forecast"><span>Вистачить приблизно на</span><strong>{{ duration(row.days_remaining) }}</strong></div>
            <dl class="sole-date-list"><div><dt>Середня витрата</dt><dd>{{ number(row.daily_rate) }} пар/день</dd></div><div><dt>Орієнтовно закінчиться</dt><dd>{{ date(row.depletion_date) }}</dd></div><div><dt>Замовляти</dt><dd>{{ row.days_until_reorder === 0 ? 'Зараз' : duration(row.days_until_reorder, true) }}<small v-if="row.reorder_date">до {{ date(row.reorder_date) }}</small></dd></div></dl>
            <small v-if="row.legacy_quantity !== null || row.adjustment_quantity" class="sole-legacy-note">Попередня база: {{ number(row.legacy_quantity ?? 0) }} пар; коригування: {{ number(row.adjustment_quantity) }} пар.</small>
          </article>
        </section>

        <div class="sole-content-grid">
          <section class="sole-card"><header class="sole-card-heading"><h2>Витрата по місяцях</h2><span>Відправлені пари з CRM</span></header><div class="sole-table-scroll"><table class="sole-table"><caption class="visually-hidden">Витрата підошви за місяцями та розмірами</caption><thead><tr><th scope="col">Місяць</th><th v-for="row in category.rows" :key="row.size" scope="col">{{ row.size }}</th><th scope="col">Разом</th></tr></thead><tbody><tr v-for="month in category.months" :key="month.month"><th scope="row">{{ monthLabel(month.month) }}</th><td v-for="row in category.rows" :key="row.size">{{ number(month.sizes[row.size]) }}</td><td><strong>{{ number(month.total) }}</strong></td></tr><tr v-if="!category.months.length"><td :colspan="category.rows.length + 2" class="sole-empty">Додайте партію, щоб розпочати розрахунок.</td></tr></tbody></table></div></section>
          <section class="sole-card"><header class="sole-card-heading"><h2>Отримані партії</h2><span>{{ category.batches.length }} партій</span></header><p v-if="!category.batches.length" class="sole-empty">Тут будуть дати та кількості ваших поставок.</p><ol v-else class="sole-batches"><li v-for="batch in category.batches" :key="batch.id"><div class="sole-batch-heading"><strong>{{ date(batch.received_on) }}</strong><span>{{ number(batch.quantities.reduce((sum, row) => sum + row.quantity, 0)) }} пар</span><button class="sole-link" :disabled="saving || loading" :aria-label="`Редагувати партію від ${date(batch.received_on)}`" @click="openBatch(batch)">Редагувати</button></div><div class="sole-batch-sizes"><span v-for="row in batch.quantities.filter(row => row.quantity > 0)" :key="row.size">{{ row.size }} · {{ number(row.quantity) }}</span></div><p v-if="batch.note">{{ batch.note }}</p></li></ol></section>
        </div>

        <details v-if="hasWarnings(category)" class="sole-quality"><summary>Є дані для перевірки</summary><p v-if="category.warnings.unknown_size_pairs">Не розпізнано розмір: {{ category.warnings.unknown_size_pairs }} пар. Вони не списані.</p><p v-if="category.warnings.missing_date_orders">Без дати відправлення: {{ category.warnings.missing_date_orders }} замовлень за всю історію — не враховані.</p><p v-if="category.warnings.duplicate_orders">Пропущено дублі ТТН: {{ category.warnings.duplicate_orders }}.</p><ul><li v-for="(issue, index) in category.issues" :key="index"><a :href="`/orders/${issue.order_id}`" target="_blank" rel="noopener">№ {{ issue.order_number }}</a> — {{ issue.message }}</li></ul></details>
        <p v-if="report.history_available_from && category.counting_from && report.history_available_from.slice(0, 10) > category.counting_from" class="sole-quality">Історія трекінгу починається {{ date(report.history_available_from.slice(0, 10)) }}, пізніше за першу партію. Старі відправлення можуть бути неповними — залишок може бути завищений.</p>
        <details class="sole-method"><summary>Як працює розрахунок</summary><p>Отримані партії − відправлені пари від дати першого приїзду включно = розрахунковий залишок. Кожна наступна партія додає запас, а витрата не починається заново. 1 пара капців = 1 пара підошви.</p><p>Використовуємо дані цих категорій у CRM, не з сайту-магазину. Створена ТТН і пакування не списують підошву; повернення готових капців не відновлює її. Одна ТТН рахується один раз. {{ category.warnings.approximate_date_pairs }} пар мають приблизну дату відправлення за пізнішим статусом; {{ category.warnings.without_ttn_orders }} замовлень враховано без ТТН за статусом CRM.</p><p>Середня витрата — за повні календарні дні від першої партії або за обраний недавній період. Сьогоднішні відправлення зменшують залишок, але не змінюють середній темп до завершення дня. Дата замовлення = приблизне закінчення − термін поставки − запас часу. Прогноз орієнтовний: сезонність, виробництво наперед і повторні відправлення готових повернень можуть впливати на нього.</p><p v-if="report.unlinked_item_count">У CRM є {{ report.unlinked_item_count }} позицій без пов’язаного товару за всю історію. Їх неможливо розподілити за категоріями, тому вони не враховані.</p></details>
      </template>
      <footer class="sole-footer">Оновлено {{ new Date(report.as_of).toLocaleString('uk-UA', { timeZone: 'Europe/Kyiv' }) }} · час Києва</footer>
    </template>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { fetchSoleInventory, saveSolePlan, addSoleBatch, updateSoleBatch, inventoryError } from '@/crm/services/soleInventoryApi';

const report = ref(null), activeId = ref(null), loading = ref(false), saving = ref(false), error = ref(''), notice = ref('');
const editor = ref(null), form = ref({}), formError = ref('');
const category = computed(() => report.value?.categories.find(item => item.id === activeId.value) || report.value?.categories[0]);
const batchTotal = computed(() => (form.value.quantities || []).reduce((sum, row) => sum + Number(row.quantity || 0), 0));
const statusLabels = { unconfigured: 'Додайте партію', not_received: 'Ще не надходив', depleted: 'Запас вичерпано', no_history: 'Немає темпу', missing_lead: 'Укажіть термін доставки', order_now: 'Замовляти зараз', sufficient: 'Запас є' };
const number = value => value === null || value === undefined ? '—' : Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 2 });
const date = value => value ? value.slice(0, 10).split('-').reverse().join('.') : '—';
const monthLabel = value => new Intl.DateTimeFormat('uk-UA', { month: 'long', year: 'numeric' }).format(new Date(`${value}-01T12:00:00`));
const hasWarnings = item => item.warnings.unknown_size_pairs || item.warnings.missing_date_orders || item.warnings.duplicate_orders;
function duration(days, until = false) {
  if (days === null || days === undefined) return '—';
  const label = days >= 30 ? `${number(Math.round(days / 30.44 * 10) / 10)} міс.` : `${number(days)} дн.`;
  return `${until ? 'через ' : ''}≈ ${label}`;
}
async function load() {
  if (loading.value) return;
  loading.value = true; error.value = '';
  try { report.value = (await fetchSoleInventory()).data; }
  catch (err) { error.value = `${inventoryError(err)}${report.value ? ' На екрані попередні дані.' : ''}`; }
  finally { loading.value = false; }
}
async function focusEditor() {
  await nextTick();
  document.querySelector('.sole-editor input')?.focus();
}
function openPlan() {
  editor.value = { categoryId: category.value.id, type: 'plan' }; formError.value = ''; notice.value = '';
  form.value = { ...category.value.settings };
  focusEditor();
}
function openBatch(batch = null) {
  editor.value = { categoryId: category.value.id, type: 'batch', batchId: batch?.id }; formError.value = ''; notice.value = '';
  form.value = { ...(batch ? { version: batch.version } : { request_key: crypto.randomUUID() }),
    received_on: batch?.received_on || report.value.today, note: batch?.note || '',
    quantities: category.value.rows.map(row => ({ size: row.size, quantity: batch?.quantities.find(item => item.size === row.size)?.quantity ?? 0 })) };
  focusEditor();
}
async function save() {
  if (saving.value) return;
  saving.value = true; formError.value = ''; notice.value = '';
  try {
    if (editor.value.type === 'plan') {
      await saveSolePlan(editor.value.categoryId, { ...form.value,
        lead_time_days: form.value.lead_time_days === '' || form.value.lead_time_days === null ? null : Number(form.value.lead_time_days),
        safety_days: Number(form.value.safety_days), lookback_days: Number(form.value.lookback_days) });
    } else {
      if (!batchTotal.value) { formError.value = 'Вкажіть кількість отриманих пар хоча б для одного розміру.'; return; }
      const payload = { ...form.value, note: form.value.note || null, quantities: form.value.quantities.map(row => ({ ...row, quantity: Number(row.quantity) })) };
      if (editor.value.batchId) await updateSoleBatch(editor.value.categoryId, editor.value.batchId, payload);
      else await addSoleBatch(editor.value.categoryId, payload);
    }
    editor.value = null; notice.value = 'Збережено. Залишок і прогноз перераховано автоматично.';
    await load();
    if (error.value) notice.value = 'Запис збережено. Натисніть «Оновити», щоб отримати новий розрахунок.';
  } catch (err) { formError.value = inventoryError(err); }
  finally { saving.value = false; }
}
onMounted(load);
</script>

<style scoped>
.sole-page{--ink:#182239;--muted:#718198;--border:#e5eaf2;color:var(--ink);max-width:1720px;margin:auto;font-size:14px}.sole-heading,.sole-category-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}.sole-heading h1{font-size:clamp(25px,3vw,34px);font-weight:800;letter-spacing:-.04em;margin:0 0 7px}.sole-heading p,.sole-category-heading p{color:var(--muted);margin:0}.sole-heading p{font-size:13px}.sole-button{border:1px solid var(--border);background:white;color:#475569;font-weight:650;border-radius:10px;padding:10px 14px;font-size:13px}.sole-button:hover{background:#f3f5fb;border-color:#c4cbea}.btn-primary{background:#574ce8;border-color:#574ce8;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:650}.btn i{margin-right:4px}.sole-heading>.btn i{margin:0}.sole-actions{display:flex;gap:8px;flex-shrink:0}.sole-categories{display:flex;gap:6px;padding:5px;background:#edf0f7;border-radius:12px;width:fit-content;max-width:100%;margin:0 0 24px}.sole-categories button{border:0;background:transparent;color:#637087;border-radius:8px;font-weight:650;padding:11px 18px;line-height:1.5;text-align:left}.sole-categories button.active{background:white;color:#4f46e5;box-shadow:0 2px 7px #2434510c}.sole-category-heading p{font-size:12px}.sole-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin:20px 0}.sole-summary article{display:flex;justify-content:space-between;gap:12px;background:white;border:1px solid var(--border);border-radius:14px;padding:20px}.sole-summary article>i{align-self:flex-start;display:grid;place-items:center;flex-shrink:0;background:#f2f3fd;color:#7066d8;border-radius:10px;width:36px;height:36px;font-size:16px}.sole-summary span{color:var(--muted);font-size:12px}.sole-summary strong{display:block;font-size:28px;font-weight:800;margin-top:8px;letter-spacing:-.03em}.sole-summary small{font-size:13px;font-weight:500;color:var(--muted)}.sole-summary .remaining{border-color:#c9e8e2;background:#f5fcfa}.sole-summary .remaining strong{color:#0f8c7d}.sole-summary .remaining>i{background:#e0f3ed;color:#0f8c7d}.sole-link{border:0;background:none;color:#6554d9;font-size:12px;padding:4px 0;text-align:left}.sole-onboarding,.sole-order-alert{display:flex;gap:14px;background:#f2f4ff;border:1px solid #dfe4fa;padding:18px 20px;border-radius:12px;color:#4b5c82;margin-bottom:20px}.sole-onboarding>i,.sole-order-alert>i{font-size:21px}.sole-onboarding p,.sole-order-alert p{font-size:12px;line-height:1.7;margin:5px 0 0}.sole-order-alert{background:#edf9f5;border-color:#d0eade;color:#276a59}.sole-order-alert.urgent{background:#fff7e8;border-color:#f3dfb5;color:#94631a}.sole-rate-caption{font-size:12px;color:var(--muted);margin:23px 0 12px}.sole-sizes{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:24px}.sole-size-card{background:white;border:1px solid var(--border);border-radius:15px;padding:22px}.sole-size-card header{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:24px}.sole-size-card h2{font-size:23px;font-weight:800;margin:0}.sole-status{font-size:10px;font-weight:750;padding:6px 8px;border-radius:7px;background:#f1f5f9;color:#64748b}.sole-status-sufficient{background:#ecfdf5;color:#047857}.sole-status-order_now,.sole-status-missing_lead{background:#fffbeb;color:#a16207}.sole-status-depleted{background:#fff1f2;color:#be123c}.sole-size-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.sole-size-metrics span{font-size:11px;color:var(--muted)}.sole-size-metrics strong{display:block;font-size:21px;font-weight:750;margin-top:4px}.sole-size-metrics>div:last-child strong{color:#0d9488}.sole-size-metrics .negative{color:#dc4560!important}.sole-progress{height:5px;background:#eef2f6;border-radius:5px;margin:18px 0 21px;overflow:hidden}.sole-progress span{display:block;height:100%;background:#29b5a3;border-radius:5px}.sole-forecast{padding:15px;background:#f7f9fc;border-radius:10px;margin-bottom:18px}.sole-forecast>span{display:block;color:var(--muted);font-size:11px}.sole-forecast>strong{display:block;font-size:26px;letter-spacing:-.02em;margin-top:5px}.sole-date-list{font-size:11px;margin:0}.sole-date-list>div{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-top:12px}.sole-date-list dt{font-weight:400;color:var(--muted)}.sole-date-list dd{text-align:right;margin:0;font-weight:650}.sole-date-list small{display:block;color:var(--muted);font-weight:400;margin-top:3px}.sole-legacy-note{display:block;margin-top:12px;color:#9b7529;font-size:11px}.sole-content-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:20px;margin-bottom:20px}.sole-card{background:white;border:1px solid var(--border);border-radius:15px;overflow:hidden}.sole-card-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:21px}.sole-card-heading h2{font-size:16px;font-weight:750;margin:0}.sole-card-heading>span{font-size:11px;color:var(--muted)}.sole-table-scroll{overflow:auto;max-height:480px}.sole-table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:12px}.sole-table th,.sole-table td{padding:13px 18px;border-bottom:1px solid #eef1f6;text-align:right}.sole-table th:first-child{text-align:left;font-weight:500;text-transform:capitalize}.sole-table thead{color:var(--muted);background:#f8fafc;font-size:11px;position:sticky;top:0}.sole-batches{list-style:none;margin:0;padding:0;max-height:480px;overflow:auto}.sole-batches li{border-top:1px solid #eef1f6;padding:16px 21px}.sole-batch-heading{display:flex;align-items:center;gap:12px;font-size:12px}.sole-batch-heading .sole-link{margin-left:auto}.sole-batch-heading>span{color:var(--muted)}.sole-batch-sizes{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.sole-batch-sizes span{font-size:11px;background:#f2f5fa;padding:4px 8px;border-radius:5px;color:#61718a}.sole-batches p{font-size:12px;color:var(--muted);margin:9px 0 0;overflow-wrap:anywhere}.sole-editor{padding:22px;margin-bottom:20px;border-color:#d3daf4;background:#fafbff}.sole-editor fieldset{min-width:0}.sole-editor legend{font-size:17px;font-weight:750}.sole-editor p{font-size:12px;color:#64748b;line-height:1.7}.sole-form-grid{display:grid;grid-template-columns:1fr 2fr;gap:16px;margin:18px 0}.sole-form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.sole-editor label{display:block;font-size:12px;font-weight:600;color:#63718a}.sole-editor .form-control,.sole-editor .form-select{border-color:#dbe3ef;border-radius:8px;min-height:43px;font-size:14px;color:#334155;background-color:white;margin-top:7px}.sole-optional{font-weight:400;color:#8b98ab}.sole-quantities{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0 10px}.sole-quantities .input-group{margin-top:7px}.sole-quantities .form-control{margin:0}.sole-quantities .input-group-text{font-size:12px;color:var(--muted);background:#f5f7fb;border-color:#dbe3ef;border-radius:0 8px 8px 0}.sole-editor .sole-hint{margin-bottom:20px}.sole-quality{padding:13px 17px;background:#fffbeb;color:#976a28;border-radius:10px;font-size:12px;line-height:1.7;margin-bottom:16px}.sole-quality p{margin:8px 0}.sole-quality summary,.sole-method summary{cursor:pointer;font-weight:650}.sole-quality ul{padding-left:18px}.sole-method{font-size:12px;color:#718198;line-height:1.8;padding:16px 2px}.sole-method p{margin:12px 0}.sole-footer{text-align:right;font-size:11px;color:var(--muted);margin:12px 0}.sole-empty{text-align:center;padding:28px;color:var(--muted);font-size:12px}button:focus-visible,summary:focus-visible{outline:3px solid #a5b4fc;outline-offset:3px}
@media(max-width:1150px){.sole-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.sole-content-grid{grid-template-columns:1fr}.sole-quantities{grid-template-columns:repeat(2,minmax(0,1fr))}.sole-sizes{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.sole-heading{align-items:flex-start}.sole-heading p{font-size:12px}.sole-categories{width:100%;gap:3px}.sole-categories button{padding:9px 10px;font-size:12px;flex:1}.sole-category-heading{align-items:flex-start;flex-direction:column;gap:12px}.sole-summary{gap:10px}.sole-summary article{padding:15px}.sole-summary article>i{display:none}.sole-summary strong{font-size:24px}.sole-sizes{grid-template-columns:1fr}.sole-form-grid,.sole-form-grid.three{grid-template-columns:1fr}.sole-editor{padding:17px}.sole-card-heading{padding:17px}.sole-card-heading>span{font-size:10px}.sole-onboarding,.sole-order-alert{padding:15px}.sole-forecast>strong{font-size:25px}.sole-batch-heading{flex-wrap:wrap}.sole-quantities{gap:12px}}
</style>
