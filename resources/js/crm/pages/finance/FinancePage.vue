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
        <div class="card modern-card h-100">
          <div class="card-header-clean p-4 border-bottom border-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark">Налаштування підключення</h5>
            <button class="btn btn-icon-round" type="button" @click="openSettingsModal" aria-label="Редагувати налаштування">
              <i class="bi bi-gear-fill"></i>
            </button>
          </div>
          <div class="card-body p-4">
            
            <div class="settings-summary d-flex flex-column gap-3">
              <div class="auth-summary p-3 rounded-3 border border-light-subtle bg-body-secondary bg-opacity-25">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                  <div style="min-width: 0;">
                    <div class="text-uppercase small text-muted fw-semibold">API URL</div>
                    <div class="fw-semibold text-dark text-truncate" :title="form.api_url">
                      {{ form.api_url || '—' }}
                    </div>
                  </div>
                  <span class="badge bg-light text-dark">Checkbox</span>
                </div>
              </div>

              <div class="auth-summary p-3 rounded-3 border border-light-subtle bg-body-secondary bg-opacity-25">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="text-uppercase small text-muted fw-semibold">Авторизація</div>
                    <div class="fw-semibold text-dark">
                      {{ hasLicenseKey && hasPassword && form.login ? 'Встановлено' : 'Не налаштовано' }}
                    </div>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge" :class="hasLicenseKey ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                      Ключ
                    </span>
                    <span class="badge" :class="form.login ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                      Логін
                    </span>
                    <span class="badge" :class="hasPassword ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                      Пароль
                    </span>
                  </div>
                </div>
              </div>

              <div class="automation-summary p-3 rounded-3 border border-light-subtle">
                <div class="text-uppercase small text-muted fw-semibold mb-3">Автоматизація</div>
                <div class="automation-grid">
                  <div class="automation-item">
                    <div class="automation-label"><i class="bi bi-sun me-1 text-success"></i> Відкриття</div>
                    <div class="automation-value">{{ form.open_time }}</div>
                  </div>
                  <div class="automation-item">
                    <div class="automation-label"><i class="bi bi-moon me-1 text-danger"></i> Закриття</div>
                    <div class="automation-value">{{ form.close_time }}</div>
                  </div>
                  <div class="automation-item">
                    <div class="automation-label"><i class="bi bi-cloud-upload me-1 text-primary"></i> Фіскалізація</div>
                    <div class="automation-value">{{ form.queue_process_time }}</div>
                  </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                  <span class="badge" :class="form.enabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                    Інтеграція: {{ form.enabled ? 'Увімкнено' : 'Вимкнено' }}
                  </span>
                  <span class="badge" :class="form.queue_enabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                    Нічна черга: {{ form.queue_enabled ? 'Увімкнено' : 'Вимкнено' }}
                  </span>
                </div>
              </div>
            </div>
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
              <span style="font-size: 0.85rem">Чеки, створені поза робочим часом, автоматично накопичуються тут для подальшої відправки.</span>
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
              <h5 class="mb-1 fw-bold text-dark">Фіскалізація за день</h5>
              <div class="text-muted small">Графік активності за {{ receiptsDateLabel }}</div>
            </div>
            <span class="badge bg-light text-dark">Чеків: {{ receiptsCount }}</span>
          </div>
          <div class="card-body p-4">
            <div class="chart-scroll">
              <div class="chart-inner">
                <svg class="fiscal-chart-svg" :viewBox="`0 0 ${chartWidth} ${chartHeight}`" preserveAspectRatio="none">
                  <defs>
                    <linearGradient id="fiscalGradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stop-color="#2ec4b6" stop-opacity="0.5" />
                      <stop offset="100%" stop-color="#2ec4b6" stop-opacity="0" />
                    </linearGradient>
                  </defs>
                  <g class="chart-grid">
                    <line
                      v-for="(y, idx) in chartGridLines"
                      :key="`grid-${idx}`"
                      x1="0"
                      :x2="chartWidth"
                      :y1="y"
                      :y2="y"
                    />
                  </g>
                  <path class="chart-area" :d="chartAreaPath" fill="url(#fiscalGradient)" />
                  <path class="chart-line" :d="chartLinePath" />
                </svg>
                <div class="chart-axis">
                  <div
                    v-for="(label, idx) in chartHours"
                    :key="label"
                    class="chart-label"
                    :class="{ 'is-dim': idx % 2 !== 0 }"
                  >
                    {{ label }}
                  </div>
                </div>
              </div>
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
      <div v-if="showSettingsModal" class="modal-backdrop" @click.self="closeSettingsModal">
        <div class="modal-card modal-card-lg">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0 fw-bold text-dark">Налаштування підключення</h5>
            <button type="button" class="btn btn-link text-muted" @click="closeSettingsModal">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <form @submit.prevent="saveSettings">
            <div class="row g-4 mb-4">
              <div class="col-12">
                <label class="form-label fw-semibold text-secondary small text-uppercase ls-1">API URL</label>
                <div class="input-group-modern">
                  <span class="input-icon"><i class="bi bi-link-45deg"></i></span>
                  <input v-model="form.api_url" type="text" class="form-control" placeholder="https://api.checkbox.in.ua...">
                </div>
              </div>

              <div class="col-12">
                <div class="text-uppercase small text-muted fw-semibold">Авторизація</div>
              </div>

              <div class="col-12">
                <label class="form-label fw-semibold text-secondary small text-uppercase ls-1">Ліцензійний ключ</label>
                <div class="input-group-modern" :class="{'is-valid': hasLicenseKey}">
                  <span class="input-icon"><i class="bi bi-key"></i></span>
                  <input
                    v-model="form.license_key"
                    type="password"
                    class="form-control"
                    :placeholder="hasLicenseKey ? '•••••••• (встановлено)' : 'Введіть ключ ліцензії'"
                  >
                  <span v-if="hasLicenseKey" class="valid-icon"><i class="bi bi-check-circle-fill text-success"></i></span>
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label fw-semibold text-secondary small text-uppercase ls-1">Логін касира</label>
                <div class="input-group-modern">
                  <span class="input-icon"><i class="bi bi-person"></i></span>
                  <input v-model="form.login" type="text" class="form-control" placeholder="login">
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label fw-semibold text-secondary small text-uppercase ls-1">Пароль касира</label>
                <div class="input-group-modern" :class="{'is-valid': hasPassword}">
                  <span class="input-icon"><i class="bi bi-shield-lock"></i></span>
                  <input
                    v-model="form.password"
                    type="password"
                    class="form-control"
                    :placeholder="hasPassword ? '•••••••• (встановлено)' : 'Введіть пароль'"
                  >
                  <span v-if="hasPassword" class="valid-icon"><i class="bi bi-check-circle-fill text-success"></i></span>
                </div>
              </div>
            </div>

            <div class="position-relative my-4">
              <hr class="text-muted-light">
              <span class="position-absolute top-50 start-50 translate-middle bg-white px-3 text-muted small fw-bold text-uppercase ls-1">Автоматизація</span>
            </div>

            <div class="row g-4">
              <div class="col-md-4">
                <div class="time-card p-3 rounded-3 bg-success-subtle border border-success-subtle">
                  <label class="form-label text-success fw-bold small"><i class="bi bi-sun me-1"></i> Відкриття</label>
                  <input v-model="form.open_time" type="time" class="form-control time-input bg-transparent border-0 p-0 fw-bold fs-5 text-dark">
                </div>
              </div>
              <div class="col-md-4">
                <div class="time-card p-3 rounded-3 bg-danger-subtle border border-danger-subtle">
                  <label class="form-label text-danger fw-bold small"><i class="bi bi-moon me-1"></i> Закриття</label>
                  <input v-model="form.close_time" type="time" class="form-control time-input bg-transparent border-0 p-0 fw-bold fs-5 text-dark">
                </div>
              </div>
              <div class="col-md-4">
                <div class="time-card p-3 rounded-3 bg-primary-subtle border border-primary-subtle">
                  <label class="form-label text-primary fw-bold small"><i class="bi bi-cloud-upload me-1"></i> Фіскалізація</label>
                  <input v-model="form.queue_process_time" type="time" class="form-control time-input bg-transparent border-0 p-0 fw-bold fs-5 text-dark">
                </div>
              </div>
            </div>

            <div class="bg-body-secondary bg-opacity-50 rounded-4 p-4 mt-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                  <h6 class="mb-0 fw-bold text-dark">Активність інтеграції</h6>
                  <small class="text-muted">Увімкнути роботу з Checkbox API</small>
                </div>
                <div class="form-check form-switch">
                  <input v-model="form.enabled" class="form-check-input custom-switch" type="checkbox" style="width: 3em; height: 1.5em;">
                </div>
              </div>
              <hr class="my-3 border-secondary opacity-10">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <h6 class="mb-0 fw-bold text-dark">Нічна черга</h6>
                  <small class="text-muted">Накопичувати чеки вночі замість помилки</small>
                </div>
                <div class="form-check form-switch">
                  <input v-model="form.queue_enabled" class="form-check-input custom-switch" type="checkbox" style="width: 3em; height: 1.5em;">
                </div>
              </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
              <button type="button" class="btn btn-light" @click="closeSettingsModal">Скасувати</button>
              <button type="submit" class="btn btn-primary" :disabled="loading.save">
                <span v-if="loading.save" class="spinner-border spinner-border-sm me-2"></span>
                Зберегти
              </button>
            </div>
          </form>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { formatCurrency } from '@/crm/utils/orderDisplay';
// Замініть цей імпорт на ваш реальний шлях
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
  queue: false
});

const notice = reactive({ type: '', message: '' });
const shiftStatus = ref('unknown'); // 'opened', 'closed', 'error', 'unknown'
const shiftMessage = ref('Статус невідомий');
const queueCount = ref(0);
const showSettingsModal = ref(false);

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

const chartWidth = 240;
const chartHeight = 90;
const chartPadding = 12;
const chartBaseY = chartHeight - chartPadding;
const chartPlotHeight = chartHeight - chartPadding * 2;
const chartStep = chartWidth / 23;

const dailyTotalFormatted = computed(() => formatCurrency(Number(dailyTotalCents.value || 0) / 100));

const receiptsCount = computed(() => receipts.value.length);

const receiptsDateLabel = computed(() => {
  if (!receiptsDate.value) return 'сьогодні';
  const parsed = new Date(receiptsDate.value);
  if (Number.isNaN(parsed.getTime())) return receiptsDate.value;
  return parsed.toLocaleDateString('uk-UA', { day: '2-digit', month: 'long', year: 'numeric' });
});

const chartHours = computed(() => Array.from({ length: 24 }, (_, idx) => `${String(idx).padStart(2, '0')}:00`));

const chartSeries = computed(() => {
  if (Array.isArray(hourlyCounts.value) && hourlyCounts.value.length === 24) {
    return hourlyCounts.value.map((value) => Number(value) || 0);
  }
  const values = Array.from({ length: 24 }, () => 0);
  receipts.value.forEach((receipt) => {
    if (!receipt?.created_at) return;
    const parsed = new Date(receipt.created_at);
    if (Number.isNaN(parsed.getTime())) return;
    values[parsed.getHours()] += 1;
  });
  return values;
});

const chartMax = computed(() => Math.max(...chartSeries.value, 1));

const chartPoints = computed(() => chartSeries.value.map((value, idx) => {
  const x = chartStep * idx;
  const y = chartBaseY - (value / chartMax.value) * chartPlotHeight;
  return { x, y };
}));

const chartLinePath = computed(() => {
  const points = chartPoints.value;
  if (!points.length) return '';
  return points
    .map((point, idx) => `${idx === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(' ');
});

const chartAreaPath = computed(() => {
  const points = chartPoints.value;
  if (!points.length) return '';
  const line = points.map((point) => `L ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' ');
  return `M 0 ${chartBaseY} ${line} L ${chartWidth} ${chartBaseY} Z`;
});

const chartGridLines = computed(() => {
  const lines = [];
  const steps = 4;
  for (let i = 0; i <= steps; i += 1) {
    lines.push(chartPadding + (chartPlotHeight / steps) * i);
  }
  return lines;
});

const receiptTypeLabel = (type) => {
  const map = {
    sell: 'Продаж',
    return: 'Повернення',
    service_in: 'Службове внесення',
    service_out: 'Службове вилучення',
  };
  return map[type] || '—';
};

const receiptStatusLabel = (status) => {
  const map = {
    success: 'Успішно',
    pending: 'В черзі',
    processing: 'Обробка',
    error: 'Помилка',
    canceled: 'Скасовано',
  };
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
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
};

// --- Actions (Логіка збережена з вашого прикладу) ---

const normalizeShiftStatus = (rawStatus) => {
  if (!rawStatus) return 'unknown';
  const value = String(rawStatus).toLowerCase();

  if (value === 'opened' || value === 'open' || value.includes('opened') || value.includes('відкрит')) return 'opened';
  if (value === 'closed' || value === 'close' || value.includes('closed') || value.includes('закрит')) return 'closed';
  if (value === 'error' || value === 'failed' || value.includes('error') || value.includes('помил')) return 'error';

  return 'unknown';
};

const setShiftFromStatus = (rawStatus) => {
  const normalized = normalizeShiftStatus(rawStatus);
  shiftStatus.value = normalized;

  if (normalized === 'opened') {
    shiftMessage.value = 'Зміна відкрита';
    return;
  }

  if (normalized === 'closed') {
    shiftMessage.value = 'Зміна закрита';
    return;
  }

  if (normalized === 'error') {
    shiftMessage.value = 'Помилка';
    return;
  }

  shiftMessage.value = 'Статус невідомий';
};

const showNotice = (type, msg) => {
  notice.type = type;
  notice.message = msg;
  setTimeout(() => { notice.message = ''; }, 5000);
};

const loadSettings = async () => {
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
    const receiptsPayload = data.receipts || {};
    receipts.value = Array.isArray(receiptsPayload.items) ? receiptsPayload.items : [];
    receiptsDate.value = receiptsPayload.date || '';
    dailyTotalCents.value = receiptsPayload.daily_total ?? 0;
    hourlyCounts.value = Array.isArray(receiptsPayload.hourly_counts) ? receiptsPayload.hourly_counts : [];
    
    // Оновлення статусу при завантаженні (якщо API повертає)
    if (data.shift_status) {
        setShiftFromStatus(data.shift_status);
    }
  } catch (e) {
    console.error(e);
    showNotice('danger', 'Не вдалося завантажити налаштування');
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
    showSettingsModal.value = false;
  } catch (e) {
    showNotice('danger', e.response?.data?.message || 'Помилка збереження');
  } finally {
    loading.save = false;
  }
};

const openSettingsModal = () => {
  showSettingsModal.value = true;
};

const closeSettingsModal = () => {
  showSettingsModal.value = false;
  form.license_key = '';
  form.password = '';
};

const testConnection = async () => {
  loading.test = true;
  try {
    const res = await testFinanceConnection();
    const statusSource = res.shift?.status || res.message;
    const normalized = normalizeShiftStatus(statusSource);
    if (normalized !== 'unknown') {
      setShiftFromStatus(statusSource);
    } else {
      shiftStatus.value = 'unknown';
      shiftMessage.value = res.message || 'Зв\'язок встановлено';
    }
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
    } else {
      throw new Error(res.message);
    }
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
    } else {
      throw new Error(res.message);
    }
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

.icon-square {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
}

.ls-1 {
  letter-spacing: 1px;
}

/* Картки */
.modern-card {
  border: none;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* Inputs */
.input-group-modern {
  position: relative;
  display: flex;
  align-items: center;
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 10px;
  padding: 0.5rem 1rem;
  transition: all 0.2s ease;
}

.input-group-modern:focus-within {
  background: #fff;
  border-color: #0d6efd;
  box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
}

.input-group-modern .input-icon {
  color: #6c757d;
  font-size: 1.1rem;
  margin-right: 0.75rem;
}

.input-group-modern .form-control {
  border: none;
  background: transparent;
  padding: 0;
  color: #344767;
  font-weight: 500;
}

.input-group-modern .form-control:focus {
  box-shadow: none;
}

.valid-icon {
  margin-left: 0.5rem;
}

/* Time Inputs (видаляємо стандартні стилі браузера) */
.time-input::-webkit-calendar-picker-indicator {
  cursor: pointer;
  opacity: 0.6;
}
.time-input:focus {
    box-shadow: none;
    outline: none;
}

/* Status Card Gradient Backgrounds */
.bg-gradient-primary {
  background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
}

.card-bg-circle-1, .card-bg-circle-2 {
  position: absolute;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  z-index: 0;
}

.card-bg-circle-1 {
  width: 200px;
  height: 200px;
  top: -50px;
  right: -50px;
}

.card-bg-circle-2 {
  width: 150px;
  height: 150px;
  bottom: -30px;
  left: -30px;
}

/* Кнопки */
.btn-white {
  background: white;
  border: none;
  transition: all 0.2s;
}
.btn-white:hover {
  background: #f8f9fa;
  transform: translateY(-1px);
}

.btn-success-soft {
  background: rgba(25, 135, 84, 0.2);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.3);
  font-weight: 600;
  transition: all 0.2s;
}
.btn-success-soft:hover:not(:disabled) {
  background: #198754;
  color: white;
}
.btn-success-soft:disabled {
    background: rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.5);
    border-color: transparent;
}

.btn-danger-soft {
  background: rgba(220, 53, 69, 0.2);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.3);
  font-weight: 600;
  transition: all 0.2s;
}
.btn-danger-soft:hover:not(:disabled) {
  background: #dc3545;
  color: white;
}
.btn-danger-soft:disabled {
    background: rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.5);
    border-color: transparent;
}

.btn-icon-round {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  border: 1px solid #e9ecef;
  background: #fff;
  color: #4b5563;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}
.btn-icon-round:hover {
  background: #f8f9fa;
  color: #0d6efd;
  box-shadow: 0 4px 10px rgba(13, 110, 253, 0.15);
}

.auth-summary .badge {
  font-weight: 600;
}

.automation-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.automation-item {
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  background: #f8fafc;
}

.automation-label {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #6b7280;
  font-weight: 600;
}

.automation-value {
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}

.chart-scroll {
  overflow-x: auto;
}

.chart-inner {
  min-width: 840px;
}

.fiscal-chart-svg {
  width: 100%;
  height: 140px;
  display: block;
}

.chart-grid line {
  stroke: #e5e7eb;
  stroke-dasharray: 6 6;
}

.chart-line {
  fill: none;
  stroke: #2ec4b6;
  stroke-width: 2.5;
}

.chart-axis {
  display: grid;
  grid-template-columns: repeat(24, minmax(32px, 1fr));
  gap: 0;
  font-size: 0.7rem;
  color: #6b7280;
  margin-top: 6px;
}

.chart-label {
  text-align: center;
  white-space: nowrap;
}

.chart-label.is-dim {
  color: #cbd5e1;
}

.receipts-table th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #6b7280;
}

.receipts-table td {
  padding: 0.85rem 1rem;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  z-index: 1055;
}

.modal-card {
  width: 100%;
  max-width: 640px;
  background: #fff;
  border-radius: 20px;
  padding: 2rem;
  box-shadow: 0 20px 60px rgba(15, 23, 42, 0.2);
}

.modal-card.modal-card-lg {
  max-width: 860px;
}

.fade-enter-active, .fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from, .fade-leave-to {
  opacity: 0;
}

/* Pulse Animation */
.pulse-dot {
  width: 10px;
  height: 10px;
  background-color: #198754;
  border-radius: 50%;
  position: relative;
}

.pulse-dot::after {
  content: '';
  position: absolute;
  width: 100%;
  height: 100%;
  top: 0;
  left: 0;
  background-color: #198754;
  border-radius: 50%;
  animation: pulse-ring 1.5s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
}

@keyframes pulse-ring {
  0% { transform: scale(1); opacity: 0.8; }
  100% { transform: scale(2.5); opacity: 0; }
}

/* Transitions */
.slide-fade-enter-active, .slide-fade-leave-active {
  transition: all 0.3s ease;
}
.slide-fade-enter-from, .slide-fade-leave-to {
  transform: translateY(-10px);
  opacity: 0;
}

/* Backdrop blur helper */
.backdrop-blur {
    backdrop-filter: blur(5px);
}

@media (max-width: 992px) {
  .automation-grid {
    grid-template-columns: 1fr;
  }

  .chart-inner {
    min-width: 720px;
  }
}
</style>
