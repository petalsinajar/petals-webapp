<x-landing.layout
    title="Petals in a Jar — Event Rentals & Ideas"
    description="Every celebration, collected. Discover weddings, birthdays and corporate events, then rent the chairs, canopies, decor and sound you see from trusted vendors in Ghana. Join the waitlist."
>
    <header class="sticky top-0 z-40 border-b border-ink/10 bg-cream/90 backdrop-blur">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6" aria-label="Main">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-display text-xl font-semibold">
                <img src="{{ asset('images/landing/favicon.svg') }}" alt="" width="32" height="32" class="size-8">
                Petals in a Jar
            </a>
            <div class="flex items-center gap-2 sm:gap-5">
                <a href="#how-it-works" class="hidden text-sm font-semibold hover:underline md:inline">How it works</a>
                <a href="#vendors" class="text-sm font-semibold hover:underline">For vendors</a>
                <a href="#waitlist" class="btn btn-dark hidden min-h-10 px-4 text-sm sm:inline-flex">Join the waitlist</a>
            </div>
        </nav>
    </header>

    <main id="main">
        @include('landing.sections.hero')
        @include('landing.sections.how-it-works')
        @include('landing.sections.shop-the-look')
        @include('landing.sections.event-types')
        @include('landing.sections.vendors')
        @include('landing.sections.trust')
        @include('landing.sections.gallery')
        @include('landing.sections.faq')
    </main>

    @include('landing.sections.footer')
</x-landing.layout>
