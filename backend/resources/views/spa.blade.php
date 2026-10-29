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
    {{ Vite::useBuildDirectory('spa')->withEntryPoints(['src/main.tsx']) }}
</head>
<body>
    <div id="root"></div>
</body>
</html>
