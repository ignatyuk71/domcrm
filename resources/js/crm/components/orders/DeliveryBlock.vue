<template>
  <div class="delivery-card glass-card">
    <div class="d-flex flex-column gap-4">
      
      <section>
        <label class="form-label-custom">Спосіб доставки</label>
        <div class="delivery-toggle-group">
          <input type="radio" class="btn-check" id="dt_warehouse" value="warehouse" v-model="local.delivery_type" @change="resetDeliveryFields">
          <label class="toggle-item" for="dt_warehouse">
            <i class="bi bi-box-seam"></i>
            <span>Відділення</span>
          </label>

          <input type="radio" class="btn-check" id="dt_courier" value="courier" v-model="local.delivery_type" @change="resetDeliveryFields">
          <label class="toggle-item" for="dt_courier">
            <i class="bi bi-geo-fill"></i>
            <span>Курʼєр</span>
          </label>
        </div>
      </section>

      <section class="position-relative">
        <label class="form-label-custom">Місто доставки</label>
        <div class="input-modern-wrapper">
          <i class="bi bi-search prefix-icon"></i>
          <input
            type="text"
            autocomplete="off"
            class="form-control input-modern with-prefix"
            :class="{ 'is-invalid': errors.city_name }"
            v-model="cityQuery"
            @focus="showCityDropdown = true"
            @blur="scheduleCloseCity"
            placeholder="Оберіть місто..."
          />
          <div v-if="cityLoading" class="input-suffix">
            <div class="loader-mini"></div>
          </div>
          упаіваівфваіф
          <div v-if="showCityDropdown && (cityOptions.length || cityError)" class="premium-dropdown">
            <div v-if="cityError" class="dropdown-item text-danger small">{{ cityError }}</div>
            <button
              v-for="city in cityOptions"
              :key="city.ref"
              type="button"
              class="dropdown-item"
              @mousedown.prevent="selectCity(city)"
            >
              <div class="d-flex align-items-center">
                <i class="bi bi-geo-alt me-2 text-muted"></i>
                <div>
                  <div class="fw-bold">{{ city.name }}</div>
                  <div class="text-muted smaller">{{ city.area }}</div>
                </div>
              </div>
            </button>
          </div>
        </div>
        
        <div class="invalid-feedback d-block" v-if="errors.city_name">{{ errors.city_name }}</div>
      </section>

      <section v-if="local.delivery_type !== 'courier'" class="position-relative animate-fade-in">
        <label class="form-label-custom">Відділення або поштомат</label>
        <div class="input-modern-wrapper" :class="{ 'opacity-50': !local.city_ref }">
          <i class="bi bi-building-up prefix-icon"></i>
          <input
            type="text"
            autocomplete="off"
            class="form-control input-modern with-prefix"
            :class="{ 'is-invalid': errors.warehouse_name }"
            v-model="warehouseQuery"
            @focus="onWarehouseFocus"
            @blur="scheduleCloseWarehouse"
            :disabled="!local.city_ref"
            placeholder="Введіть номер або назву..."
          />
          <div v-if="warehouseLoading" class="input-suffix">
            <div class="loader-mini"></div>
          </div>

          <div v-if="showWarehouseDropdown && warehouseOptions.length" class="premium-dropdown">
            <button
              v-for="wh in warehouseOptions"
              :key="wh.ref || wh.name"
              type="button"
              class="dropdown-item"
              @mousedown.prevent="selectWarehouse(wh)"
            >
              <div class="d-flex gap-2 text-start">
                <i class="bi" :class="wh.name.includes('Поштомат') ? 'bi-mailbox text-info' : 'bi-door-open text-primary'"></i>
                <span class="small fw-medium">{{ wh.name }}</span>
              </div>
            </button>
          </div>
        </div>

        <div class="invalid-feedback d-block" v-if="errors.warehouse_name">{{ errors.warehouse_name }}</div>
        
        <div class="mt-3">
          <label class="form-label-custom">Додатково (ПІБ або коментар)</label>
          <input class="form-control input-modern input-modern-small" v-model="local.address_note" placeholder="Наприклад: Отримувач інша особа" />
        </div>
      </section>

      <section v-else class="animate-fade-in">
        <div class="mb-3 position-relative">
          <label class="form-label-custom">Вулиця</label>
          <div class="input-modern-wrapper" :class="{ 'opacity-50': !local.settlement_ref }">
            <i class="bi bi-signpost-split prefix-icon"></i>
            <input
              type="text"
              autocomplete="off"
              class="form-control input-modern with-prefix"
              :class="{ 'is-invalid': errors.street_name }"
              v-model="streetQuery"
              @focus="onStreetFocus"
              @blur="scheduleCloseStreet"
              :disabled="!local.settlement_ref"
              placeholder="Почніть вводити (2+ символи)..."
            />
            <div v-if="streetLoading" class="input-suffix">
              <div class="loader-mini"></div>
            </div>

            <div v-if="showStreetDropdown && (streetOptions.length || streetLoading)" class="premium-dropdown">
              <div v-if="streetLoading" class="dropdown-item text-muted small">Пошук...</div>
              <button 
                v-for="s in streetOptions" 
                :key="s.ref" 
                type="button"
                class="dropdown-item" 
                @mousedown.prevent="selectStreet(s)"
              >
                {{ s.name }}
              </button>
            </div>
          </div>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label-custom">Будинок</label>
            <input class="form-control input-modern" :class="{ 'is-invalid': errors.building }" v-model="local.building" placeholder="10/А" />
          </div>
          <div class="col-6">
            <label class="form-label-custom">Кв./Офіс</label>
            <input class="form-control input-modern" v-model="local.apartment" placeholder="42" />
          </div>
        </div>
      </section>

      <section>
        <label class="form-label-custom">Платник доставки</label>
        <div class="payer-selector">
          <div class="payer-option" :class="{ active: local.payer === 'recipient' }" @click="local.payer = 'recipient'">
            <i class="bi bi-person"></i>
            <span>Отримувач</span>
          </div>
          <div class="payer-option" :class="{ active: local.payer === 'sender' }" @click="local.payer = 'sender'">
            <i class="bi bi-shop"></i>
            <span>Відправник</span>
          </div>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { fetchCities, fetchWarehouses } from '@/crm/api/novaPoshta';
import http from '@/crm/api/http';

const props = defineProps({
  errors: { type: Object, default: () => ({}) },
});

// Використовуємо defineModel для двостороннього зв'язку
const model = defineModel({ type: Object, default: () => ({}) });

const local = reactive({
  city_ref: '', 
  settlement_ref: '',
  settlement_name: '',
  city_name: '', 
  warehouse_ref: '', 
  warehouse_name: '',
  delivery_type: 'warehouse', 
  carrier: 'nova_poshta', 
  payer: 'recipient',
  street_name: '', 
  street_ref: '',
  building: '', 
  apartment: '', 
  address_note: '',
  ...model.value,
});

const cityQuery = ref(local.city_name || '');
const warehouseQuery = ref(local.warehouse_name || '');
const streetQuery = ref(local.street_name || '');

const cityOptions = ref([]);
const warehouseOptions = ref([]);
const streetOptions = ref([]);

const showCityDropdown = ref(false);
const showWarehouseDropdown = ref(false);
const showStreetDropdown = ref(false);

const cityLoading = ref(false);
const warehouseLoading = ref(false);
const streetLoading = ref(false);
const cityError = ref('');

let cityTimer, warehouseTimer, streetTimer;
const skipFetch = reactive({ city: false, warehouse: false, street: false });

// 1. Слідкуємо за змінами в local -> оновлюємо батьківську модель
watch(() => ({ ...local }), (val) => { 
  model.value = val; 
}, { deep: true });

// 2. Слідкуємо за змінами props.model -> оновлюємо local (на випадок редагування)
watch(() => model.value, (newVal) => {
  if (newVal) {
    Object.assign(local, newVal);
    // Синхронізуємо інпути пошуку, якщо дані прийшли ззовні
    if (newVal.city_name && newVal.city_name !== cityQuery.value) {
      skipFetch.city = true;
      cityQuery.value = newVal.city_name;
    }
    if (newVal.warehouse_name && newVal.warehouse_name !== warehouseQuery.value) {
      skipFetch.warehouse = true;
      warehouseQuery.value = newVal.warehouse_name;
    }
    if (newVal.street_name && newVal.street_name !== streetQuery.value) {
      skipFetch.street = true;
      streetQuery.value = newVal.street_name;
    }
  }
}, { deep: true, immediate: true });

// ПОШУК МІСТ
watch(cityQuery, (val) => {
  if (skipFetch.city) { skipFetch.city = false; return; }
  
  local.city_name = val;
  // Користувач вводить вручну — скидаємо Ref та дочірні поля
  local.city_ref = '';
  local.settlement_ref = '';
  local.settlement_name = '';
  resetDeliveryFields();

  if (cityTimer) clearTimeout(cityTimer);
  if (!val || val.length < 3) { 
    cityOptions.value = []; 
    return; 
  }
  
  cityTimer = setTimeout(() => loadCities(val), 400); 
});

// ПОШУК ВІДДІЛЕНЬ
watch(warehouseQuery, (val) => {
  if (skipFetch.warehouse) { skipFetch.warehouse = false; return; }
  local.warehouse_name = val;
  local.warehouse_ref = ''; // Скидаємо ref при ручному вводі

  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (!local.city_ref) return;
  
  warehouseTimer = setTimeout(() => loadWarehouses(val), 400);
});

// ПОШУК ВУЛИЦЬ
watch(streetQuery, (val) => {
  if (skipFetch.street) { skipFetch.street = false; return; }
  local.street_name = val;
  local.street_ref = '';

  if (streetTimer) clearTimeout(streetTimer);
  if (!local.settlement_ref || local.delivery_type !== 'courier') return;
  if (!val || val.length < 2) { streetOptions.value = []; return; }

  streetTimer = setTimeout(() => loadStreets(val), 400);
});

async function loadCities(query) {
  cityLoading.value = true;
  cityError.value = '';
  try {
    const { data } = await fetchCities(query);
    cityOptions.value = data?.data || data || [];
  } catch (e) {
    console.error(e);
    cityError.value = 'Помилка завантаження';
  } finally { 
    cityLoading.value = false; 
  }
}

async function loadWarehouses(query) {
  warehouseLoading.value = true;
  try {
    const { data } = await fetchWarehouses({ cityRef: local.city_ref, query, limit: 100 });
    warehouseOptions.value = data?.data || data || [];
  } finally { warehouseLoading.value = false; }
}

async function loadStreets(query) {
  const hasQuery = query && query.length >= 2;
  if (!local.settlement_ref || !hasQuery) {
    streetOptions.value = [];
    return;
  }
  streetLoading.value = true;
  try {
    const { data } = await http.get('/nova-poshta/streets', {
      params: {
        settlement_ref: local.settlement_ref,
        q: query,
        limit: 25
      }
    });
    streetOptions.value = data?.data || data || [];
  } finally { streetLoading.value = false; }
}

function selectCity(city) {
  clearWarehouseFields();
  resetStreetFields();

  const deliveryCityRef = city.delivery_city_ref || city.ref || '';
  const settlementRef = city.settlement_ref || city.ref || deliveryCityRef || '';

  local.city_ref = deliveryCityRef;
  local.settlement_ref = settlementRef;
  local.city_name = city.name || '';
  local.settlement_name = city.name || '';

  skipFetch.city = true;
  cityQuery.value = local.city_name;
  showCityDropdown.value = false;

  if (local.delivery_type === 'warehouse') {
    loadWarehouses('');
  }
}

function selectWarehouse(wh) {
  local.warehouse_ref = wh.ref; 
  local.warehouse_name = wh.name;
  skipFetch.warehouse = true; 
  warehouseQuery.value = wh.name;
  showWarehouseDropdown.value = false;
}

function selectStreet(street) {
  local.street_name = street.name; 
  local.street_ref = street.street_ref || street.ref || '';
  skipFetch.street = true;
  streetQuery.value = street.name; 
  showStreetDropdown.value = false;
}

function onWarehouseFocus() {
  showWarehouseDropdown.value = true;
  if (local.city_ref && !warehouseOptions.value.length) loadWarehouses('');
}

function onStreetFocus() {
  showStreetDropdown.value = true;
}

function resetStreetFields() {
  local.street_name = ''; local.street_ref = ''; local.building = ''; local.apartment = '';
  streetQuery.value = '';
  streetOptions.value = [];
}

function resetDeliveryFields() {
  clearWarehouseFields();
  resetStreetFields();
}

function clearWarehouseFields() {
  local.warehouse_ref = '';
  local.warehouse_name = '';
  warehouseQuery.value = '';
  warehouseOptions.value = [];
}

const scheduleCloseCity = () => setTimeout(() => showCityDropdown.value = false, 200);
const scheduleCloseWarehouse = () => setTimeout(() => showWarehouseDropdown.value = false, 200);
const scheduleCloseStreet = () => setTimeout(() => showStreetDropdown.value = false, 200);
</script>

<style scoped>
.delivery-card {
  background: rgba(255, 255, 255, 0.9);
  border-radius: 24px;
  border: 1px solid rgba(255, 255, 255, 0.6);
  padding: 1.25rem;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
}

.form-label-custom { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 0.4rem; display: block; }

/* У цього блока вже є position: relative, тому dropdown всередині позиціонується від нього */
.input-modern-wrapper {
  position: relative;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  transition: all 0.2s ease;
  display: flex; align-items: center;
}
.input-modern-wrapper:hover { background: #fff; border-color: #cbd5e1; }
.input-modern-wrapper:focus-within { background: #fff; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15); }

.input-modern {
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
.input-modern.with-prefix { padding-left: 46px; }
.input-modern.input-modern-small { padding: 10px 14px; border-radius: 12px; }

.prefix-icon { position: absolute; left: 16px; color: #94a3b8; font-size: 1.1rem; transition: color 0.2s; }
.input-modern-wrapper:focus-within .prefix-icon { color: #6366f1; }

.input-suffix { position: absolute; right: 0.9rem; display: flex; align-items: center; justify-content: center; }
.loader-mini { width: 16px; height: 16px; border: 2px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }

.delivery-toggle-group { display: flex; background: #f4f6f9; padding: 4px; border-radius: 12px; gap: 4px; }
.toggle-item { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px; border-radius: 9px; cursor: pointer; transition: all 0.2s; font-weight: 600; color: #6c757d; font-size: 0.9rem; margin-bottom: 0; }
.btn-check:checked + .toggle-item { background: #fff; color: #0d6efd; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }

/* Тут змін не треба, так як ми перенесли HTML */
.premium-dropdown {
  position: absolute;
  top: calc(100% + 4px); /* 4px від нижнього краю input-modern-wrapper */
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
.dropdown-item { padding: 10px 14px; border: none; background: none; width: 100%; text-align: left; transition: background 0.15s; border-bottom: 1px solid #f8f9fa; font-size: 0.85rem; cursor: pointer; }
.dropdown-item:hover { background: #eff6ff; }

.payer-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.payer-option { border: 1px solid #e9ecef; padding: 10px; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; transition: 0.2s; background: white; color: #6c757d; font-weight: 500; font-size: 0.9rem; }
.payer-option.active { border-color: #0d6efd; background: #f0f7ff; color: #0d6efd; }

.animate-fade-in { animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>