<footer class="bg-cream-deep" aria-labelledby="footer-title">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr]">
        <div>
            <h2 id="footer-title" class="flex items-center gap-2 font-display text-2xl font-semibold">
                <img src="{{ asset('images/landing/favicon.svg') }}" alt="" width="32" height="32" class="size-8">
                Petals in a Jar
            </h2>
            <p class="mt-2 text-ink-soft">Event Rentals &amp; Ideas. Every celebration, collected.</p>

            {{-- Not the official store badges: those are only allowed once the app is listed. --}}
            <div class="mt-6 flex flex-wrap gap-3">
                @foreach (['App Store', 'Google Play'] as $store)
                    <span class="inline-flex min-h-12 items-center gap-3 rounded-2xl bg-ink px-4 py-2 text-cream">
                        <span class="text-left leading-tight">
                            <span class="block text-[11px] font-medium text-cream/75">Coming soon to</span>
                            <span class="block font-semibold">{{ $store }}</span>
                        </span>
                    </span>
                @endforeach
            </div>
        </div>

        <nav aria-label="Social media">
            <h3 class="text-sm font-bold tracking-[0.14em] text-ink-soft uppercase">Follow us</h3>
            <ul class="mt-4 space-y-2">
                @foreach ($marketing['socials'] as $network => $url)
                    <li><a href="{{ $url }}" rel="noopener" target="_blank" class="font-semibold hover:underline">{{ $network }}<span class="sr-only"> (opens in a new tab)</span></a></li>
                @endforeach
            </ul>
        </nav>

        <div>
            <h3 class="text-sm font-bold tracking-[0.14em] text-ink-soft uppercase">Contact</h3>
            <ul class="mt-4 space-y-2">
                <li><a href="mailto:{{ $marketing['contact_email'] }}" class="font-semibold hover:underline">{{ $marketing['contact_email'] }}</a></li>
                <li><a href="{{ route('privacy') }}" class="font-semibold hover:underline">Privacy</a></li>
            </ul>
        </div>
    </div>
    <p class="border-t border-ink/10 px-4 py-5 text-center text-sm text-ink-soft">© {{ now()->year }} Petals in a Jar. Made in Ghana.</p>
</footer>
