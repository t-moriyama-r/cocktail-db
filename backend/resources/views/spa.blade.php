<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
@isset($head)
    <title>{{ $head['title'] }}</title>
    <meta name="description" content="{{ $head['description'] }}">
    <link rel="canonical" href="{{ $head['canonical'] }}">
@else
    <title>宅飲みカクテルデータベース</title>
@endisset
{{-- OGP・Twitter カード(#112)。詳細ページはページごとの値、それ以外はサイト共通の値(SocialCard) --}}
@php($social ??= \App\Support\SocialCard::site())
    <meta property="og:url" content="{{ $social['url'] }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $social['title'] }}">
    <meta property="og:description" content="{{ $social['description'] }}">
    <meta property="og:image" content="{{ $social['image'] }}">
    <meta name="twitter:card" content="{{ $social['card'] }}">
    <meta name="twitter:title" content="{{ $social['title'] }}">
    <meta name="twitter:description" content="{{ $social['description'] }}">
    <meta name="twitter:image" content="{{ $social['image'] }}">
    {{ Vite::useBuildDirectory('spa')->withEntryPoints(['src/main.tsx']) }}
</head>
<body>
    <div id="root"></div>
</body>
</html>
