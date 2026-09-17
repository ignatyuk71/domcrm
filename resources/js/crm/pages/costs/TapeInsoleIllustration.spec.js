import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TapeInsoleIllustration from './TapeInsoleIllustration.vue';
import { insolePath } from '@/crm/utils/cardboardInsole';

describe('Схема окантовки устілки', () => {
  it('окантовка повторює весь замкнений контур устілки, а не прямокутник', () => {
    const wrapper = mount(TapeInsoleIllustration);
    const body = wrapper.get('[data-testid="tape-insole-body"]'), edge = wrapper.get('[data-testid="tape-insole-edging"]');
    expect(body.attributes('d')).toBe(insolePath); expect(edge.attributes('d')).toBe(insolePath);
    expect(insolePath.endsWith('Z')).toBe(true); expect(edge.attributes('fill')).toBe('none');
    expect(wrapper.text()).toContain('Оксамитова стрічка'); expect(wrapper.text()).toContain('не вимірюємо за зображенням');
    expect(wrapper.find('img').exists()).toBe(false);
    wrapper.unmount();
  });
  it('має доступний опис і унікальні посилання на градієнт та підписи', () => {
    const wrapper = mount({ components: { TapeInsoleIllustration }, template: '<div><TapeInsoleIllustration /><TapeInsoleIllustration /></div>' });
    const ids = wrapper.findAll('[id]').map(node => node.attributes('id'));
    expect(new Set(ids).size).toBe(ids.length);
    for (const svg of wrapper.findAll('svg')) {
      expect(svg.attributes('role')).toBe('img');
      for (const id of svg.attributes('aria-labelledby').split(' ')) expect(ids).toContain(id);
      expect(svg.get('[data-testid="tape-insole-body"]').attributes('fill')).toBe(`url(#${svg.get('linearGradient').attributes('id')})`);
    }
    wrapper.unmount();
  });
});
