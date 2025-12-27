<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                DomCRM — Dashboard
            </h2>

            <span class="text-sm text-gray-500">
                {{ now()->format('d.m.Y') }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            {{-- Статистика --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @php
                    $stats = [
                        ['label' => 'Нові замовлення', 'value' => 0],
                        ['label' => 'В роботі', 'value' => 0],
                        ['label' => 'Готові до пакування', 'value' => 0],
                        ['label' => 'Відправлено сьогодні', 'value' => 0],
                    ];
                @endphp

                @foreach($stats as $s)
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <div class="text-sm text-gray-500">{{ $s['label'] }}</div>
                        <div class="mt-2 text-3xl font-bold text-gray-900">{{ $s['value'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Швидкі дії --}}
            <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Швидкі дії</h3>

                    <a href="#"
                       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                        <span class="text-lg leading-none">+</span>
                        Створити замовлення
                    </a>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="#" class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg text-sm hover:bg-gray-200">
                        📦 Пакування
                    </a>
                    <a href="#" class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg text-sm hover:bg-gray-200">
                        📋 Список пакування
                    </a>
                    <a href="#" class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg text-sm hover:bg-gray-200">
                        👥 Клієнти
                    </a>
                </div>
            </div>

            {{-- Останні події --}}
            <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Останні події</h3>
                <div class="text-sm text-gray-500">
                    Тут зʼявиться історія дій: створення замовлень, зміна статусів, пакування.
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
