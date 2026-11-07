@props(['title', 'description'])
@php
    $shareImage = asset('images/landing/og-share.jpg');
@endphp
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#fff8ee">
        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <link rel="canonical" href="{{ url()->current() }}">
        <link rel="icon" href="{{ asset('images/landing/favicon.svg') }}" type="image/svg+xml">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">

        {{-- Link previews (WhatsApp, Instagram, Facebook use Open Graph; X uses the twitter: tags). --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Petals in a Jar">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ $shareImage }}">
        <meta property="og:image:secure_url" content="{{ $shareImage }}">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Petals in a Jar — Every celebration, collected.">
        <meta property="og:locale" content="en_GH">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $title }}">
        <meta name="twitter:description" content="{{ $description }}">
        <meta name="twitter:image" content="{{ $shareImage }}">
        <meta name="twitter:image:alt" content="Petals in a Jar — Every celebration, collected.">

        @fonts(['fredoka', 'dm-sans'])
        @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    </head>
    <body class="min-h-dvh">
        <a href="#main" class="sr-only z-50 rounded-full bg-ink px-4 py-2 text-cream focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Skip to content</a>

        {{ $slot }}
    </body>
</html>
