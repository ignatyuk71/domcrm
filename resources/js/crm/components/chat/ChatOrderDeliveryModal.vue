<template>
  <teleport to="body" :disabled="embedded">
    <transition name="modal-fade">
      <div
        v-if="open"
        class="delivery-shell"
        :class="{ 'is-embedded': embedded }"
        @click.self="embedded ? null : closeModal()"
      >
        <transition :name="embedded ? 'sidebar-slide' : 'modal-slide'">
          <div v-if="open" class="delivery-panel" :class="{ 'is-embedded': embedded }">
            <header class="delivery-header">
              <div class="delivery-header-main">
                <div class="delivery-header-icon">
                  <i class="bi bi-box-seam-fill"></i>
                </div>
                <div class="delivery-header-copy">
                  <h3>Налаштування доставки</h3>
                  <span>Нова пошта</span>
                </div>
              </div>

              <button class="delivery-close-btn" type="button" :title="embedded ? 'Назад' : 'Закрити'" @click="closeModal">
                <i class="bi" :class="embedded ? 'bi-arrow-left' : 'bi-x-lg'"></i>
              </button>
            </header>

            <div class="delivery-body custom-scrollbar">
              <section class="delivery-section">
                <div class="section-label">Спосіб отримання</div>
                <div class="delivery-type-stack">
                  <button
                    type="button"
                    class="delivery-type-btn"
                    :class="{ active: local.delivery_type === 'warehouse' }"
                    @click="setDeliveryType('warehouse')"
                  >
                    <span class="delivery-type-icon"><i class="bi bi-building"></i></span>
                    <span class="delivery-type-copy">
                      <strong>У відділення</strong>
                      <small>Відділення або поштомат</small>
                    </span>
                    <i v-if="local.delivery_type === 'warehouse'" class="bi bi-check2-circle delivery-type-check"></i>
                  </button>

                  <button
                    type="button"
                    class="delivery-type-btn"
                    :class="{ active: local.delivery_type === 'courier' }"
                    @click="setDeliveryType('courier')"
                  >
                    <span class="delivery-type-icon"><i class="bi bi-truck"></i></span>
                    <span class="delivery-type-copy">
                      <strong>Кур'єром</strong>
                      <small>Адресна доставка</small>
                    </span>
                    <i v-if="local.delivery_type === 'courier'" class="bi bi-check2-circle delivery-type-check"></i>
                  </button>
                </div>
              </section>

              <section class="delivery-section">
                <div class="section-label">Населений пункт</div>
                <div class="field-shell">
                  <i class="bi bi-geo-alt field-icon"></i>
                  <input
                    v-model="cityQuery"
                    type="text"
                    class="field-input"
                    placeholder="Введіть назву міста..."
                    @focus="showCityDropdown = true"
                    @blur="scheduleCloseCity"
                  >
                  <div v-if="cityLoading" class="spinner-input"></div>

                  <transition name="fade">
                    <div v-if="showCityDropdown && cityOptions.length" class="field-dropdown custom-scrollbar">
                      <button
                        v-for="city in cityOptions"
                        :key="city.ref"
                        type="button"
                        class="field-dropdown-item"
                        @mousedown.prevent="selectCity(city)"
                      >
                        <span class="dropdown-title">{{ city.name }}</span>
                        <span class="dropdown-subtitle">{{ city.area }}</span>
                      </button>
                    </div>
                  </transition>
                </div>
              </section>

              <transition name="slide-up" mode="out-in">
                <section v-if="local.delivery_type === 'warehouse'" key="warehouse" class="delivery-section">
                  <div class="section-label">Відділення або поштомат</div>
                  <div class="field-shell">
                    <i class="bi bi-signpost-2 field-icon"></i>
                    <input
                      v-model="warehouseQuery"
                      type="text"
                      class="field-input"
                      :disabled="!local.city_ref"
                      placeholder="Введіть номер або адресу..."
                      @focus="onWarehouseFocus"
                      @blur="scheduleCloseWarehouse"
                    >
                    <div v-if="warehouseLoading" class="spinner-input"></div>

                    <transition name="fade">
                      <div v-if="showWarehouseDropdown && warehouseOptions.length" class="field-dropdown custom-scrollbar">
                        <button
                          v-for="wh in warehouseOptions"
                          :key="wh.ref"
                          type="button"
                          class="field-dropdown-item"
                          @mousedown.prevent="selectWarehouse(wh)"
                        >
                          <span class="dropdown-title">{{ wh.name }}</span>
                        </button>
                      </div>
                    </transition>
                  </div>
                </section>

                <section v-else key="courier" class="delivery-section">
                  <div class="section-label">Адреса доставки</div>
                  <div class="courier-stack">
                    <div class="field-shell">
                      <i class="bi bi-map field-icon"></i>
                      <input
                        v-model="streetQuery"
                        type="text"
                        class="field-input"
                        :disabled="!local.city_ref"
                        placeholder="Вулиця (2+ символи)"
                        @focus="onStreetFocus"
                        @blur="scheduleCloseStreet"
                      >
                      <div v-if="streetLoading" class="spinner-input"></div>

                      <transition name="fade">
                        <div v-if="showStreetDropdown && (streetOptions.length || streetLoading)" class="field-dropdown custom-scrollbar">
                          <div v-if="streetLoading" class="field-dropdown-state">Пошук...</div>
                          <button
                            v-for="st in streetOptions"
                            v-else
                            :key="st.ref"
                            type="button"
                            class="field-dropdown-item"
                            @mousedown.prevent="selectStreet(st)"
                          >
                            <span class="dropdown-title">{{ st.name }}</span>
                          </button>
                        </div>
                      </transition>
                    </div>

                    <div class="courier-meta-grid">
                      <div class="field-shell compact-field">
                        <input v-model="local.building" type="text" class="field-input compact-input" placeholder="Буд.">
                      </div>
                      <div class="field-shell compact-field">
                        <input v-model="local.apartment" type="text" class="field-input compact-input" placeholder="Кв.">
                      </div>
                    </div>
                  </div>
                </section>
              </transition>

              <section class="delivery-section">
                <div class="section-label">Платник доставки</div>
                <div class="payer-segmented">
                  <button
                    type="button"
                    class="payer-btn"
                    :class="{ active: local.payer === 'recipient' }"
                    @click="local.payer = 'recipient'"
                  >
                    Отримувач
                  </button>
                  <button
                    type="button"
                    class="payer-btn"
                    :class="{ active: local.payer === 'sender' }"
                    @click="local.payer = 'sender'"
                  >
                    Відправник
                  </button>
                </div>
              </section>
            </div>

            <footer class="delivery-footer">
              <button class="save-btn" :class="{ success: isSaved }" :disabled="isSaving" @click="handleSave">
                <span v-if="!isSaved">Зберегти</span>
                <span v-else><i class="bi bi-check-lg"></i> Готово</span>
              </button>
            </footer>
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
  embedded: { type: Boolean, default: false },
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
  settlement_ref: '',
  warehouse_name: '',
  warehouse_ref: '',
  street_name: '',
  street_ref: '',
  building: '',
  apartment: '',
  address_note: '',
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
    local.street_name = ''; local.street_ref = ''; local.building = ''; local.apartment = ''; streetQuery.value = ''; streetOptions.value = [];
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
  local.settlement_ref = '';
  local.street_ref = '';
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
  local.settlement_ref = '';
  local.city_name = city.name;
  skipFetch.city = true; cityQuery.value = city.name;
  showCityDropdown.value = false;
  local.warehouse_ref = ''; local.warehouse_name = ''; warehouseQuery.value = '';
  local.street_ref = ''; local.street_name = ''; streetQuery.value = '';
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

watch(streetQuery, (val) => {
  if (skipFetch.street) { skipFetch.street = false; return; }
  local.street_name = val;
  local.street_ref = '';
  if (streetTimer) clearTimeout(streetTimer);
  if (!local.city_ref || local.delivery_type !== 'courier') return;
  if (!val || val.length < 2) { streetOptions.value = []; return; }
  streetTimer = setTimeout(async () => loadStreets(val), 500);
});

const loadWarehouses = async (query) => {
  warehouseLoading.value = true;
  try {
    const { data } = await fetchWarehouses({ cityRef: local.city_ref, query });
    warehouseOptions.value = data?.data || [];
  } finally { warehouseLoading.value = false; }
};

const loadStreets = async (query) => {
  if (!local.city_ref || !query || query.length < 2) {
    streetOptions.value = [];
    return;
  }
  streetLoading.value = true;
  try {
    const { data } = await fetchStreets({
      cityRef: local.city_ref,
      query,
      limit: 25
    });
    streetOptions.value = data?.data || [];
  } finally { streetLoading.value = false; }
};

const selectStreet = (street) => {
  local.street_name = street.name;
  local.street_ref = street.ref || '';
  skipFetch.street = true; streetQuery.value = street.name;
  showStreetDropdown.value = false;
};

const onWarehouseFocus = () => { showWarehouseDropdown.value = true; if(local.city_ref && !warehouseOptions.value.length) loadWarehouses(''); };
const onStreetFocus = () => { showStreetDropdown.value = true; };
const scheduleCloseCity = () => setTimeout(() => showCityDropdown.value = false, 200);
const scheduleCloseWarehouse = () => setTimeout(() => showWarehouseDropdown.value = false, 200);
const scheduleCloseStreet = () => setTimeout(() => showStreetDropdown.value = false, 200);
</script>

<style scoped>
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.modal-slide-enter-active,
.modal-slide-leave-active,
.sidebar-slide-enter-active,
.sidebar-slide-leave-active {
  transition: transform 0.24s ease, opacity 0.24s ease;
}

.modal-slide-enter-from,
.modal-slide-leave-to {
  opacity: 0;
  transform: translateY(24px);
}

.sidebar-slide-enter-from,
.sidebar-slide-leave-to {
  opacity: 0;
  transform: translateX(18px);
}

.slide-up-enter-active,
.slide-up-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

.delivery-shell {
  position: fixed;
  inset: 0;
  z-index: 999999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(15, 23, 42, 0.58);
  backdrop-filter: blur(8px);
}

.delivery-shell.is-embedded {
  position: absolute;
  z-index: 45;
  padding: 0;
  background: #ffffff;
  backdrop-filter: none;
  align-items: stretch;
  justify-content: stretch;
}

.delivery-panel {
  width: min(100%, 500px);
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
}

.delivery-panel.is-embedded {
  width: 100%;
  height: 100%;
  max-height: none;
  border-radius: 0;
  box-shadow: none;
}

.delivery-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
  border-bottom: 1px solid #e5e7eb;
  background: #ffffff;
}

.delivery-header-main {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.delivery-header-icon {
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  background: #fff1f2;
  color: #dc2626;
  font-size: 18px;
}

.delivery-header-copy {
  min-width: 0;
}

.delivery-header-copy h3 {
  margin: 0;
  font-size: 16px;
  line-height: 1.2;
  font-weight: 800;
  color: #0f172a;
}

.delivery-header-copy span {
  display: inline-block;
  margin-top: 3px;
  font-size: 11px;
  line-height: 1.2;
  font-weight: 800;
  color: #dc2626;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.delivery-close-btn {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #ffffff;
  color: #64748b;
  cursor: pointer;
  transition: all 0.2s ease;
}

.delivery-close-btn:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
  color: #0f172a;
}

.delivery-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 16px;
  background: #ffffff;
}

.delivery-section {
  margin-bottom: 18px;
}

.delivery-section:last-child {
  margin-bottom: 8px;
}

.section-label {
  margin-bottom: 8px;
  font-size: 11px;
  line-height: 1.2;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.delivery-type-stack {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.delivery-type-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #ffffff;
  color: #0f172a;
  cursor: pointer;
  transition: all 0.2s ease;
  box-sizing: border-box;
}

.delivery-type-btn:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
}

.delivery-type-btn.active {
  border-color: #a78bfa;
  background: #f5f3ff;
  color: #6d28d9;
}

.delivery-type-icon {
  width: 28px;
  flex-shrink: 0;
  text-align: center;
  font-size: 18px;
  color: #94a3b8;
}

.delivery-type-btn.active .delivery-type-icon {
  color: #7c3aed;
}

.delivery-type-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  text-align: left;
}

.delivery-type-copy strong {
  font-size: 14px;
  line-height: 1.2;
  font-weight: 700;
}

.delivery-type-copy small {
  font-size: 11px;
  line-height: 1.2;
  color: #64748b;
}

.delivery-type-check {
  margin-left: auto;
  flex-shrink: 0;
  font-size: 18px;
  color: #7c3aed;
}

.field-shell {
  position: relative;
  width: 100%;
  min-width: 0;
}

.field-icon {
  position: absolute;
  top: 50%;
  left: 14px;
  transform: translateY(-50%);
  font-size: 17px;
  color: #94a3b8;
  pointer-events: none;
}

.field-input {
  width: 100%;
  min-height: 44px;
  padding: 11px 14px 11px 42px;
  border: 1px solid #dbe3ef;
  border-radius: 12px;
  background: #ffffff;
  color: #0f172a;
  font-size: 14px;
  line-height: 1.2;
  outline: none;
  box-sizing: border-box;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
}

.field-input:focus {
  border-color: #a78bfa;
  box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.12);
}

.field-input:disabled {
  background: #f8fafc;
  color: #94a3b8;
  cursor: not-allowed;
}

.compact-field .field-input {
  padding-left: 14px;
  text-align: center;
}

.field-dropdown {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 55;
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 190px;
  padding: 6px;
  overflow-y: auto;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.14);
  box-sizing: border-box;
}

.field-dropdown-item {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 8px 10px;
  border: none;
  border-radius: 10px;
  background: transparent;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.18s ease;
}

.field-dropdown-item:hover {
  background: #f8fafc;
}

.field-dropdown-state {
  padding: 10px;
  font-size: 12px;
  color: #64748b;
}

.dropdown-title {
  font-size: 13px;
  line-height: 1.35;
  font-weight: 600;
  color: #0f172a;
  white-space: normal;
  word-break: break-word;
}

.dropdown-subtitle {
  font-size: 11px;
  line-height: 1.2;
  color: #94a3b8;
}

.spinner-input {
  position: absolute;
  top: 50%;
  right: 14px;
  width: 16px;
  height: 16px;
  transform: translateY(-50%);
  border: 2px solid #e2e8f0;
  border-top-color: #7c3aed;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.courier-stack {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.courier-meta-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 8px;
}

.payer-segmented {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.payer-btn {
  min-height: 42px;
  padding: 10px 12px;
  border: 1px solid #dbe3ef;
  border-radius: 12px;
  background: #ffffff;
  color: #475569;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.18s ease;
}

.payer-btn:hover {
  border-color: #cbd5e1;
  background: #f8fafc;
}

.payer-btn.active {
  border-color: #a78bfa;
  background: #f5f3ff;
  color: #6d28d9;
}

.delivery-footer {
  padding: 14px 16px;
  border-top: 1px solid #e5e7eb;
  background: #ffffff;
}

.save-btn {
  width: 100%;
  min-height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: none;
  border-radius: 12px;
  background: #0f172a;
  color: #ffffff;
  font-size: 14px;
  font-weight: 800;
  cursor: pointer;
  transition: transform 0.18s ease, background-color 0.18s ease;
}

.save-btn:hover {
  background: #1e293b;
}

.save-btn.success {
  background: #10b981;
}

.save-btn:disabled {
  opacity: 0.75;
  cursor: not-allowed;
}

.custom-scrollbar::-webkit-scrollbar {
  width: 5px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 999px;
}

@keyframes spin {
  to {
    transform: translateY(-50%) rotate(360deg);
  }
}

@media (max-width: 768px) {
  .delivery-shell {
    padding: 0;
  }

  .delivery-panel {
    width: 100%;
    height: 100dvh;
    max-height: 100dvh;
    border-radius: 0;
  }
}
</style>
