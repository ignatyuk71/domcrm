<template>
  <teleport to="body">
    <transition name="fade">
      <div v-if="open" class="delivery-overlay">
        <div class="delivery-modal">
          <div class="modal-header">
            <div class="header-title">
              <span class="np-badge">
                <i class="bi bi-truck"></i>
                НОВА ПОШТА
              </span>
              <h3>Дані доставки</h3>
            </div>
            <button class="btn-close" type="button" @click="emit('close')">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <div class="modal-body">
            <section class="card-np">
              <div class="card-title">
                <i class="bi bi-truck"></i>
                <span>Нова Пошта</span>
              </div>
              <p>Швидка доставка по всій Україні</p>
            </section>

            <section class="section">
              <div class="section-title">Отримувач</div>
              <div class="grid-two">
                <div class="field">
                  <label>Прізвище</label>
                  <input class="elite-input" v-model="draft.last_name" placeholder="Іванов" />
                </div>
                <div class="field">
                  <label>Ім'я</label>
                  <input class="elite-input" v-model="draft.first_name" placeholder="Іван" />
                </div>
              </div>
              <div class="field">
                <label>По батькові</label>
                <input class="elite-input" v-model="draft.middle_name" placeholder="Іванович" />
              </div>
              <div class="field">
                <label>Телефон</label>
                <input class="elite-input" v-model="draft.phone" placeholder="380XXXXXXXXX" />
              </div>
            </section>

            <section class="section">
              <div class="section-title">Тип доставки</div>
              <div class="toggle-group">
                <button
                  type="button"
                  class="toggle-btn"
                  :class="{ active: draft.delivery_type === 'warehouse' }"
                  @click="draft.delivery_type = 'warehouse'"
                >
                  Відділення
                </button>
                <button
                  type="button"
                  class="toggle-btn"
                  :class="{ active: draft.delivery_type === 'courier' }"
                  @click="draft.delivery_type = 'courier'"
                >
                  Кур'єр
                </button>
              </div>
            </section>

            <section class="section">
              <div class="section-title">Місто</div>
              <div class="field">
                <input class="elite-input" v-model="draft.city" placeholder="Почніть вводити..." />
                <div class="dropdown">
                  <button
                    v-for="city in cityOptions"
                    :key="city.ref"
                    type="button"
                    @click="draft.city = city.name"
                  >
                    {{ city.name }} — {{ city.area }}
                  </button>
                </div>
              </div>
            </section>

            <section class="section" v-if="draft.delivery_type !== 'courier'">
              <div class="section-title">Відділення</div>
              <div class="field">
                <input class="elite-input" v-model="draft.warehouse" placeholder="Введіть номер..." />
                <div class="dropdown">
                  <button
                    v-for="wh in warehouseOptions"
                    :key="wh.ref"
                    type="button"
                    @click="draft.warehouse = wh.name"
                  >
                    {{ wh.name }}
                  </button>
                </div>
              </div>
            </section>

            <section class="section" v-else>
              <div class="section-title">Вулиця</div>
              <div class="field">
                <input class="elite-input" v-model="draft.street" placeholder="Почніть вводити..." />
                <div class="dropdown">
                  <button
                    v-for="street in streetOptions"
                    :key="street.ref"
                    type="button"
                    @click="draft.street = street.name"
                  >
                    {{ street.name }}
                  </button>
                </div>
              </div>
              <div class="grid-two">
                <div class="field">
                  <label>Будинок</label>
                  <input class="elite-input" v-model="draft.building" placeholder="10/А" />
                </div>
                <div class="field">
                  <label>Кв./Офіс</label>
                  <input class="elite-input" v-model="draft.apartment" placeholder="42" />
                </div>
              </div>
            </section>

            <section class="section">
              <div class="section-title">Платник доставки</div>
              <div class="payer-toggle">
                <button
                  type="button"
                  class="payer-btn"
                  :class="{ active: draft.payer === 'recipient' }"
                  @click="draft.payer = 'recipient'"
                >
                  Отримувач
                </button>
                <button
                  type="button"
                  class="payer-btn"
                  :class="{ active: draft.payer === 'sender' }"
                  @click="draft.payer = 'sender'"
                >
                  Відправник
                </button>
              </div>
            </section>
          </div>

          <div class="modal-footer">
            <button class="btn-cancel" type="button" @click="emit('close')">Скасувати</button>
            <button class="btn-save" type="button" @click="emit('save', draft)">Зберегти</button>
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script setup>
import { reactive, watch } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: true },
  initialData: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'save']);

const draft = reactive({
  first_name: 'Іван',
  last_name: 'Іванов',
  middle_name: 'Іванович',
  phone: '380631234567',
  delivery_type: 'warehouse',
  city: 'Київ',
  warehouse: 'Відділення №12 (Хрещатик, 10)',
  street: '',
  building: '10/А',
  apartment: '42',
  payer: 'recipient',
});

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    Object.assign(draft, {
      first_name: 'Іван',
      last_name: 'Іванов',
      middle_name: 'Іванович',
      phone: '380631234567',
      delivery_type: 'warehouse',
      city: 'Київ',
      warehouse: 'Відділення №12 (Хрещатик, 10)',
      street: '',
      building: '10/А',
      apartment: '42',
      payer: 'recipient',
      ...props.initialData,
    });
  },
  { immediate: true }
);

const cityOptions = [
  { ref: 'c1', name: 'Київ', area: 'Київська' },
  { ref: 'c2', name: 'Львів', area: 'Львівська' },
  { ref: 'c3', name: 'Одеса', area: 'Одеська' },
];

const warehouseOptions = [
  { ref: 'w1', name: 'Відділення №12 (Хрещатик, 10)' },
  { ref: 'w2', name: 'Поштомат №3452 (ТРЦ Ocean Plaza)' },
  { ref: 'w3', name: 'Відділення №5 (Оболонь)' },
];

const streetOptions = [
  { ref: 's1', name: 'Хрещатик' },
  { ref: 's2', name: 'Січових Стрільців' },
  { ref: 's3', name: 'Володимирська' },
];
</script>

<style scoped>
.delivery-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  z-index: 120000;
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(8px);
}

.delivery-modal {
  width: 640px;
  max-width: 96vw;
  max-height: 92vh;
  background: #ffffff;
  border-radius: 32px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
}

.modal-header {
  padding: 20px 24px;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-title h3 {
  margin: 6px 0 0;
  font-size: 18px;
  font-weight: 800;
  color: #0f172a;
}

.np-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 900;
  color: #ef4444;
  font-size: 12px;
  letter-spacing: 0.06em;
}

.btn-close {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: none;
  background: #f8fafc;
  color: #64748b;
  cursor: pointer;
}

.modal-body {
  padding: 20px 24px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.card-np {
  background: #f5f3ff;
  border-radius: 20px;
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.card-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 900;
  color: #4c1d95;
}

.section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.section-title {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #94a3b8;
  font-weight: 700;
}

.grid-two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.field label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
  margin-bottom: 6px;
  display: block;
}

.elite-input {
  width: 100%;
  height: 48px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 0 14px;
  font-size: 14px;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.elite-input:focus {
  border-color: #8b5cf6;
  box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
  outline: none;
}

.toggle-group {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.toggle-btn {
  height: 48px;
  border-radius: 14px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  font-weight: 700;
  color: #475569;
  cursor: pointer;
}

.toggle-btn.active {
  border-color: #8b5cf6;
  background: #ede9fe;
  color: #4c1d95;
}

.dropdown {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.dropdown button {
  text-align: left;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 10px 12px;
  background: #ffffff;
  cursor: pointer;
}

.payer-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.payer-btn {
  height: 48px;
  border-radius: 14px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  font-weight: 700;
  color: #475569;
  cursor: pointer;
}

.payer-btn.active {
  border-color: #8b5cf6;
  background: #ede9fe;
  color: #4c1d95;
}

.modal-footer {
  padding: 18px 24px;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.btn-cancel {
  height: 48px;
  padding: 0 18px;
  border-radius: 12px;
  border: none;
  background: #f8fafc;
  color: #64748b;
  cursor: pointer;
}

.btn-save {
  height: 48px;
  padding: 0 24px;
  border-radius: 14px;
  border: none;
  background: #8b5cf6;
  color: #ffffff;
  font-weight: 800;
  cursor: pointer;
  position: relative;
  overflow: hidden;
}

.btn-save::after {
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

.btn-save:hover::after { left: 160%; }

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 768px) {
  .delivery-overlay {
    align-items: stretch;
    justify-content: stretch;
  }

  .delivery-modal {
    width: 100%;
    height: 100%;
    max-width: 100%;
    max-height: 100%;
    border-radius: 0;
  }

  .elite-input,
  .toggle-btn,
  .payer-btn,
  .btn-cancel,
  .btn-save {
    height: 48px;
    font-size: 16px;
  }
}
</style>
