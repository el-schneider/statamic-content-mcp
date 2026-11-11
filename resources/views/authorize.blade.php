<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Connect :client to :site', ['client' => $client->name, 'site' => $siteName]) }}</title>
    @include('content-mcp::partials.styles')
</head>
<body>
<main>
    <h1>{{ __('Connect :client to :site', ['client' => $client->name, 'site' => $siteName]) }}</h1>

    <p>{{ __('After you approve, you will be sent to:') }}</p>
    <span class="host">{{ $redirectHost }}</span>
    <p class="muted">{{ __('Any app can call itself ":client". This address tells you who you are really connecting.', ['client' => $client->name]) }}</p>

    <p>{{ __('The app will be able to read and edit content with your permissions. New entries are saved as drafts.') }}</p>
    <p class="muted">{{ __('Signed in as :user', ['user' => $user->email()]) }}</p>

    <div class="actions">
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">{{ __('Cancel') }}</button>
        </form>
        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">{{ __('Approve') }}</button>
        </form>
    </div>
</main>
</body>
</html>
