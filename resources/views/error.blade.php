<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }}</title>
</head>
<body>
    <h1>{{ $status }}</h1>
    <p>{{ $message }}</p>
</body>
</html>
