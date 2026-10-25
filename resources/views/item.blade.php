<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entity->get(array_key_first($type->fields)) }}</title>
</head>
<body>
    <article>
        <h1>{{ $entity->get(array_key_first($type->fields)) }}</h1>
    </article>
</body>
</html>
