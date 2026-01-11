<x-app-layout>
    @if(isset($order))
        {{-- Якщо передано замовлення, відкриваємо робоче місце пакувальника --}}
        <div id="packing-workspace" data-order='@json($order)'></div>
    @else
        {{-- Якщо замовлення немає, відкриваємо загальний список (чергу) --}}
        <div id="packing-list"></div>
    @endif
</x-app-layout>