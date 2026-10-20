{{-- Shared <head> for every layout: meta, the no-flash theme script, assets. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
@if ($description)
    <meta name="description" content="{{ $description }}">
@endif
<meta name="theme-color" content="#ffffff">

{{--
    Applies the theme before anything paints. Without this, a dark-mode
    user sees a white flash on every page load while the CSS and Alpine
    catch up. Same rule as applyTheme() in app.js: a saved choice wins,
    otherwise follow the OS.
--}}
<script>
    (() => {
        let mode = 'system';
        try { mode = localStorage.getItem('theme') ?? 'system'; } catch {}
        const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        document.querySelector('meta[name="theme-color"]').content = dark ? '#09090b' : '#ffffff';
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
