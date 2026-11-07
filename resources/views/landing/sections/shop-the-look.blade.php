<section id="shop-the-look" class="py-16 sm:py-24" aria-labelledby="look-title">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 sm:px-6 lg:grid-cols-[1.4fr_1fr] lg:items-center">
        <div class="relative" data-look>
            <img
                src="{{ asset('images/landing/shop-the-look-wedding.jpg') }}"
                alt="An outdoor wedding reception: white chairs under a gold-trimmed canopy, blush and gold centrepieces and a fairy-light curtain"
                width="1200" height="900"
                loading="lazy" decoding="async"
                class="aspect-[4/3] w-full rounded-3xl object-cover"
            >

            @foreach ($look as $i => $pin)
                <button
                    type="button"
                    class="pin"
                    style="left: {{ $pin['x'] }}%; top: {{ $pin['y'] }}%;"
                    data-pin
                    aria-expanded="false"
                    aria-controls="look-pin-{{ $i }}"
                    aria-label="{{ $pin['item'] }} from {{ $pin['vendor'] }}, {{ $pin['price'] }} {{ $pin['unit'] }}"
                >{{ $i + 1 }}</button>
            @endforeach

            {{-- One card at a time, along the bottom of the photo so it never runs off a small screen. --}}
            @foreach ($look as $i => $pin)
                <div id="look-pin-{{ $i }}" class="absolute inset-x-3 bottom-3 rounded-2xl bg-white p-4 shadow-xl sm:right-auto sm:w-80" hidden>
                    <p class="text-xs font-bold tracking-wide text-ink-soft uppercase">{{ $pin['vendor'] }}</p>
                    <p class="mt-1 font-display text-xl font-semibold">{{ $pin['item'] }}</p>
                    <p class="mt-1"><span class="text-lg font-bold">{{ $pin['price'] }}</span> <span class="text-sm text-ink-soft">{{ $pin['unit'] }}</span></p>
                </div>
            @endforeach
        </div>

        <div>
            <p class="eyebrow text-gold-deep">Shop the look</p>
            <h2 id="look-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">Love it? Rent it.</h2>
            <p class="mt-4 text-lg leading-relaxed text-ink-soft">
                Every post can be tagged with the pieces in it. Tap a number on the photo to see what it is, who rents it and what it costs.
            </p>

            <h3 class="mt-8 text-sm font-bold text-ink-soft">In this look</h3>
            <ol class="mt-3 divide-y divide-ink/10 rounded-3xl border border-ink/10 bg-white">
                @foreach ($look as $i => $pin)
                    <li class="flex items-center gap-4 px-5 py-4">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-cream-deep text-sm font-bold" aria-hidden="true">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $pin['item'] }}</p>
                            <p class="text-sm text-ink-soft">{{ $pin['vendor'] }}</p>
                        </div>
                        <p class="text-right font-bold whitespace-nowrap">{{ $pin['price'] }}<span class="block text-xs font-medium text-ink-soft">{{ $pin['unit'] }}</span></p>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</section>
