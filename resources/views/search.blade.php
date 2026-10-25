<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Search</title>
    @livewireStyles
</head>
<body>
    <livewire:drupal::search/>
    @livewireScripts
</body>
</html>
