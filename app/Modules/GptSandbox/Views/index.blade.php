<x-app-layout>
    <div id="gpt-sandbox-root" class="container-fluid p-3 p-lg-4"></div>

    @push('scripts')
        @php($sandboxVite = clone app(\Illuminate\Foundation\Vite::class))
        {{ $sandboxVite->useHotFile(public_path('gpt-sandbox.hot'))('resources/js/gpt-sandbox/index.js', 'build/gpt-sandbox') }}
    @endpush
</x-app-layout>
