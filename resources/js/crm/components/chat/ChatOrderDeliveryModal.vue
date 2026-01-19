<template>
  <transition name="modal-spring">
    <div v-if="open" class="delivery-modal-overlay" @click.self="$emit('close')">
      <div class="delivery-window">
        <div class="delivery-header">
          <div class="header-info">
            <h4>Дані отримувача</h4>
            <p>Нова Пошта • Налаштування доставки</p>
          </div>
          <button class="btn-close-modal" @click="$emit('close')">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>

        <div class="delivery-body custom-scrollbar">
          <div class="form-section">
            <label class="form-label-custom">Отримувач (ПІБ та Телефон)</label>
            <div class="row g-2">
              <div class="col-6">
                <input type="text" class="elite-input" placeholder="Прізвище" v-model="testData.lastName">
              </div>
              <div class="col-6">
                <input type="text" class="elite-input" placeholder="Ім'я" v-model="testData.firstName">
              </div>
              <div class="col-12">
                <div class="input-with-icon">
                  <i class="bi bi-telephone"></i>
                  <input type="tel" class="elite-input icon-padding" placeholder="380XXXXXXXXX" v-model="testData.phone">
                </div>
              </div>
            </div>
          </div>

          <div class="form-section">
            <label class="form-label-custom">Спосіб доставки</label>
            <div class="delivery-toggle-group">
              <button 
                class="toggle-item" 
                :class="{ active: testData.type === 'warehouse' }"
                @click="testData.type = 'warehouse'"
              >
                <i class="bi bi-box-seam"></i> Відділення
              </button>
              <button 
                class="toggle-item" 
                :class="{ active: testData.type === 'courier' }"
                @click="testData.type = 'courier'"
              >
                <i class="bi bi-geo-fill"></i> Кур'єр
              </button>
            </div>
          </div>

          <div class="form-section">
            <label class="form-label-custom">Місто доставки</label>
            <div class="input-with-icon">
              <i class="bi bi-search"></i>
              <select class="elite-input select-custom" v-model="testData.city">
                <option value="Київ">Київ</option>
                <option value="Львів">Львів</option>
                <option value="Одеса">Одеса</option>
                <option value="Харків">Харків</option>
                <option value="Дніпро">Дніпро</option>
              </select>
            </div>
          </div>

          <div class="form-section" v-if="testData.type === 'warehouse'">
            <label class="form-label-custom">Відділення / Поштомат</label>
            <div class="input-with-icon">
              <i class="bi bi-building-up"></i>
              <select class="elite-input select-custom" v-model="testData.warehouse">
                <option value="Відділення №1: вул. Пирогова, 13">Відділення №1: вул. Пирогова, 13</option>
                <option value="Поштомат №5522: пр. Перемоги, 4">Поштомат №5522: пр. Перемоги, 4</option>
                <option value="Відділення №15: вул. Мазепи, 2">Відділення №15: вул. Мазепи, 2</option>
              </select>
            </div>
          </div>

          <div class="form-section" v-else>
            <label class="form-label-custom">Адреса для кур'єра</label>
            <input type="text" class="elite-input mb-2" placeholder="Вулиця" v-model="testData.street">
            <div class="row g-2">
              <div class="col-6">
                <input type="text" class="elite-input" placeholder="Буд." v-model="testData.building">
              </div>
              <div class="col-6">
                <input type="text" class="elite-input" placeholder="Кв." v-model="testData.apartment">
              </div>
            </div>
          </div>

          <div class="form-section">
            <label class="form-label-custom">Хто оплачує доставку?</label>
            <div class="payer-selector">
              <button :class="{ active: testData.payer === 'recipient' }" @click="testData.payer = 'recipient'">
                <i class="bi bi-person"></i> Отримувач
              </button>
              <button :class="{ active: testData.payer === 'sender' }" @click="testData.payer = 'sender'">
                <i class="bi bi-shop"></i> Відправник
              </button>
            </div>
          </div>
        </div>

        <div class="delivery-footer">
          <button class="btn-cancel-light" @click="$emit('close')">Скасувати</button>
          <button class="btn-save-elite" @click="$emit('save', testData)">
            Зберегти замовлення
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { reactive } from 'vue';

defineProps({ open: Boolean });
defineEmits(['close', 'save']);

// Тестові дані для візуальної демонстрації шаблону
const testData = reactive({
  firstName: 'Олександр',
  lastName: 'Ковальчук',
  phone: '380970000000',
  type: 'warehouse',
  city: 'Київ',
  warehouse: 'Відділення №1: вул. Пирогова, 13',
  street: '',
  building: '',
  apartment: '',
  payer: 'recipient'
});
</script>

<style scoped>
/* ПРЕМІУМ МОДАЛКА */
.delivery-modal-overlay { 
  position: fixed; inset: 0; background: rgba(5, 10, 20, 0.6); 
  z-index: 100005; backdrop-filter: blur(8px); 
  display: flex; align-items: center; justify-content: center; 
}
.delivery-window { 
  background: #fff; width: 520px; max-width: 95%; max-height: 90vh; 
  border-radius: 32px; display: flex; flex-direction: column; 
  overflow: hidden; box-shadow: 0 40px 100px rgba(0,0,0,0.4); 
}

/* HEADER */
.delivery-header { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.header-info h4 { font-size: 19px; font-weight: 800; color: #0f172a; margin: 0; }
.header-info p { font-size: 11px; color: #94a3b8; margin: 2px 0 0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
.btn-close-modal { border: none; background: #f1f5f9; width: 36px; height: 36px; border-radius: 50%; color: #64748b; cursor: pointer; transition: 0.2s; }
.btn-close-modal:hover { transform: rotate(90deg); background: #e2e8f0; }

/* BODY */
.delivery-body { flex: 1; padding: 24px; overflow-y: auto; background: #fafafa; }
.form-section { margin-bottom: 24px; }
.form-label-custom { font-size: 10px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.06em; margin-bottom: 10px; display: block; }

/* INPUTS */
.elite-input { 
  width: 100%; padding: 14px 18px; border-radius: 14px; 
  border: 1.5px solid #edf2f7; font-size: 14px; font-weight: 700; 
  color: #1e293b; background: #fff; transition: 0.3s; outline: none; 
}
.elite-input:focus { border-color: #a78bfb; background: #fcfaff; box-shadow: 0 0 0 4px rgba(167, 139, 251, 0.1); }
.input-with-icon { position: relative; display: flex; align-items: center; }
.input-with-icon i { position: absolute; left: 16px; color: #a78bfb; font-size: 18px; pointer-events: none; }
.icon-padding { padding-left: 45px; }

/* CUSTOM SELECT */
.select-custom { 
  cursor: pointer; appearance: none; padding-left: 45px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23a78bfb' class='bi bi-chevron-down' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E"); 
  background-repeat: no-repeat; background-position: right 18px center; 
}

/* TOGGLE GROUP */
.delivery-toggle-group { display: flex; background: #f1f5f9; padding: 5px; border-radius: 16px; gap: 5px; }
.toggle-item { 
  flex: 1; border: none; background: transparent; padding: 12px; 
  border-radius: 12px; font-size: 13px; font-weight: 800; color: #64748b; 
  cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; 
}
.toggle-item.active { background: #fff; color: #a78bfb; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }

/* PAYER SELECTOR */
.payer-selector { display: flex; gap: 12px; }
.payer-selector button { 
  flex: 1; border: 1.5px solid #edf2f7; background: #fff; padding: 14px; 
  border-radius: 16px; font-size: 13px; font-weight: 800; color: #64748b; 
  cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; 
}
.payer-selector button.active { border-color: #a78bfb; background: #f5f3ff; color: #a78bfb; transform: scale(1.02); }

/* FOOTER */
.delivery-footer { padding: 18px 24px; border-top: 1px solid #f1f5f9; display: flex; gap: 14px; background: #fff; }
.btn-cancel-light { 
  flex: 1; border: 1.5px solid #edf2f7; background: #fff; height: 52px; 
  border-radius: 18px; color: #64748b; font-weight: 800; cursor: pointer; transition: 0.2s; 
}
.btn-save-elite { 
  flex: 2; background: #a78bfb; border: none; height: 52px; 
  border-radius: 18px; color: #ffffff; font-weight: 800; cursor: pointer; 
  position: relative; overflow: hidden; transition: 0.2s; 
}
.btn-save-elite::after {
  content: '';
  position: absolute;
  top: -50%;
  left: -60%;
  width: 20%;
  height: 200%;
  background: rgba(255, 255, 255, 0.25);
  transform: rotate(30deg);
  transition: all 0.6s ease;
}
.btn-save-elite:hover::after { left: 160%; }

@media (max-width: 768px) {
  .delivery-modal-overlay { align-items: stretch; justify-content: stretch; }
  .delivery-window { width: 100%; height: 100%; max-width: 100%; max-height: 100%; border-radius: 0; }
  .elite-input,
  .toggle-item,
  .payer-selector button,
  .btn-cancel-light,
  .btn-save-elite {
    height: 48px;
    font-size: 16px;
  }
}
</style>
