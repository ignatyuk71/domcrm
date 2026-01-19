<template>
  <teleport to="body">
    <transition name="modal-fade">
      <div v-if="open" class="modal-backdrop" @click.self="closeModal">
        <transition name="modal-slide">
          <div v-if="open" class="modal-window">
            
            <!-- HEADER -->
            <div class="modal-header">
              <div class="header-content">
                <div class="icon-brand">
                  <i class="bi bi-box-seam-fill"></i>
                </div>
                <div class="title-group">
                  <h3>Налаштування доставки</h3>
                  <span class="brand-tag">НОВА ПОШТА</span>
                </div>
              </div>
              <button class="btn-close" @click="closeModal">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>

            <!-- BODY (SCROLLABLE) -->
            <div class="modal-body custom-scrollbar">
              
              <!-- 1. ТИП ДОСТАВКИ -->
              <section class="form-section">
                <label class="section-label">Спосіб отримання</label>
                <div class="grid-2">
                  <div 
                    class="option-card" 
                    :class="{ active: local.delivery_type === 'warehouse' }"
                    @click="setDeliveryType('warehouse')"
                  >
                    <div class="card-icon"><i class="bi bi-building"></i></div>
                    <span class="card-title">У відділення</span>
                    <i class="bi bi-check-circle-fill check-icon"></i>
                  </div>
                  
                  <div 
                    class="option-card" 
                    :class="{ active: local.delivery_type === 'courier' }"
                    @click="setDeliveryType('courier')"
                  >
                    <div class="card-icon"><i class="bi bi-truck"></i></div>
                    <span class="card-title">Кур'єром</span>
                    <i class="bi bi-check-circle-fill check-icon"></i>
                  </div>
                </div>
              </section>

              <!-- 2. МІСТО -->
              <section class="form-section">
                <label class="section-label">Населений пункт</label>
                <div class="input-group-modern">
                  <i class="bi bi-geo-alt input-icon"></i>
                  <input 
                    type="text" 
                    class="modern-input" 
                    v-model="cityQuery"
                    @focus="showCityDropdown = true"
                    @blur="scheduleCloseCity"
                    placeholder="Введіть назву міста..."
                  >
                  <div v-if="cityLoading" class="spinner-input"></div>
                  
                  <!-- Dropdown -->
                  <transition name="fade">
                    <div v-if="showCityDropdown && cityOptions.length" class="dropdown-menu-custom custom-scrollbar">
                      <div 
                        v-for="city in cityOptions" 
                        :key="city.ref" 
                        class="dropdown-item"
                        @mousedown.prevent="selectCity(city)"
                      >
                        <div class="item-main">{{ city.name }}</div>
                        <div class="item-sub">{{ city.area }}</div>
                      </div>
                    </div>
                  </transition>
                </div>
              </section>

              <!-- 3. ВІДДІЛЕННЯ (АБО АДРЕСА) -->
              <transition name="slide-up" mode="out-in">
                <section v-if="local.delivery_type === 'warehouse'" key="warehouse" class="form-section">
                  <label class="section-label">Відділення або Поштомат</label>
                  <div class="input-group-modern" :class="{ disabled: !local.city_ref }">
                    <i class="bi bi-signpost-2 input-icon"></i>
                    <input 
                      type="text" 
                      class="modern-input" 
                      v-model="warehouseQuery"
                      :disabled="!local.city_ref"
                      @focus="onWarehouseFocus"
                      @blur="scheduleCloseWarehouse"
                      placeholder="Введіть номер або адресу..."
                    >
                    <div v-if="warehouseLoading" class="spinner-input"></div>

                    <transition name="fade">
                      <div v-if="showWarehouseDropdown && warehouseOptions.length" class="dropdown-menu-custom custom-scrollbar">
                        <div 
                          v-for="wh in warehouseOptions" 
                          :key="wh.ref" 
                          class="dropdown-item"
                          @mousedown.prevent="selectWarehouse(wh)"
                        >
                          <div class="item-main">{{ wh.name }}</div>
                        </div>
                      </div>
                    </transition>
                  </div>
                </section>

                <section v-else key="courier" class="form-section">
                  <label class="section-label">Адреса доставки</label>
                  <div class="input-stack">
                    <div class="input-group-modern mb-2">
                      <i class="bi bi-map input-icon"></i>
                      <input 
                        type="text" 
                        class="modern-input" 
                        v-model="streetQuery"
                        :disabled="!local.city_ref"
                        @focus="onStreetFocus"
                        @blur="scheduleCloseStreet"
                        placeholder="Вулиця"
                      >
                      <div v-if="showStreetDropdown && streetOptions.length" class="dropdown-menu-custom">
                         <div v-for="st in streetOptions" :key="st.ref" class="dropdown-item" @mousedown.prevent="selectStreet(st)">
                            {{ st.name }}
                         </div>
                      </div>
                    </div>
                    
                    <div class="grid-2 small-gap">
                      <div class="input-group-modern">
                        <input type="text" class="modern-input center-text" v-model="local.building" placeholder="Буд.">
                      </div>
                      <div class="input-group-modern">
                        <input type="text" class="modern-input center-text" v-model="local.apartment" placeholder="Кв.">
                      </div>
                    </div>
                  </div>
                </section>
              </transition>

              <!-- 4. ПЛАТНИК -->
              <section class="form-section">
                <label class="section-label">Платник доставки</label>
                <div class="toggle-switch">
                  <div class="switch-bg" :style="{ left: local.payer === 'recipient' ? '4px' : '50%' }"></div>
                  <button 
                    type="button"
                    class="switch-btn" 
                    :class="{ active: local.payer === 'recipient' }" 
                    @click="local.payer = 'recipient'"
                  >
                    Отримувач
                  </button>
                  <button 
                    type="button"
                    class="switch-btn" 
                    :class="{ active: local.payer === 'sender' }" 
                    @click="local.payer = 'sender'"
                  >
                    Відправник
                  </button>
                </div>
              </section>

              <!-- SPACER ДЛЯ МОБІЛОК -->
              <div class="mobile-spacer"></div>

            </div>

            <!-- FOOTER -->
            <div class="modal-footer">
              <button class="btn-save-primary" :class="{ 'success': isSaved }" :disabled="isSaving" @click="handleSave">
                <span v-if="!isSaved">Зберегти</span>
                <span v-else><i class="bi bi-check-lg"></i> Готово</span>
              </button>
            </div>

          </div>
        </transition>
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

let cityTimer, warehouseTimer, streetTimer;
const skipFetch = reactive({ city: false, warehouse: false, street: false });

const syncFromModel = () => {
  const data = props.modelValue || {};
  Object.assign(local, { ...local, ...data });
  
  skipFetch.city = true; cityQuery.value = local.city_name || '';
  skipFetch.warehouse = true; warehouseQuery.value = local.warehouse_name || '';
  skipFetch.street = true; streetQuery.value = local.street_name || '';
};

watch(() => props.open, (val) => { if(val) syncFromModel(); }, { immediate: true });

watch(() => props.modelValue, (val) => {
  if (props.open && val) syncFromModel();
}, { deep: true });

watch(local, () => {
  emit('update:modelValue', { ...local });
}, { deep: true });

const setDeliveryType = (type) => {
  local.delivery_type = type;
  if (type === 'courier') {
    local.warehouse_ref = ''; local.warehouse_name = ''; warehouseQuery.value = ''; warehouseOptions.value = [];
  } else {
    local.street_name = ''; local.building = ''; local.apartment = ''; streetQuery.value = ''; streetOptions.value = [];
  }
};

const handleSave = () => {
  if (isSaving.value) return;
  isSaving.value = true;
  setTimeout(() => {
    isSaved.value = true;
    setTimeout(() => {
      emit('save', { ...local });
      closeModal();
    }, 600);
  }, 600);
};

const closeModal = () => {
  if (isSaving.value && !isSaved.value) return;
  emit('close');
  setTimeout(() => { isSaving.value = false; isSaved.value = false; }, 300);
};

watch(cityQuery, (val) => {
  if (skipFetch.city) { skipFetch.city = false; return; }
  local.city_name = val;
  local.city_ref = '';
  if (cityTimer) clearTimeout(cityTimer);
  if (!val || val.length < 2) { cityOptions.value = []; return; }
  cityTimer = setTimeout(async () => {
    cityLoading.value = true;
    try {
      const { data } = await fetchCities(val);
      cityOptions.value = data?.data || [];
    } finally { cityLoading.value = false; }
  }, 500);
});

watch(warehouseQuery, (val) => {
  if (skipFetch.warehouse) { skipFetch.warehouse = false; return; }
  local.warehouse_name = val;
  if (warehouseTimer) clearTimeout(warehouseTimer);
  if (!local.city_ref) return;
  warehouseTimer = setTimeout(async () => {
    warehouseLoading.value = true;
    try {
      const { data } = await fetchWarehouses({ cityRef: local.city_ref, query: val });
      warehouseOptions.value = data?.data || [];
    } finally { warehouseLoading.value = false; }
  }, 500);
});

const selectCity = (city) => {
  local.city_ref = city.ref;
  local.city_name = city.name;
  skipFetch.city = true; cityQuery.value = city.name;
  showCityDropdown.value = false;
  local.warehouse_ref = ''; local.warehouse_name = ''; warehouseQuery.value = '';
  if (local.delivery_type === 'warehouse') {
    skipFetch.warehouse = false;
    warehouseTimer = setTimeout(async () => {
      const { data } = await fetchWarehouses({ cityRef: city.ref, query: '' });
      warehouseOptions.value = data?.data || [];
    }, 100);
  }
};

const selectWarehouse = (wh) => {
  local.warehouse_ref = wh.ref;
  local.warehouse_name = wh.name;
  skipFetch.warehouse = true; warehouseQuery.value = wh.name;
  showWarehouseDropdown.value = false;
};

// ... (решта логіки вулиць залишається) ...
const onWarehouseFocus = () => { showWarehouseDropdown.value = true; if(local.city_ref && !warehouseOptions.value.length) loadWarehouses(''); };
const onStreetFocus = () => { showStreetDropdown.value = true; };
const scheduleCloseCity = () => setTimeout(() => showCityDropdown.value = false, 200);
const scheduleCloseWarehouse = () => setTimeout(() => showWarehouseDropdown.value = false, 200);
const scheduleCloseStreet = () => setTimeout(() => showStreetDropdown.value = false, 200);
</script>

<style scoped>
/* Анімації */
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.3s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
.modal-slide-enter-active, .modal-slide-leave-active { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.modal-slide-enter-from, .modal-slide-leave-to { transform: translateY(100%); }
.slide-up-enter-active, .slide-up-leave-active { transition: all 0.2s ease; }
.slide-up-enter-from, .slide-up-leave-to { opacity: 0; transform: translateY(10px); }

/* LAYOUT */
.modal-backdrop {
  position: fixed; inset: 0; 
  background: rgba(15, 23, 42, 0.65); 
  backdrop-filter: blur(8px);
  z-index: 999999;
  display: flex; align-items: center; justify-content: center;
}

.modal-window {
  background: #ffffff;
  width: 500px;
  max-width: 100%;
  border-radius: 28px;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  display: flex; flex-direction: column;
  max-height: 85vh;
  position: relative;
  overflow: hidden;
}

/* HEADER */
.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid #f1f5f9;
  display: flex; align-items: center; justify-content: space-between;
  background: #fff; flex-shrink: 0;
}
.header-content { display: flex; align-items: center; gap: 14px; }
.icon-brand {
  width: 42px; height: 42px; background: #fff1f2; color: #dc2626; border-radius: 12px;
  display: flex; align-items: center; justify-content: center; font-size: 20px;
}
.title-group h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.2; }
.brand-tag { font-size: 11px; font-weight: 800; color: #dc2626; letter-spacing: 0.05em; text-transform: uppercase; }

.btn-close {
  width: 36px; height: 36px; border-radius: 50%; border: none; background: #f8fafc;
  color: #64748b; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: 0.2s;
}
.btn-close:hover { background: #fee2e2; color: #ef4444; }

/* BODY */
.modal-body { flex: 1; padding: 24px; overflow-y: auto; background: #ffffff; }
.form-section { margin-bottom: 24px; }
.section-label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 10px; display: block; }

/* GRID CARDS */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.option-card {
  border: 2px solid #f1f5f9; border-radius: 16px; padding: 16px; cursor: pointer; position: relative;
  transition: 0.2s ease; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px;
}
.option-card.active { border-color: #a78bfb; background: #f5f3ff; color: #5b21b6; }
.card-icon { font-size: 24px; color: #94a3b8; transition: 0.2s; }
.option-card.active .card-icon { color: #7c3aed; }
.card-title { font-weight: 700; font-size: 14px; }
.check-icon { position: absolute; top: 10px; right: 10px; font-size: 18px; color: #a78bfb; opacity: 0; transform: scale(0.5); transition: 0.2s; }
.option-card.active .check-icon { opacity: 1; transform: scale(1); }

/* INPUTS */
.input-group-modern { position: relative; }
.input-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 18px; pointer-events: none; }
.modern-input {
  width: 100%; padding: 14px 16px 14px 48px; border: 1px solid #e2e8f0; border-radius: 14px;
  font-size: 15px; font-weight: 500; color: #0f172a; outline: none; transition: 0.2s; background: #fcfcfc;
}
.modern-input:focus { border-color: #a78bfb; background: #fff; box-shadow: 0 0 0 4px rgba(167, 139, 251, 0.1); }
.modern-input:disabled { background: #f1f5f9; cursor: not-allowed; opacity: 0.7; }
.center-text { text-align: center; padding-left: 10px; }

/* DROPDOWN */
.dropdown-menu-custom {
  position: absolute; top: 105%; left: 0; right: 0; background: #fff; border: 1px solid #f1f5f9;
  border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-height: 220px; overflow-y: auto; z-index: 50; padding: 6px;
}
.dropdown-item { padding: 10px 12px; border-radius: 8px; cursor: pointer; transition: 0.1s; }
.dropdown-item:hover { background: #f5f3ff; color: #7c3aed; }
.item-main { font-weight: 600; font-size: 14px; }
.item-sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }

/* TOGGLE */
.toggle-switch { background: #f1f5f9; padding: 4px; border-radius: 14px; display: flex; position: relative; }
.switch-btn { flex: 1; border: none; background: transparent; padding: 10px; font-weight: 700; font-size: 13px; color: #64748b; position: relative; z-index: 2; cursor: pointer; transition: 0.2s; }
.switch-btn.active { color: #0f172a; }
.switch-bg { position: absolute; top: 4px; bottom: 4px; width: calc(50% - 6px); background: #fff; border-radius: 11px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: left 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 1; }

/* FOOTER */
.modal-footer { padding: 20px 24px; border-top: 1px solid #f1f5f9; background: #fff; flex-shrink: 0; }
.btn-save-primary {
  width: 100%; height: 50px; background: #0f172a; color: #fff; border: none; border-radius: 14px;
  font-weight: 700; font-size: 15px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-save-primary:hover { background: #1e293b; transform: translateY(-1px); }
.btn-save-primary.success { background: #10b981; }

.spinner-input { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; border: 2px solid #e2e8f0; border-top-color: #a78bfb; border-radius: 50%; animation: spin 0.8s linear infinite; }

/* MOBILE FIX */
@media (max-width: 768px) {
  .modal-window { width: 100%; height: 100dvh; max-height: 100dvh; border-radius: 0; }
  .modal-fade-enter-active, .modal-fade-leave-active { transition: none; }
  .modal-slide-enter-active, .modal-slide-leave-active { transition: transform 0.3s ease-out; }
  .modal-slide-enter-from, .modal-slide-leave-to { transform: translateY(100%); }
  
  .mobile-spacer { height: 80px; }
  
  /* Піднімаємо кнопку вище системної зони */
  .modal-footer { padding-bottom: calc(30px + env(safe-area-inset-bottom)); }
}

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>