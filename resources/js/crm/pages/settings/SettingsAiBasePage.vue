<template>
  <div class="ai-base-page">
    <transition name="toast">
      <div v-if="toast.show" class="toast-notification" :class="toast.type">
        <i class="bi" :class="toast.type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <div class="toast-copy">
          <strong>{{ toast.type === 'success' ? 'Готово' : 'Помилка' }}</strong>
          <span>{{ toast.message }}</span>
        </div>
      </div>
    </transition>

    <div class="layout-shell">
      <header class="hero">
        <div class="hero-main">
          <p class="hero-kicker">AI база керування</p>
          <h1>База знань та шаблони AI</h1>
          <p class="hero-subtitle">
            Центр керування поведінкою агента: етапи діалогу, база знань та мапінг моделі клієнта на товари CRM.
          </p>

          <div class="hero-actions">
            <a href="/settings/ai" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i>
              Назад до параметрів
            </a>
            <button type="button" class="btn btn-sm btn-outline-dark" :disabled="loading" @click="loadData">
              <i class="bi bi-arrow-clockwise me-1"></i>
              Оновити
            </button>
          </div>
        </div>

        <aside class="hero-side">
          <label class="agent-picker">
            <span>Активний агент</span>
            <select v-model="selectedAgentCode" :disabled="loading" @change="loadData">
              <option v-for="agent in agents" :key="agent.code" :value="agent.code">
                {{ agent.name }} ({{ agent.code }})
              </option>
            </select>
          </label>

          <div class="stats-grid">
            <article class="stat-card">
              <span>Елементів бази знань</span>
              <strong>{{ knowledgeItems.length }}</strong>
            </article>
            <article class="stat-card">
              <span>Мапінгів модель -> товар</span>
              <strong>{{ modelMaps.length }}</strong>
            </article>
          </div>

          <nav class="quick-nav" aria-label="Швидка навігація AI Base">
            <a href="#stage-templates" class="quick-link">Етапи</a>
            <a href="#knowledge-base" class="quick-link">База знань</a>
            <a href="#model-map" class="quick-link">Мапінг</a>
          </nav>
        </aside>
      </header>

      <section id="stage-templates" class="panel">
        <header class="panel-head">
          <div>
            <h2>Шаблони етапів діалогу</h2>
            <p>Кожен етап керує тоном відповіді й правилом переходу до наступного кроку.</p>
          </div>
          <span class="panel-chip">{{ stageDefs.length }} етапи</span>
        </header>

        <div class="stage-grid">
          <article
            v-for="stage in stageDefs"
            :key="stage.code"
            class="stage-card"
            :class="`stage-${stage.code}`"
          >
            <header class="stage-card-head">
              <div>
                <div class="stage-meta-row">
                  <span class="stage-order">{{ stage.short }}</span>
                  <span class="stage-kind">{{ stage.badge }}</span>
                </div>
                <h3>{{ stage.title }}</h3>
                <p>{{ stage.description }}</p>
              </div>
              <div class="stage-head-actions">
                <span class="version-badge">v{{ promptVersion(stage.code) }}</span>
                <button
                  type="button"
                  class="stage-toggle"
                  :aria-expanded="isStageExpanded(stage.code)"
                  @click="toggleStageExpanded(stage.code)"
                >
                  <i class="bi" :class="isStageExpanded(stage.code) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                  {{ isStageExpanded(stage.code) ? 'Згорнути' : 'Розгорнути' }}
                </button>
              </div>
            </header>

            <transition name="stage-collapse">
              <div v-show="isStageExpanded(stage.code)">
                <div class="stage-form-grid">
                  <label class="field">
                    <span>System prompt</span>
                    <textarea
                      v-model="promptDrafts[stage.code].system_prompt"
                      rows="8"
                      placeholder="Основна інструкція для етапу"
                    ></textarea>
                  </label>

                  <label class="field">
                    <div class="field-headline">
                      <span>policy_json</span>
                      <small>структуровані правила</small>
                    </div>
                    <textarea
                      v-model="promptDrafts[stage.code].policy_json_text"
                      rows="8"
                      class="mono"
                      placeholder="{ }"
                    ></textarea>
                  </label>
                </div>

                <div class="card-actions">
                  <button
                    type="button"
                    class="btn btn-sm btn-dark"
                    :disabled="loading || promptSaving[stage.code]"
                    @click="savePrompt(stage.code)"
                  >
                    <span v-if="promptSaving[stage.code]" class="spinner-border spinner-border-sm me-2"></span>
                    Зберегти етап
                  </button>
                </div>
              </div>
            </transition>
          </article>
        </div>
      </section>

      <section id="knowledge-base" class="panel">
        <header class="panel-head panel-head-split">
          <div>
            <h2>База знань</h2>
            <p>Ручні правила, шаблони і FAQ, які підмішуються у контекст AI.</p>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success" @click="addKnowledgeItem">
            <i class="bi bi-plus-lg me-1"></i>
            Додати текст
          </button>
        </header>

        <div class="hint-box">
          Порада: виносьте сюди стабільні правила бізнесу (доставка, оплата, гарантія, тон), а не тимчасові акції.
        </div>

        <div class="stack-list">
          <article v-for="item in knowledgeItems" :key="item.local_id" class="stack-card">
            <div class="stack-grid">
              <label class="field">
                <span>Ключ</span>
                <input v-model="item.key" type="text" placeholder="delivery_rules_v1">
              </label>

              <label class="field">
                <span>Тип</span>
                <select v-model="item.item_type">
                  <option v-for="option in knowledgeTypeOptions" :key="option" :value="option">
                    {{ option }}
                  </option>
                </select>
              </label>

              <label class="field">
                <span>Порядок</span>
                <input v-model.number="item.sort_order" type="number" min="1" max="9999">
              </label>

              <label class="switch-field">
                <span>Активний</span>
                <input v-model="item.is_active" type="checkbox">
              </label>
            </div>

            <label class="field">
              <span>Назва</span>
              <input v-model="item.title" type="text" placeholder="Правила оформлення">
            </label>

            <label class="field">
              <span>Текст</span>
              <textarea v-model="item.content" rows="5" placeholder="Що саме агент має враховувати..."></textarea>
            </label>

            <div class="card-actions">
              <button type="button" class="btn btn-sm btn-dark" :disabled="item.saving" @click="saveKnowledgeItem(item)">
                <span v-if="item.saving" class="spinner-border spinner-border-sm me-2"></span>
                Зберегти
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                :disabled="item.saving"
                @click="deleteKnowledgeItem(item)"
              >
                Видалити
              </button>
            </div>
          </article>
        </div>
      </section>

      <section id="model-map" class="panel">
        <header class="panel-head panel-head-split">
          <div>
            <h2>Мапінг "модель -> товар"</h2>
            <p>Підкажіть агенту, як розпізнавати формулювання клієнта і привʼязувати їх до потрібних товарів.</p>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success" @click="addModelMap">
            <i class="bi bi-plus-lg me-1"></i>
            Додати мапінг
          </button>
        </header>

        <div class="hint-box">
          Якщо клієнт пише "класик чорні" або "пухнасті 37", цей блок допоможе AI швидко підхопити потрібний товар.
        </div>

        <div class="stack-list">
          <article v-for="map in modelMaps" :key="map.local_id" class="stack-card">
            <div class="stack-grid">
              <label class="field wide">
                <span>Фраза моделі</span>
                <input v-model="map.model_phrase" type="text" placeholder="тапочки класик чорні">
              </label>

              <label class="field">
                <span>Товар</span>
                <select v-model="map.product_id" @change="onMapProductChange(map)">
                  <option :value="null">—</option>
                  <option v-for="product in products" :key="product.id" :value="product.id">
                    #{{ product.id }} — {{ product.title }}
                  </option>
                </select>
              </label>

              <label class="field">
                <span>Варіант</span>
                <select v-model="map.variant_id" :disabled="!map.product_id">
                  <option :value="null">—</option>
                  <option v-for="variant in variantsByProduct(map.product_id)" :key="variant.id" :value="variant.id">
                    #{{ variant.id }} — {{ variant.size || 'без розміру' }} ({{ variant.stock_qty }} шт.)
                  </option>
                </select>
              </label>

              <label class="field">
                <span>Колір</span>
                <select v-model="map.color_id">
                  <option :value="null">—</option>
                  <option v-for="color in colors" :key="color.id" :value="color.id">
                    {{ color.name }}
                  </option>
                </select>
              </label>

              <label class="field">
                <span>Підказка розміру</span>
                <input v-model="map.size_hint" type="text" placeholder="37">
              </label>

              <label class="field">
                <span>Пріоритет</span>
                <input v-model.number="map.priority" type="number" min="1" max="9999">
              </label>

              <label class="switch-field">
                <span>Активний</span>
                <input v-model="map.is_active" type="checkbox">
              </label>
            </div>

            <label class="field">
              <span>Нотатки</span>
              <textarea v-model="map.notes" rows="3" placeholder="Службова примітка для команди"></textarea>
            </label>

            <div class="card-actions">
              <button type="button" class="btn btn-sm btn-dark" :disabled="map.saving" @click="saveModelMap(map)">
                <span v-if="map.saving" class="spinner-border spinner-border-sm me-2"></span>
                Зберегти
              </button>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                :disabled="map.saving"
                @click="deleteModelMap(map)"
              >
                Видалити
              </button>
            </div>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import http from '@/crm/api/http';

const stageDefs = [
  {
    code: 'interest',
    title: 'Зацікавлення',
    short: 'Етап 1',
    badge: 'Консультація',
    description: 'Ціна, фото, наявність, кольори. Без тиску і без збору доставки.',
  },
  {
    code: 'selection',
    title: 'Підбір',
    short: 'Етап 2',
    badge: 'Вибір',
    description: 'Уточнення моделі/кольору/розміру, щоб вивести релевантний варіант.',
  },
  {
    code: 'checkout_ready',
    title: 'Готовність до оформлення',
    short: 'Етап 3',
    badge: 'Підтвердження',
    description: 'Клієнт підтверджує намір купити. Починаємо збір даних доставки.',
  },
  {
    code: 'checkout',
    title: 'Оформлення',
    short: 'Етап 4',
    badge: 'Фінал',
    description: 'Фінальна перевірка, підтвердження замовлення і передача менеджеру.',
  },
];

const knowledgeTypeOptions = ['instruction', 'template', 'faq'];

const loading = ref(false);
const agents = ref([]);
const products = ref([]);
const colors = ref([]);
const knowledgeItems = ref([]);
const modelMaps = ref([]);
const selectedAgentCode = ref('');
const promptSaving = reactive({});
const prompts = reactive({});
const promptDrafts = reactive({});
const variantOptionsByProduct = reactive({});
const stageExpanded = reactive({});

const toast = reactive({
  show: false,
  message: '',
  type: 'success',
});

let toastTimer = null;

function showToast(message, type = 'success') {
  const text = String(message || '').trim();
  if (!text) {
    return;
  }

  toast.message = text;
  toast.type = type === 'error' ? 'error' : 'success';
  toast.show = true;

  if (toastTimer) {
    clearTimeout(toastTimer);
  }

  toastTimer = setTimeout(() => {
    toast.show = false;
  }, 3200);
}

function promptVersion(stageCode) {
  const version = Number(prompts[stageCode]?.version || 0);
  return version > 0 ? version : 1;
}

function isStageExpanded(stageCode) {
  return stageExpanded[stageCode] !== false;
}

function toggleStageExpanded(stageCode) {
  stageExpanded[stageCode] = !isStageExpanded(stageCode);
}

function ensurePromptDraft(stageCode, prompt = {}) {
  if (!promptDrafts[stageCode]) {
    promptDrafts[stageCode] = {
      system_prompt: '',
      policy_json_text: '{}',
    };
  }

  promptDrafts[stageCode].system_prompt = String(prompt.system_prompt || '');
  promptDrafts[stageCode].policy_json_text = JSON.stringify(prompt.policy_json || {}, null, 2);
}

function normalizeKnowledgeRow(item = {}) {
  return {
    id: item.id ?? null,
    local_id: item.id ? `k_${item.id}` : `k_new_${Date.now()}_${Math.random()}`,
    key: String(item.key || ''),
    title: String(item.title || ''),
    item_type: String(item.item_type || 'instruction'),
    content: String(item.content || ''),
    sort_order: Number(item.sort_order || 100),
    is_active: item.is_active !== false,
    saving: false,
  };
}

function normalizeModelMapRow(map = {}) {
  return {
    id: map.id ?? null,
    local_id: map.id ? `m_${map.id}` : `m_new_${Date.now()}_${Math.random()}`,
    model_phrase: String(map.model_phrase || ''),
    product_id: map.product_id ? Number(map.product_id) : null,
    variant_id: map.variant_id ? Number(map.variant_id) : null,
    color_id: map.color_id ? Number(map.color_id) : null,
    size_hint: String(map.size_hint || ''),
    priority: Number(map.priority || 100),
    notes: String(map.notes || ''),
    is_active: map.is_active !== false,
    saving: false,
  };
}

async function ensureVariantsLoaded(productId) {
  const normalizedId = Number(productId || 0);
  if (!normalizedId) {
    return;
  }

  if (Array.isArray(variantOptionsByProduct[normalizedId])) {
    return;
  }

  try {
    const { data } = await http.get(`/settings/ai/base/products/${normalizedId}/variants`);
    variantOptionsByProduct[normalizedId] = Array.isArray(data?.data) ? data.data : [];
  } catch (error) {
    variantOptionsByProduct[normalizedId] = [];
    showToast(error.response?.data?.message || 'Не вдалося завантажити варіанти товару.', 'error');
  }
}

function variantsByProduct(productId) {
  const normalizedId = Number(productId || 0);
  if (!normalizedId) {
    return [];
  }

  return Array.isArray(variantOptionsByProduct[normalizedId])
    ? variantOptionsByProduct[normalizedId]
    : [];
}

async function loadData() {
  loading.value = true;
  try {
    const params = {};
    if (selectedAgentCode.value) {
      params.agent_code = selectedAgentCode.value;
    }

    const { data } = await http.get('/settings/ai/base', { params });

    agents.value = Array.isArray(data?.agents) ? data.agents : [];
    products.value = Array.isArray(data?.products) ? data.products : [];
    colors.value = Array.isArray(data?.colors) ? data.colors : [];
    selectedAgentCode.value = String(data?.selected_agent_code || agents.value[0]?.code || '');

    const serverPrompts = data?.prompts && typeof data.prompts === 'object' ? data.prompts : {};
    for (const stage of stageDefs) {
      prompts[stage.code] = serverPrompts[stage.code] || {
        stage: stage.code,
        version: 1,
        system_prompt: '',
        policy_json: {},
      };
      ensurePromptDraft(stage.code, prompts[stage.code]);
    }

    knowledgeItems.value = Array.isArray(data?.knowledge_items)
      ? data.knowledge_items.map((item) => normalizeKnowledgeRow(item))
      : [];

    modelMaps.value = Array.isArray(data?.model_maps)
      ? data.model_maps.map((map) => normalizeModelMapRow(map))
      : [];

    const productIds = [...new Set(modelMaps.value.map((item) => Number(item.product_id || 0)).filter((id) => id > 0))];
    for (const productId of productIds) {
      // eslint-disable-next-line no-await-in-loop
      await ensureVariantsLoaded(productId);
    }
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося завантажити AI базу.', 'error');
  } finally {
    loading.value = false;
  }
}

async function savePrompt(stageCode) {
  if (!promptDrafts[stageCode]) {
    return;
  }

  let parsedPolicy = {};
  const rawPolicy = String(promptDrafts[stageCode].policy_json_text || '').trim();
  if (rawPolicy !== '') {
    try {
      parsedPolicy = JSON.parse(rawPolicy);
    } catch {
      showToast(`policy_json для етапу "${stageCode}" має бути валідним JSON.`, 'error');
      return;
    }
  }

  promptSaving[stageCode] = true;
  try {
    const payload = {
      agent_code: selectedAgentCode.value,
      system_prompt: promptDrafts[stageCode].system_prompt,
      policy_json: parsedPolicy,
    };

    const { data } = await http.post(`/settings/ai/base/prompts/${stageCode}`, payload);
    prompts[stageCode] = data?.prompt || prompts[stageCode];
    ensurePromptDraft(stageCode, prompts[stageCode]);
    showToast(data?.message || `Етап ${stageCode} збережено.`);
  } catch (error) {
    showToast(error.response?.data?.message || `Не вдалося зберегти етап ${stageCode}.`, 'error');
  } finally {
    promptSaving[stageCode] = false;
  }
}

function addKnowledgeItem() {
  knowledgeItems.value.unshift(normalizeKnowledgeRow());
}

async function saveKnowledgeItem(item) {
  item.saving = true;
  try {
    const payload = {
      key: item.key,
      title: item.title,
      item_type: item.item_type,
      content: item.content,
      sort_order: Number(item.sort_order || 100),
      is_active: !!item.is_active,
    };

    let response;
    if (item.id) {
      response = await http.patch(`/settings/ai/base/knowledge-items/${item.id}`, payload);
    } else {
      response = await http.post('/settings/ai/base/knowledge-items', payload);
    }

    const savedItem = response?.data?.item;
    if (savedItem) {
      Object.assign(item, normalizeKnowledgeRow(savedItem));
    }

    showToast(response?.data?.message || 'Елемент бази знань збережено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося зберегти елемент бази знань.', 'error');
  } finally {
    item.saving = false;
  }
}

async function deleteKnowledgeItem(item) {
  if (!item.id) {
    knowledgeItems.value = knowledgeItems.value.filter((row) => row.local_id !== item.local_id);
    return;
  }

  item.saving = true;
  try {
    const { data } = await http.delete(`/settings/ai/base/knowledge-items/${item.id}`);
    knowledgeItems.value = knowledgeItems.value.filter((row) => row.id !== item.id);
    showToast(data?.message || 'Елемент бази знань видалено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося видалити елемент бази знань.', 'error');
  } finally {
    item.saving = false;
  }
}

function addModelMap() {
  modelMaps.value.unshift(normalizeModelMapRow());
}

async function onMapProductChange(map) {
  map.variant_id = null;
  await ensureVariantsLoaded(map.product_id);
}

async function saveModelMap(map) {
  map.saving = true;
  try {
    if (!map.product_id) {
      showToast('Оберіть товар для мапінгу.', 'error');
      return;
    }

    const payload = {
      model_phrase: map.model_phrase,
      product_id: map.product_id,
      variant_id: map.variant_id || null,
      color_id: map.color_id || null,
      size_hint: map.size_hint || null,
      priority: Number(map.priority || 100),
      notes: map.notes || null,
      is_active: !!map.is_active,
    };

    let response;
    if (map.id) {
      response = await http.patch(`/settings/ai/base/model-maps/${map.id}`, payload);
    } else {
      response = await http.post('/settings/ai/base/model-maps', payload);
    }

    const savedMap = response?.data?.map;
    if (savedMap) {
      Object.assign(map, normalizeModelMapRow(savedMap));
      await ensureVariantsLoaded(map.product_id);
    }

    showToast(response?.data?.message || 'Мапінг збережено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося зберегти мапінг.', 'error');
  } finally {
    map.saving = false;
  }
}

async function deleteModelMap(map) {
  if (!map.id) {
    modelMaps.value = modelMaps.value.filter((row) => row.local_id !== map.local_id);
    return;
  }

  map.saving = true;
  try {
    const { data } = await http.delete(`/settings/ai/base/model-maps/${map.id}`);
    modelMaps.value = modelMaps.value.filter((row) => row.id !== map.id);
    showToast(data?.message || 'Мапінг видалено.');
  } catch (error) {
    showToast(error.response?.data?.message || 'Не вдалося видалити мапінг.', 'error');
  } finally {
    map.saving = false;
  }
}

onMounted(loadData);

onBeforeUnmount(() => {
  if (toastTimer) {
    clearTimeout(toastTimer);
  }
});
</script>

<style scoped>
.ai-base-page {
  width: 100%;
  color: #0f172a;
  position: relative;
  padding-top: 12px;
}

.layout-shell {
  width: min(1360px, calc(100% - 48px));
  margin: 8px auto 0;
  padding-bottom: 28px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.hero {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
  gap: 14px;
  border-radius: 20px;
  border: 1px solid #dce8f5;
  background: linear-gradient(137deg, #eef6ff 0%, #f8fbff 64%, #f3fffb 100%);
  padding: 20px;
  box-shadow: 0 20px 42px -34px rgba(15, 23, 42, 0.4);
}

.hero-kicker {
  margin: 0;
  font-size: 12px;
  line-height: 1;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  font-weight: 800;
  color: #1d4ed8;
}

h1 {
  margin: 10px 0 10px;
  font-size: clamp(28px, 2.3vw, 34px);
  line-height: 1.08;
  font-weight: 800;
  letter-spacing: -0.015em;
  color: #0f172a;
}

.hero-subtitle {
  margin: 0;
  max-width: 720px;
  color: #334155;
}

.hero-actions {
  margin-top: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.hero-side {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.agent-picker {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 11px 12px;
  border-radius: 12px;
  border: 1px solid #d4e0ed;
  background: rgba(255, 255, 255, 0.8);
}

.agent-picker span {
  font-size: 12px;
  color: #475569;
  font-weight: 700;
}

.agent-picker select {
  height: 36px;
  border-radius: 10px;
  border: 1px solid #cfdbe9;
  padding: 0 10px;
  background: #fff;
  color: #0f172a;
}

.stats-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.stat-card {
  border-radius: 12px;
  border: 1px solid #d7e3f3;
  background: rgba(255, 255, 255, 0.92);
  min-height: 74px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.stat-card span {
  font-size: 12px;
  color: #64748b;
}

.stat-card strong {
  font-size: 20px;
  line-height: 1;
  font-weight: 800;
  color: #0f172a;
}

.quick-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.quick-link {
  text-decoration: none;
  border: 1px solid #d6e3f2;
  background: #ffffff;
  color: #1e3a8a;
  font-size: 12px;
  font-weight: 700;
  border-radius: 999px;
  padding: 6px 10px;
  transition: all 0.15s ease;
}

.quick-link:hover {
  border-color: #93c5fd;
  color: #1d4ed8;
  background: #eff6ff;
}

.panel {
  border-radius: 18px;
  border: 1px solid #dde6f1;
  background: #fff;
  box-shadow: 0 15px 34px -32px rgba(15, 23, 42, 0.45);
  padding: 18px;
  scroll-margin-top: 14px;
}

.panel-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  padding-bottom: 10px;
  border-bottom: 1px dashed #dbe4ef;
}

.panel-head h2 {
  margin: 0;
  font-size: 19px;
  font-weight: 800;
  letter-spacing: -0.01em;
  color: #0f172a;
}

.panel-head p {
  margin: 5px 0 0;
  color: #64748b;
  font-size: 13px;
}

.panel-head-split {
  flex-wrap: wrap;
}

.panel-chip {
  align-self: center;
  border-radius: 999px;
  border: 1px solid #cfe0f3;
  background: #f8fbff;
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 700;
  padding: 6px 10px;
}

.hint-box {
  margin-top: 12px;
  margin-bottom: 12px;
  padding: 11px 12px;
  border-radius: 12px;
  border: 1px solid #dbeafe;
  border-left: 4px solid #60a5fa;
  background: #f8fbff;
  color: #334155;
  font-size: 13px;
  line-height: 1.4;
}

.stage-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}

.stage-card {
  border-radius: 14px;
  border: 1px solid #dbe4ef;
  background: #fcfdff;
  box-shadow: 0 10px 24px -30px rgba(15, 23, 42, 0.4);
  padding: 13px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.stage-interest { border-top: 3px solid #4f46e5; }
.stage-selection { border-top: 3px solid #2563eb; }
.stage-checkout_ready { border-top: 3px solid #f59e0b; }
.stage-checkout { border-top: 3px solid #16a34a; }

.stage-card-head {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  align-items: flex-start;
}

.stage-head-actions {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 7px;
}

.stage-meta-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 6px;
}

.stage-order,
.stage-kind {
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 8px;
}

.stage-order {
  border: 1px solid #dbe4ef;
  background: #f8fafc;
  color: #334155;
}

.stage-kind {
  border: 1px solid #bfdbfe;
  background: #ecf5ff;
  color: #1d4ed8;
}

.stage-card-head h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 800;
  color: #0f172a;
}

.stage-card-head p {
  margin: 4px 0 0;
  font-size: 12px;
  color: #64748b;
}

.version-badge {
  padding: 4px 9px;
  border-radius: 999px;
  border: 1px solid #bfdbfe;
  background: #eaf3ff;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 800;
}

.stage-toggle {
  border: 1px solid #dbe4ef;
  border-radius: 999px;
  background: #f8fafc;
  color: #334155;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  padding: 7px 10px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.15s ease;
}

.stage-toggle:hover {
  border-color: #bfdbfe;
  background: #eff6ff;
  color: #1d4ed8;
}

.stage-toggle:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
}

.stage-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.stage-collapse-enter-active,
.stage-collapse-leave-active {
  transition: all 0.18s ease;
  transform-origin: top;
}

.stage-collapse-enter-from,
.stage-collapse-leave-to {
  opacity: 0;
  transform: scaleY(0.98);
}

.stack-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.stack-card {
  border-radius: 14px;
  border: 1px solid #dbe4ef;
  background: #fcfdff;
  box-shadow: 0 10px 24px -30px rgba(15, 23, 42, 0.4);
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.stack-grid {
  display: grid;
  grid-template-columns: 1.4fr 1fr 0.9fr 0.95fr;
  gap: 10px;
  align-items: end;
}

.stack-grid .field.wide {
  grid-column: span 2;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.field span {
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  letter-spacing: 0.01em;
}

.field input,
.field select,
.field textarea {
  border: 1px solid #cfdae8;
  border-radius: 10px;
  padding: 9px 11px;
  background: #fff;
  color: #0f172a;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.field input:focus,
.field select:focus,
.field textarea:focus,
.agent-picker select:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
}

.field textarea {
  resize: vertical;
  min-height: 104px;
}

.field .mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 12px;
  background: #f8fafc;
}

.field-headline {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.field-headline small {
  color: #64748b;
  font-size: 11px;
  font-weight: 500;
}

.switch-field {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  border: 1px solid #dbe4ef;
  border-radius: 10px;
  background: #f8fafc;
  min-height: 40px;
  padding: 9px 10px;
  margin: 0;
}

.switch-field span {
  font-size: 12px;
  color: #334155;
  font-weight: 700;
}

.switch-field input[type="checkbox"] {
  accent-color: #16a34a;
}

.card-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.toast-notification {
  position: fixed;
  top: 16px;
  right: 16px;
  z-index: 1100;
  min-width: 240px;
  max-width: min(380px, calc(100vw - 24px));
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #d1fae5;
  background: rgba(240, 253, 244, 0.98);
  box-shadow: 0 14px 30px -20px rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(6px);
}

.toast-notification.success {
  border-color: #bbf7d0;
  background: rgba(240, 253, 244, 0.98);
}

.toast-notification.error {
  border-color: #fecaca;
  background: rgba(254, 242, 242, 0.98);
}

.toast-notification > i {
  margin-top: 1px;
  font-size: 18px;
  flex-shrink: 0;
}

.toast-notification.success > i {
  color: #16a34a;
}

.toast-notification.error > i {
  color: #dc2626;
}

.toast-copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.toast-copy strong {
  font-size: 12px;
  line-height: 1.2;
  font-weight: 700;
  color: #0f172a;
}

.toast-copy span {
  font-size: 13px;
  line-height: 1.35;
  color: #334155;
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

@media (max-width: 1220px) {
  .layout-shell {
    width: min(1360px, calc(100% - 32px));
  }

  .hero {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 980px) {
  .stage-form-grid {
    grid-template-columns: 1fr;
  }

  .stack-grid {
    grid-template-columns: 1fr 1fr;
  }

  .stack-grid .field.wide {
    grid-column: span 2;
  }
}

@media (max-width: 740px) {
  .layout-shell {
    width: calc(100% - 16px);
    gap: 14px;
  }

  .hero {
    border-radius: 16px;
    padding: 16px;
  }

  h1 {
    font-size: 25px;
  }

  .panel {
    border-radius: 14px;
    padding: 14px;
  }

  .stats-grid {
    grid-template-columns: 1fr;
  }

  .stack-grid {
    grid-template-columns: 1fr;
  }

  .stack-grid .field.wide {
    grid-column: span 1;
  }
}
</style>
