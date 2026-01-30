<template>
  <div class="app-container">
    <div class="content-wrapper max-width-1100">
      
      <header class="page-header">
        <div class="header-content">
          <div class="brand-badge">
            <div class="brand-icon">
              <i class="bi bi-box-seam-fill"></i>
            </div>
            <div class="brand-text">
              <h1 class="title">Нова Пошта <span class="badge-api">API 2.0</span></h1>
              <p class="subtitle">Керування логістикою та відділеннями</p>
            </div>
          </div>
          
          <button 
            class="btn-save" 
            :class="{ 'saving': saving }"
            :disabled="saving" 
            @click="save"
          >
            <div class="btn-content">
              <span v-if="saving" class="loader"></span>
              <i v-else class="bi bi-cloud-check-fill"></i>
              <span>{{ saving ? 'Збереження...' : 'Зберегти зміни' }}</span>
            </div>
          </button>
        </div>
      </header>

      <div class="grid-layout">
        
        <div class="grid-item">
          <div class="glass-card accent-border">
            <div class="card-header">
              <div class="icon-circle text-indigo">
                <i class="bi bi-shield-lock-fill"></i>
              </div>
              <div>
                <h3>API Інтеграція</h3>
                <p>Ключ доступу до сервісу</p>
              </div>
            </div>

            <div class="card-body">
              <div class="form-group">
                <label class="floating-label">API Ключ</label>
                <div class="input-modern-wrapper" :class="{ 'has-focus': focusApi }">
                  <input 
                    v-model.trim="form.api_key" 
                    type="text" 
                    placeholder="Введіть ваш ключ..." 
                    @focus="focusApi = true" 
                    @blur="focusApi = false"
                  />
                  <button 
                    class="action-btn" 
                    :disabled="fetching || !form.api_key" 
                    @click="fetchRefs"
                    title="Синхронізувати дані"
                  >
                    <i class="bi bi-arrow-repeat" :class="{ 'spin': fetching }"></i>
                  </button>
                </div>
                <div class="status-indicator" :class="form.sender_ref ? 'active' : 'inactive'">
                  <span class="dot"></span>
                  {{ form.sender_ref ? 'Підключено та синхронізовано' : 'Очікує підключення' }}
                </div>
              </div>
            </div>
          </div>

          <div class="glass-card mt-4">
             <div class="card-header pb-0">
              <div class="icon-circle text-slate">
                <i class="bi bi-database-fill"></i>
              </div>
              <div>
                <h3>Системні дані</h3>
                <p>Заповнюються автоматично</p>
              </div>
            </div>
            <div class="card-body">
              <div class="read-only-grid">
                <div class="ro-item">
                  <span class="label">Sender Ref</span>
                  <span class="value">{{ form.sender_ref || '—' }}</span>
                </div>
                <div class="ro-item">
                  <span class="label">Contact Ref</span>
                  <span class="value">{{ form.contact_ref || '—' }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="grid-item">
          <div class="glass-card overflow-visible">
            <div class="card-header">
              <div class="icon-circle text-rose">
                <i class="bi bi-geo-alt-fill"></i>
              </div>
              <div>
                <h3>Дані відправника</h3>
                <p>Звідки будуть їхати посилки</p>
              </div>
            </div>

            <div class="card-body d-flex flex-column gap-4">
              
              <div class="form-group">
                <label class="floating-label">Контактний телефон</label>
                <div class="input-modern-wrapper">
                  <i class="bi bi-telephone prefix-icon"></i>
                  <input 
                    v-model.trim="form.sender_phone" 
                    type="text" 
                    class="with-prefix"
                    placeholder="380 XX XXX XX XX" 
                  />
                </div>
              </div>

              <div class="form-group position-relative z-2">
                <label class="floating-label">Місто відправлення</label>
                <div class="input-modern-wrapper">
                  <i class="bi bi-buildings prefix-icon"></i>
                  <input
                    v-model="cityQuery"
                    type="text"
                    class="with-prefix"
                    placeholder="Почніть вводити назву..."
                    @focus="showCityDropdown = true"
                    @blur="scheduleCloseCity"
                  />
                  <div v-if="cityLoading" class="loader-mini"></div>
                </div>
                
                <transition name="dropdown">
                  <div v-if="showCityDropdown && (cityOptions.length || cityError)" class="premium-dropdown">
                    <div v-if="cityError" class="p-3 text-danger text-center small">{{ cityError }}</div>
                    <ul v-else class="options-list">
                      <li 
                        v-for="city in cityOptions" 
                        :key="city.ref" 
                        @mousedown.prevent="selectCity(city)"
                      >
                        <div class="opt-title">{{ city.name }}</div>
                        <div class="opt-desc">{{ city.area }}</div>
                      </li>
                    </ul>
                  </div>
                </transition>
              </div>

              <div class="form-group position-relative z-1" :class="{ 'opacity-50 pointer-events-none': !form.sender_city_ref }">
                <label class="floating-label">Відділення / Поштомат</label>
                <div class="input-modern-wrapper">
                  <i class="bi bi-box2 prefix-icon"></i>
                  <input
                    v-model="warehouseQuery"
                    type="text"
                    class="with-prefix"
                    placeholder="Номер або адреса..."
                    :disabled="!form.sender_city_ref"
                    @focus="onWarehouseFocus"
                    @blur="scheduleCloseWarehouse"
                  />
                  <div v-if="warehouseLoading" class="loader-mini"></div>
                </div>

                <transition name="dropdown">
                  <div v-if="showWarehouseDropdown && warehouseOptions.length" class="premium-dropdown">
                    <ul class="options-list">
                      <li 
                        v-for="wh in warehouseOptions" 
                        :key="wh.ref" 
                        @mousedown.prevent="selectWarehouse(wh)"
                        class="d-flex align-items-center gap-2"
                      >
                        <i class="bi bi-geo text-muted small"></i>
                        <span class="opt-title small">{{ wh.name }}</span>
                      </li>
                    </ul>
                  </div>
                </transition>
              </div>

            </div>
          </div>
        </div>

      </div>
    </div>

    <transition name="toast-pop">
      <div v-if="toast" class="premium-toast">
        <div class="toast-indicator"></div>
        <i class="bi bi-check-circle-fill"></i>
        <span>{{ toast }}</span>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import http from '@/crm/api/http';

const form = reactive({
  api_key: '',
  sender_ref: '',
  contact_ref: '',
  sender_phone: '',
  sender_city_ref: '',
  sender_warehouse_ref: '',
});

const saving = ref(false);
const fetching = ref(false);
const focusApi = ref(false);
const toast = ref('');
let toastTimer = null;

const cityQuery = ref('');
const warehouseQuery = ref('');
const cityOptions = ref([]);
const warehouseOptions = ref([]);
const cityLoading = ref(false);
const warehouseLoading = ref(false);
const cityError = ref('');
const showCityDropdown = ref(false);
const showWarehouseDropdown = ref(false);
let cityTimer = null;
let warehouseTimer = null;

const showToast = (message) => {
  toast.value = message;
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toast.value = ''; }, 3000);
};

const load = async () => {
  try {
    const { data } = await http.get('/settings/nova-poshta');
    if (data) Object.assign(form, data);
    if (data?.sender_city_ref) {
      cityQuery.value = data.sender_city_ref;
    }
    if (data?.sender_warehouse_ref) {
      warehouseQuery.value = data.sender_warehouse_ref;
    }
  } catch (e) {
    // no-op
  }
};

const save = async () => {
  saving.value = true;
  try {
    await http.post('/settings/nova-poshta', form);
    showToast('Налаштування збережено');
  } finally {
    saving.value = false;
  }
};

const fetchRefs = async () => {
  if (!form.api_key) return;
  fetching.value = true;
  try {
    const { data } = await http.post('/settings/nova-poshta/fetch-refs', {
      api_key: form.api_key,
    });
    form.sender_ref = data.sender_ref || '';
    form.contact_ref = data.contact_ref || '';
    form.sender_phone = data.sender_phone || '';
    showToast('Рефи підтягнуто');
  } finally {
    fetching.value = false;
  }
};

watch(cityQuery, (val) => {
  cityError.value = '';
  if (cityTimer) clearTimeout(cityTimer);
  if (!val || val.length < 3) {
    cityOptions.value = [];
    return;
  }
  cityTimer = setTimeout(() => loadCities(val), 600);
});

watch(warehouseQuery, (val) => {
  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (!form.sender_city_ref) return;
  warehouseTimer = setTimeout(() => loadWarehouses(val), 600);
});

const loadCities = async (query) => {
  cityLoading.value = true;
  cityError.value = '';
  try {
    const { data } = await http.get('/nova-poshta/cities', {
      params: { q: query, limit: 20 },
    });
    cityOptions.value = data?.data || data || [];
  } catch (e) {
    cityError.value = 'Помилка завантаження';
  } finally {
    cityLoading.value = false;
  }
};

const loadWarehouses = async (query) => {
  warehouseLoading.value = true;
  try {
    const { data } = await http.get('/nova-poshta/warehouses', {
      params: { city_ref: form.sender_city_ref, q: query || '', limit: 50 },
    });
    warehouseOptions.value = data?.data || data || [];
  } finally {
    warehouseLoading.value = false;
  }
};

const selectCity = (city) => {
  form.sender_city_ref = city.ref || '';
  cityQuery.value = city.name || '';
  showCityDropdown.value = false;
  form.sender_warehouse_ref = '';
  warehouseQuery.value = '';
  warehouseOptions.value = [];
};

const selectWarehouse = (wh) => {
  form.sender_warehouse_ref = wh.ref || '';
  warehouseQuery.value = wh.name || '';
  showWarehouseDropdown.value = false;
};

const onWarehouseFocus = () => {
  showWarehouseDropdown.value = true;
  if (form.sender_city_ref && !warehouseOptions.value.length) loadWarehouses('');
};

const scheduleCloseCity = () => setTimeout(() => { showCityDropdown.value = false; }, 200);
const scheduleCloseWarehouse = () => setTimeout(() => { showWarehouseDropdown.value = false; }, 200);

onMounted(load);
</script>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

/* --- Global Layout Variables --- */
.app-container {
  background: #f3f4f6;
  background-image: radial-gradient(at 0% 0%, rgba(224, 231, 255, 0.4) 0px, transparent 50%),
                    radial-gradient(at 100% 0%, rgba(254, 215, 170, 0.3) 0px, transparent 50%);
  min-height: 100vh;
  font-family: 'Plus Jakarta Sans', sans-serif;
  color: #1e293b;
  padding-bottom: 4rem;
}

.max-width-1100 { max-width: 1100px; margin: 0 auto; padding: 0 1.5rem; }

/* --- Header --- */
.page-header {
  padding: 2.5rem 0;
}

.header-content {
  display: flex; justify-content: space-between; align-items: flex-end;
  flex-wrap: wrap; gap: 1rem;
}

.brand-badge {
  display: flex; align-items: center; gap: 1rem;
}

.brand-icon {
  width: 56px; height: 56px;
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: white;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.75rem;
  box-shadow: 0 10px 25px -5px rgba(220, 38, 38, 0.4);
}

.title { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.025em; margin: 0; line-height: 1.2; }
.subtitle { color: #64748b; font-size: 0.95rem; margin-top: 4px; font-weight: 500; }
.badge-api { 
  font-size: 0.75rem; background: #dbeafe; color: #2563eb; 
  padding: 2px 8px; border-radius: 6px; vertical-align: middle; 
}

/* --- Save Button --- */
.btn-save {
  background: #111827;
  color: white;
  border: none;
  padding: 0.85rem 2rem;
  border-radius: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.btn-save:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.15);
  background: #000;
}

.btn-save:disabled { opacity: 0.7; transform: none; }
.btn-content { display: flex; align-items: center; gap: 0.75rem; }

/* --- Grid Layout --- */
.grid-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 2rem;
}
@media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }

/* --- Glass Card --- */
.glass-card {
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(12px);
  border-radius: 24px;
  border: 1px solid rgba(255, 255, 255, 0.5);
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
  overflow: hidden;
  transition: transform 0.3s ease;
}
.glass-card.accent-border { border-top: 4px solid #6366f1; }
.glass-card.overflow-visible { overflow: visible; }

.card-header { padding: 1.75rem; display: flex; align-items: flex-start; gap: 1rem; border-bottom: 1px solid #f1f5f9; }
.card-header h3 { font-size: 1.1rem; font-weight: 700; margin: 0; color: #0f172a; }
.card-header p { font-size: 0.85rem; color: #64748b; margin: 2px 0 0 0; }

.icon-circle {
  width: 42px; height: 42px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.25rem;
  background: #f8fafc;
}
.text-indigo { color: #6366f1; background: #e0e7ff; }
.text-rose { color: #e11d48; background: #ffe4e6; }
.text-slate { color: #475569; background: #f1f5f9; }

.card-body { padding: 1.75rem; }

/* --- Modern Inputs --- */
.form-group { margin-bottom: 0.5rem; }
.floating-label { display: block; font-size: 0.8rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.02em; }

.input-modern-wrapper {
  position: relative;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  transition: all 0.2s ease;
  display: flex; align-items: center;
}

.input-modern-wrapper:hover { background: #fff; border-color: #cbd5e1; }
.input-modern-wrapper:focus-within { 
  background: #fff; 
  border-color: #6366f1; 
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15); 
}

.input-modern-wrapper input {
  width: 100%;
  border: none;
  background: transparent;
  padding: 14px 16px;
  font-size: 0.95rem;
  font-weight: 500;
  color: #0f172a;
  outline: none;
  border-radius: 14px;
}

.input-modern-wrapper input.with-prefix { padding-left: 46px; }
.prefix-icon {
  position: absolute; left: 16px; 
  color: #94a3b8; font-size: 1.1rem;
  transition: color 0.2s;
}
.input-modern-wrapper:focus-within .prefix-icon { color: #6366f1; }

/* Action Button inside input */
.action-btn {
  border: none; background: transparent;
  color: #6366f1;
  padding: 0 16px;
  cursor: pointer;
  transition: transform 0.2s;
}
.action-btn:hover { color: #4338ca; transform: scale(1.1); }
.action-btn:disabled { color: #cbd5e1; cursor: default; transform: none; }

/* Loaders */
.spin { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }

.loader-mini {
  width: 16px; height: 16px;
  border: 2px solid #e2e8f0;
  border-top-color: #6366f1;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-right: 16px;
}

.loader {
  width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

/* Status Indicator */
.status-indicator {
  margin-top: 10px;
  font-size: 0.8rem;
  font-weight: 500;
  display: flex; align-items: center; gap: 8px;
  color: #94a3b8;
}
.status-indicator.active { color: #10b981; }
.status-indicator .dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #cbd5e1;
}
.status-indicator.active .dot { background: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2); }

/* Read Only Grid */
.read-only-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
}
.ro-item {
  background: #f8fafc;
  padding: 12px;
  border-radius: 12px;
  display: flex; flex-direction: column;
}
.ro-item .label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 600; }
.ro-item .value { font-size: 0.9rem; font-weight: 600; color: #334155; font-family: monospace; margin-top: 4px; }

/* Premium Dropdown */
.premium-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  left: 0; right: 0;
  background: white;
  border: 1px solid #f1f5f9;
  border-radius: 16px;
  box-shadow: 0 20px 40px -5px rgba(0, 0, 0, 0.15);
  z-index: 2000;
  overflow: hidden;
  max-height: 280px;
  overflow-y: auto;
}
.options-list { list-style: none; margin: 0; padding: 6px; }
.options-list li {
  padding: 10px 14px;
  border-radius: 10px;
  cursor: pointer;
  transition: background 0.15s;
}
.options-list li:hover { background: #eff6ff; }
.opt-title { font-weight: 600; font-size: 0.9rem; color: #1e293b; }
.opt-desc { font-size: 0.8rem; color: #64748b; }

/* Transitions */
.dropdown-enter-active, .dropdown-leave-active { transition: all 0.2s ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: translateY(-8px) scale(0.98); }

/* Toast */
.premium-toast {
  position: fixed;
  bottom: 2rem; left: 50%; transform: translateX(-50%);
  background: white;
  padding: 12px 24px;
  border-radius: 99px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.12);
  display: flex; align-items: center; gap: 12px;
  z-index: 1000;
  font-weight: 600;
  color: #0f172a;
  border: 1px solid rgba(255,255,255,0.8);
}
.toast-indicator { width: 4px; height: 4px; background: #10b981; border-radius: 50%; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3); }
.bi-check-circle-fill { color: #10b981; font-size: 1.1rem; }

.toast-pop-enter-active, .toast-pop-leave-active { transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.toast-pop-enter-from, .toast-pop-leave-to { opacity: 0; transform: translate(-50%, 20px) scale(0.9); }
</style>
