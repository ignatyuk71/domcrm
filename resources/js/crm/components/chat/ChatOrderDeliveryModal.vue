<template>
  <teleport to="body">
    <transition name="ultra-modal">
      <div v-if="open" class="god-mode-overlay" @click.self="closeModal">
        <div class="god-mode-window">
          
          <header class="god-header">
            <div class="header-content">
              <div class="brand-avatar-glow">
                <i class="bi bi-truck-flatbed"></i>
              </div>
              <div class="header-titles">
                <h4>Конфігурація доставки</h4>
                <div class="np-badge">
                  <span class="red-dot"></span>
                  <span class="np-text-bold">НОВА ПОШТА</span>
                </div>
              </div>
            </div>
            <button class="btn-close-neo" @click="closeModal">
              <i class="bi bi-x"></i>
            </button>
          </header>

          <div class="god-body custom-scrollbar">
            
            <div class="elite-section">
              <label class="elite-label">Метод логістики</label>
              <div class="method-tiles">
                <div class="method-tile" :class="{ selected: local.delivery_type === 'warehouse' }" @click="setDeliveryType('warehouse')">
                  <div class="tile-icon"><i class="bi bi-box-seam"></i></div>
                  <div class="tile-label">Відділення</div>
                  <div class="tile-status"><i class="bi bi-check2-circle"></i></div>
                </div>
                <div class="method-tile" :class="{ selected: local.delivery_type === 'courier' }" @click="setDeliveryType('courier')">
                  <div class="tile-icon"><i class="bi bi-house-door"></i></div>
                  <div class="tile-label">Кур'єр</div>
                  <div class="tile-status"><i class="bi bi-check2-circle"></i></div>
                </div>
              </div>
            </div>

            <div class="elite-section">
              <label class="elite-label">Населений пункт</label>
              <div class="neo-input-wrap">
                <i class="bi bi-geo-alt-fill pin-icon"></i>
                <input
                  class="neo-select"
                  type="text"
                  v-model="cityQuery"
                  @focus="showCityDropdown = true"
                  @blur="scheduleCloseCity"
                  placeholder="Оберіть місто..."
                />
                <i v-if="cityLoading" class="bi bi-arrow-repeat arrow-icon np-spinner"></i>
                <i v-else class="bi bi-chevron-down arrow-icon"></i>
              </div>
              <div v-if="showCityDropdown" class="np-dropdown">
                <div v-if="cityError" class="np-dropdown-item muted">{{ cityError }}</div>
                <button
                  v-for="city in cityOptions"
                  :key="city.ref"
                  type="button"
                  class="np-dropdown-item"
                  @mousedown.prevent="selectCity(city)"
                >
                  <div class="np-option-title">{{ city.name }}</div>
                  <div class="np-option-sub">{{ city.area }}</div>
                </button>
                <div v-if="!cityOptions.length && !cityError" class="np-dropdown-item muted">Нічого не знайдено</div>
              </div>
            </div>

            <transition name="slide-fade" mode="out-in">
              <div v-if="local.delivery_type === 'warehouse'" :key="'wh'" class="elite-section">
                <label class="elite-label">Пункт призначення</label>
                <div class="neo-input-wrap">
                  <i class="bi bi-signpost-split-fill pin-icon"></i>
                  <input
                    class="neo-select"
                    type="text"
                    v-model="warehouseQuery"
                    :disabled="!local.city_ref"
                    @focus="onWarehouseFocus"
                    @blur="scheduleCloseWarehouse"
                    placeholder="Виберіть відділення..."
                  />
                  <i v-if="warehouseLoading" class="bi bi-arrow-repeat arrow-icon np-spinner"></i>
                  <i v-else class="bi bi-chevron-down arrow-icon"></i>
                </div>
                <div v-if="showWarehouseDropdown" class="np-dropdown">
                  <button
                    v-for="wh in warehouseOptions"
                    :key="wh.ref || wh.name"
                    type="button"
                    class="np-dropdown-item"
                    @mousedown.prevent="selectWarehouse(wh)"
                  >
                    <div class="np-option-title">{{ wh.name }}</div>
                    <div class="np-option-sub">{{ wh.category || wh.type || '' }}</div>
                  </button>
                  <div v-if="!warehouseOptions.length" class="np-dropdown-item muted">Нічого не знайдено</div>
                </div>
              </div>

              <div v-else :key="'cur'" class="elite-section">
                <label class="elite-label">Адресація</label>
                <div class="neo-input-wrap mb-3">
                  <input
                    type="text"
                    class="neo-input"
                    v-model="streetQuery"
                    :disabled="!local.city_ref"
                    @focus="onStreetFocus"
                    @blur="scheduleCloseStreet"
                    placeholder="Вулиця (пошук...)"
                  >
                  <i v-if="streetLoading" class="bi bi-arrow-repeat arrow-icon np-spinner"></i>
                </div>
                <div v-if="showStreetDropdown" class="np-dropdown">
                  <button
                    v-for="street in streetOptions"
                    :key="street.ref"
                    type="button"
                    class="np-dropdown-item"
                    @mousedown.prevent="selectStreet(street)"
                  >
                    <div class="np-option-title">{{ street.name }}</div>
                  </button>
                  <div v-if="!streetOptions.length" class="np-dropdown-item muted">Нічого не знайдено</div>
                </div>
                <div class="row g-3">
                  <div class="col-6"><input type="text" class="neo-input" v-model="local.building" placeholder="Будинок"></div>
                  <div class="col-6"><input type="text" class="neo-input" v-model="local.apartment" placeholder="Кв. / Офіс"></div>
                </div>
              </div>
            </transition>

            <div class="elite-section no-border">
              <label class="elite-label">Розподіл витрат</label>
              <div class="segmented-control">
                <div class="segment" :class="{ active: local.payer === 'recipient' }" @click="local.payer = 'recipient'">
                  <i class="bi bi-person"></i> Отримувач
                </div>
                <div class="segment" :class="{ active: local.payer === 'sender' }" @click="local.payer = 'sender'">
                  <i class="bi bi-shop"></i> Відправник
                </div>
                <div class="active-bg" :style="{ left: local.payer === 'recipient' ? '4px' : '50%' }"></div>
              </div>
            </div>

          </div>

          <footer class="god-footer">
            <button class="btn-cancel-elite" :disabled="isSaving" @click="closeModal">Скасувати</button>
            
            <button 
              class="btn-confirm-ultimate" 
              :class="{ 'success-state': isSaved }"
              :disabled="isSaving"
              @click="handleSave"
            >
              <transition name="fade-scale" mode="out-in">
                <span v-if="!isSaved" class="btn-text">ЗБЕРЕГТИ КОНФІГУРАЦІЮ</span>
                <i v-else class="bi bi-check-circle-fill success-icon"></i>
              </transition>
              
              <div v-if="!isSaved" class="glow-effect"></div>
            </button>
          </footer>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { fetchCities, fetchWarehouses, fetchStreets } from '@/crm/api/novaPoshta';

const props = defineProps({
  open: Boolean,
  modelValue: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'save', 'update:modelValue']);

const isSaving = ref(false);
const isSaved = ref(false);

const local = reactive({
  carrier: 'nova_poshta',
  delivery_type: 'warehouse',
  city_name: '',
  city_ref: '',
  warehouse_name: '',
  warehouse_ref: '',
  street_name: '',
  building: '',
  apartment: '',
  payer: 'recipient',
});

const cityQuery = ref('');
const warehouseQuery = ref('');
const streetQuery = ref('');

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

let cityTimer;
let warehouseTimer;
let streetTimer;
const skipFetch = reactive({ city: false, warehouse: false, street: false });

const syncFromModel = () => {
  const data = props.modelValue || {};
  local.delivery_type = data.delivery_type || 'warehouse';
  local.city_name = data.city_name || '';
  local.city_ref = data.city_ref || '';
  local.warehouse_name = data.warehouse_name || '';
  local.warehouse_ref = data.warehouse_ref || '';
  local.street_name = data.street_name || '';
  local.building = data.building || '';
  local.apartment = data.apartment || '';
  local.payer = data.payer === 'sender' ? 'sender' : 'recipient';

  skipFetch.city = true;
  cityQuery.value = local.city_name || '';
  skipFetch.warehouse = true;
  warehouseQuery.value = local.warehouse_name || '';
  skipFetch.street = true;
  streetQuery.value = local.street_name || '';
};

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      syncFromModel();
    }
  },
  { immediate: true }
);

watch(
  () => props.modelValue,
  (val) => {
    if (props.open && val) syncFromModel();
  },
  { deep: true }
);

watch(
  local,
  () => {
    emit('update:modelValue', {
      carrier: 'nova_poshta',
      delivery_type: local.delivery_type,
      city_name: local.city_name,
      city_ref: local.city_ref,
      warehouse_name: local.warehouse_name,
      warehouse_ref: local.warehouse_ref,
      street_name: local.street_name,
      building: local.building,
      apartment: local.apartment,
      payer: local.payer,
    });
  },
  { deep: true }
);

const setDeliveryType = (type) => {
  local.delivery_type = type;
  if (type === 'courier') {
    local.warehouse_ref = '';
    local.warehouse_name = '';
    warehouseQuery.value = '';
    warehouseOptions.value = [];
  } else {
    local.street_name = '';
    local.building = '';
    local.apartment = '';
    streetQuery.value = '';
    streetOptions.value = [];
  }
};

const handleSave = () => {
  if (isSaving.value) return;
  isSaving.value = true;
  isSaved.value = true;

  const payload = {
    carrier: 'nova_poshta',
    delivery_type: local.delivery_type,
    city_name: local.city_name,
    city_ref: local.city_ref,
    warehouse_name: local.warehouse_name,
    warehouse_ref: local.warehouse_ref,
    street_name: local.street_name,
    building: local.building,
    apartment: local.apartment,
    payer: local.payer,
  };

  setTimeout(() => {
    emit('update:modelValue', payload);
    emit('save', payload);
    closeModal(true);
  }, 1100);
};

watch(cityQuery, (val) => {
  if (skipFetch.city) {
    skipFetch.city = false;
    return;
  }
  local.city_name = val;
  local.city_ref = '';
  local.warehouse_ref = '';
  local.warehouse_name = '';
  local.street_name = '';
  warehouseQuery.value = '';
  streetQuery.value = '';
  warehouseOptions.value = [];
  streetOptions.value = [];
  cityError.value = '';

  if (cityTimer) clearTimeout(cityTimer);
  const query = val.trim();
  if (query.length < 2) {
    cityOptions.value = [];
    return;
  }
  cityTimer = setTimeout(() => loadCities(query), 600);
});

watch(warehouseQuery, (val) => {
  if (skipFetch.warehouse) {
    skipFetch.warehouse = false;
    return;
  }
  local.warehouse_name = val;
  local.warehouse_ref = '';
  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (!local.city_ref) return;
  warehouseTimer = setTimeout(() => loadWarehouses(val.trim()), 600);
});

watch(streetQuery, (val) => {
  if (skipFetch.street) {
    skipFetch.street = false;
    return;
  }
  local.street_name = val;
  if (streetTimer) clearTimeout(streetTimer);
  if (!local.city_ref || local.delivery_type !== 'courier') return;
  streetTimer = setTimeout(() => loadStreets(val.trim()), 600);
});

const loadCities = async (query) => {
  cityLoading.value = true;
  cityError.value = '';
  try {
    const { data } = await fetchCities(query);
    cityOptions.value = data?.data || data || [];
  } catch (e) {
    console.error(e);
    cityError.value = 'Помилка завантаження';
    cityOptions.value = [];
  } finally {
    cityLoading.value = false;
  }
};

const loadWarehouses = async (query) => {
  warehouseLoading.value = true;
  try {
    const { data } = await fetchWarehouses({ cityRef: local.city_ref, query, limit: 50 });
    warehouseOptions.value = data?.data || data || [];
  } finally {
    warehouseLoading.value = false;
  }
};

const loadStreets = async (query) => {
  streetLoading.value = true;
  try {
    const { data } = await fetchStreets({ cityRef: local.city_ref, query });
    streetOptions.value = data?.data || data || [];
  } finally {
    streetLoading.value = false;
  }
};

const selectCity = (city) => {
  local.city_ref = city.ref || '';
  local.city_name = city.name || '';
  skipFetch.city = true;
  cityQuery.value = local.city_name;
  showCityDropdown.value = false;
  local.warehouse_ref = '';
  local.warehouse_name = '';
  warehouseQuery.value = '';
  warehouseOptions.value = [];
  if (local.delivery_type === 'warehouse') loadWarehouses('');
};

const selectWarehouse = (wh) => {
  local.warehouse_ref = wh.ref || '';
  local.warehouse_name = wh.name || '';
  skipFetch.warehouse = true;
  warehouseQuery.value = local.warehouse_name;
  showWarehouseDropdown.value = false;
};

const selectStreet = (street) => {
  local.street_name = street.name || '';
  skipFetch.street = true;
  streetQuery.value = local.street_name;
  showStreetDropdown.value = false;
};

const onWarehouseFocus = () => {
  showWarehouseDropdown.value = true;
  if (local.city_ref && !warehouseOptions.value.length) loadWarehouses('');
};

const onStreetFocus = () => {
  showStreetDropdown.value = true;
  if (local.city_ref && !streetOptions.value.length) loadStreets('');
};

const scheduleCloseCity = () => setTimeout(() => { showCityDropdown.value = false; }, 200);
const scheduleCloseWarehouse = () => setTimeout(() => { showWarehouseDropdown.value = false; }, 200);
const scheduleCloseStreet = () => setTimeout(() => { showStreetDropdown.value = false; }, 200);

const closeModal = (force = false) => {
  if (!force && isSaving.value && !isSaved.value) return;
  emit('close');
  setTimeout(() => {
    isSaving.value = false;
    isSaved.value = false;
  }, 500);
};
</script>

<style scoped>
/* Всі попередні стилі залишаються без змін... */

/* ПРЕМІАЛЬНИЙ ОВЕРЛЕЙ */
.god-mode-overlay { 
  position: fixed; inset: 0; background: rgba(5, 8, 15, 0.7); 
  z-index: 100010; backdrop-filter: blur(20px); 
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.god-mode-window { 
  background: #ffffff; width: 580px; max-width: 100%; max-height: 95vh; 
  border-radius: 44px; display: flex; flex-direction: column; 
  overflow: hidden; box-shadow: 0 60px 150px rgba(0,0,0,0.5); 
}

/* ШАПКА */
.god-header { padding: 30px 40px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.brand-avatar-glow { width: 54px; height: 54px; background: #f5f3ff; color: #a78bfb; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 26px; box-shadow: 0 10px 25px rgba(167, 139, 251, 0.2); }
.header-titles h4 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; letter-spacing: -0.03em; }
.np-badge { display: flex; align-items: center; gap: 6px; margin-top: 4px; }
.red-dot { width: 8px; height: 8px; background: #dc2626; border-radius: 50%; animation: blink 2s infinite; }
.np-text-bold { font-size: 11px; color: #dc2626; font-weight: 900; text-transform: uppercase; letter-spacing: 0.1em; }
@keyframes blink { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.9); } }
.btn-close-neo { border: none; background: #f8fafc; width: 40px; height: 40px; border-radius: 50%; color: #94a3b8; font-size: 20px; cursor: pointer; transition: 0.3s; }
.btn-close-neo:hover { background: #fee2e2; color: #ef4444; transform: rotate(90deg); }

/* BODY */
.god-body { flex: 1; padding: 35px 40px; overflow-y: auto; background: #ffffff; }
.elite-section { margin-bottom: 35px; }
.no-border { margin-bottom: 0; }
.elite-label { font-size: 11px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.15em; margin-bottom: 18px; display: block; }

/* МЕТОД ЛОГІСТИКИ */
.method-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.method-tile { 
  background: #fff; border: 2.5px solid #f1f5f9; border-radius: 15px; padding: 14px; 
  cursor: pointer; position: relative; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
  display: flex; flex-direction: column; align-items: center; 
}
.tile-icon { font-size: 42px; color: #cbd5e1; transition: 0.3s; }
.tile-label { font-size: 17px; font-weight: 800; color: #1e293b; }
.tile-status { position: absolute; top: 20px; right: 20px; color: #a78bfb; opacity: 0; transform: scale(0.4); transition: 0.3s; font-size: 24px; }
.method-tile:hover { border-color: #e2e8f0; transform: translateY(-5px); }
.method-tile.selected { border-color: #a78bfb; background: #fcfaff; transform: scale(1.02); box-shadow: 0 25px 50px rgba(167, 139, 251, 0.15); }
.method-tile.selected .tile-icon { color: #a78bfb; transform: scale(1.1); }
.method-tile.selected .tile-status { opacity: 1; transform: scale(1); }

/* INPUT SYSTEM */
.neo-input-wrap { position: relative; display: flex; align-items: center; }
.pin-icon { position: absolute; left: 24px; color: #a78bfb; font-size: 24px; z-index: 2; }
.arrow-icon { position: absolute; right: 24px; color: #94a3b8; font-size: 20px; pointer-events: none; }
.neo-select, .neo-input { 
  width: 100%; padding: 20px 25px 20px 65px; border-radius: 26px; 
  border: 2px solid #f1f5f9; font-size: 16px; font-weight: 700; 
  color: #1e293b; background: #f8fafc; transition: 0.3s; outline: none; appearance: none;
}
.neo-input { padding-left: 25px; }
.neo-select:focus, .neo-input:focus { border-color: #a78bfb; background: #fff; box-shadow: 0 15px 30px rgba(167, 139, 251, 0.1); }
.np-spinner { animation: spin 1s linear infinite; }

.np-dropdown {
  background: #ffffff;
  border: 1px solid #f1f5f9;
  border-radius: 18px;
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
  margin-top: 10px;
  max-height: 240px;
  overflow-y: auto;
}
.np-dropdown-item {
  width: 100%;
  border: none;
  background: transparent;
  text-align: left;
  padding: 12px 16px;
  cursor: pointer;
  transition: background 0.2s ease;
  color: #1e293b;
  font-weight: 700;
}
.np-dropdown-item:hover { background: #f5f3ff; }
.np-dropdown-item.muted { color: #94a3b8; font-weight: 600; }
.np-option-title { font-size: 14px; }
.np-option-sub { font-size: 12px; color: #94a3b8; margin-top: 2px; }

/* SEGMENTED CONTROL */
.segmented-control { 
  position: relative; display: flex; background: #f1f5f9; padding: 4px; border-radius: 24px; height: 68px; align-items: center; 
}
.segment { flex: 1; position: relative; z-index: 2; text-align: center; font-size: 15px; font-weight: 800; color: #64748b; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; }
.segment.active { color: #0f172a; }
.active-bg { position: absolute; width: calc(50% - 8px); height: calc(100% - 8px); background: #fff; border-radius: 20px; transition: 0.4s cubic-bezier(0.19, 1, 0.22, 1); box-shadow: 0 8px 20px rgba(0,0,0,0.08); z-index: 1; }

/* FOOTER */
.god-footer { padding: 30px 40px; border-top: 1px solid #f1f5f9; display: flex; gap: 20px; background: #fff; }
.btn-cancel-elite { flex: 1; background: #f8fafc; border: none; height: 68px; border-radius: 26px; color: #94a3b8; font-weight: 800; cursor: pointer; transition: 0.3s; }

/* АНІМАЦІЯ КНОПКИ ЗБЕРЕЖЕННЯ */
.btn-confirm-ultimate { 
  flex: 2; background: #0f172a; border: none; height: 68px; border-radius: 26px; 
  color: #fff; font-weight: 900; letter-spacing: 0.05em; cursor: pointer; 
  position: relative; overflow: hidden; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.btn-confirm-ultimate:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 20px 40px rgba(15, 23, 42, 0.3); }

/* СТАН УСПІХУ */
.btn-confirm-ultimate.success-state {
  background: #10b981; /* Зелений колір успіху */
  box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4);
  transform: scale(1.05);
  pointer-events: none; /* Блокуємо повторні кліки */
}

.success-icon {
  font-size: 32px;
  color: #ffffff;
  display: inline-block;
}

.glow-effect { position: absolute; inset: 0; background: radial-gradient(circle at center, rgba(167, 139, 251, 0.4) 0%, transparent 70%); opacity: 0; transition: 0.5s; }
.btn-confirm-ultimate:hover .glow-effect { opacity: 1; transform: scale(1.5); }

/* ПЛАВНА ЗАМІНА ТЕКСТУ НА ІКОНКУ */
.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.3s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.5); }

/* ADAPTATION */
@media (max-width: 768px) {
  .god-mode-window { width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
  .god-mode-overlay { padding: 0; align-items: flex-start; }
}

/* ANIMATIONS */
.ultra-modal-enter-active { animation: god-in 0.7s cubic-bezier(0.19, 1, 0.22, 1); }
.ultra-modal-leave-active { transition: 0.3s opacity ease; opacity: 0; }
@keyframes god-in { from { opacity: 0; transform: scale(0.8) translateY(80px); } to { opacity: 1; transform: scale(1) translateY(0); } }
@keyframes spin { to { transform: rotate(360deg); } }

.slide-fade-enter-active, .slide-fade-leave-active { transition: all 0.4s ease; }
.slide-fade-enter-from, .slide-fade-leave-to { opacity: 0; transform: translateY(20px); }

.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>
