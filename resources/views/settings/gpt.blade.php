<x-app-layout>
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div id="crm-settings-gpt"></div>
    </div>

    @push('scripts')
        @vite('resources/js/gpt-drafts.js', 'build/gpt')
    @endpush
</x-app-layout>
