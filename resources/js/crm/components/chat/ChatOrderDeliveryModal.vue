<template>
  <transition name="modal-spring">
    <div v-if="open" class="delivery-modal-overlay" @click.self="$emit('close')">
      <div class="delivery-window">
        
        <div class="delivery-header">
          <div class="header-info">
            <h4 class="m-0">Доставка</h4>
            <p class="brand-red-text">Нова Пошта • Оберіть параметри</p>
          </div>
          <button class="btn-close-modal" @click="$emit('close')">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>

        <div class="delivery-body custom-scrollbar">
          <div class="delivery-card p-3 border-0 bg-white">
            <div class="d-flex flex-column gap-4">
              
              <section>
                <label class="form-label-custom">Спосіб доставки</label>
                <div class="delivery-toggle-group">
                  <input type="radio" class="btn-check" id="dt_warehouse" value="warehouse" v-model="local.delivery_type">
                  <label class="toggle-item" for="dt_warehouse">
                    <i class="bi bi-box-seam"></i>
                    <span>Відділення</span>
                  </label>

                  <input type="radio" class="btn-check" id="dt_courier" value="courier" v-model="local.delivery_type">
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
                    type="text"
                    class="form-control custom-input"
                    v-model="cityQuery"
                    @focus="showCityDropdown = true"
                    placeholder="Оберіть місто..."
                  />
                </div>
                
                <div v-if="showCityDropdown && cityQuery" class="custom-dropdown shadow">
                  <button
                    v-for="city in filteredCities"
                    :key="city"
                    type="button"
                    class="dropdown-item"
                    @click="selectCity(city)"
                  >
                    <i class="bi bi-geo-alt me-2 text-muted"></i>
                    <span class="fw-bold">{{ city }}</span>
                  </button>
                </div>
              </section>

              <section v-if="local.delivery_type !== 'courier'" class="position-relative">
                <label class="form-label-custom">Відділення або поштомат</label>
                <div class="input-wrapper">
                  <i class="bi bi-building-up input-prefix"></i>
                  <input
                    type="text"
                    class="form-control custom-input"
                    v-model="warehouseQuery"
                    @focus="showWarehouseDropdown = true"
                    placeholder="Введіть номер або назву..."
                  />
                </div>

                <div v-if="showWarehouseDropdown && warehouseQuery" class="custom-dropdown shadow">
                  <button
                    v-for="wh in filteredWarehouses"
                    :key="wh"
                    type="button"
                    class="dropdown-item"
                    @click="selectWarehouse(wh)"
                  >
                    <i class="bi bi-door-open text-primary me-2"></i>
                    <span class="small fw-medium">{{ wh }}</span>
                  </button>
                </div>
                
                <div class="mt-3">
                  <label class="form-label-custom">Додатково (ПІБ або коментар)</label>
                  <input class="form-control custom-input-small" v-model="local.address_note" placeholder="Наприклад: Отримувач інша особа" />
                </div>
              </section>

              <section v-else>
                <div class="mb-3">
                  <label class="form-label-custom">Вулиця</label>
                  <input type="text" class="form-control custom-input" v-model="local.street_name" placeholder="Вулиця..." />
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label-custom">Будинок</label>
                    <input class="form-control custom-input" v-model="local.building" placeholder="10/А" />
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
        </div>

        <div class="delivery-footer">
          <button class="btn-cancel" @click="$emit('close')">Скасувати</button>
          <button class="btn-save" @click="$emit('save', local)">Зберегти дані</button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { reactive, ref, computed } from 'vue';

const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'save']);

// Тестові дані замість API
const mockCities = ['Київ', 'Одеса', 'Львів', 'Харків', 'Дніпро'];
const mockWarehouses = ['Відділення №1: вул. Пирогова, 13', 'Поштомат №5522', 'Відділення №15'];

const local = reactive({
  city_name: '', 
  warehouse_name: '',
  delivery_type: 'warehouse', 
  payer: 'recipient',
  street_name: '', 
  building: '', 
  apartment: '', 
  address_note: ''
});

const cityQuery = ref('');
const warehouseQuery = ref('');
const showCityDropdown = ref(false);
const showWarehouseDropdown = ref(false);

const filteredCities = computed(() => mockCities.filter(c => c.toLowerCase().includes(cityQuery.value.toLowerCase())));
const filteredWarehouses = computed(() => mockWarehouses.filter(w => w.toLowerCase().includes(warehouseQuery.value.toLowerCase())));

function selectCity(city) {
  local.city_name = city;
  cityQuery.value = city;
  showCityDropdown.value = false;
}

function selectWarehouse(wh) {
  local.warehouse_name = wh;
  warehouseQuery.value = wh;
  showWarehouseDropdown.value = false;
}
</script>

<style scoped>
/* ПОВНОЕКРАННА ВЕРСТКА ДЛЯ МОБІЛЬНИХ ПРИСТРОЇВ */
.delivery-modal-overlay { position: fixed; inset: 0; background: rgba(10, 15, 30, 0.4); z-index: 100005; backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; }

.delivery-window { background: #fff; width: 500px; max-width: 95%; max-height: 90vh; border-radius: 24px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 40px 100px rgba(0,0,0,0.4); transition: 0.3s; }

.delivery-header { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
.brand-red-text { color: #dc2626; font-size: 11px; font-weight: 800; text-transform: uppercase; margin: 2px 0 0; }

.delivery-body { flex: 1; overflow-y: auto; background: #fff; padding: 10px; }

.delivery-footer { padding: 16px 20px; border-top: 1px solid #f1f5f9; display: flex; gap: 12px; }

/* Твої оригінальні стилі */
.form-label-custom { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #8898aa; margin-bottom: 0.4rem; display: block; }
.input-wrapper { position: relative; display: flex; align-items: center; }
.custom-input { border-radius: 10px; padding: 0.65rem 2.6rem 0.65rem 2.4rem; border: 1px solid #e9ecef; width: 100%; font-size: 0.9rem; }
.input-prefix { position: absolute; left: 0.9rem; color: #adb5bd; }
.delivery-toggle-group { display: flex; background: #f4f6f9; padding: 4px; border-radius: 12px; gap: 4px; }
.toggle-item { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px; border-radius: 9px; cursor: pointer; transition: 0.2s; font-weight: 600; font-size: 0.9rem; color: #6c757d; }
.btn-check { display: none; }
.btn-check:checked + .toggle-item { background: #fff; color: #a78bfb; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }

.custom-dropdown { position: absolute; top: 105%; left: 0; right: 0; z-index: 1050; background: white; border-radius: 10px; max-height: 200px; overflow-y: auto; border: 1px solid #eee; }
.dropdown-item { padding: 10px; border: none; background: none; width: 100%; text-align: left; border-bottom: 1px solid #f8f9fa; font-size: 0.85rem; }
.dropdown-item:hover { background: #f5f3ff; }

.payer-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.payer-option { border: 1px solid #e9ecef; padding: 10px; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; font-size: 0.9rem; color: #6c757d; }
.payer-option.active { border-color: #a78bfb; background: #f5f3ff; color: #a78bfb; }

.btn-cancel { flex: 1; border: 1px solid #e2e8f0; background: #fff; padding: 10px; border-radius: 12px; font-weight: 700; color: #64748b; cursor: pointer; }
.btn-save { flex: 2; background: #a78bfb; color: #fff; border: none; padding: 10px; border-radius: 12px; font-weight: 800; cursor: pointer; }

/* АДАПТИВНІСТЬ: НА ВЕСЬ ЕКРАН ДЛЯ МОБІЛОК */
@media (max-width: 768px) {
  .delivery-window { width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
  .delivery-modal-overlay { align-items: flex-start; }
}

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

.modal-spring-enter-active { animation: spring-in 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
@keyframes spring-in { from { opacity: 0; transform: scale(0.9) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
</style>