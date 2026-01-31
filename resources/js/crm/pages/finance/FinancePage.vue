<template>
  <div class="finance-page container-fluid py-4">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
      <div>
        <h1 class="h3 fw-bold text-dark mb-1 d-flex align-items-center">
          <i class="bi bi-wallet2 text-primary me-2"></i>
          Фіскалізація & Каса
        </h1>
        <p class="text-muted mb-0 small">Управління ПРРО Checkbox та моніторинг транзакцій</p>
      </div>
      
      <div class="d-flex align-items-center gap-2">
        <div class="d-flex align-items-center px-3 py-2 bg-white rounded-pill shadow-sm border border-light">
          <span class="pulse-dot me-2" :class="connectionStatusColor"></span>
          <span class="small fw-semibold text-muted">Checkbox API</span>
        </div>

        <button class="btn btn-white shadow-sm border border-light rounded-pill px-3 fw-semibold" @click="openAuthModal">
          <i class="bi bi-gear-fill text-muted me-2"></i>
          Налаштування
        </button>
      </div>
    </div>

    <transition name="slide-fade">
      <div v-if="notice.message" class="alert modern-alert mb-4 shadow-sm border-0 d-flex align-items-center" :class="`alert-${notice.type}`">
        <i class="bi fs-5 me-3" :class="notice.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <div class="fw-medium">{{ notice.message }}</div>
      </div>
    </transition>

    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 overflow-hidden position-relative stat-card">
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
            <div class="mt-2 x-small text-muted" v-if="form.enabled">
              <i class="bi bi-clock me-1"></i> {{ form.open_time }} - {{ form.close_time }}
            </div>
          </div>
          <div class="progress-line bg-indigo" :style="{ opacity: form.enabled ? 1 : 0 }"></div>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      
      <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark">Активність фіскалізації</h6>
            <span class="badge bg-light text-muted fw-normal">за 24 години</span>
          </div>
          <div class="card-body p-0 position-relative" style="min-height: 320px;">
             <div v-if="loading.chart" class="position-absolute w-100 h-100 d-flex justify-content-center align-items-center bg-white z-2">
                <div class="spinner-border text-primary text-opacity-25" role="status"></div>
             </div>
             
             <div class="px-2 pt-2">
               <apexchart 
                 type="area" 
                 height="300" 
                 :options="chartOptions" 
                 :series="chartSeries"
               ></apexchart>
             </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card border-0 shadow-lg h-100 overflow-hidden bg-gradient-primary text-white position-relative">
          <div class="circle-decoration one"></div>
          <div class="circle-decoration two"></div>
          
          <div class="card-body position-relative z-1 d-flex flex-column justify-content-between p-4">
            
            <div class="mb-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge bg-white bg-opacity-20 backdrop-blur fw-normal">Каса №1</span>
                <button class="btn btn-sm btn-link text-white text-opacity-75 p-0" @click="testConnection">
                  <i class="bi bi-arrow-repeat" :class="{'spin': loading.test}"></i>
                </button>
              </div>
              <h3 class="fw-bold mb-1">{{ shiftStatus === 'opened' ? 'Зміна відкрита' : 'Зміна закрита' }}</h3>
              <p class="text-white text-opacity-75 small">{{ shiftMessage }}</p>
            </div>

            <div class="actions-grid">
               <button 
                class="btn btn-action-white w-100 mb-2" 
                @click="openShift" 
                :disabled="shiftStatus === 'opened' || loading.shift"
              >
                 <span v-if="loading.shift && shiftStatus !== 'opened'" class="spinner-border spinner-border-sm me-2"></span>
                 <i v-else class="bi bi-unlock-fill me-2 text-success"></i>
                 <span class="text-dark fw-bold">Відкрити зміну</span>
              </button>

              <button 
                class="btn btn-action-danger w-100" 
                @click="closeShift" 
                :disabled="shiftStatus === 'closed' || loading.shift"
              >
                 <span v-if="loading.shift && shiftStatus === 'opened'" class="spinner-border spinner-border-sm me-2"></span>
                 <i v-else class="bi bi-lock-fill me-2"></i>
                 <span>Закрити зміну</span>
              </button>

              <div class="mt-4 pt-3 border-top border-white border-opacity-10">
                 <button class="btn btn-sm btn-link text-white text-opacity-75 text-decoration-none px-0" @click="processQueue" :disabled="queueCount === 0 || loading.queue">
                    <i class="bi bi-send me-1"></i> Відправити чеки з черги ({{ queueCount }})
                 </button>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="fw-bold mb-0 text-dark">Історія операцій</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-table">
          <thead class="bg-light">
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
                <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                Операцій за сьогодні не знайдено
              </td>
            </tr>
            <tr v-for="receipt in receipts" :key="receipt.id">
              <td class="ps-4 fw-medium text-dark">{{ formatReceiptTime(receipt.created_at) }}</td>
              <td>
                 <span class="badge rounded-pill fw-normal" :class="receiptStatusClass(receipt.status)">
                   {{ receiptStatusLabel(receipt.status) }}
                 </span>
              </td>
              <td class="text-muted small">{{ receiptTypeLabel(receipt.type) }}</td>
              <td class="text-end fw-bold text-dark">{{ formatReceiptAmount(receipt.total_amount) }}</td>
              <td class="pe-4 text-end">
                <a v-if="receipt.check_link" :href="receipt.check_link" target="_blank" class="btn btn-sm btn-light rounded-pill">
                  Чек <i class="bi bi-box-arrow-up-right ms-1"></i>
                </a>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <transition name="fade">
      <div v-if="showAuthModal" class="modal-backdrop" @click.self="closeAuthModal">
        <div class="modal-card">
          <div class="modal-header border-bottom mb-4 pb-3">
             <h5 class="fw-bold m-0">Налаштування ПРРО</h5>
             <button class="btn-close" @click="closeAuthModal"></button>
          </div>
          
          <div class="row g-3">
             <div class="col-12"><label class="form-label fw-bold small text-muted">API CREDENTIALS</label></div>
             <div class="col-12">
               <input v-model="form.license_key" type="password" class="form-control" :placeholder="hasLicenseKey ? 'Ключ ліцензії (встановлено)' : 'Ключ ліцензії'">
             </div>
             <div class="col-md-6">
               <input v-model="form.login" type="text" class="form-control" placeholder="Логін касира">
             </div>
             <div class="col-md-6">
               <input v-model="form.password" type="password" class="form-control" :placeholder="hasPassword ? 'Пароль (встановлено)' : 'Пароль'">
             </div>

             <div class="col-12 mt-4"><label class="form-label fw-bold small text-muted">АВТОМАТИЗАЦІЯ</label></div>
             
             <div class="col-12 mb-2">
               <div class="form-check form-switch p-0 d-flex justify-content-between align-items-center border rounded p-3">
                 <label class="form-check-label ms-2 fw-medium" for="autoSwitch">Увімкнути авто-зміни</label>
                 <input class="form-check-input m-0" type="checkbox" id="autoSwitch" v-model="form.enabled">
               </div>
             </div>

             <div class="col-4">
               <label class="small text-muted mb-1">Відкриття</label>
               <input v-model="form.open_time" type="time" class="form-control">
             </div>
             <div class="col-4">
               <label class="small text-muted mb-1">Фіскалізація</label>
               <input v-model="form.queue_process_time" type="time" class="form-control">
             </div>
             <div class="col-4">
               <label class="small text-muted mb-1">Закриття</label>
               <input v-model="form.close_time" type="time" class="form-control">
             </div>
          </div>

          <div class="modal-footer pt-4 mt-4 border-top d-flex justify-content-end gap-2">
            <button class="btn btn-light" @click="closeAuthModal">Скасувати</button>
            <button class="btn btn-primary px-4" @click="saveSettings" :disabled="loading.save">
               {{ loading.save ? 'Збереження...' : 'Зберегти' }}
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
import VueApexCharts from "vue3-apexcharts";
import {
  fetchFinanceSettings,
  saveFinanceSettings,
  testFinanceConnection,
  openFinanceShift,
  closeFinanceShift,
  processFinanceQueue,
} from '@/crm/services/financeApi';

const apexchart = VueApexCharts;

const loading = reactive({ save: false, test: false, shift: false, queue: false, chart: false });
const notice = reactive({ type: '', message: '' });
const shiftStatus = ref('unknown'); 
const shiftMessage = ref('Отримання статусу...');
const queueCount = ref(0);
const showAuthModal = ref(false);
const hasLicenseKey = ref(false);
const hasPassword = ref(false);
const receipts = ref([]);
const dailyTotalCents = ref(0);

const form = reactive({
  api_url: '', license_key: '', login: '', password: '',
  open_time: '08:00', close_time: '23:00', queue_process_time: '08:30',
  enabled: true, queue_enabled: true,
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

// --- Chart Data ---
const chartSeries = computed(() => {
  const hoursData = new Array(24).fill(0);
  if (receipts.value.length) {
    receipts.value.forEach(r => {
      const h = new Date(r.created_at).getHours();
      if(h >= 0 && h < 24) hoursData[h]++;
    });
  }
  return [{ name: 'Чеки', data: hoursData }];
});

const chartOptions = computed(() => ({
  chart: { type: 'area', fontFamily: 'inherit', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: 3 },
  fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100] } },
  colors: ['#6366f1'], // Modern Indigo color
  dataLabels: { enabled: false },
  xaxis: { 
    categories: Array.from({length: 24}, (_, i) => `${i}:00`),
    axisBorder: { show: false },
    axisTicks: { show: false },
    labels: { style: { colors: '#9ca3af', fontSize: '10px' } }
  },
  yaxis: { show: false },
  grid: { show: true, borderColor: '#f3f4f6', strokeDashArray: 4, padding: { top: 0, right: 0, bottom: 0, left: 10 } },
  tooltip: { theme: 'light', x: { show: true } }
}));

// --- Helpers ---
const receiptStatusClass = (s) => ({
  success: 'bg-success-subtle text-success',
  error: 'bg-danger-subtle text-danger',
  pending: 'bg-warning-subtle text-warning-emphasis'
}[s] || 'bg-light text-muted');

const receiptStatusLabel = (s) => ({ success: 'Успішно', error: 'Помилка', pending: 'Черга' }[s] || s);
const receiptTypeLabel = (t) => ({ sell: 'Продаж', return: 'Повернення', service_in: 'Внесення', service_out: 'Вилучення' }[t] || t);
const formatReceiptAmount = (a) => formatCurrency(Number(a||0)/100);
const formatReceiptTime = (v) => v ? new Date(v).toLocaleTimeString('uk-UA', {hour:'2-digit', minute:'2-digit'}) : '-';

// --- Actions (Simplified for brevity) ---
const showNotice = (t, m) => { notice.type = t; notice.message = m; setTimeout(()=>notice.message='', 4000); };
const openAuthModal = () => showAuthModal.value = true;
const closeAuthModal = () => showAuthModal.value = false;

const loadSettings = async () => {
  loading.chart = true;
  try {
    const data = await fetchFinanceSettings();
    const s = data.settings || {};
    Object.assign(form, {
      ...s, 
      open_time: s.open_time?.slice(0,5) || '08:00',
      close_time: s.close_time?.slice(0,5) || '23:00',
      queue_process_time: s.queue_process_time?.slice(0,5) || '08:30'
    });
    hasLicenseKey.value = !!s.has_license_key;
    hasPassword.value = !!s.has_password;
    queueCount.value = data.queue?.waiting || 0;
    receipts.value = data.receipts?.items || [];
    dailyTotalCents.value = data.receipts?.daily_total || 0;
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
  try { await saveFinanceSettings(form); showNotice('success', 'Збережено'); closeAuthModal(); } 
  catch(e) { showNotice('danger', 'Помилка'); } 
  finally { loading.save = false; }
};

const testConnection = async () => {
  loading.test = true;
  try { const res = await testFinanceConnection(); shiftStatus.value = normalizeStatus(res.shift?.status); shiftMessage.value = res.message; showNotice('success', 'OK'); } 
  catch(e) { shiftStatus.value = 'error'; showNotice('danger', 'Error'); } 
  finally { loading.test = false; }
};

const openShift = async () => {
  loading.shift = true;
  try { await openFinanceShift(); shiftStatus.value = 'opened'; shiftMessage.value = 'Відкрито'; showNotice('success', 'Зміну відкрито'); } 
  catch(e) { showNotice('danger', 'Помилка'); } finally { loading.shift = false; }
};

const closeShift = async () => {
  loading.shift = true;
  try { await closeFinanceShift(); shiftStatus.value = 'closed'; shiftMessage.value = 'Закрито'; showNotice('success', 'Зміну закрито'); } 
  catch(e) { showNotice('danger', 'Помилка'); } finally { loading.shift = false; }
};

const processQueue = async () => {
  loading.queue = true;
  try { await processFinanceQueue(); showNotice('success', 'Чергу оброблено'); loadSettings(); } 
  catch(e) { showNotice('danger', 'Помилка'); } finally { loading.queue = false; }
};

onMounted(loadSettings);
</script>

<style scoped>
/* GENERAL */
.finance-page { font-family: 'Inter', sans-serif; background-color: #f8fafc; min-height: 100vh; }
.text-indigo { color: #6366f1; }
.bg-indigo { background-color: #6366f1; }
.x-small { font-size: 0.7rem; letter-spacing: 0.5px; }

/* KPI CARDS */
.stat-card { transition: transform 0.2s; border-radius: 12px; }
.stat-card:hover { transform: translateY(-2px); }
.icon-shape { width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
.progress-line { height: 3px; width: 100%; position: absolute; bottom: 0; left: 0; }

/* GRADIENT CARD (CONTROL PANEL) */
.bg-gradient-primary { background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); }
.circle-decoration { position: absolute; border-radius: 50%; background: rgba(255,255,255,0.1); }
.circle-decoration.one { width: 150px; height: 150px; top: -40px; right: -40px; }
.circle-decoration.two { width: 100px; height: 100px; bottom: -20px; left: 20px; }
.backdrop-blur { backdrop-filter: blur(4px); }

/* BUTTONS */
.btn-action-white { background: #fff; border: none; padding: 12px; border-radius: 10px; transition: all 0.2s; }
.btn-action-white:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.btn-action-danger { background: rgba(0,0,0,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 12px; border-radius: 10px; transition: all 0.2s; }
.btn-action-danger:hover { background: rgba(220,38,38,0.8); border-color: transparent; }

/* ANIMATIONS */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }
.pulse-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 2px rgba(255,255,255,0.5); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-fade-enter-active { transition: all 0.3s ease-out; }
.slide-fade-enter-from { transform: translateY(-20px); opacity: 0; }

/* MODAL */
.modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.4); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 1050; }
.modal-card { background: #fff; width: 100%; max-width: 500px; border-radius: 16px; padding: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); animation: modalUp 0.3s ease-out; }
@keyframes modalUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

/* TABLE */
.custom-table th { font-weight: 600; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; }
.custom-table td { padding: 1rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
.custom-table tr:last-child td { border-bottom: none; }
</style>