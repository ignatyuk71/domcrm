<template>
  <teleport to="body">
    <transition name="modal-spring">
      <div v-if="open" class="ultimate-overlay" @click.self="$emit('close')">
        <div class="ultimate-window">
          
          <header class="ultimate-header">
            <div class="brand-group">
              <div class="brand-logo-mini">
                <i class="bi bi-box-seam-fill"></i>
              </div>
              <div class="brand-text">
                <h4>Логістика</h4>
                <span class="np-red-tag">Нова Пошта • Центр керування</span>
              </div>
            </div>
            <button class="btn-close-minimal" @click="$emit('close')">
              <i class="bi bi-x-lg"></i>
            </button>
          </header>

          <div class="ultimate-body custom-scrollbar">
            
            <section class="config-section">
              <label class="section-label">Метод отримання</label>
              <div class="method-grid">
                <div class="method-card" :class="{ active: test.type === 'wh' }" @click="test.type = 'wh'">
                  <div class="method-icon"><i class="bi bi-shop"></i></div>
                  <div class="method-info">
                    <span class="m-title">Відділення</span>
                    <span class="m-desc">Або поштомат</span>
                  </div>
                  <div class="method-check"><i class="bi bi-check-circle-fill"></i></div>
                </div>

                <div class="method-card" :class="{ active: test.type === 'cur' }" @click="test.type = 'cur'">
                  <div class="method-icon"><i class="bi bi-house-heart"></i></div>
                  <div class="method-info">
                    <span class="m-title">Кур'єр</span>
                    <span class="m-desc">На вашу адресу</span>
                  </div>
                  <div class="method-check"><i class="bi bi-check-circle-fill"></i></div>
                </div>
              </div>
            </section>

            <section class="config-section">
              <label class="section-label">Місто доставки</label>
              <div class="elite-input-wrap">
                <i class="bi bi-geo-alt input-icon"></i>
                <select class="elite-select" v-model="test.city">
                  <option value="Київ">Київ, Київська обл.</option>
                  <option value="Львів">Львів, Львівська обл.</option>
                  <option value="Одеса">Одеса, Одеська обл.</option>
                  <option value="Дніпро">Дніпро, Дніпропетровська обл.</option>
                </select>
              </div>
            </section>

            <transition name="fade-slide" mode="out-in">
              <section v-if="test.type === 'wh'" :key="'wh'" class="config-section">
                <label class="section-label">Виберіть пункт видачі</label>
                <div class="elite-input-wrap">
                  <i class="bi bi-signpost-2 input-icon"></i>
                  <select class="elite-select" v-model="test.warehouse">
                    <option value="1">Відділення №1: вул. Пирогова, 13</option>
                    <option value="2">Поштомат №5522: пр. Перемоги, 4</option>
                    <option value="3">Відділення №15: вул. Мазепи, 2</option>
                  </select>
                </div>
              </section>

              <section v-else :key="'cur'" class="config-section">
                <label class="section-label">Адреса доставки</label>
                <div class="street-input mb-2">
                  <input type="text" class="elite-input-minimal" placeholder="Вулиця (напр. Хрещатик)">
                </div>
                <div class="row g-2">
                  <div class="col-6"><input type="text" class="elite-input-minimal" placeholder="Будинок"></div>
                  <div class="col-6"><input type="text" class="elite-input-minimal" placeholder="Кв. / Офіс"></div>
                </div>
              </section>
            </transition>

            <section class="config-section no-margin">
              <label class="section-label">Хто оплачує послуги?</label>
              <div class="payer-toggle-modern">
                <button :class="{ active: test.payer === 'r' }" @click="test.payer = 'r'">
                  <i class="bi bi-person-fill"></i> Отримувач
                </button>
                <button :class="{ active: test.payer === 's' }" @click="test.payer = 's'">
                  <i class="bi bi-send-check-fill"></i> Відправник
                </button>
              </div>
            </section>

          </div>

          <footer class="ultimate-footer">
            <button class="btn-cancel-elite" @click="$emit('close')">Скасувати</button>
            <button class="btn-confirm-elite" @click="$emit('save', test)">
              <span>ПІДТВЕРДИТИ ПАРАМЕТРИ</span>
              <div class="shimmer-gold"></div>
            </button>
          </footer>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { reactive } from 'vue';

defineProps({ open: Boolean });
defineEmits(['close', 'save']);

// Тестові дані для демонстрації шаблону
const test = reactive({
  type: 'wh',
  city: 'Київ',
  warehouse: '1',
  payer: 'r'
});
</script>

<style scoped>
/* ULTIMATE UI DESIGN SYSTEM */
.ultimate-overlay { 
  position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); 
  z-index: 100010; backdrop-filter: blur(15px); 
  display: flex; align-items: center; justify-content: center; 
}
.ultimate-window { 
  background: #ffffff; width: 560px; max-width: 95%; max-height: 85vh; 
  border-radius: 40px; display: flex; flex-direction: column; 
  overflow: hidden; box-shadow: 0 50px 120px rgba(0,0,0,0.5); 
}

/* HEADER */
.ultimate-header { padding: 25px 35px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.brand-group { display: flex; align-items: center; gap: 15px; }
.brand-logo-mini { width: 42px; height: 42px; background: #f5f3ff; color: #a78bfb; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.brand-text h4 { font-size: 20px; font-weight: 900; color: #0f172a; margin: 0; letter-spacing: -0.02em; }
.np-red-tag { font-size: 10px; color: #dc2626; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; display: block; margin-top: 2px; }
.btn-close-minimal { border: none; background: #f8fafc; width: 36px; height: 36px; border-radius: 50%; color: #94a3b8; transition: 0.3s; }
.btn-close-minimal:hover { background: #fee2e2; color: #ef4444; transform: rotate(90deg); }

/* BODY */
.ultimate-body { flex: 1; padding: 35px; overflow-y: auto; background: #ffffff; }
.config-section { margin-bottom: 30px; }
.no-margin { margin-bottom: 0; }
.section-label { font-size: 11px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.12em; margin-bottom: 15px; display: block; }

/* METHOD CARDS */
.method-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.method-card { 
  background: #fff; border: 2px solid #f1f5f9; border-radius: 24px; padding: 20px; 
  cursor: pointer; position: relative; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
}
.method-icon { font-size: 24px; color: #cbd5e1; margin-bottom: 10px; transition: 0.3s; }
.m-title { display: block; font-size: 15px; font-weight: 800; color: #1e293b; }
.m-desc { font-size: 12px; color: #94a3b8; font-weight: 600; }
.method-check { position: absolute; top: 15px; right: 15px; color: #a78bfb; opacity: 0; transform: scale(0.5); transition: 0.3s; font-size: 20px; }

.method-card:hover { border-color: #e2e8f0; transform: translateY(-3px); }
.method-card.active { border-color: #a78bfb; background: #fcfaff; box-shadow: 0 15px 30px rgba(167, 139, 251, 0.15); }
.method-card.active .method-icon { color: #a78bfb; }
.method-card.active .method-check { opacity: 1; transform: scale(1); }

/* INPUTS */
.elite-input-wrap { position: relative; display: flex; align-items: center; }
.input-icon { position: absolute; left: 20px; color: #a78bfb; font-size: 20px; pointer-events: none; }
.elite-select, .elite-input-minimal { 
  width: 100%; padding: 16px 20px 16px 55px; border-radius: 20px; 
  border: 2px solid #f1f5f9; font-size: 15px; font-weight: 700; 
  color: #1e293b; background: #f8fafc; transition: 0.3s; outline: none; appearance: none;
}
.elite-input-minimal { padding-left: 20px; }
.elite-select:focus, .elite-input-minimal:focus { border-color: #a78bfb; background: #fff; box-shadow: 0 10px 20px rgba(167, 139, 251, 0.05); }

/* PAYER TOGGLE */
.payer-toggle-modern { display: flex; background: #f1f5f9; padding: 6px; border-radius: 20px; gap: 8px; }
.payer-toggle-modern button { 
  flex: 1; border: none; background: transparent; padding: 14px; 
  border-radius: 15px; font-size: 14px; font-weight: 800; color: #64748b; 
  cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; 
}
.payer-toggle-modern button.active { background: #ffffff; color: #a78bfb; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }

/* FOOTER */
.ultimate-footer { padding: 25px 35px; border-top: 1px solid #f1f5f9; display: flex; gap: 20px; background: #fff; }
.btn-cancel-elite { flex: 1; background: #f8fafc; border: none; height: 60px; border-radius: 20px; color: #94a3b8; font-weight: 800; cursor: pointer; transition: 0.3s; }
.btn-confirm-elite { 
  flex: 2; background: #0f172a; border: none; height: 60px; border-radius: 20px; 
  color: #fff; font-weight: 900; letter-spacing: 0.05em; cursor: pointer; 
  position: relative; overflow: hidden; transition: 0.4s;
}
.btn-confirm-elite:hover { background: #1e293b; transform: translateY(-3px); box-shadow: 0 20px 40px rgba(15, 23, 42, 0.3); }

.shimmer-gold { position: absolute; top: 0; left: -100%; width: 50%; height: 100%; background: linear-gradient(90deg, transparent, rgba(167, 139, 251, 0.3), transparent); animation: shimmer-elite 3s infinite; }
@keyframes shimmer-elite { 100% { left: 200%; } }

/* ADAPTATION */
@media (max-width: 768px) {
  .ultimate-window { width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
  .ultimate-overlay { align-items: flex-start; }
}

/* ANIMATIONS */
.modal-spring-enter-active { animation: spring-in 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.modal-spring-leave-active { transition: 0.3s opacity ease; opacity: 0; }
@keyframes spring-in { from { opacity: 0; transform: scale(0.8) translateY(50px); } to { opacity: 1; transform: scale(1) translateY(0); } }

.fade-slide-enter-active { transition: 0.3s ease; opacity: 0; transform: translateY(10px); }
.fade-slide-enter-to { opacity: 1; transform: translateY(0); }

.custom-scrollbar::-webkit-scrollbar { width: 5px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>