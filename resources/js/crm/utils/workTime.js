export const workMonths = ['Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень', 'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'];
export const periodKey = (year, month) => `${year}-${String(month).padStart(2, '0')}`;
export const workDays = period => {
    const [year, month] = period.split('-').map(Number);
    return Array.from({ length: new Date(year, month, 0).getDate() }, (_, i) => {
        const day = i + 1, weekday = new Date(year, month - 1, day).getDay();
        return { day, date: `${period}-${String(day).padStart(2, '0')}`, weekend: weekday === 0 || weekday === 6, label: ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'][weekday] };
    });
};
export const parseWorkHours = value => {
    const text = String(value ?? '').trim().replace(',', '.');
    if (!text) return null;
    if (!/^\d{1,2}(\.\d{1,2})?$/.test(text) || Number(text) > 24) throw new Error('Введіть від 0 до 24 годин, наприклад 8 або 7,5.');
    return Number(text).toFixed(2);
};
export const parseWorkAmount = value => {
    const text = String(value ?? '').trim().replace(',', '.');
    if (!text) return null;
    if (!/^\d{1,10}(\.\d{1,2})?$/.test(text) || Number(text) > 1000000000) throw new Error('Введіть суму від 0 до 1 000 000 000 грн, до двох знаків після коми.');
    return Number(text).toFixed(2);
};
export const workError = error => {
    if (error?.response?.status === 409) return error.response.data?.message || 'Дані вже змінили. Оновіть табель.';
    if ([401, 419].includes(error?.response?.status)) return 'Сесія закінчилася. Збережіть введені значення та увійдіть знову.';
    if (error?.response?.status === 403) return 'Недостатньо прав доступу.';
    if (error?.response?.data?.errors) return Object.values(error.response.data.errors).flat()[0];
    return error?.response?.data?.message || 'Не вдалося зберегти або завантажити дані. Перевірте з’єднання й повторіть.';
};
