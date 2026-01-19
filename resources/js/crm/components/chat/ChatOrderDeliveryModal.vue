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
                <div class="method-tile" :class="{ selected: test.type === 'wh' }" @click="test.type = 'wh'">
                  <div class="tile-icon"><i class="bi bi-box-seam"></i></div>
                  <div class="tile-label">Відділення</div>
                  <div class="tile-status"><i class="bi bi-check2-circle"></i></div>
                </div>
                <div class="method-tile" :class="{ selected: test.type === 'cur' }" @click="test.type = 'cur'">
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
                <select class="neo-select" v-model="test.city">
                  <option value="Одеса">Одеса, Одеська область</option>
                  <option value="Київ">Київ, Київська область</option>
                  <option value="Львів">Львів, Львівська область</option>
                </select>
                <i class="bi bi-chevron-down arrow-icon"></i>
              </div>
            </div>

            <transition name="slide-fade" mode="out-in">
              <div v-if="test.type === 'wh'" :key="'wh'" class="elite-section">
                <label class="elite-label">Пункт призначення</label>
                <div class="neo-input-wrap">
                  <i class="bi bi-signpost-split-fill pin-icon"></i>
                  <select class="neo-select" v-model="test.warehouse">
                    <option value="1">Поштомат №5522: пр. Перемоги, 4</option>
                    <option value="2">Відділення №1: вул. Пирогова, 13</option>
                  </select>
                  <i class="bi bi-chevron-down arrow-icon"></i>
                </div>
              </div>

              <div v-else :key="'cur'" class="elite-section">
                <label class="elite-label">Адресація</label>
                <input type="text" class="neo-input mb-3" placeholder="Вулиця (пошук...)">
                <div class="row g-3">
                  <div class="col-6"><input type="text" class="neo-input" placeholder="Будинок"></div>
                  <div class="col-6"><input type="text" class="neo-input" placeholder="Кв. / Офіс"></div>
                </div>
              </div>
            </transition>

            <div class="elite-section no-border">
              <label class="elite-label">Розподіл витрат</label>
              <div class="segmented-control">
                <div class="segment" :class="{ active: test.payer === 'r' }" @click="test.payer = 'r'">
                  <i class="bi bi-person"></i> Отримувач
                </div>
                <div class="segment" :class="{ active: test.payer === 's' }" @click="test.payer = 's'">
                  <i class="bi bi-shop"></i> Відправник
                </div>
                <div class="active-bg" :style="{ left: test.payer === 'r' ? '4px' : '50%' }"></div>
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

const props = defineProps({
  open: Boolean,
  modelValue: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'save', 'update:modelValue']);

const isSaving = ref(false);
const isSaved = ref(false);

const test = reactive({
  type: 'wh',
  city: 'Одеса',
  warehouse: '1',
  street: '',
  building: '',
  apartment: '',
  payer: 'r',
});

const syncFromModel = () => {
  const data = props.modelValue || {};
  const deliveryType = data.delivery_type || data.type || 'warehouse';
  test.type = deliveryType === 'courier' ? 'cur' : 'wh';
  test.city = data.city_name || data.city || 'Одеса';
  test.warehouse = data.warehouse_ref || data.warehouse || '1';
  test.street = data.street_name || data.street || '';
  test.building = data.building || '';
  test.apartment = data.apartment || '';
  test.payer = data.payer === 'sender' || data.payer === 's' ? 's' : 'r';
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

const readCourierInputs = () => {
  const modal = document.querySelector('.god-mode-window');
  if (!modal) return { street: test.street, building: test.building, apartment: test.apartment };
  const streetInput = modal.querySelector('.neo-input.mb-3');
  const rowInputs = modal.querySelectorAll('.row.g-3 .neo-input');
  return {
    street: streetInput?.value || test.street || '',
    building: rowInputs?.[0]?.value || test.building || '',
    apartment: rowInputs?.[1]?.value || test.apartment || '',
  };
};

const resolveWarehouseName = () => {
  if (test.type !== 'wh') return '';
  const modal = document.querySelector('.god-mode-window');
  const selects = modal?.querySelectorAll('.elite-section select.neo-select') || [];
  const warehouseSelect = selects.length > 1 ? selects[1] : selects[0];
  const selectedOption = warehouseSelect?.selectedOptions?.[0];
  return selectedOption?.text || '';
};

const handleSave = () => {
  if (isSaving.value) return;
  isSaving.value = true;
  isSaved.value = true;

  const courier = readCourierInputs();
  const payload = {
    delivery_type: test.type === 'cur' ? 'courier' : 'warehouse',
    city_name: test.city,
    warehouse_name: resolveWarehouseName() || test.warehouse,
    street_name: courier.street,
    building: courier.building,
    apartment: courier.apartment,
    payer: test.payer === 's' ? 'sender' : 'recipient',
  };

  setTimeout(() => {
    emit('update:modelValue', payload);
    emit('save', payload);
    closeModal(true);
  }, 1100);
};

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

.slide-fade-enter-active, .slide-fade-leave-active { transition: all 0.4s ease; }
.slide-fade-enter-from, .slide-fade-leave-to { opacity: 0; transform: translateY(20px); }

.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>
