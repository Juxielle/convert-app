<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="canonical-base" content="{{ rtrim(config('app.url'), '/') }}">
        <meta name="indexing-enabled" content="{{ config('seo.indexing') ? 'true' : 'false' }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="description" content="{{ $seo['description'] ?? 'Convertir vos fichiers avec Convert.' }}">
        <meta name="robots" content="{{ $seo['robots'] ?? 'noindex, nofollow' }}">
        @isset($seo['canonical'])
            <link rel="canonical" href="{{ $seo['canonical'] }}">
            <meta property="og:url" content="{{ $seo['canonical'] }}">
        @endisset
        <meta property="og:title" content="{{ $seo['title'] ?? 'Convert' }}">
        <meta property="og:description" content="{{ $seo['description'] ?? '' }}">
        <meta property="og:type" content="website">
        @if(!empty($seo['schema']))
            <script type="application/ld+json">{!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endif
        <meta name="theme-color" content="#f8faf7">
        <title>{{ $seo['title'] ?? 'Convert — Votre espace fichiers' }}</title>
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <script src="/theme.js"></script>
        @viteReactRefresh
        @vite('resources/js/main.tsx')
    </head>
    <body>
        <div id="root"></div>
        <noscript>Activez JavaScript pour consulter le catalogue et les outils de Convert.</noscript>
    </body>
</html>
