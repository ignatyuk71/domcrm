<template>
  <div class="finance-page container-fluid py-4 position-relative">
    
    <!-- TOAST NOTIFICATIONS (Спливаючі повідомлення) -->
    <div class="toast-container position-fixed top-0 end-0 p-4" style="z-index: 1070;">
      <transition-group name="toast-slide">
        <div 
          v-for="toast in toasts" 
          :key="toast.id" 
          class="custom-toast mb-3 shadow-lg d-flex align-items-center"
          :class="toast.type === 'success' ? 'toast-success' : 'toast-error'"
        >
          <div class="toast-icon-box">
             <i class="bi" :class="toast.type === 'success' ? 'bi-check-lg' : 'bi-exclamation-triangle-fill'"></i>
          </div>
          <div class="toast-content ps-3 pe-4 py-3">
             <div class="toast-title">{{ toast.type === 'success' ? 'Успішно' : 'Помилка' }}</div>
             <div class="toast-msg">{{ toast.message }}</div>
          </div>
          <button class="btn-close me-3 small" @click="removeToast(toast.id)"></button>
          
          <!-- Прогрес бар часу -->
          <div class="toast-progress" :style="{ animationDuration: '5000ms' }"></div>
        </div>
      </transition-group>
    </div>

    <!-- HEADER SECTION -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
      <div>
        <h1 class="h3 fw-bold text-dark mb-1 d-flex align-items-center">
          <i class="bi bi-wallet2 text-primary me-2"></i>
          Фіскалізація & Каса
        </h1>
        <p class="text-muted mb-0 small">Управління ПРРО Checkbox та моніторинг транзакцій</p>
      </div>
      
      <div class="d-flex align-items-center gap-2">
        <!-- Індикатор API -->
        <div class="d-flex align-items-center px-3 py-2 bg-white rounded-pill shadow-sm border border-light">
          <span class="pulse-dot me-2" :class="connectionStatusColor"></span>
          <span class="small fw-semibold text-muted">Checkbox API</span>
        </div>

        <!-- Кнопка налаштувань -->
        <button class="btn btn-white shadow-sm border border-light rounded-pill px-3 fw-semibold" @click="openSettingsModal">
          <i class="bi bi-gear-fill text-muted me-2"></i>
          Налаштування
        </button>
      </div>
    </div>

    <!-- 1. KPI CARDS (СТАТИСТИКА) -->
    <div class="row g-3 mb-4">
      <!-- Статус зміни -->
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 stat-card">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-uppercase text-muted x-small fw-bold mb-1">Статус зміни</div>
                <div class="h5 mb-0 fw-bold" :class="shiftStatus === 'opened' ? 'text-success' : 'text-danger'">
                  {{ shiftBadge }}
                </div>
              </div>
              <div class="icon-shape bg-light rounded-circle text-dark">
                <i class="bi" :class="statusIcon"></i>
              </div>
            </div>
          </div>
          <div class="progress-line" :class="shiftStatus === 'opened' ? 'bg-success' : 'bg-danger'"></div>
        </div>
      </div>

      <!-- Черга -->
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 stat-card">
          <div class="card-body p-3">
             <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-uppercase text-muted x-small fw-bold mb-1">Черга чеків</div>
                <div class="h5 mb-0 fw-bold text-dark">{{ queueCount }}</div>
              </div>
              <div class="icon-shape bg-light rounded-circle text-primary">
                <i class="bi bi-layers-fill"></i>
              </div>
            </div>
          </div>
          <div class="progress-line bg-primary" :style="{ width: queueCount > 0 ? '100%' : '0%' }"></div>
        </div>
      </div>

      <!-- Виторг -->
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 stat-card">
          <div class="card-body p-3">
             <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-uppercase text-muted x-small fw-bold mb-1">Виторг сьогодні</div>
                <div class="h5 mb-0 fw-bold text-dark">{{ dailyTotalFormatted }}</div>
              </div>
              <div class="icon-shape bg-light rounded-circle text-success">
                <i class="bi bi-currency-exchange"></i>
              </div>
            </div>
          </div>
          <div class="progress-line bg-success"></div>
        </div>
      </div>

      <!-- Автоматизація -->
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 stat-card">
          <div class="card-body p-3">
             <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-uppercase text-muted x-small fw-bold mb-1">Авто-режим</div>
                <div class="d-flex align-items-center gap-2">
                   <div class="h6 mb-0 fw-bold" :class="form.enabled ? 'text-indigo' : 'text-muted'">
                    {{ form.enabled ? 'Активний' : 'Вимкнено' }}
                   </div>
                </div>
              </div>
              <div class="icon-shape bg-light rounded-circle text-indigo">
                <i class="bi bi-robot"></i>
              </div>
            </div>
          </div>
          <div class="progress-line bg-indigo" :style="{ opacity: form.enabled ? 1 : 0 }"></div>
        </div>
      </div>
    </div>

    <!-- 2. MAIN CONTENT (Table + Actions) -->
    <div class="row g-4 mb-4">
      
      <!-- ТАБЛИЦЯ ІСТОРІЇ (З фіксованою висотою ~5 рядків) -->
      <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark">Історія операцій</h6>
            <div class="d-flex gap-2">
               <span class="badge bg-light text-muted fw-normal" v-if="receipts.length">Всього: {{ receipts.length }}</span>
            </div>
          </div>
          <!-- CHANGED: max-height: 380px для відображення приблизно 5 чеків -->
          <div class="table-responsive custom-scrollbar" style="max-height: 300px;">
            <table class="table table-hover align-middle mb-0 custom-table">
              <thead class="bg-light sticky-top" style="z-index: 2;">
                <tr>
                  <th class="ps-4 text-muted x-small text-uppercase">Час</th>
                  <th class="text-muted x-small text-uppercase">Статус</th>
                  <th class="text-muted x-small text-uppercase">Тип</th>
                  <th class="text-muted x-small text-uppercase text-end">Сума</th>
                  <th class="pe-4 text-muted x-small text-uppercase text-end">Дія</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="receipts.length === 0">
                  <td colspan="5" class="text-center py-5 text-muted">
                     <i class="bi bi-inbox fs-4 d-block mb-2 opacity-50"></i>
                     Операцій за сьогодні не знайдено
                  </td>
                </tr>
                <tr v-for="receipt in receipts" :key="receipt.id">
                  <td class="ps-4 fw-medium text-dark">{{ formatReceiptTime(receipt.created_at) }}</td>
                  <td><span class="badge rounded-pill fw-normal" :class="receiptStatusClass(receipt.status)">{{ receiptStatusLabel(receipt.status) }}</span></td>
                  <td class="text-muted small">{{ receiptTypeLabel(receipt.type) }}</td>
                  <td class="text-end fw-bold text-dark">{{ formatReceiptAmount(receipt.total_amount) }}</td>
                  <td class="pe-4 text-end">
                    <a v-if="receipt.check_link" :href="receipt.check_link" target="_blank" class="btn btn-sm btn-light rounded-pill">Чек <i class="bi bi-box-arrow-up-right ms-1"></i></a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ПАНЕЛЬ КЕРУВАННЯ -->
      <div class="col-lg-4">
        
        <div class="card border-0 shadow-lg h-100 overflow-hidden bg-gradient-primary text-white position-relative">
          
          <div class="circle-decoration one"></div>
          <div class="circle-decoration two"></div>
          
          <div class="card-body position-relative z-1 d-flex flex-column justify-content-between p-4">
            
            <!-- ВЕРХНЯ ЧАСТИНА -->
            <div>
              <div class="d-flex justify-content-between align-items-center mb-4">
                 <div class="badge-glass px-3 py-2 d-flex align-items-center gap-2">
                    <i class="bi bi-shop"></i>
                    <span class="fw-medium">Каса №1</span>
                 </div>
                 
                 <button class="btn btn-icon-glass" @click="testConnection" :disabled="loading.test" title="Перевірити з'єднання">
                    <i class="bi bi-arrow-repeat" :class="{'spin': loading.test}"></i>
                 </button>
              </div>
              
              <div class="mt-2">
                 <h2 class="display-6 fw-bold mb-1">
                   {{ shiftStatus === 'opened' ? 'Зміна відкрита' : 'Зміна закрита' }}
                 </h2>
                 
                 <div class="d-flex align-items-center mt-2">
                    <div class="status-dot-pulse me-2" :class="shiftStatus === 'opened' ? 'bg-success' : 'bg-danger'"></div>
                    <span class="text-white text-opacity-75 font-monospace small">
                      {{ shiftMessage }}
                    </span>
                 </div>
              </div>
            </div>

            <!-- НИЖНЯ ЧАСТИНА -->
            <div class="mt-auto pt-4">
              
              <div class="d-grid gap-3">
                
                <button 
                   class="btn fw-bold shadow-sm py-2 position-relative overflow-hidden"
                   :class="queueCount > 0 ? 'btn-warning' : 'btn-glass-disabled'" 
                   @click="processQueue" 
                   :disabled="loading.queue || queueCount === 0"
                 >
                    <span v-if="loading.queue" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="bi bi-send-fill me-2"></i>
                    Відправити чеки з черги ({{ queueCount }})
                 </button>

                <button 
                  v-if="shiftStatus !== 'opened'"
                  class="btn btn-action-glass py-3 position-relative overflow-hidden group"
                  @click="openShift" 
                  :disabled="loading.shift"
                >
                   <div class="position-relative z-1 d-flex align-items-center justify-content-center gap-2">
                      <span v-if="loading.shift" class="spinner-border spinner-border-sm text-white me-2"></span>
                      <i v-else class="bi bi-unlock-fill fs-5"></i>
                      <span class="fw-bold text-uppercase ls-1">Відкрити зміну</span>
                   </div>
                </button>

                <button 
                  v-else
                  class="btn btn-action-danger-glass py-3 position-relative overflow-hidden group"
                  @click="closeShift" 
                  :disabled="loading.shift"
                >
                   <div class="position-relative z-1 d-flex align-items-center justify-content-center gap-2">
                      <span v-if="loading.shift" class="spinner-border spinner-border-sm text-white me-2"></span>
                      <i v-else class="bi bi-lock-fill fs-5"></i>
                      <span class="fw-bold text-uppercase ls-1">Закрити зміну</span>
                   </div>
                </button>

              </div>

            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- 3. CHART SECTION -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Динаміка виторгу</h6>
        <span class="badge bg-light text-muted fw-normal">Сума грн / година</span>
      </div>
      <div class="card-body p-0 position-relative" style="min-height: 320px;">
         <div v-if="loading.chart" class="position-absolute w-100 h-100 d-flex justify-content-center align-items-center bg-white z-2">
            <div class="spinner-border text-primary text-opacity-25" role="status"></div>
         </div>
         <div class="px-2 pt-2">
           <ApexChart type="area" height="300" :options="chartOptions" :series="chartSeries" />
         </div>
      </div>
    </div>

    <!-- MODAL (НАЛАШТУВАННЯ) -->
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
                  <input v-model="form.api_url" type="text" class="form-control" placeholder="https://api.checkbox.in.ua/api/v1">
                </div>
              </div>

              <div class="col-12">
                <div class="text-uppercase small text-muted fw-semibold">Авторизація</div>
              </div>

              <div class="col-12">
                <label class="form-label fw-semibold text-secondary small text-uppercase ls-1">Ліцензійний ключ</label>
                <div class="input-group-modern" :class="{'is-valid': hasLicenseKey}">
                  <span class="input-icon"><i class="bi bi-key"></i></span>
                  <input v-model="form.license_key" type="password" class="form-control" :placeholder="hasLicenseKey ? '•••••••• (встановлено)' : 'Введіть ключ ліцензії'">
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
                  <input v-model="form.password" type="password" class="form-control" :placeholder="hasPassword ? '•••••••• (встановлено)' : 'Введіть пароль'">
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
import ApexChart from 'vue3-apexcharts';
import {
  fetchFinanceSettings,
  saveFinanceSettings,
  testFinanceConnection,
  openFinanceShift,
  closeFinanceShift,
  processFinanceQueue,
} from '@/crm/services/financeApi';

const loading = reactive({ save: false, test: false, shift: false, queue: false, chart: false });
const toasts = ref([]); // Замінено notice на масив toasts
const shiftStatus = ref('unknown'); 
const shiftMessage = ref('Отримання статусу...');
const queueCount = ref(0);
const showSettingsModal = ref(false); 

const hasLicenseKey = ref(false);
const hasPassword = ref(false);
const receipts = ref([]);
const dailyTotalCents = ref(0);
const hourlyAmounts = ref([]);

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
  if (shiftStatus.value === 'opened') return 'Відкрито';
  if (shiftStatus.value === 'closed') return 'Закрито';
  return 'Помилка';
});
const statusIcon = computed(() => shiftStatus.value === 'opened' ? 'bi-unlock-fill text-success' : 'bi-lock-fill text-danger');
const connectionStatusColor = computed(() => shiftStatus.value === 'error' ? 'bg-danger' : 'bg-success');
const dailyTotalFormatted = computed(() => formatCurrency(Number(dailyTotalCents.value || 0) / 100));

// --- CHART DATA (Сума в гривнях, тільки success + sell) ---
const chartSeries = computed(() => {
  const hoursData = new Array(24).fill(0);
  if (Array.isArray(hourlyAmounts.value) && hourlyAmounts.value.length === 24) {
    hourlyAmounts.value.forEach((amount, idx) => {
      hoursData[idx] = Number(amount || 0) / 100;
    });
  } else if (receipts.value.length) {
    receipts.value.forEach((r) => {
      if (r?.status !== 'success' || r?.type !== 'sell') return;
      const h = new Date(r.created_at).getHours();
      if (h >= 0 && h < 24) {
        hoursData[h] += (r.total_amount || 0) / 100;
      }
    });
  }
  const formattedData = hoursData.map(val => Number(val.toFixed(2)));
  return [{ name: 'Сума', data: formattedData }];
});

const chartOptions = computed(() => ({
  chart: { type: 'area', fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: 3 },
  fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] } },
  colors: ['#6366f1'],
  dataLabels: { enabled: false },
  xaxis: { 
    categories: Array.from({length: 24}, (_, i) => `${i}:00`),
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#9ca3af', fontSize: '10px' } }
  },
  yaxis: { 
    show: true,
    labels: {
      formatter: (value) => `${Math.round(value).toLocaleString('uk-UA')} ₴`,
      style: { colors: '#9ca3af', fontSize: '10px' }
    }
  },
  grid: { show: true, borderColor: '#f3f4f6', strokeDashArray: 4, padding: { top: 0, right: 0, bottom: 0, left: 10 } },
  tooltip: { 
    theme: 'light', 
    y: { formatter: (val) => formatCurrency(val) }
  }
}));

// --- Helpers ---
const receiptStatusClass = (s) => ({
  success: 'bg-success-subtle text-success',
  error: 'bg-danger-subtle text-danger',
  pending: 'bg-warning-subtle text-warning-emphasis',
  processing: 'bg-warning-subtle text-warning-emphasis',
  canceled: 'bg-secondary-subtle text-secondary'
}[s] || 'bg-light text-muted');

const receiptStatusLabel = (s) => ({
  success: 'Успішно',
  error: 'Помилка',
  pending: 'Черга',
  processing: 'Обробка',
  canceled: 'Скасовано'
}[s] || s);
const receiptTypeLabel = (t) => ({ sell: 'Продаж', return: 'Повернення', service_in: 'Внесення', service_out: 'Вилучення' }[t] || t);
const formatReceiptAmount = (a) => formatCurrency(Number(a||0)/100);
const formatReceiptTime = (v) => v ? new Date(v).toLocaleTimeString('uk-UA', {hour:'2-digit', minute:'2-digit'}) : '-';

// --- Actions (TOASTS) ---
const showNotice = (type, message) => { 
  const id = Date.now();
  toasts.value.push({ id, type, message });
  setTimeout(() => removeToast(id), 5000);
};

const removeToast = (id) => {
  toasts.value = toasts.value.filter(t => t.id !== id);
};

const openSettingsModal = () => showSettingsModal.value = true;
const closeSettingsModal = () => showSettingsModal.value = false;

const loadSettings = async () => {
  loading.chart = true;
  try {
    const data = await fetchFinanceSettings();
    const s = data.settings || {};
    Object.assign(form, {
      ...s,
      api_url: s.api_url || 'https://api.checkbox.in.ua/api/v1',
      open_time: s.open_time?.slice(0,5) || '08:00',
      close_time: s.close_time?.slice(0,5) || '23:00',
      queue_process_time: s.queue_process_time?.slice(0,5) || '08:30'
    });
    hasLicenseKey.value = !!s.has_license_key;
    hasPassword.value = !!s.has_password;
    queueCount.value = data.queue?.waiting || 0;
    receipts.value = data.receipts?.items || [];
    dailyTotalCents.value = data.receipts?.daily_total || 0;
    hourlyAmounts.value = data.receipts?.hourly_amounts || [];
    shiftStatus.value = normalizeStatus(data.shift_status);
    shiftMessage.value = getStatusMsg(shiftStatus.value);
  } catch(e) { console.error(e); } 
  finally { loading.chart = false; }
};

const normalizeStatus = (s) => {
  if(!s) return 'unknown';
  const v = s.toLowerCase();
  if(v.includes('open')) return 'opened';
  if(v.includes('close')) return 'closed';
  return 'error';
};

const getStatusMsg = (s) => s === 'opened' ? 'Каса працює в штатному режимі' : (s === 'closed' ? 'Зміна закрита, чеки накопичуються' : 'Помилка з\'єднання');

const saveSettings = async () => {
  loading.save = true;
  try { 
    await saveFinanceSettings(form); 
    showNotice('success', 'Налаштування збережено'); 
    closeSettingsModal(); 
    if (form.license_key) hasLicenseKey.value = true;
    if (form.password) hasPassword.value = true;
    form.license_key = '';
    form.password = '';
  } 
  catch(e) { showNotice('danger', 'Помилка збереження'); } 
  finally { loading.save = false; }
};

const testConnection = async () => {
  loading.test = true;
  try { const res = await testFinanceConnection(); shiftStatus.value = normalizeStatus(res.shift?.status); shiftMessage.value = res.message; showNotice('success', 'Зв\'язок встановлено'); } 
  catch(e) { shiftStatus.value = 'error'; showNotice('danger', 'Помилка з\'єднання'); } 
  finally { loading.test = false; }
};

const openShift = async () => {
  loading.shift = true;
  try { await openFinanceShift(); shiftStatus.value = 'opened'; shiftMessage.value = 'Відкрито'; showNotice('success', 'Зміну відкрито'); } 
  catch(e) { showNotice('danger', 'Не вдалося відкрити зміну'); } finally { loading.shift = false; }
};

const closeShift = async () => {
  loading.shift = true;
  try { await closeFinanceShift(); shiftStatus.value = 'closed'; shiftMessage.value = 'Закрито'; showNotice('success', 'Зміну закрито'); } 
  catch(e) { showNotice('danger', 'Не вдалося закрити зміну'); } finally { loading.shift = false; }
};

const processQueue = async () => {
  loading.queue = true;
  try { await processFinanceQueue(); showNotice('success', 'Чергу оброблено'); loadSettings(); } 
  catch(e) { showNotice('danger', 'Помилка обробки'); } finally { loading.queue = false; }
};

onMounted(loadSettings);
</script>

<style scoped>
/* GENERAL */
.finance-page { font-family: 'Inter', sans-serif; background-color: #f8fafc; min-height: 100vh; }
.text-indigo { color: #6366f1; }
.bg-indigo { background-color: #6366f1; }
.x-small { font-size: 0.7rem; letter-spacing: 0.5px; }
.ls-1 { letter-spacing: 1px; }

/* --- TOASTS STYLES --- */
.custom-toast {
  width: 350px;
  background: #fff;
  border-radius: 12px;
  overflow: hidden;
  position: relative;
  transition: all 0.3s ease;
  border-left: 4px solid transparent;
}
.toast-success { border-left-color: #198754; }
.toast-error { border-left-color: #dc3545; }

.toast-icon-box {
  width: 50px; height: 100%; min-height: 60px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem;
}
.toast-success .toast-icon-box { background: rgba(25, 135, 84, 0.1); color: #198754; }
.toast-error .toast-icon-box { background: rgba(220, 53, 69, 0.1); color: #dc3545; }

.toast-content { flex: 1; }
.toast-title { font-weight: 700; font-size: 0.95rem; margin-bottom: 2px; }
.toast-msg { font-size: 0.85rem; color: #64748b; line-height: 1.3; }

.toast-progress {
  position: absolute; bottom: 0; left: 0; height: 3px; background: rgba(0,0,0,0.1); width: 100%;
  animation: toastProgress linear forwards;
}
@keyframes toastProgress { from { width: 100%; } to { width: 0%; } }

/* Toast Transitions */
.toast-slide-enter-active, .toast-slide-leave-active { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-slide-enter-from { transform: translateX(100%); opacity: 0; }
.toast-slide-leave-to { transform: translateX(100%); opacity: 0; }
.toast-slide-move { transition: transform 0.4s ease; }

/* KPI CARDS */
.stat-card { transition: transform 0.2s; border-radius: 12px; position: relative; }
.stat-card:hover { transform: translateY(-2px); }
.icon-shape { width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
.progress-line { height: 3px; width: 100%; position: absolute; bottom: 0; left: 0; }

/* --- GLASSMORPHISM STYLES --- */
.bg-gradient-primary {
  background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 50%, #0ea5e9 100%);
}

.circle-decoration {
  position: absolute; border-radius: 50%;
  background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 70%); pointer-events: none;
}
.circle-decoration.one { width: 300px; height: 300px; top: -100px; right: -50px; opacity: 0.6; }
.circle-decoration.two { width: 200px; height: 200px; bottom: -50px; left: -50px; opacity: 0.4; }

.badge-glass {
  background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 50px;
  font-size: 0.85rem; color: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}

.btn-icon-glass {
  width: 40px; height: 40px; border-radius: 50%;
  background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2);
  color: white; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;
}
.btn-icon-glass:hover { background: rgba(255, 255, 255, 0.25); transform: rotate(30deg); }

.status-dot-pulse {
  width: 10px; height: 10px; border-radius: 50%; position: relative;
}
.status-dot-pulse::after {
  content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
  width: 100%; height: 100%; border-radius: 50%; background: inherit; animation: pulse-ring 2s infinite; opacity: 0.6;
}
@keyframes pulse-ring { 0% { width: 100%; height: 100%; opacity: 0.6; } 100% { width: 300%; height: 300%; opacity: 0; } }

/* GLASS ACTION BUTTONS */
.btn-action-glass {
  background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.4);
  color: white; border-radius: 12px; transition: all 0.3s ease;
}
.btn-action-glass:hover { background: rgba(255, 255, 255, 0.35); transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); }

.btn-action-danger-glass {
  background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(255, 255, 255, 0.15);
  color: white; border-radius: 12px; transition: all 0.3s ease;
}
.btn-action-danger-glass:hover {
  background: rgba(220, 38, 38, 0.6); border-color: rgba(220, 38, 38, 0.8); box-shadow: 0 10px 20px rgba(0,0,0,0.2);
}

.btn-hover-effect {
  position: absolute; top: 0; left: 0; width: 100%; height: 100%;
  background: linear-gradient(120deg, transparent, rgba(255,255,255,0.3), transparent);
  transform: translateX(-100%); transition: 0.5s;
}
.group:hover .btn-hover-effect { transform: translateX(100%); }

/* DISABLED GLASS BUTTON (GRAY BLOCKED) */
.btn-glass-disabled {
  background: rgba(0, 0, 0, 0.15);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.5);
  pointer-events: none; /* Block clicks */
}

/* MODAL & GENERAL STYLES */
.modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.4); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1050; }
.modal-card { background: #fff; width: 100%; max-width: 500px; border-radius: 16px; padding: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); animation: modalUp 0.3s ease-out; overflow-y: auto; max-height: 90vh; }
.modal-card-lg { max-width: 700px; }
@keyframes modalUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.btn-white { background: #fff; color: #1e293b; border: none; transition: all 0.2s; }
.btn-white:hover { transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }

/* FORM ELEMENTS */
.input-group-modern { position: relative; display: flex; align-items: center; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 10px; padding: 0.5rem 1rem; transition: all 0.2s ease; }
.input-group-modern:focus-within { background: #fff; border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15); }
.input-group-modern .input-icon { color: #6c757d; font-size: 1.1rem; margin-right: 0.75rem; }
.input-group-modern .form-control { border: none; background: transparent; padding: 0; color: #344767; font-weight: 500; }
.input-group-modern .form-control:focus { box-shadow: none; }
.valid-icon { margin-left: 0.5rem; }

/* TIME INPUTS */
.time-card { transition: transform 0.2s; }
.time-card:hover { transform: translateY(-2px); }
.time-input::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.6; }
.time-input:focus { outline: none; box-shadow: none; }

/* ANIMATIONS */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }
.pulse-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 2px rgba(255,255,255,0.5); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-fade-enter-active { transition: all 0.3s ease-out; }
.slide-fade-enter-from { transform: translateY(-20px); opacity: 0; }

/* TABLE */
.custom-table th { font-weight: 600; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; }
.custom-table td { padding: 1rem 0.75rem; border-bottom: 1px solid #f1f5f9; }

/* Custom Scrollbar for Table */
.custom-scrollbar {
  overflow-y: auto;
}
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: #f1f5f9;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 3px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>