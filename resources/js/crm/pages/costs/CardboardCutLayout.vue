<template>
  <section class="sheet-cut panel-card" data-testid="cardboard-layout">
    <header><div><h3>Розкрій одного листа</h3><p>Заготовка {{ number(blankLength) }} × {{ number(blankWidth) }} см</p><p class="insole-dimensions">Умовна устілка {{ insoleLength }} × {{ insoleWidth }} см · п’ятка вужча</p></div><i class="bi bi-grid-3x3" aria-hidden="true"></i></header>
    <template v-if="layout?.pairs">
      <figure><figcaption>{{ number(length) }} × {{ number(width) }} см</figcaption>
        <svg :viewBox="`-1 -1 ${length + 2} ${width + 2}`" role="img" :aria-label="`Картон: ${layout.primary_pieces} заготовок в основних рядах, ще ${layout.rotated_pieces} поперек. Усього ${layout.total_pieces} заготовок, ${layout.pairs} повних пар. Контури устілок умовні, пунктир — межі заготовок.${truncated ? ' Показано фрагмент.' : ''}`">
          <rect x="0" y="0" :width="length" :height="width" fill="#edf0f5" stroke="#cbd3df" stroke-width="1" vector-effect="non-scaling-stroke" />
          <g v-for="(polygon, index) in polygons" :key="index" :data-rotated="polygon.rotated">
            <polygon :points="polygon.points.map(point => point.join(',')).join(' ')" :fill="polygon.rotated ? '#fff8ec' : '#eef8f5'" stroke="#a5b7b3" stroke-width="0.6" stroke-dasharray="2 2" vector-effect="non-scaling-stroke" />
            <path data-testid="cardboard-insole" :d="insolePath" :transform="insoles[index].transform" :data-mirrored="insoles[index].mirrored" :fill="polygon.rotated ? '#f5cd8c' : '#bce5dc'" :stroke="polygon.rotated ? '#b67a23' : '#329a86'" stroke-width="0.7" vector-effect="non-scaling-stroke" />
          </g>
        </svg>
        <div class="legend"><span><i class="main-color"></i>Основні ряди</span><span><i class="rotated-color"></i>Поперек у залишках</span><span><i class="offcut-color"></i>Залишки листа</span><span>Пунктир — межі заготовок</span></div>
        <small v-if="truncated">Фрагмент розкладки; кількість розрахована для всього листа.</small>
        <small v-if="scaledDown">Контур зменшено, щоб він помістився у задану заготовку.</small>
      </figure>
      <dl>
        <div><dt>Основні ряди</dt><dd>{{ number(layout.pieces_per_row) }} × {{ number(layout.rows_per_cut) }} = {{ number(layout.primary_pieces) }}</dd></div>
        <div><dt>Додатково поперек</dt><dd data-testid="cardboard-rotated">+{{ number(layout.rotated_pieces) }}</dd></div>
        <div><dt>Усього заготовок</dt><dd data-testid="cardboard-pieces">{{ number(layout.total_pieces) }}</dd></div>
        <div><dt>Повних пар із листа</dt><dd data-testid="cardboard-pairs">{{ number(layout.pairs) }}</dd></div>
        <div><dt>Обрізки листа</dt><dd>{{ number(layout.offcut_percent) }}%</dd></div>
      </dl>
      <p v-if="layout.unpaired_pieces" class="hint">Одна заготовка залишається без пари. Для ціни рахуємо тільки повні пари з одного листа.</p>
      <p class="hint">Форма устілки — лише ілюстрація, не точне лекало. Вихід і ціна рахуються за прямокутними заготовками; порожнє місце навколо контуру не додає нових деталей. Додаткові проміжки між заготовками не враховані.</p>
    </template>
    <p v-else class="hint">Із цього листа не виходить двох цілих заготовок. Перевірте розміри.</p>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { laminatePolygons } from '@/crm/utils/laminateRows';
import { insoleInRectangle, insoleLength, insoleWidth, insolePath } from '@/crm/utils/cardboardInsole';
const props = defineProps({ length: Number, width: Number, blankLength: Number, blankWidth: Number, layout: Object });
const number = value => Number(value).toLocaleString('uk-UA', { maximumFractionDigits: 4 });
const polygons = computed(() => laminatePolygons(props.layout, props.blankLength, props.blankLength, props.blankWidth));
const insoles = computed(() => polygons.value.map(insoleInRectangle));
const scaledDown = computed(() => insoles.value.some(insole => insole.scale < 1 - 1e-8));
const truncated = computed(() => props.layout?.zones.some(zone => zone.rows > 24 || zone.pieces_per_row > 24));
</script>

<style scoped>
.sheet-cut{padding:22px}.sheet-cut header{display:flex;justify-content:space-between;gap:12px;align-items:center}.sheet-cut h3{font-size:15px;font-weight:750;margin:0 0 6px}.sheet-cut header p{margin:0;font-size:12px;color:#6b7c93}.sheet-cut header>i{color:#329a86;background:#e6f5f0;padding:9px;border-radius:10px}.sheet-cut figure{margin:20px 0}.sheet-cut figcaption{text-align:center;font-size:12px;color:#6b7c93;margin-bottom:10px}.sheet-cut svg{display:block;width:100%;height:240px}.legend{display:flex;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:12px;font-size:11px;color:#6b7c93}.legend span{display:flex;align-items:center;gap:5px}.legend i{width:10px;height:10px;border-radius:2px;border:1px solid #cbd3df}.main-color{background:#bce5dc}.rotated-color{background:#f5cd8c}.offcut-color{background:#edf0f5}.sheet-cut small{display:block;text-align:center;font-size:11px;color:#6b7c93;margin-top:10px}.sheet-cut dl{margin:0}.sheet-cut dl>div{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #edf0f5;font-size:12px}.sheet-cut dt{color:#6b7c93;font-weight:400}.sheet-cut dd{margin:0;font-weight:700;text-align:right}.hint{font-size:11px;color:#6b7c93;line-height:1.8;margin:12px 0 0}@media(max-width:600px){.sheet-cut{padding:18px}.sheet-cut svg{height:190px}}
</style>
<style scoped>
.sheet-cut header .insole-dimensions{margin-top:5px;color:#329a86}
</style>
