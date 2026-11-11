<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('No access') }}</title>
    @include('content-mcp::partials.styles')
</head>
<body>
<main>
    <h1>{{ __('No access') }}</h1>
    <p>{{ __('The account :user may not connect apps to this site.', ['user' => $user->email()]) }}</p>
    <p class="muted">{{ __('Ask an administrator for the permission ":permission".', ['permission' => __('Connect Claude via Content MCP')]) }}</p>
</main>
</body>
</html>
