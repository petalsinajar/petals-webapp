@php
    // Drifting petals: position, colour, size, speed. rest-* is where each one sits when motion is reduced.
    $petals = [
        ['x' => '6%', 'c' => 'var(--color-gold)', 'size' => '14px', 'd' => '16s', 'delay' => '-2s', 'sway' => '60px', 'rest-y' => '18%', 'rest-r' => '20deg'],
        ['x' => '18%', 'c' => 'var(--color-pink)', 'size' => '18px', 'd' => '19s', 'delay' => '-9s', 'sway' => '-40px', 'rest-y' => '72%', 'rest-r' => '-35deg'],
        ['x' => '34%', 'c' => 'var(--color-blue)', 'size' => '12px', 'd' => '14s', 'delay' => '-5s', 'sway' => '50px', 'rest-y' => '40%', 'rest-r' => '60deg'],
        ['x' => '52%', 'c' => 'var(--color-gold)', 'size' => '16px', 'd' => '21s', 'delay' => '-13s', 'sway' => '-60px', 'rest-y' => '8%', 'rest-r' => '-15deg'],
        ['x' => '68%', 'c' => 'var(--color-pink)', 'size' => '13px', 'd' => '17s', 'delay' => '-7s', 'sway' => '40px', 'rest-y' => '86%', 'rest-r' => '45deg'],
        ['x' => '84%', 'c' => 'var(--color-blue)', 'size' => '17px', 'd' => '20s', 'delay' => '-15s', 'sway' => '-50px', 'rest-y' => '30%', 'rest-r' => '-50deg'],
        ['x' => '94%', 'c' => 'var(--color-gold)', 'size' => '12px', 'd' => '15s', 'delay' => '-1s', 'sway' => '-30px', 'rest-y' => '60%', 'rest-r' => '10deg'],
    ];
@endphp
<section class="relative overflow-hidden" aria-labelledby="hero-title">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        @foreach ($petals as $p)
            <span class="petal" style="--x: {{ $p['x'] }}; --c: {{ $p['c'] }}; --size: {{ $p['size'] }}; --d: {{ $p['d'] }}; --delay: {{ $p['delay'] }}; --sway: {{ $p['sway'] }}; --rest-y: {{ $p['rest-y'] }}; --rest-r: {{ $p['rest-r'] }};"></span>
        @endforeach
    </div>

    <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 pt-10 pb-16 sm:px-6 md:grid-cols-[1.1fr_1fr] md:pt-16 md:pb-24">
        <div>
            <p class="font-display text-lg font-semibold">Petals in a Jar</p>
            <p class="eyebrow mt-1 text-gold-deep">Event Rentals &amp; Ideas</p>
            <h1 id="hero-title" class="mt-5 font-display text-5xl leading-[1.02] font-semibold text-balance sm:text-6xl lg:text-7xl">
                Every celebration, collected.
            </h1>
            <p class="mt-5 max-w-xl text-lg leading-relaxed text-ink-soft sm:text-xl">
                Scroll through real weddings, birthdays and company events near you. Love the canopy? The chairs? The lights?
                Tap them and rent the same pieces from the vendors who supplied them.
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="#waitlist" class="btn btn-primary text-lg">Join the waitlist</a>
                <a href="#vendors" class="btn btn-secondary text-lg">I’m a vendor</a>
            </div>
            <p class="mt-4 text-sm text-ink-soft">Free to join. We’ll message you on WhatsApp when we launch.</p>
        </div>

        {{-- Phone mockup: a wedding post with shop tags. --}}
        <figure class="mx-auto w-full max-w-[19rem]" aria-label="Preview of the app: a wedding post with shop tags on the items">
            <div class="relative rounded-[2.75rem] border-[10px] border-ink bg-white p-0 shadow-[0_30px_60px_-20px_rgb(43_33_24/0.45)]">
                <div class="absolute top-2 left-1/2 h-5 w-24 -translate-x-1/2 rounded-full bg-ink" aria-hidden="true"></div>
                <div class="overflow-hidden rounded-[2.1rem]">
                    <div class="flex items-center gap-2 px-4 pt-9 pb-3">
                        <span class="grid size-9 place-items-center rounded-full bg-gold-soft font-display font-semibold text-gold-deep" aria-hidden="true">RC</span>
                        <div class="text-left leading-tight">
                            <p class="text-sm font-bold">royal.canopies</p>
                            <p class="text-xs text-ink-soft">Ama &amp; Kwame’s wedding · Aburi</p>
                        </div>
                    </div>
                    <div class="relative">
                        <img
                            src="{{ asset('images/landing/hero-wedding-post.jpg') }}"
                            alt="A garden wedding reception with a white canopy, gold-trimmed chairs and blush flowers"
                            width="800" height="1000"
                            class="aspect-[4/5] w-full object-cover"
                            fetchpriority="high"
                        >
                        <span class="absolute top-[22%] left-[10%] flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold shadow">
                            <span class="size-2 rounded-full bg-gold" aria-hidden="true"></span> Canopy · GH₵2,500
                        </span>
                        <span class="absolute top-[52%] right-[8%] flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold shadow">
                            <span class="size-2 rounded-full bg-pink" aria-hidden="true"></span> Centrepiece · GH₵180
                        </span>
                        <span class="absolute bottom-[12%] left-[14%] flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold shadow">
                            <span class="size-2 rounded-full bg-blue" aria-hidden="true"></span> Chairs · GH₵15
                        </span>
                    </div>
                    <div class="flex items-center justify-between px-4 py-3 text-ink" aria-hidden="true">
                        <div class="flex gap-3">
                            <svg class="size-6" viewBox="0 0 24 24" fill="var(--color-pink)"><path d="M12 21s-7.5-4.6-9.4-9.3C1.2 8.3 3.4 4.5 7.2 4.5c2 0 3.6 1.1 4.8 2.7 1.2-1.6 2.8-2.7 4.8-2.7 3.8 0 6 3.8 4.6 7.2C19.5 16.4 12 21 12 21z"/></svg>
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
                        </div>
                        <span class="rounded-full bg-ink px-3 py-1 text-xs font-bold text-cream">Shop 6 items</span>
                    </div>
                </div>
            </div>
        </figure>
    </div>
</section>
