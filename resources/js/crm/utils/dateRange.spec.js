import { afterEach, describe, expect, it, vi } from 'vitest';
import { dateISO, datePresets, dayCount, daysLabel, formatDateRange, parseDate, todayInKyiv } from './dateRange';

afterEach(() => vi.useRealTimers());

describe('Періоди аналітики', () => {
  it('відхиляє неіснуючі дати та підтримує високосний лютий', () => {
    for (const invalid of ['', null, '2026-02-29', '2026-04-31', '2026-13-01', '2026-1-01', '0000-01-01']) expect(parseDate(invalid)).toBeNull();
    expect(dateISO(parseDate('2024-02-29'))).toBe('2024-02-29');
    expect(dayCount('2024-02-28', '2024-03-01')).toBe(3);
    expect(dayCount('2026-02-28', '2026-03-01')).toBe(2);
  });

  it('рахує обидві дати включно незалежно від переведення годинника', () => {
    expect(dayCount('2026-03-28', '2026-03-30')).toBe(3);
    expect(dayCount('2026-10-24', '2026-10-26')).toBe(3);
    expect(dayCount('2026-09-15', '2026-09-15')).toBe(1);
    expect(dayCount('2026-09-15', '2026-09-14')).toBeNull();
    expect(dayCount('2026-09-15', '')).toBeNull();
    expect(dayCount('2024-01-01', '2025-12-31')).toBe(731);
  });

  it('коректно утворює періоди на межі місяця й року', () => {
    expect(datePresets('2026-01-02').find(preset => preset.key === '7days')).toMatchObject({ from: '2025-12-27', to: '2026-01-02' });
    expect(datePresets('2026-01-02').find(preset => preset.key === 'previous_month')).toMatchObject({ from: '2025-12-01', to: '2025-12-31' });
    expect(datePresets('2024-03-15').find(preset => preset.key === 'previous_month')).toMatchObject({ from: '2024-02-01', to: '2024-02-29' });
    expect(datePresets('2026-03-15').find(preset => preset.key === 'previous_month')).toMatchObject({ from: '2026-02-01', to: '2026-02-28' });
  });

  it('визначає сьогодні за Києвом, а не часовим поясом комп’ютера', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-14T21:30:00Z'));
    expect(todayInKyiv()).toBe('2026-09-15');
    expect(datePresets()[0]).toMatchObject({ from: '2026-09-15', to: '2026-09-15' });
  });

  it('показує читабельний український діапазон і кількість днів', () => {
    expect(formatDateRange('2026-09-01', '2026-09-15')).toContain('вересня');
    expect(formatDateRange('2025-12-31', '2026-01-02')).toMatch(/2025.*2026/);
    expect(formatDateRange('', '')).toBe('Обрати період');
    expect([1, 2, 5, 21, 22, 11].map(daysLabel)).toEqual(['1 день', '2 дні', '5 днів', '21 день', '22 дні', '11 днів']);
  });
});
