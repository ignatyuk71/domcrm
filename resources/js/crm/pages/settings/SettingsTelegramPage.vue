<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const options = [
  { key: 'manual_test', icon: 'bi-send-check', title: 'Тестові повідомлення', text: 'Перевірка зв’язку за вашим натисканням кнопки.' },
  { key: 'warehouse_reminder', icon: 'bi-hourglass-split', title: 'Зберігання у відділенні', text: 'Щодня о 10:00 за Києвом: спільний список замовлень на 5–7-й день від прибуття у відділення. День прибуття — перший.', planned: false },
  { key: 'new_order', icon: 'bi-bag-plus', title: 'Нові замовлення', text: 'Сповіщати команду про нове замовлення в CRM.', planned: true },
  { key: 'return_alert', icon: 'bi-arrow-return-left', title: 'Повернення посилок', text: 'Повідомляти про повернення, які потребують уваги.', planned: true },
];
const saved = ref(null);
const loading = ref(true);
const busy = ref('');
const error = ref('');
const errors = ref({});
const notice = ref('');
const reveal = ref(false);
const credentials = reactive({ bot_token: '', chat_id: '' });
const form = reactive({ enabled: false, permissions: Object.fromEntries(options.map(item => [item.key, false])) });
const connected = computed(() => Boolean(saved.value?.verified_at));
const connectionDirty = computed(() => Boolean(credentials.bot_token) || credentials.chat_id !== (saved.value?.chat_id || ''));
const dirty = computed(() => Boolean(saved.value) && (form.enabled !== saved.value.enabled || options.some(item => form.permissions[item.key] !== saved.value.permissions[item.key])));
const canTest = computed(() => connected.value && saved.value?.enabled && saved.value?.permissions.manual_test && !dirty.value && !connectionDirty.value && !busy.value);
const stateLabel = computed(() => !connected.value ? 'Очікує підключення' : saved.value.enabled ? 'Підключення увімкнено' : 'Надсилання на паузі');
const timestamp = value => value ? new Intl.DateTimeFormat('uk-UA', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Kyiv' }).format(new Date(value)) : 'Ще не перевіряли';

function accept(data) {
  saved.value = data;
  form.enabled = data.enabled;
  Object.assign(form.permissions, data.permissions);
}

async function perform(name, action, message) {
  if (busy.value) return;
  busy.value = name;
  error.value = '';
  errors.value = {};
  notice.value = '';
  try {
    await action();
    notice.value = message;
  } catch (e) {
    errors.value = e.response?.data?.errors || {};
    error.value = Object.values(errors.value).flat()[0] || e.response?.data?.message || 'Не вдалося виконати дію. Спробуйте ще раз.';
  } finally {
    busy.value = '';
  }
}

async function load() {
  loading.value = true;
  await perform('load', async () => {
    const { data } = await http.get('/settings/telegram');
    accept(data);
    credentials.chat_id = data.chat_id;
  }, '');
  loading.value = false;
}

function connect() {
  return perform('connect', async () => {
    const selectedPermissions = { ...form.permissions };
    const selectedEnabled = form.enabled;
    const changingConnection = connectionDirty.value;
    const { data } = await http.post('/settings/telegram/connect', credentials);
    accept(data);
    Object.assign(form.permissions, selectedPermissions);
    if (!changingConnection) form.enabled = selectedEnabled;
    credentials.bot_token = '';
    credentials.chat_id = data.chat_id;
    reveal.value = false;
  }, 'Бота та групу перевірено. Підключення збережено.');
}

function save() {
  return perform('save', async () => {
    const { data } = await http.put('/settings/telegram', form);
    accept(data);
  }, 'Налаштування надсилання збережено.');
}

function test() {
  if (!canTest.value) return;
  return perform('test', async () => {
    const { data } = await http.post('/settings/telegram/test');
    accept(data);
  }, 'Тестове повідомлення успішно надіслано в робочу групу.');
}

function reset() {
  accept(saved.value);
  credentials.bot_token = '';
  credentials.chat_id = saved.value.chat_id;
  reveal.value = false;
  error.value = ''; errors.value = {}; notice.value = '';
}

onMounted(load);
</script>

<template>
  <main class="telegram-settings" :aria-busy="loading || Boolean(busy)">
    <header class="page-heading">
      <div>
        <div class="eyebrow">Налаштування <span>/</span> Комунікація</div>
        <h1>Telegram</h1>
        <p>Потрібні повідомлення — одразу у вашій робочій групі.</p>
      </div>
      <span class="state-pill" :class="{ active: connected && saved?.enabled }"><span class="state-dot"></span>{{ stateLabel }}</span>
    </header>

    <div v-if="error" class="feedback is-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ error }}</div>
    <div v-if="notice" class="feedback is-success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ notice }}</div>
    <div v-if="loading" class="loading-panel" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Завантажуємо налаштування…</div>
    <button v-else-if="!saved" type="button" class="btn btn-primary" @click="load">Повторити завантаження</button>

    <template v-else>
      <section class="connection-banner" aria-labelledby="connection-title">
        <div class="telegram-logo"><i class="bi bi-telegram" aria-hidden="true"></i></div>
        <div class="banner-copy">
          <h2 id="connection-title">Підключення Telegram</h2>
          <p>{{ connected ? 'Керуйте надсиланням у групу одним перемикачем.' : 'Підключіть бота до групи, щоб налаштувати надсилання.' }}</p>
        </div>
        <label class="master-toggle form-check form-switch">
          <span>{{ form.enabled ? 'Увімкнено' : 'Вимкнено' }}</span>
          <input v-model="form.enabled" class="form-check-input" type="checkbox" role="switch" aria-label="Підключення Telegram" :disabled="!connected || Boolean(busy)" />
        </label>
      </section>

      <div class="settings-grid">
        <div class="main-column">
          <section class="settings-card" aria-labelledby="bot-heading">
            <div class="section-heading"><span class="step-number">01</span><div><h2 id="bot-heading">Бот і робоча група</h2><p>Вкажіть, хто надсилатиме повідомлення та куди.</p></div></div>
            <form @submit.prevent="connect">
              <fieldset :disabled="Boolean(busy)">
                <label for="telegram-token" class="field-label">Токен бота <span v-if="saved.has_token" class="saved-label"><i class="bi bi-shield-check" aria-hidden="true"></i> Збережено</span></label>
                <div class="token-field">
                  <input id="telegram-token" v-model.trim="credentials.bot_token" class="form-control" :class="{ 'is-invalid': errors.bot_token }" :type="reveal ? 'text' : 'password'" :placeholder="saved.has_token ? 'Токен захищено. Вставте новий лише для заміни' : 'Вставте токен із BotFather'" autocomplete="new-password" spellcheck="false" :aria-invalid="Boolean(errors.bot_token)" aria-describedby="token-help" />
                  <button type="button" class="reveal-button" :aria-label="reveal ? 'Приховати новий токен' : 'Показати введений токен'" :aria-pressed="reveal" :disabled="!credentials.bot_token" @click="reveal = !reveal"><i class="bi" :class="reveal ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i></button>
                </div>
                <p id="token-help" class="field-help">Ключ із <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer">BotFather <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>. Після збереження він прихований і зашифрований.</p>

                <label for="telegram-chat" class="field-label group-label">ID робочої групи</label>
                <div class="group-input"><i class="bi bi-people" aria-hidden="true"></i><input id="telegram-chat" v-model.trim="credentials.chat_id" class="form-control" :class="{ 'is-invalid': errors.chat_id }" type="text" placeholder="Наприклад, -1001234567890" spellcheck="false" :aria-invalid="Boolean(errors.chat_id)" aria-describedby="chat-help" required /></div>
                <p id="chat-help" class="field-help">Числовий ID групи зі знаком мінус. Бот має бути її учасником.</p>

                <div v-if="connected" class="verified-identity">
                  <span class="identity-icon"><i class="bi bi-check2" aria-hidden="true"></i></span>
                  <div><strong>{{ saved.bot_name }}</strong><span>@{{ saved.bot_username }} <span class="identity-arrow">→</span> {{ saved.chat_title }}</span></div>
                </div>
                <div class="connect-actions">
                  <button class="btn btn-connect" type="submit" :disabled="!credentials.chat_id || (!credentials.bot_token && !saved.has_token)"><span v-if="busy === 'connect'" class="spinner-border spinner-border-sm" aria-hidden="true"></span><i v-else class="bi bi-link-45deg" aria-hidden="true"></i>{{ busy === 'connect' ? 'Перевіряємо…' : connected ? 'Перевірити й зберегти' : 'Підключити бота' }}</button>
                  <span>Перевірка не надсилає повідомлень у групу.</span>
                </div>
              </fieldset>
            </form>
          </section>

          <section class="settings-card permissions-card" aria-labelledby="permissions-heading">
            <div class="section-heading"><span class="step-number">02</span><div><h2 id="permissions-heading">Що CRM може надсилати</h2><p>Дозвольте тільки ті повідомлення, які потрібні команді.</p></div></div>
            <div class="permissions-list">
              <label v-for="option in options" :key="option.key" class="permission-row" :for="`permission-${option.key}`">
                <span class="permission-icon"><i class="bi" :class="option.icon" aria-hidden="true"></i></span>
                <span class="permission-copy"><span class="permission-title">{{ option.title }}</span><span class="permission-description">{{ option.text }}</span><span v-if="option.planned" class="planned-label">Сценарій ще не активований</span></span>
                <span class="form-check form-switch"><input :id="`permission-${option.key}`" v-model="form.permissions[option.key]" class="form-check-input" type="checkbox" role="switch" :disabled="Boolean(busy)" /></span>
              </label>
            </div>
            <div class="permissions-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Нагадування про зберігання працюють за увімкненого підключення та дозволу. Використовується дата входу у відділення з історії CRM; посилки без дати або без свіжого трекінгу пропускаються.</span></div>
          </section>
        </div>

        <aside class="side-column">
          <section class="preview-card" aria-labelledby="preview-heading">
            <div class="preview-heading"><i class="bi bi-chat-left-text" aria-hidden="true"></i><h2 id="preview-heading">Як це побачить команда</h2></div>
            <div class="chat-preview">
              <div class="chat-header"><span class="group-avatar"><i class="bi bi-people-fill" aria-hidden="true"></i></span><div><strong>{{ saved.chat_title || 'Ваша робоча група' }}</strong><span>Повідомлення від CRM</span></div></div>
              <div class="chat-body">
                <span class="example-label">Приклад майбутнього нагадування</span>
                <div class="message-bubble"><span class="bot-label">{{ saved.bot_name || 'Ваш бот' }}</span><strong>📦 Посилка очікує на клієнта</strong><p>П’ятий день зберігання у відділенні.</p><div class="sample-details"><span>Замовлення <b>№1234</b></span><span>Клієнт <b>Ім’я клієнта</b></span></div><div class="sample-task">Передзвоніть і уточніть, коли клієнт забере посилку.</div><span class="message-time">09:00</span></div>
                <div class="preview-link">Відкрити замовлення <i class="bi bi-arrow-up-right" aria-hidden="true"></i></div>
              </div>
            </div>
            <p class="preview-caption">Усі дозволені повідомлення надходитимуть у вибрану групу.</p>
          </section>

          <section class="settings-card test-card" aria-labelledby="test-heading">
            <h2 id="test-heading">Перевірка зв’язку</h2>
            <p>Надішліть одне тестове повідомлення, щоб перевірити доставку в групу.</p>
            <button class="btn btn-test" type="button" :disabled="!canTest" @click="test"><span v-if="busy === 'test'" class="spinner-border spinner-border-sm" aria-hidden="true"></span><i v-else class="bi bi-send" aria-hidden="true"></i>{{ busy === 'test' ? 'Надсилаємо…' : 'Надіслати тест у групу' }}</button>
            <p v-if="!canTest && !busy" class="field-help">{{ dirty || connectionDirty ? 'Спочатку збережіть зміни.' : 'Увімкніть підключення та дозвіл на тести й збережіть налаштування.' }}</p>
            <dl class="connection-facts"><div><dt>З’єднання перевірено</dt><dd>{{ timestamp(saved.verified_at) }}</dd></div><div><dt>Останній успішний тест</dt><dd>{{ saved.last_test_at ? timestamp(saved.last_test_at) : 'Ще не надсилали' }}</dd></div></dl>
          </section>
        </aside>
      </div>

      <footer class="save-bar">
        <span><i class="bi" :class="dirty || connectionDirty ? 'bi-pencil-square' : 'bi-check2-circle'" aria-hidden="true"></i>{{ dirty || connectionDirty ? 'Є незбережені зміни' : 'Усі зміни збережено' }}</span>
        <div><button class="btn btn-cancel" type="button" :disabled="Boolean(busy) || (!dirty && !connectionDirty)" @click="reset">Скасувати зміни</button><button class="btn btn-save" type="button" :disabled="Boolean(busy) || !dirty || connectionDirty" @click="save"><span v-if="busy === 'save'" class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ busy === 'save' ? 'Зберігаємо…' : 'Зберегти налаштування' }}</button></div>
      </footer>
    </template>
  </main>
</template>

<style scoped>
.telegram-settings { max-width: 1240px; margin: 0 auto; padding: 30px 26px 40px; color: #1e293b; }
.page-heading { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
.eyebrow { color: #64748b; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 10px; }
.eyebrow span { color: #cbd5e1; margin: 0 9px; }
h1 { font-size: 32px; font-weight: 750; letter-spacing: -.8px; margin: 0 0 8px; }
.page-heading p { margin: 0; font-size: 14px; color: #64748b; }
.state-pill { display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0; border: 1px solid #e2e8f0; background: #fff; padding: 8px 12px; border-radius: 24px; color: #64748b; font-size: 12px; font-weight: 600; }
.state-dot { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
.state-pill.active { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }.active .state-dot { background: #16a34a; }
.connection-banner { display: flex; align-items: center; gap: 16px; padding: 22px 26px; margin-bottom: 24px; background: linear-gradient(110deg, #f0f9ff, #fff 72%); border: 1px solid #dcebf7; border-radius: 16px; }
.telegram-logo { display: grid; place-items: center; width: 52px; height: 52px; flex-shrink: 0; border-radius: 15px; background: #229ed9; color: #fff; font-size: 27px; box-shadow: 0 5px 12px #229ed91a; }
h2 { margin: 0; font-size: 16px; font-weight: 700; letter-spacing: -.2px; }
.banner-copy { flex: 1; }.banner-copy p { margin: 6px 0 0; color: #64748b; font-size: 13px; }
.form-switch { display: flex; align-items: center; min-height: 0; padding: 0; margin: 0; }
.form-switch .form-check-input { float: none; width: 38px; height: 22px; margin: 0; flex-shrink: 0; cursor: pointer; box-shadow: none; border-color: #cbd5e1; }
.form-switch .form-check-input:checked { background-color: #6366f1; border-color: #6366f1; }
.form-check-input:focus-visible { outline: 3px solid #c7d2fe; outline-offset: 3px; }.form-check-input:disabled { cursor: not-allowed; }
.master-toggle { gap: 14px; font-size: 12px; font-weight: 600; }.master-toggle .form-check-input { width: 44px; height: 25px; }
.settings-grid { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr); gap: 24px; align-items: start; }
.main-column, .side-column { display: grid; gap: 24px; min-width: 0; }
.settings-card, .preview-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 3px 10px #0f172a03; }
.settings-card { padding: 24px; }.section-heading { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 24px; }
.step-number { display: grid; place-items: center; width: 29px; height: 29px; border-radius: 8px; color: #6366f1; background: #eef2ff; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.section-heading p { font-size: 12px; line-height: 1.6; color: #64748b; margin: 5px 0 0; }
fieldset { min-width: 0; }.field-label { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 12px; font-weight: 650; margin-bottom: 8px; }
.saved-label { color: #15803d; font-size: 11px; font-weight: 500; }.group-label { margin-top: 22px; }
.form-control { min-height: 44px; border: 1px solid #dbe2ec; border-radius: 9px; background: #fff; color: #334155; padding: 10px 12px; font-size: 13px; }
.form-control:focus { border-color: #818cf8; box-shadow: 0 0 0 3px #6366f112; }.form-control::placeholder { color: #94a3b8; font-size: 12px; }
.token-field, .group-input { position: relative; }.token-field .form-control { padding-right: 43px; }
.reveal-button { position: absolute; right: 7px; top: 6px; width: 32px; height: 32px; border: 0; background: transparent; color: #64748b; border-radius: 6px; }.reveal-button:hover:not(:disabled) { background: #f1f5f9; }.reveal-button:disabled { opacity: .4; }
.field-help { margin: 8px 0 0; color: #64748b; font-size: 11px; line-height: 1.6; }.field-help a { color: #4f46e5; text-decoration: none; }
.group-input > i { position: absolute; left: 13px; top: 12px; font-size: 14px; color: #94a3b8; }.group-input input { padding-left: 36px; font-variant-numeric: tabular-nums; }
.verified-identity { display: flex; align-items: center; gap: 10px; padding: 13px; margin-top: 20px; border-radius: 10px; background: #f8fafc; }
.identity-icon { display: grid; place-items: center; width: 30px; height: 30px; flex-shrink: 0; border-radius: 50%; background: #dcfce7; color: #15803d; }
.verified-identity strong { display: block; font-size: 12px; }.verified-identity div > span { display: block; font-size: 11px; color: #64748b; margin-top: 3px; overflow-wrap: anywhere; }.identity-arrow { padding: 0 4px; color: #94a3b8; }
.connect-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 20px; }.connect-actions > span { font-size: 10px; color: #64748b; }
.btn { display: inline-flex; justify-content: center; align-items: center; gap: 7px; min-height: 40px; font-size: 12px; font-weight: 600; border-radius: 9px; padding: 9px 14px; }.btn:focus-visible { outline: 3px solid #c7d2fe; outline-offset: 2px; }
.btn-connect { background: #eef2ff; color: #4f46e5; border: 1px solid #e0e7ff; }.btn-connect:hover { background: #e0e7ff; color: #4338ca; }
.permissions-card { padding-bottom: 18px; }.permissions-card .section-heading { margin-bottom: 10px; }
.permission-row { display: flex; gap: 12px; align-items: center; padding: 18px 0; cursor: pointer; border-bottom: 1px solid #f1f5f9; }.permission-row:last-child { border-bottom: 0; }
.permission-icon { align-self: flex-start; display: grid; place-items: center; width: 34px; height: 34px; flex-shrink: 0; border-radius: 10px; background: #f8fafc; color: #64748b; font-size: 16px; }
.permission-copy { flex: 1; min-width: 0; }.permission-title, .permission-description { display: block; }.permission-title { font-size: 13px; font-weight: 600; }.permission-description { font-size: 11px; color: #64748b; line-height: 1.6; margin-top: 4px; }
.planned-label { display: inline-block; color: #78716c; background: #f5f5f4; padding: 2px 6px; border-radius: 4px; font-size: 9px; margin-top: 6px; }
.permissions-note { display: flex; gap: 8px; padding: 12px; margin-top: 6px; border-radius: 8px; background: #f8fafc; color: #64748b; font-size: 11px; line-height: 1.6; }.permissions-note i { flex-shrink: 0; }
.preview-card { overflow: hidden; }.preview-heading { display: flex; align-items: center; gap: 8px; padding: 20px; color: #64748b; }.preview-heading h2 { color: #334155; font-size: 13px; }
.chat-preview { margin: 0 16px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }.chat-header { display: flex; align-items: center; gap: 10px; padding: 13px; background: #fff; }
.group-avatar { display: grid; place-items: center; width: 34px; height: 34px; flex-shrink: 0; background: #e0f2fe; color: #0284c7; border-radius: 50%; }
.chat-header strong, .chat-header div > span { display: block; }.chat-header strong { font-size: 12px; }.chat-header div > span { color: #94a3b8; font-size: 10px; margin-top: 2px; }
.chat-body { padding: 16px 13px 20px; background: #eaf2f4; }.example-label { display: block; width: fit-content; margin: 0 auto 15px; padding: 4px 8px; border-radius: 20px; background: #d5e3e8; color: #526875; font-size: 9px; text-align: center; }
.message-bubble { position: relative; background: #fff; border-radius: 10px 10px 10px 2px; padding: 13px 13px 25px; box-shadow: 0 2px 4px #0f172a08; font-size: 12px; line-height: 1.6; }.bot-label { display: block; color: #0284c7; font-size: 11px; font-weight: 700; margin-bottom: 7px; }.message-bubble > strong { font-size: 12px; }.message-bubble p { margin: 6px 0 12px; color: #475569; }
.sample-details { display: grid; gap: 4px; font-size: 11px; color: #64748b; }.sample-details b { color: #334155; font-weight: 500; margin-left: 5px; }.sample-task { margin-top: 13px; padding-left: 9px; border-left: 2px solid #38bdf8; color: #334155; font-size: 11px; }.message-time { position: absolute; bottom: 7px; right: 11px; color: #94a3b8; font-size: 9px; }
.preview-link { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 5px; background: #d4e3e9; color: #365b70; border-radius: 7px; font-size: 11px; font-weight: 600; padding: 10px; }.preview-caption { padding: 0 22px; color: #94a3b8; font-size: 10px; line-height: 1.6; margin: 14px 0 18px; }
.test-card > p { font-size: 12px; color: #64748b; line-height: 1.7; margin: 9px 0 15px; }.btn-test { width: 100%; color: #4f46e5; border: 1px solid #e0e7ff; background: #f8faff; }.btn-test:hover { background: #eef2ff; color: #4338ca; }
.connection-facts { border-top: 1px solid #f1f5f9; margin: 18px 0 0; padding-top: 16px; display: grid; gap: 10px; }.connection-facts div { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 5px; }.connection-facts dt { font-size: 10px; color: #94a3b8; font-weight: 400; }.connection-facts dd { margin: 0; font-size: 10px; color: #64748b; }
.save-bar { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-top: 24px; padding: 18px 22px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }.save-bar > span { display: flex; align-items: center; gap: 7px; color: #64748b; font-size: 11px; }.save-bar > div { display: flex; gap: 10px; }.btn-cancel { color: #64748b; }.btn-save { background: #6366f1; border-color: #6366f1; color: #fff; }.btn-save:hover { background: #4f46e5; color: #fff; }
.feedback { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding: 13px 16px; border: 1px solid; border-radius: 10px; font-size: 13px; }.is-error { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }.is-success { color: #15803d; background: #f0fdf4; border-color: #bbf7d0; }.loading-panel { padding: 50px; text-align: center; color: #64748b; }
@media (max-width: 1050px) { .settings-grid { grid-template-columns: minmax(0, 1.3fr) minmax(280px, 1fr); gap: 18px; }.settings-card { padding: 20px; }.telegram-settings { padding: 24px 18px; } }
@media (max-width: 800px) { .settings-grid { grid-template-columns: 1fr; }.side-column { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }.connection-banner { flex-wrap: wrap; }.master-toggle { margin-left: auto; }.page-heading { align-items: flex-start; flex-direction: column; gap: 14px; } }
@media (max-width: 560px) { .telegram-settings { padding: 20px 12px; }h1 { font-size: 28px; }.side-column { grid-template-columns: 1fr; }.connection-banner { padding: 18px; }.banner-copy { min-width: 180px; }.save-bar { flex-direction: column; align-items: stretch; padding: 16px; }.save-bar > div { flex-direction: column-reverse; }.settings-card { padding: 18px; } }
</style>
