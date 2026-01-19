<template>
  <transition name="modal-spring">
    <div v-if="open" class="elite-modal-overlay" @click.self="$emit('close')">
      <div class="elite-delivery-window">
        
        <header class="elite-header">
          <div class="header-main-info">
            <h4 class="title-text">Оформлення доставки</h4>
            <p class="brand-np-red">Нова Пошта • Експрес-доставка</p>
          </div>
          <button class="btn-close-circle" @click="$emit('close')">
            <i class="bi bi-x"></i>
          </button>
        </header>

        <div class="elite-body custom-scrollbar">
          
          <div class="form-elite-group">
            <label class="label-elite">Дані отримувача</label>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <input type="text" class="elite-input-field" placeholder="Прізвище" v-model="test.lastName">
              </div>
              <div class="col-6">
                <input type="text" class="elite-input-field" placeholder="Ім'я" v-model="test.firstName">
              </div>
            </div>
            <div class="input-with-icon-elite">
              <i class="bi bi-phone-vibrate"></i>
              <input type="tel" class="elite-input-field icon-pad" placeholder="380XXXXXXXXX" v-model="test.phone">
            </div>
          </div>

          <div class="form-elite-group">
            <label class="label-elite">Метод отримання</label>
            <div class="delivery-toggle-elite">
              <button class="toggle-btn" :class="{ active: test.type === 'wh' }" @click="test.type = 'wh'">
                <i class="bi bi-box-seam"></i> Відділення
              </button>
              <button class="toggle-btn" :class="{ active: test.type === 'cur' }" @click="test.type = 'cur'">
                <i class="bi bi-geo-fill"></i> Кур'єр
              </button>
            </div>
          </div>

          <div class="form-elite-group">
            <label class="label-elite">Локація доставки</label>
            <div class="input-with-icon-elite">
              <i class="bi bi-search"></i>
              <select class="elite-input-field select-elite" v-model="test.city">
                <option value="Київ">Київ</option>
                <option value="Львів">Львів</option>
                <option value="Одеса">Одеса</option>
                <option value="Харків">Харків</option>
              </select>
            </div>
          </div>

          <div v-if="test.type === 'wh'" class="form-elite-group animate-in">
            <label class="label-elite">Вибір відділення або поштомату</label>
            <div class="input-with-icon-elite">
              <i class="bi bi-building"></i>
              <select class="elite-input-field select-elite" v-model="test.warehouse">
                <option value="1">Відділення №1: вул. Пирогова, 13</option>
                <option value="2">Поштомат №5522: пр. Перемоги, 4</option>
                <option value="3">Відділення №15: вул. Мазепи, 2</option>
              </select>
            </div>
            <div class="mt-3">
              <label class="label-elite">Додатковий коментар</label>
              <input class="elite-input-field small-f" v-model="test.note" placeholder="Наприклад: Отримувач інша особа">
            </div>
          </div>

          <div v-else class="form-elite-group animate-in">
             <label class="label-elite">Адреса для кур'єра</label>
             <input type="text" class="elite-input-field mb-2" placeholder="Вулиця">
             <div class="row g-2">
               <div class="col-6"><input type="text" class="elite-input-field" placeholder="Буд."></div>
               <div class="col-6"><input type="text" class="elite-input-field" placeholder="Кв."></div>
             </div>
          </div>

          <div class="form-elite-group">
            <label class="label-elite">Хто сплачує за доставку?</label>
            <div class="payer-grid-elite">
              <button class="payer-card" :class="{ active: test.payer === 'r' }" @click="test.payer = 'r'">
                <i class="bi bi-person-badge"></i> Отримувач
              </button>
              <button class="payer-card" :class="{ active: test.payer === 's' }" @click="test.payer = 's'">
                <i class="bi bi-shop-window"></i> Відправник
              </button>
            </div>
          </div>

        </div>

        <footer class="elite-footer">
          <button class="btn-secondary-elite" @click="$emit('close')">Скасувати</button>
          <button class="btn-primary-shimmer-elite" @click="$emit('save', test)">
            <span>ЗБЕРЕГТИ ДАНІ</span>
            <div class="shimmer-effect"></div>
          </button>
        </footer>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { reactive } from 'vue';

defineProps({ open: Boolean });
defineEmits(['close', 'save']);

// ТЕСТОВИЙ НАБІР ДАНИХ (БЕЗ ЛОГІКИ API)
const test = reactive({
  lastName: 'Остапчук',
  firstName: 'Дмитро',
  phone: '380991234567',
  type: 'wh',
  city: 'Київ',
  warehouse: '1',
  payer: 'r',
  note: ''
});
</script>

<style scoped>
/* PREMIUM OVERLAY & WINDOW */
.elite-modal-overlay { 
  position: fixed; inset: 0; background: rgba(10, 15, 30, 0.5); 
  z-index: 100005; backdrop-filter: blur(12px); 
  display: flex; align-items: center; justify-content: center; 
}
.elite-delivery-window { 
  background: #ffffff; width: 540px; max-width: 95%; max-height: 88vh; 
  border-radius: 32px; display: flex; flex-direction: column; 
  overflow: hidden; box-shadow: 0 40px 100px rgba(0,0,0,0.45); 
}

/* HEADER */
.elite-header { padding: 24px 30px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.title-text { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; }
.brand-np-red { font-size: 11px; color: #dc2626; font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em; margin-top: 4px; }
.btn-close-circle { border: none; background: #f1f5f9; width: 38px; height: 38px; border-radius: 50%; color: #64748b; font-size: 24px; cursor: pointer; transition: 0.3s; }
.btn-close-circle:hover { transform: rotate(90deg); background: #fee2e2; color: #ef4444; }

/* BODY */
.elite-body { flex: 1; padding: 30px; overflow-y: auto; background: #fafafa; }
.form-elite-group { margin-bottom: 28px; }
.label-elite { font-size: 10px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.1em; margin-bottom: 12px; display: block; }

/* INPUT STYLES */
.elite-input-field { 
  width: 100%; padding: 15px 20px; border-radius: 16px; 
  border: 1.5px solid #edf2f7; font-size: 14px; font-weight: 700; 
  color: #1e293b; background: #fff; transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); outline: none; 
}
.elite-input-field:focus { border-color: #a78bfb; background: #fff; box-shadow: 0 0 0 5px rgba(167, 139, 251, 0.15); }
.input-with-icon-elite { position: relative; display: flex; align-items: center; }
.input-with-icon-elite i { position: absolute; left: 18px; color: #a78bfb; font-size: 18px; }
.icon-pad { padding-left: 52px; }

/* CUSTOM SELECT */
.select-elite { 
  cursor: pointer; appearance: none; padding-left: 52px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23a78bfb' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E"); 
  background-repeat: no-repeat; background-position: right 20px center; 
}

/* TOGGLE GROUP */
.delivery-toggle-elite { display: flex; background: #f1f5f9; padding: 6px; border-radius: 20px; gap: 6px; }
.toggle-btn { 
  flex: 1; border: none; background: transparent; padding: 14px; 
  border-radius: 14px; font-size: 13px; font-weight: 800; color: #64748b; 
  cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; 
}
.toggle-btn.active { background: #fff; color: #a78bfb; box-shadow: 0 8px 16px rgba(0,0,0,0.06); }

/* PAYER SELECTOR */
.payer-grid-elite { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.payer-card { 
  border: 2px solid #edf2f7; background: #fff; padding: 16px; 
  border-radius: 20px; font-size: 13px; font-weight: 800; color: #64748b; 
  cursor: pointer; transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 8px; 
}
.payer-card i { font-size: 20px; }
.payer-card.active { border-color: #a78bfb; background: #f5f3ff; color: #a78bfb; transform: translateY(-3px); box-shadow: 0 10px 20px rgba(167, 139, 251, 0.1); }

/* FOOTER & SHIMMER */
.elite-footer { padding: 20px 30px; border-top: 1px solid #f1f5f9; display: flex; gap: 16px; background: #fff; }
.btn-secondary-elite { 
  flex: 1; border: 2.5px solid #edf2f7; background: #fff; height: 56px; 
  border-radius: 20px; color: #64748b; font-weight: 800; cursor: pointer; 
}
.btn-primary-shimmer-elite { 
  flex: 2; background: #a78bfb; border: none; height: 56px; 
  border-radius: 20px; color: #fff; font-weight: 900; cursor: pointer; 
  position: relative; overflow: hidden; transition: 0.3s; letter-spacing: 0.05em;
}
.btn-primary-shimmer-elite:hover { background: #9061f9; transform: translateY(-3px); box-shadow: 0 15px 30px rgba(167, 139, 251, 0.4); }

.shimmer-effect { position: absolute; top: 0; left: -100%; width: 50%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent); animation: shimmer 2.5s infinite; }
@keyframes shimmer { 100% { left: 200%; } }

/* MOBILE ADAPTATION: FULL SCREEN */
@media (max-width: 768px) {
  .elite-delivery-window { width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
  .elite-modal-overlay { align-items: flex-start; }
}

/* ANIMATIONS */
.modal-spring-enter-active { animation: spring-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.modal-spring-leave-active { animation: spring-in 0.3s reverse; }
@keyframes spring-in { from { opacity: 0; transform: scale(0.9) translateY(40px); } to { opacity: 1; transform: scale(1) translateY(0); } }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>