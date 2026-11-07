@php
    $promises = [
        ['title' => 'Verified vendors', 'text' => 'We check every business before it can take bookings, and verified shops carry a badge.', 'tone' => 'bg-blue-soft text-blue-deep',
            'icon' => '<path d="M12 3l7 3v6c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>'],
        ['title' => 'Deposits held until delivery', 'text' => 'Your payment stays with us until your rentals arrive. Vendors are paid once the event has gone well.', 'tone' => 'bg-gold-soft text-gold-deep',
            'icon' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
        ['title' => 'Real reviews', 'text' => 'Reviews come from people who actually booked, with photos from the day.', 'tone' => 'bg-pink-soft text-pink-deep',
            'icon' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3z"/>'],
    ];
@endphp
<section id="trust" class="py-16 sm:py-24" aria-labelledby="trust-title">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="eyebrow text-gold-deep">Book with confidence</p>
        <h2 id="trust-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">Safe for hosts, fair for vendors</h2>

        <ul class="mt-10 grid gap-5 md:grid-cols-3">
            @foreach ($promises as $promise)
                <li class="rounded-3xl border border-ink/10 bg-white p-6">
                    <span class="grid size-12 place-items-center rounded-2xl {{ $promise['tone'] }}" aria-hidden="true">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $promise['icon'] !!}</svg>
                    </span>
                    <h3 class="mt-4 font-display text-xl font-semibold">{{ $promise['title'] }}</h3>
                    <p class="mt-2 leading-relaxed text-ink-soft">{{ $promise['text'] }}</p>
                </li>
            @endforeach
        </ul>

        <div class="mt-14 text-center">
            <h3 class="text-sm font-bold tracking-[0.14em] text-ink-soft uppercase">Launching with</h3>
            {{-- Replace with real partner logos: public/images/landing/logo-launch-partner-*.svg --}}
            <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-8 gap-y-5">
                @foreach (range(1, 5) as $n)
                    <li>
                        <img src="{{ asset("images/landing/logo-launch-partner-{$n}.svg") }}" alt="Launch partner {{ $n }}"
                             width="160" height="56" loading="lazy" class="h-12 w-auto">
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
