<template>
  <figure class="tape-illustration" data-testid="tape-insole-illustration">
    <figcaption><div><h3>Окантовка устілки</h3><p>Стрічка проходить по всьому краю</p></div><span>1 капець</span></figcaption>
    <svg viewBox="0 0 420 220" role="img" :aria-labelledby="`${titleId} ${descriptionId}`">
      <title :id="titleId">Устілка з оксамитовою окантовкою по краю</title>
      <desc :id="descriptionId">Умовна устілка з вужчою п’яткою. Фіолетова стрічка обрамляє весь контур, світла частина — середина устілки. Схема не визначає довжину стрічки.</desc>
      <defs>
        <linearGradient :id="fillId" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#fffaf0" /><stop offset="100%" stop-color="#f1e7d8" />
        </linearGradient>
      </defs>
      <ellipse cx="209" cy="184" rx="132" ry="8" fill="#eae6f4" opacity=".45" />
      <g transform="translate(58 65) scale(11.4)">
        <path data-testid="tape-insole-body" :d="insolePath" :fill="`url(#${fillId})`" stroke="#7256b3" stroke-width="1.15" stroke-linejoin="round" />
        <path data-testid="tape-insole-edging" :d="insolePath" fill="none" stroke="#b397de" stroke-width=".78" stroke-linejoin="round" />
        <path :d="insolePath" fill="none" stroke="#fff8ff" stroke-width=".07" stroke-dasharray=".2 .2" stroke-linecap="round" opacity=".85" />
      </g>
      <text x="220" y="128" class="insole-label" text-anchor="middle">Устілка</text>
      <path d="M 292 65 L 314 38 H 389" fill="none" stroke="#a28bc7" stroke-width="1.2" />
      <circle cx="292" cy="65" r="3" fill="#7959b8" stroke="#fff" stroke-width="1.2" />
      <text x="389" y="26" class="tape-label" text-anchor="end">Оксамитова стрічка</text>
    </svg>
    <div class="tape-legend"><span><i class="insole-swatch"></i>Устілка</span><span><i class="tape-swatch"></i>Окантовка по краю</span></div>
    <p class="tape-illustration-note">Умовна схема. Довжину стрічки та запас беремо з полів розрахунку, не вимірюємо за зображенням.</p>
  </figure>
</template>

<script setup>
import { useId } from 'vue';
import { insolePath } from '@/crm/utils/cardboardInsole';

// Використовуємо той самий умовний контур, не змінюючи норму витрати стрічки.
const id = useId();
const fillId = `tape-insole-fill-${id}`, titleId = `tape-insole-title-${id}`, descriptionId = `tape-insole-description-${id}`;
</script>

<style scoped>
.tape-illustration{margin:0;padding:20px 22px 17px;background:#fff;border:1px solid #e3e9f2;border-radius:16px;min-width:0}.tape-illustration figcaption{display:flex;justify-content:space-between;align-items:center;gap:12px}.tape-illustration h3{font-size:14px;font-weight:750;color:#28354b;margin:0 0 5px}.tape-illustration figcaption p{font-size:11px;color:#8690a1;margin:0;line-height:1.5}.tape-illustration figcaption>span{font-size:10px;color:#8d7da4;border:1px solid #ede8f4;background:#faf8fd;border-radius:6px;padding:5px 8px;white-space:nowrap}.tape-illustration svg{display:block;width:100%;max-width:480px;height:auto;margin:6px auto 0;font-family:inherit}.insole-label{fill:#a29383;font-size:12px;font-weight:550;letter-spacing:.3px}.tape-label{fill:#8566af;font-size:11px;font-weight:600}.tape-legend{display:flex;justify-content:center;flex-wrap:wrap;gap:18px;font-size:11px;color:#8590a2}.tape-legend span{display:flex;align-items:center;gap:6px}.tape-legend i{display:inline-block;width:11px;height:11px;border-radius:4px}.insole-swatch{background:#f4eadb;border:1px solid #e6dccc}.tape-swatch{background:#b397de;border:1px solid #9676c5}.tape-illustration-note{margin:13px 0 0;font-size:10px;color:#95a0af;line-height:1.7;text-align:center}@media(max-width:600px){.tape-illustration{padding:18px}.tape-illustration svg{margin-top:10px}}
</style>
