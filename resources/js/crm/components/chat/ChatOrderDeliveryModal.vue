<template>
  <teleport to="body">
    <transition name="ultra-modal">
      <div v-if="open" class="god-mode-overlay" @click.self="$emit('close')">
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
                  <span class="np-text-bold">Нова Пошта</span>
                </div>
              </div>
            </div>
            <button class="btn-close-neo" @click="$emit('close')">
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
                  <option value="Київ">Київ, Київська область</option>
                  <option value="Одеса">Одеса, Одеська область</option>
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
                    <option value="1">Відділення №1: вул. Пирогова, 13</option>
                    <option value="2">Поштомат №5522: пр. Перемоги, 4</option>
                    <option value="3">Відділення №15: вул. Мазепи, 2</option>
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
            <button class="btn-cancel-elite" @click="$emit('close')">Скасувати</button>
            <button class="btn-confirm-ultimate" @click="$emit('save', test)">
              <span class="btn-text">ЗБЕРЕГТИ КОНФІГУРАЦІЮ</span>
              <div class="glow-effect"></div>
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

const test = reactive({
  type: 'wh',
  city: 'Київ',
  warehouse: '1',
  payer: 'r'
});
</script>

<style scoped>
/* ГЛИБОКИЙ ТА ЧИСТИЙ ОВЕРЛЕЙ */
.god-mode-overlay { 
  position: fixed; inset: 0; background: rgba(5, 8, 15, 0.65); 
  z-index: 100010; backdrop-filter: blur(25px); 
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.god-mode-window { 
  background: #ffffff; width: 580px; max-width: 100%; max-height: 90vh; 
  border-radius: 44px; display: flex; flex-direction: column; 
  overflow: hidden; box-shadow: 0 60px 150px rgba(0,0,0,0.6); 
  border: 1px solid rgba(255, 255, 255, 0.2);
}

/* ШАПКА З ГРАДІЄНТНИМ АКЦЕНТОМ */
.god-header { padding: 30px 40px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #fff; }
.header-content { display: flex; align-items: center; gap: 18px; }
.brand-avatar-glow { width: 48px; height: 48px; background: linear-gradient(135deg, #a78bfb, #7c3aed); color: #fff; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 10px 20px rgba(167, 139, 251, 0.3); }

.header-titles h4 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; letter-spacing: -0.03em; }
.np-badge { display: flex; align-items: center; gap: 6px; margin-top: 4px; }
.red-dot { width: 8px; height: 8px; background: #dc2626; border-radius: 50%; box-shadow: 0 0 8px #ef4444; animation: blink 2s infinite; }
.np-text-bold { font-size: 11px; color: #dc2626; font-weight: 900; text-transform: uppercase; letter-spacing: 0.12em; }

@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

.btn-close-neo { border: none; background: #f8fafc; width: 40px; height: 40px; border-radius: 50%; color: #94a3b8; font-size: 20px; cursor: pointer; transition: 0.3s; }
.btn-close-neo:hover { background: #fee2e2; color: #ef4444; transform: rotate(90deg); }

/* BODY */
.god-body { flex: 1; padding: 35px 40px; overflow-y: auto; background: #ffffff; }
.elite-section { margin-bottom: 35px; }
.elite-label { font-size: 11px; font-weight: 900; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.15em; margin-bottom: 18px; display: block; }

/* METHOD TILES (ПРЕМІАЛЬНІ КАРТКИ) */
.method-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.method-tile { 
  background: #fff; border: 2.5px solid #f1f5f9; border-radius: 28px; padding: 25px; 
  cursor: pointer; position: relative; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.tile-icon { font-size: 32px; color: #cbd5e1; transition: 0.3s; }
.tile-label { font-size: 16px; font-weight: 800; color: #1e293b; }
.tile-status { position: absolute; top: 15px; right: 15px; color: #a78bfb; opacity: 0; transform: scale(0.4); transition: 0.3s; font-size: 22px; }

.method-tile:hover { border-color: #e2e8f0; transform: translateY(-5px); }
.method-tile.selected { border-color: #a78bfb; background: #fcfaff; box-shadow: 0 20px 40px rgba(167, 139, 251, 0.12); }
.method-tile.selected .tile-icon { color: #a78bfb; transform: scale(1.1); }
.method-tile.selected .tile-status { opacity: 1; transform: scale(1); }

/* INPUT SYSTEM */
.neo-input-wrap { position: relative; display: flex; align-items: center; }
.pin-icon { position: absolute; left: 22px; color: #a78bfb; font-size: 22px; z-index: 2; }
.arrow-icon { position: absolute; right: 22px; color: #94a3b8; font-size: 18px; pointer-events: none; }

.neo-select, .neo-input { 
  width: 100%; padding: 18px 25px 18px 60px; border-radius: 24px; 
  border: 2px solid #f1f5f9; font-size: 16px; font-weight: 700; 
  color: #1e293b; background: #f8fafc; transition: 0.3s; outline: none; appearance: none;
}
.neo-input { padding-left: 25px; }
.neo-select:focus, .neo-input:focus { border-color: #a78bfb; background: #fff; box-shadow: 0 15px 30px rgba(167, 139, 251, 0.08); }

/* SEGMENTED CONTROL */
.segmented-control { 
  position: relative; display: flex; background: #f1f5f9; padding: 4px; 
  border-radius: 20px; height: 64px; align-items: center; 
}
.segment { 
  flex: 1; position: relative; z-index: 2; text-align: center; 
  font-size: 15px; font-weight: 800; color: #64748b; cursor: pointer; 
  transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px;
}
.segment.active { color: #0f172a; }
.active-bg { 
  position: absolute; width: calc(50% - 8px); height: calc(100% - 8px); 
  background: #fff; border-radius: 16px; transition: 0.4s cubic-bezier(0.19, 1, 0.22, 1); 
  box-shadow: 0 10px 20px rgba(0,0,0,0.06); z-index: 1;
}

/* FOOTER */
.god-footer { padding: 30px 40px; border-top: 1px solid #f1f5f9; display: flex; gap: 20px; background: #fff; }
.btn-cancel-elite { flex: 1; background: #f8fafc; border: none; height: 64px; border-radius: 24px; color: #94a3b8; font-weight: 800; cursor: pointer; transition: 0.3s; }
.btn-confirm-ultimate { 
  flex: 2; background: #0f172a; border: none; height: 64px; border-radius: 24px; 
  color: #fff; font-weight: 900; letter-spacing: 0.08em; cursor: pointer; 
  position: relative; overflow: hidden; transition: 0.4s;
}
.btn-confirm-ultimate:hover { transform: translateY(-5px); box-shadow: 0 25px 50px rgba(15, 23, 42, 0.35); }

.glow-effect { position: absolute; inset: 0; background: radial-gradient(circle at center, rgba(167, 139, 251, 0.4) 0%, transparent 70%); opacity: 0; transition: 0.5s; }
.btn-confirm-ultimate:hover .glow-effect { opacity: 1; transform: scale(1.5); }

/* ADAPTATION */
@media (max-width: 768px) {
  .god-mode-window { width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
  .god-mode-overlay { padding: 0; align-items: flex-start; }
}

/* ANIMATIONS */
.ultra-modal-enter-active { animation: god-in 0.8s cubic-bezier(0.19, 1, 0.22, 1); }
@keyframes god-in { from { opacity: 0; transform: scale(0.7) translateY(100px); } to { opacity: 1; transform: scale(1) translateY(0); } }

.slide-fade-enter-active { transition: all 0.4s ease-out; }
.slide-fade-enter-from { opacity: 0; transform: translateX(20px); }

.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>