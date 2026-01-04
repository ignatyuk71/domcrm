<template>
  <div class="delivery-card p-3 border rounded-4 bg-white shadow-sm">
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
        <div class="input-wrapper">
          <i class="bi bi-search input-prefix"></i>
          <input
            class="form-control custom-input"
            :class="{ 'is-invalid': errors.city_name }"
            v-model="cityQuery"
            @focus="showCityDropdown = true"
            @blur="scheduleCloseCity"
            placeholder="Оберіть місто..."
          />
          <div v-if="cityLoading" class="input-suffix">
            <div class="spinner-border text-primary custom-spinner" role="status"></div>
          </div>
        </div>
        
        <div v-if="showCityDropdown && (cityOptions.length || cityError)" class="custom-dropdown shadow">
          <div v-if="cityError" class="dropdown-item text-danger small">{{ cityError }}</div>
          <button
            v-for="city in cityOptions"
            :key="city.ref"
            type="button"
            class="dropdown-item"
            @mousedown="selectCity(city)"
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
        <div class="invalid-feedback d-block" v-if="errors.city_name">{{ errors.city_name }}</div>
      </section>

      <section v-if="local.delivery_type !== 'courier'" class="position-relative">
        <label class="form-label-custom">Відділення або поштомат</label>
        <div class="input-wrapper" :class="{ 'opacity-50': !local.city_ref }">
          <i class="bi bi-building-up input-prefix"></i>
          <input
            class="form-control custom-input"
            :class="{ 'is-invalid': errors.warehouse_name }"
            v-model="warehouseQuery"
            @focus="onWarehouseFocus"
            @blur="scheduleCloseWarehouse"
            :disabled="!local.city_ref"
            placeholder="Введіть номер або назву..."
          />
          <div v-if="warehouseLoading" class="input-suffix">
            <div class="spinner-border text-primary custom-spinner" role="status"></div>
          </div>
        </div>

        <div v-if="showWarehouseDropdown && warehouseOptions.length" class="custom-dropdown shadow">
          <button
            v-for="wh in warehouseOptions"
            :key="wh.ref || wh.name"
            type="button"
            class="dropdown-item"
            @mousedown="selectWarehouse(wh)"
          >
            <div class="d-flex gap-2 text-start">
              <i class="bi" :class="wh.name.includes('Поштомат') ? 'bi-mailbox text-info' : 'bi-door-open text-primary'"></i>
              <span class="small fw-medium">{{ wh.name }}</span>
            </div>
          </button>
        </div>
        <div class="invalid-feedback d-block" v-if="errors.warehouse_name">{{ errors.warehouse_name }}</div>
        
        <div class="mt-3">
          <label class="form-label-custom">Додатково (ПІБ або коментар)</label>
          <input class="form-control custom-input-small" v-model="local.address_note" placeholder="Наприклад: Отримувач інша особа" />
        </div>
      </section>

      <section v-else class="animate-fade-in">
        <div class="mb-3 position-relative">
          <label class="form-label-custom">Вулиця</label>
          <div class="input-wrapper" :class="{ 'opacity-50': !local.city_ref }">
            <i class="bi bi-signpost-split input-prefix"></i>
            <input
              class="form-control custom-input"
              :class="{ 'is-invalid': errors.street_name }"
              v-model="streetQuery"
              @focus="onStreetFocus"
              @blur="scheduleCloseStreet"
              :disabled="!local.city_ref"
              placeholder="Почніть вводити..."
            />
            <div v-if="streetLoading" class="input-suffix">
              <div class="spinner-border text-primary custom-spinner" role="status"></div>
            </div>
          </div>
          <div v-if="showStreetDropdown && streetOptions.length" class="custom-dropdown shadow">
            <button v-for="s in streetOptions" :key="s.ref" class="dropdown-item" @mousedown="selectStreet(s)">
              {{ s.name }}
            </button>
          </div>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label-custom">Будинок</label>
            <input class="form-control custom-input" :class="{ 'is-invalid': errors.building }" v-model="local.building" placeholder="10/А" />
          </div>
          <div class="col-6">
            <label class="form-label-custom">Кв./Офіс</label>
            <input class="form-control custom-input" v-model="local.apartment" placeholder="42" />
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
import { fetchCities, fetchWarehouses, fetchStreets } from '@/crm/api/novaPoshta';

const props = defineProps({
  errors: { type: Object, default: () => ({}) },
});

const model = defineModel({ type: Object, default: () => ({}) });

const local = reactive({
  city_ref: '', city_name: '', warehouse_ref: '', warehouse_name: '',
  delivery_type: 'warehouse', carrier: 'nova_poshta', payer: 'recipient',
  street_name: '', building: '', apartment: '', address_note: '',
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

watch(() => ({ ...local }), (val) => { model.value = val; }, { deep: true });

// ПОШУК МІСТ: від 4 символів, затримка 1 сек
watch(cityQuery, (val) => {
  if (skipFetch.city) { skipFetch.city = false; return; }
  local.city_name = val; local.city_ref = '';
  resetDeliveryFields();

  if (cityTimer) clearTimeout(cityTimer);
  if (!val || val.length < 4) { 
    cityOptions.value = []; 
    return; 
  }
  
  cityTimer = setTimeout(() => loadCities(val), 1000); 
});

// ПОШУК ВІДДІЛЕНЬ: затримка 1 сек
watch(warehouseQuery, (val) => {
  if (skipFetch.warehouse) { skipFetch.warehouse = false; return; }
  local.warehouse_name = val;
  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (!local.city_ref) return;
  
  warehouseTimer = setTimeout(() => loadWarehouses(val), 1000);
});

// ПОШУК ВУЛИЦЬ: затримка 1 сек
watch(streetQuery, (val) => {
  if (skipFetch.street) { skipFetch.street = false; return; }
  local.street_name = val;
  if (streetTimer) clearTimeout(streetTimer);
  if (!local.city_ref || local.delivery_type !== 'courier') return;
  
  streetTimer = setTimeout(() => loadStreets(val), 1000);
});

async function loadCities(query) {
  cityLoading.value = true;
  try {
    const { data } = await fetchCities(query);
    cityOptions.value = data?.data || data || [];
  } finally { cityLoading.value = false; }
}

async function loadWarehouses(query) {
  warehouseLoading.value = true;
  try {
    const { data } = await fetchWarehouses({ cityRef: local.city_ref, query, limit: 50 });
    warehouseOptions.value = data?.data || data || [];
  } finally { warehouseLoading.value = false; }
}

async function loadStreets(query) {
  streetLoading.value = true;
  try {
    const { data } = await fetchStreets({ cityRef: local.city_ref, query });
    streetOptions.value = data?.data || data || [];
  } finally { streetLoading.value = false; }
}

function selectCity(city) {
  local.city_ref = city.ref; local.city_name = city.name;
  skipFetch.city = true; cityQuery.value = city.name;
  showCityDropdown.value = false; 
  
  resetDeliveryFields();
  if (local.delivery_type !== 'courier') loadWarehouses('');
}

function selectWarehouse(wh) {
  local.warehouse_ref = wh.ref; local.warehouse_name = wh.name;
  skipFetch.warehouse = true; warehouseQuery.value = wh.name;
  showWarehouseDropdown.value = false;
}

function selectStreet(street) {
  local.street_name = street.name; skipFetch.street = true;
  streetQuery.value = street.name; showStreetDropdown.value = false;
}

function onWarehouseFocus() {
  showWarehouseDropdown.value = true;
  if (local.city_ref && !warehouseOptions.value.length) loadWarehouses('');
}

function onStreetFocus() {
  showStreetDropdown.value = true;
  if (local.city_ref && !streetOptions.value.length) loadStreets('');
}

function resetDeliveryFields() {
  local.warehouse_ref = ''; local.warehouse_name = '';
  local.street_name = ''; local.building = ''; local.apartment = '';
  warehouseQuery.value = ''; streetQuery.value = '';
  warehouseOptions.value = []; streetOptions.value = [];
}

const scheduleCloseCity = () => setTimeout(() => showCityDropdown.value = false, 200);
const scheduleCloseWarehouse = () => setTimeout(() => showWarehouseDropdown.value = false, 200);
const scheduleCloseStreet = () => setTimeout(() => showStreetDropdown.value = false, 200);
</script>

<style scoped>
.delivery-card { max-width: 100%; background: #fdfdfd; }
.form-label-custom { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #8898aa; margin-bottom: 0.4rem; display: block; }
.input-wrapper { position: relative; display: flex; align-items: center; }
.custom-input { 
  border-radius: 10px; 
  padding: 0.65rem 2.6rem 0.65rem 2.4rem; 
  border: 1px solid #e9ecef; 
  transition: all 0.2s; 
  font-size: 0.9rem; 
  width: 100%;
}
.custom-input:focus { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.08); background: #fff; }
.input-prefix { position: absolute; left: 0.9rem; color: #adb5bd; z-index: 4; }
.input-suffix { position: absolute; right: 0.8rem; display: flex; align-items: center; justify-content: center; z-index: 5; }
.custom-spinner { width: 1.1rem; height: 1.1rem; border-width: 0.15em; }
.delivery-toggle-group { display: flex; background: #f4f6f9; padding: 4px; border-radius: 12px; gap: 4px; }
.toggle-item { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px; border-radius: 9px; cursor: pointer; transition: all 0.2s; font-weight: 600; color: #6c757d; font-size: 0.9rem; margin-bottom: 0; }
.btn-check:checked + .toggle-item { background: #fff; color: #0d6efd; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
.custom-dropdown { position: absolute; top: 100%; left: 0; right: 0; z-index: 1000; background: white; border-radius: 10px; margin-top: 5px; max-height: 250px; overflow-y: auto; border: 1px solid #eee; box-shadow: 0 10px 15px rgba(0,0,0,0.05); }
.dropdown-item { padding: 10px 14px; border: none; background: none; width: 100%; text-align: left; transition: background 0.15s; border-bottom: 1px solid #f8f9fa; font-size: 0.85rem; }
.dropdown-item:hover { background: #f1f7ff; }
.payer-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.payer-option { border: 1px solid #e9ecef; padding: 10px; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; transition: 0.2s; background: white; color: #6c757d; font-weight: 500; font-size: 0.9rem; }
.payer-option.active { border-color: #0d6efd; background: #f0f7ff; color: #0d6efd; }
.animate-fade-in { animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.custom-input-small { border-radius: 8px; border: 1px solid #e9ecef; padding: 0.5rem; font-size: 0.85rem; width: 100%; }
</style>