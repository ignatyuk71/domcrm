<template>
  <div class="finance-page container-fluid py-4">
    <div class="header-section d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-5">
      <div>
        <h1 class="h3 fw-bold text-dark mb-1 d-flex align-items-center">
          <div class="icon-square bg-primary-subtle text-primary me-3">
            <i class="bi bi-wallet2"></i>
          </div>
          Фіскалізація (Checkbox)
        </h1>
        <p class="text-muted ms-5 ps-2 mb-0">Керування ПРРО, автоматизація змін та черга чеків.</p>
      </div>
      
      <div class="d-flex align-items-center mt-3 mt-md-0">
        <div class="status-badge px-3 py-2 rounded-pill d-flex align-items-center gap-2" :class="statusBadgeClass">
          <span class="pulse-dot" v-if="shiftStatus === 'opened'"></span>
          <i class="bi" :class="statusIcon" v-else></i>
          <span class="fw-semibold">{{ shiftBadge }}</span>
        </div>
      </div>
    </div>

    <transition name="slide-fade">
      <div v-if="notice.message" class="alert modern-alert mb-4 shadow-sm border-0 d-flex align-items-center" :class="`alert-${notice.type}`" role="alert">
        <i class="bi fs-4 me-3" :class="notice.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <div>{{ notice.message }}</div>
      </div>
    </transition>

    <div class="row g-4">
      <div class="col-lg-8">
        
        <div class="card modern-card mb-4">
          <div class="card-header-clean p-4 border-bottom border-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">Автоматизація та режим роботи</h5>
            <button class="btn btn-icon-round" type="button" @click="openAuthModal" aria-label="Налаштування">
              <i class="bi bi-gear-fill"></i>
            </button>
          </div>
          <div class="card-body p-4">
            <div class="row g-4 text-center text-md-start">
              <div class="col-md-4">
                <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-3">
                  <div class="icon-box-sm bg-success-subtle text-success rounded-circle">
                    <i class="bi bi-sun-fill"></i>
                  </div>
                  <div>
                    <div class="text-muted small text-uppercase fw-bold ls-1">Відкриття</div>
                    <div class="fs-5 fw-bold text-dark">{{ form.open_time }}</div>
                  </div>
                </div>
              </div>
              
              <div class="col-md-4">
                 <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-3">
                  <div class="icon-box-sm bg-info-subtle text-info rounded-circle">
                    <i class="bi bi-magic"></i>
                  </div>
                  <div>
                    <div class="text-muted small text-uppercase fw-bold ls-1">Авто-фіскалізація</div>
                    <div class="fs-5 fw-bold text-dark">{{ form.queue_process_time }}</div>
                  </div>
                </div>
              </div>

              <div class="col-md-4">
                 <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-3">
                  <div class="icon-box-sm bg-indigo-subtle text-indigo rounded-circle">
                    <i class="bi bi-moon-stars-fill"></i>
                  </div>
                  <div>
                    <div class="text-muted small text-uppercase fw-bold ls-1">Закриття</div>
                    <div class="fs-5 fw-bold text-dark">{{ form.close_time }}</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-4 pt-3 border-top border-light d-flex align-items-center gap-2">
               <span class="badge rounded-pill border" :class="form.enabled ? 'bg-success-subtle text-success border-success-subtle' : 'bg-light text-muted'">
                 <i class="bi me-1" :class="form.enabled ? 'bi-check-circle-fill' : 'bi-pause-circle'"></i>
                 Загальна автоматизація: {{ form.enabled ? 'УВІМКНЕНО' : 'ВИМКНЕНО' }}
               </span>
               <span class="badge rounded-pill border" :class="form.queue_enabled ? 'bg-primary-subtle text-primary border-primary-subtle' : 'bg-light text-muted'">
                 <i class="bi me-1" :class="form.queue_enabled ? 'bi-lightning-fill' : 'bi-pause-circle'"></i>
                 Черга: {{ form.queue_enabled ? 'АКТИВНА' : 'ЗУПИНЕНА' }}
               </span>
            </div>
          </div>
        </div>

        <div class="card modern-card h-100" style="min-height: 350px;">
           <div class="card-header-clean p-4 d-flex justify-content-between align-items-center">
            <div>
              <h5 class="mb-0 fw-bold text-dark">Активність за добу</h5>
              <small class="text-muted">Кількість фіскалізованих чеків по годинах</small>
            </div>
          </div>
          <div class="card-body p-2">
             <div v-if="loading.chart" class="d-flex justify-content-center align-items-center h-100 py-5">
                <div class="spinner-border text-primary" role="status"></div>
             </div>
             <ApexChart
               v-else
               type="area"
               height="300"
               :options="chartOptions"
               :series="chartSeries"
             />
          </div>
        </div>

      </div>

      <div class="col-lg-4">
        
        <div class="card modern-card mb-4 border-0 bg-gradient-primary text-white position-relative overflow-hidden shadow-lg">
          <div class="card-bg-circle-1"></div>
          <div class="card-bg-circle-2"></div>
          
          <div class="card-body position-relative z-1 p-4 d-flex flex-column justify-content-between" style="min-height: 280px;">
            <div>
              <div class="d-flex justify-content-between align-items-start mb-4">
                 <h6 class="text-white-50 text-uppercase ls-1 mb-0" style="font-size: 0.75rem;">Статус зміни</h6>
                 <div class="badge bg-white bg-opacity-25 backdrop-blur rounded-pill fw-normal px-3">Каса №1</div>
              </div>
              
              <div class="status-display mb-2">
                 <h2 class="display-6 fw-bold mb-1">{{ shiftBadge }}</h2>
                 <p class="text-white-50 small mb-0">{{ shiftMessage }}</p>
              </div>
            </div>

            <div class="d-grid gap-2 mt-4">
              <button class="btn btn-white text-primary fw-bold shadow-sm" @click="testConnection" :disabled="loading.test">
                 <span v-if="loading.test" class="spinner-border spinner-border-sm me-2"></span>
                 <i v-else class="bi bi-activity me-2"></i> Перевірити зв'язок
              </button>
              
              <div class="row g-2">
                <div class="col-6">
                   <button class="btn btn-success-soft w-100" @click="openShift" :disabled="shiftStatus === 'opened' || loading.shift">
                    <i class="bi bi-unlock me-1"></i> Відкрити
                  </button>
                </div>
                <div class="col-6">
                   <button class="btn btn-danger-soft w-100" @click="closeShift" :disabled="shiftStatus === 'closed' || loading.shift">
                    <i class="bi bi-lock me-1"></i> Закрити
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card modern-card border-0 shadow-sm">
          <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-4">
              <h6 class="fw-bold mb-0 text-dark">Черга фіскалізації</h6>
              <div class="icon-box bg-light text-primary rounded-circle p-2">
                <i class="bi bi-layers-fill"></i>
              </div>
            </div>
            
            <div class="text-center py-4 bg-light rounded-4 mb-3 position-relative overflow-hidden">
               <div class="position-absolute top-0 start-0 w-100 h-100" v-if="loading.queue">
                  <div class="d-flex h-100 justify-content-center align-items-center bg-light bg-opacity-75 backdrop-blur">
                    <div class="spinner-border text-primary" role="status"></div>
                  </div>
               </div>
              <div class="display-3 fw-bold text-dark lh-1">{{ queueCount }}</div>
              <p class="text-muted small mb-0 text-uppercase ls-1">чеків у черзі</p>
            </div>

            <button class="btn btn-outline-dark w-100 py-2 rounded-pill" @click="processQueue" :disabled="queueCount === 0 || loading.queue">
              <i class="bi bi-arrow-repeat me-2"></i> Обробити зараз
            </button>
            
            <div class="d-flex align-items-start gap-2 mt-4 text-muted small">
              <i class="bi bi-info-circle-fill text-primary mt-1"></i>
              <span style="font-size: 0.85rem">Чеки поза робочим часом накопичуються тут для подальшої відправки.</span>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="row g-4 mt-2">
      <div class="col-12">
        <div class="card modern-card">
          <div class="card-header-clean p-4 border-bottom border-light d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
              <h5 class="mb-1 fw-bold text-dark">Архів чеків за {{ receiptsDateLabel }}</h5>
              <div class="text-muted small">
                Сума надходжень за день:
                <span class="fw-semibold text-dark">{{ dailyTotalFormatted }}</span>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-light text-dark">Всього: {{ receiptsCount }}</span>
              <button class="btn btn-light btn-sm" type="button" @click="loadSettings" :disabled="loading.save || loading.queue">
                <i class="bi bi-arrow-repeat me-1"></i> Оновити
              </button>
            </div>
          </div>
          <div class="card-body p-0">
            <div v-if="!receiptsCount" class="p-4 text-muted">
              За сьогодні ще немає чеків.
            </div>
            <div v-else class="table-responsive">
              <table class="table table-hover align-middle mb-0 receipts-table">
                <thead class="table-light">
                  <tr>
                    <th>Час</th>
                    <th>Статус</th>
                    <th>Тип</th>
                    <th class="text-end">Сума</th>
                    <th class="text-end">Чек</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="receipt in receipts" :key="receipt.id">
                    <td class="text-muted small">{{ formatReceiptTime(receipt.created_at) }}</td>
                    <td>
                      <span class="badge" :class="receiptStatusClass(receipt.status)">
                        {{ receiptStatusLabel(receipt.status) }}
                      </span>
                    </td>
                    <td>{{ receiptTypeLabel(receipt.type) }}</td>
                    <td class="text-end fw-semibold">{{ formatReceiptAmount(receipt.total_amount) }}</td>
                    <td class="text-end">
                      <a
                        v-if="receipt.check_link"
                        :href="receipt.check_link"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-link btn-sm"
                      >Відкрити</a>
                      <span v-else class="text-muted">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <transition name="fade">
      <div v-if="showAuthModal" class="modal-backdrop" @click.self="closeAuthModal">
        <div class="modal-card">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0 fw-bold text-dark">Налаштування Checkbox</h5>
            <button type="button" class="btn btn-link text-muted p-0" @click="closeAuthModal">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="row g-3">
            
            <div class="col-12">
               <h6 class="text-uppercase text-muted small fw-bold mb-3 ls-1">Авторизація</h6>
            </div>

            <div class="col-12">
              <label class="form-label small fw-semibold text-secondary">Ліцензійний ключ</label>
              <div class="input-group-modern" :class="{'is-valid': hasLicenseKey}">
                <span class="input-icon"><i class="bi bi-key"></i></span>
                <input v-model="form.license_key" type="password" class="form-control"
                       :placeholder="hasLicenseKey ? '•••••••• (встановлено)' : 'Введіть ключ ліцензії'">
                <span v-if="hasLicenseKey" class="valid-icon"><i class="bi bi-check-circle-fill text-success"></i></span>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Логін касира</label>
              <div class="input-group-modern">
                <span class="input-icon"><i class="bi bi-person"></i></span>
                <input v-model="form.login" type="text" class="form-control" placeholder="login">
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Пароль касира</label>
              <div class="input-group-modern" :class="{'is-valid': hasPassword}">
                <span class="input-icon"><i class="bi bi-shield-lock"></i></span>
                <input v-model="form.password" type="password" class="form-control"
                       :placeholder="hasPassword ? '•••••••• (встановлено)' : 'Введіть пароль'">
                <span v-if="hasPassword" class="valid-icon"><i class="bi bi-check-circle-fill text-success"></i></span>
              </div>
            </div>

             <div class="col-12 mt-4">
               <div class="d-flex justify-content-between align-items-center mb-3">
                 <h6 class="text-uppercase text-muted small fw-bold mb-0 ls-1">Режим роботи та автоматизація</h6>
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="flexSwitchCheckDefault" v-model="form.enabled">
                    <label class="form-check-label small" for="flexSwitchCheckDefault">Активно</label>
                  </div>
               </div>
            </div>

            <div class="col-md-4">
               <label class="form-label small fw-semibold text-secondary">Відкриття зміни</label>
               <input v-model="form.open_time" type="time" class="form-control form-control-lg bg-light border-0">
            </div>
             <div class="col-md-4">
               <label class="form-label small fw-semibold text-secondary">Фіскалізація черги</label>
               <input v-model="form.queue_process_time" type="time" class="form-control form-control-lg bg-light border-0">
            </div>
             <div class="col-md-4">
               <label class="form-label small fw-semibold text-secondary">Закриття зміни</label>
               <input v-model="form.close_time" type="time" class="form-control form-control-lg bg-light border-0">
            </div>

            <div class="col-12 mt-3">
               <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="queueCheck" v-model="form.queue_enabled">
                  <label class="form-check-label small text-muted" for="queueCheck">
                    Дозволити накопичення чеків у черзі (якщо зміна закрита)
                  </label>
               </div>
            </div>

          </div>

          <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
            <button type="button" class="btn btn-light" @click="closeAuthModal">Скасувати</button>
            <button type="button" class="btn btn-primary" @click="saveSettings" :disabled="loading.save">
              <span v-if="loading.save" class="spinner-border spinner-border-sm me-2"></span>
              Зберегти
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { formatCurrency } from '@/crm/utils/orderDisplay';
// Підключення ApexCharts (переконайтесь що встановили: npm install apexcharts vue3-apexcharts)
import ApexChart from 'vue3-apexcharts';

// Імпорт ваших API методів (перевірте шлях)
import {
  fetchFinanceSettings,
  saveFinanceSettings,
  testFinanceConnection,
  openFinanceShift,
  closeFinanceShift,
  processFinanceQueue,
} from '@/crm/services/financeApi';

const loading = reactive({
  save: false,
  test: false,
  shift: false,
  queue: false,
  chart: false
});

const notice = reactive({ type: '', message: '' });
const shiftStatus = ref('unknown'); 
const shiftMessage = ref('Статус невідомий');
const queueCount = ref(0);
const showAuthModal = ref(false);

const hasLicenseKey = ref(false);
const hasPassword = ref(false);
const receipts = ref([]);
const receiptsDate = ref('');
const dailyTotalCents = ref(0);
const hourlyCounts = ref([]);

const form = reactive({
  api_url: '',
  license_key: '',
  login: '',
  password: '',
  open_time: '08:00',
  close_time: '23:00',
  queue_process_time: '08:30',
  enabled: true,
  queue_enabled: true,
});

// --- Computed ---

const shiftBadge = computed(() => {
  switch (shiftStatus.value) {
    case 'opened': return 'Зміна відкрита';
    case 'closed': return 'Зміна закрита';
    case 'error': return 'Помилка';
    default: return 'Невідомо';
  }
});

const statusBadgeClass = computed(() => {
  switch (shiftStatus.value) {
    case 'opened': return 'bg-success-subtle text-success border border-success-subtle';
    case 'closed': return 'bg-danger-subtle text-danger border border-danger-subtle';
    case 'error': return 'bg-warning-subtle text-warning-emphasis';
    default: return 'bg-secondary-subtle text-secondary';
  }
});

const statusIcon = computed(() => {
  switch (shiftStatus.value) {
    case 'opened': return 'bi-unlock-fill';
    case 'closed': return 'bi-lock-fill';
    case 'error': return 'bi-exclamation-triangle-fill';
    default: return 'bi-question-circle-fill';
  }
});

const dailyTotalFormatted = computed(() => formatCurrency(Number(dailyTotalCents.value || 0) / 100));
const receiptsCount = computed(() => receipts.value.length);
const receiptsDateLabel = computed(() => {
  if (!receiptsDate.value) return 'сьогодні';
  const parsed = new Date(receiptsDate.value);
  if (Number.isNaN(parsed.getTime())) return receiptsDate.value;
  return parsed.toLocaleDateString('uk-UA', { day: '2-digit', month: 'long', year: 'numeric' });
});

// --- CHART CONFIGURATION ---
// Дані для графіка на основі чеків
const chartSeries = computed(() => {
  let hoursData;
  if (Array.isArray(hourlyCounts.value) && hourlyCounts.value.length === 24) {
    hoursData = hourlyCounts.value.map((value) => Number(value) || 0);
  } else {
    hoursData = new Array(24).fill(0);
    receipts.value.forEach((receipt) => {
      if (!receipt?.created_at) return;
      const parsed = new Date(receipt.created_at);
      if (Number.isNaN(parsed.getTime())) return;
      const hour = parsed.getHours();
      if (hour >= 0 && hour < 24) hoursData[hour] += 1;
    });
  }

  return [{
    name: 'Кількість чеків',
    data: hoursData,
  }];
});

const chartOptions = computed(() => ({
  chart: {
    type: 'area',
    height: 300,
    fontFamily: 'inherit',
    toolbar: { show: false },
    zoom: { enabled: false }
  },
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2 },
  fill: {
    type: 'gradient',
    gradient: {
      shadeIntensity: 1,
      opacityFrom: 0.7,
      opacityTo: 0.2,
      stops: [0, 90, 100]
    }
  },
  colors: ['#0dcaf0'], // Cyan колір як на скріні
  xaxis: {
    categories: Array.from({length: 24}, (_, i) => `${String(i).padStart(2, '0')}:00`),
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: {
      style: { colors: '#9ca3af', fontSize: '11px' }
    }
  },
  yaxis: {
    show: false // Приховуємо вісь Y для чистоти як на макеті
  },
  grid: {
    borderColor: '#f3f4f6',
    strokeDashArray: 4,
    yaxis: { lines: { show: true } } 
  },
  tooltip: {
    theme: 'light',
    x: { show: true }
  }
}));

// --- Helper Functions ---

const receiptTypeLabel = (type) => {
  const map = { sell: 'Продаж', return: 'Повернення', service_in: 'Внесення', service_out: 'Вилучення' };
  return map[type] || '—';
};

const receiptStatusLabel = (status) => {
  const map = { success: 'Успішно', pending: 'В черзі', processing: 'Обробка', error: 'Помилка', canceled: 'Скасовано' };
  return map[status] || '—';
};

const receiptStatusClass = (status) => {
  const map = {
    success: 'bg-success-subtle text-success',
    pending: 'bg-warning-subtle text-warning-emphasis',
    processing: 'bg-warning-subtle text-warning-emphasis',
    error: 'bg-danger-subtle text-danger',
    canceled: 'bg-secondary-subtle text-secondary',
  };
  return map[status] || 'bg-light text-dark';
};

const formatReceiptAmount = (amount) => formatCurrency(Number(amount || 0) / 100);
const formatReceiptTime = (value) => {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
};

// --- Actions ---

const showNotice = (type, msg) => {
  notice.type = type;
  notice.message = msg;
  setTimeout(() => { notice.message = ''; }, 5000);
};

const normalizeShiftStatus = (rawStatus) => {
  if (!rawStatus) return 'unknown';
  const value = String(rawStatus).toLowerCase();
  if (value.includes('opened') || value.includes('open') || value.includes('відкрит')) return 'opened';
  if (value.includes('closed') || value.includes('close') || value.includes('закрит')) return 'closed';
  if (value.includes('error') || value.includes('fail')) return 'error';
  return 'unknown';
};

const setShiftFromStatus = (rawStatus) => {
  const normalized = normalizeShiftStatus(rawStatus);
  shiftStatus.value = normalized;
  if (normalized === 'opened') shiftMessage.value = 'Зміна відкрита';
  else if (normalized === 'closed') shiftMessage.value = 'Зміна закрита';
  else if (normalized === 'error') shiftMessage.value = 'Помилка';
  else shiftMessage.value = 'Статус невідомий';
};

const loadSettings = async () => {
  loading.chart = true;
  try {
    const data = await fetchFinanceSettings();
    const s = data.settings || {};
    
    form.api_url = s.api_url || 'https://api.checkbox.in.ua/api/v1';
    form.login = s.login || '';
    form.open_time = s.open_time ? s.open_time.slice(0, 5) : '08:00';
    form.close_time = s.close_time ? s.close_time.slice(0, 5) : '23:00';
    form.queue_process_time = s.queue_process_time ? s.queue_process_time.slice(0, 5) : '08:30';
    form.enabled = s.enabled ?? true;
    form.queue_enabled = s.queue_enabled ?? true;
    
    hasLicenseKey.value = !!s.has_license_key;
    hasPassword.value = !!s.has_password;
    
    queueCount.value = data.queue?.waiting || 0;
    
    // Дані для таблиці та графіка
    const receiptsPayload = data.receipts || {};
    receipts.value = Array.isArray(receiptsPayload.items) ? receiptsPayload.items : [];
    receiptsDate.value = receiptsPayload.date || '';
    dailyTotalCents.value = receiptsPayload.daily_total ?? 0;
    hourlyCounts.value = Array.isArray(receiptsPayload.hourly_counts) ? receiptsPayload.hourly_counts : [];
    
    if (data.shift_status) setShiftFromStatus(data.shift_status);
  } catch (e) {
    console.error(e);
    showNotice('danger', 'Не вдалося завантажити налаштування');
  } finally {
    loading.chart = false;
  }
};

const saveSettings = async () => {
  loading.save = true;
  try {
    await saveFinanceSettings({ ...form });
    showNotice('success', 'Налаштування збережено');
    hasLicenseKey.value = hasLicenseKey.value || !!form.license_key;
    hasPassword.value = hasPassword.value || !!form.password;
    form.license_key = '';
    form.password = '';
    closeAuthModal(); // Закриваємо модалку після збереження
  } catch (e) {
    showNotice('danger', e.response?.data?.message || 'Помилка збереження');
  } finally {
    loading.save = false;
  }
};

const openAuthModal = () => showAuthModal.value = true;
const closeAuthModal = () => {
  showAuthModal.value = false;
  form.license_key = '';
  form.password = '';
};

const testConnection = async () => {
  loading.test = true;
  try {
    const res = await testFinanceConnection();
    const statusSource = res.shift?.status || res.message;
    setShiftFromStatus(statusSource);
    showNotice('success', 'Зв\'язок перевірено успішно');
  } catch (e) {
    shiftStatus.value = 'error';
    shiftMessage.value = e.response?.data?.message || 'Помилка з\'єднання';
    showNotice('danger', 'Немає зв\'язку з Checkbox');
  } finally {
    loading.test = false;
  }
};

const openShift = async () => {
  loading.shift = true;
  try {
    const res = await openFinanceShift();
    if (res.ok) {
      shiftStatus.value = 'opened';
      shiftMessage.value = 'Зміну відкрито';
      showNotice('success', 'Зміну успішно відкрито');
    } else throw new Error(res.message);
  } catch (e) {
    showNotice('danger', e.response?.data?.message || e.message || 'Не вдалося відкрити зміну');
  } finally {
    loading.shift = false;
  }
};

const closeShift = async () => {
  loading.shift = true;
  try {
    const res = await closeFinanceShift();
    if (res.ok) {
      shiftStatus.value = 'closed';
      shiftMessage.value = 'Зміну закрито';
      showNotice('success', 'Зміну успішно закрито');
    } else throw new Error(res.message);
  } catch (e) {
    showNotice('danger', e.response?.data?.message || e.message || 'Не вдалося закрити зміну');
  } finally {
    loading.shift = false;
  }
};

const processQueue = async () => {
  loading.queue = true;
  try {
    const res = await processFinanceQueue();
    showNotice('success', res.message || 'Чергу відправлено на обробку');
    loadSettings();
  } catch (e) {
    showNotice('danger', 'Помилка обробки черги');
  } finally {
    loading.queue = false;
  }
};

onMounted(() => {
  loadSettings();
});
</script>

<style scoped>
/* Загальні стилі */
.finance-page {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  color: #344767;
}

.icon-square { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.icon-box-sm { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.ls-1 { letter-spacing: 1px; }

/* Картки */
.modern-card { border: none; border-radius: 16px; background: #fff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.bg-indigo-subtle { background-color: #e0e7ff; color: #4338ca; }

/* Inputs */
.input-group-modern { position: relative; display: flex; align-items: center; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 10px; padding: 0.5rem 1rem; transition: all 0.2s ease; }
.input-group-modern:focus-within { background: #fff; border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15); }
.input-group-modern .input-icon { color: #6c757d; font-size: 1.1rem; margin-right: 0.75rem; }
.input-group-modern .form-control { border: none; background: transparent; padding: 0; color: #344767; font-weight: 500; }
.input-group-modern .form-control:focus { box-shadow: none; }
.valid-icon { margin-left: 0.5rem; }

/* Status Card Gradient */
.bg-gradient-primary { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); }
.card-bg-circle-1, .card-bg-circle-2 { position: absolute; border-radius: 50%; background: rgba(255, 255, 255, 0.1); z-index: 0; }
.card-bg-circle-1 { width: 200px; height: 200px; top: -50px; right: -50px; }
.card-bg-circle-2 { width: 150px; height: 150px; bottom: -30px; left: -30px; }

/* Buttons */
.btn-white { background: white; border: none; transition: all 0.2s; }
.btn-white:hover { background: #f8f9fa; transform: translateY(-1px); }
.btn-success-soft { background: rgba(25, 135, 84, 0.2); color: #fff; border: 1px solid rgba(255, 255, 255, 0.3); font-weight: 600; }
.btn-success-soft:hover:not(:disabled) { background: #198754; color: white; }
.btn-danger-soft { background: rgba(220, 53, 69, 0.2); color: #fff; border: 1px solid rgba(255, 255, 255, 0.3); font-weight: 600; }
.btn-danger-soft:hover:not(:disabled) { background: #dc3545; color: white; }
.btn-icon-round { width: 44px; height: 44px; border-radius: 50%; border: 1px solid #e9ecef; background: #fff; color: #4b5563; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.btn-icon-round:hover { background: #f8f9fa; color: #0d6efd; box-shadow: 0 4px 10px rgba(13, 110, 253, 0.15); }

/* Modal */
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; padding: 1.5rem; z-index: 1055; }
.modal-card { width: 100%; max-width: 640px; background: #fff; border-radius: 20px; padding: 2rem; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.2); }

/* Animations */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.pulse-dot { width: 10px; height: 10px; background-color: #198754; border-radius: 50%; position: relative; }
.pulse-dot::after { content: ''; position: absolute; width: 100%; height: 100%; top: 0; left: 0; background-color: #198754; border-radius: 50%; animation: pulse-ring 1.5s cubic-bezier(0.215, 0.61, 0.355, 1) infinite; }
@keyframes pulse-ring { 0% { transform: scale(1); opacity: 0.8; } 100% { transform: scale(2.5); opacity: 0; } }
.slide-fade-enter-active, .slide-fade-leave-active { transition: all 0.3s ease; }
.slide-fade-enter-from, .slide-fade-leave-to { transform: translateY(-10px); opacity: 0; }
.backdrop-blur { backdrop-filter: blur(5px); }
</style>
