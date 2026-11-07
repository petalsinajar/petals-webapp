@php
    $types = [
        [
            'name' => 'Weddings',
            'image' => 'event-wedding.jpg',
            'alt' => 'A wedding reception table set with gold chargers and white flowers',
            'text' => 'Canopies, chiavari chairs, florals and lighting for engagements, white weddings and receptions.',
            'card' => 'bg-gold-soft',
            'tag' => 'text-gold-deep',
            'dot' => 'bg-gold',
        ],
        [
            'name' => 'Birthdays',
            'image' => 'event-birthday.jpg',
            'alt' => 'A colourful birthday party with a balloon arch and a cake table',
            'text' => 'Balloon arches, kids’ party sets, sound and dessert tables for first birthdays to milestone 50ths.',
            'card' => 'bg-pink-soft',
            'tag' => 'text-pink-deep',
            'dot' => 'bg-pink',
        ],
        [
            'name' => 'Corporate',
            'image' => 'event-corporate.jpg',
            'alt' => 'A conference hall with a stage, screens and rows of chairs',
            'text' => 'Staging, PA systems, branded backdrops and seating for launches, conferences and end-of-year parties.',
            'card' => 'bg-blue-soft',
            'tag' => 'text-blue-deep',
            'dot' => 'bg-blue',
        ],
    ];
@endphp
<section id="events" class="bg-white py-16 sm:py-24" aria-labelledby="events-title">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="eyebrow text-blue-deep">For every occasion</p>
        <h2 id="events-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">Whatever you’re celebrating</h2>

        <ul class="mt-10 grid gap-5 md:grid-cols-3">
            @foreach ($types as $type)
                <li class="overflow-hidden rounded-3xl {{ $type['card'] }}">
                    <img src="{{ asset('images/landing/'.$type['image']) }}" alt="{{ $type['alt'] }}" width="800" height="600"
                         loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover">
                    <div class="p-6">
                        <h3 class="flex items-center gap-2 font-display text-2xl font-semibold">
                            <span class="size-3 rounded-full {{ $type['dot'] }}" aria-hidden="true"></span>{{ $type['name'] }}
                        </h3>
                        <p class="mt-2 leading-relaxed text-ink">{{ $type['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>

        <div id="waitlist" class="mt-16 grid gap-8 lg:grid-cols-[1fr_1.3fr] lg:items-start">
            <div>
                <p class="eyebrow text-pink-deep">Planning something?</p>
                <h2 class="mt-2 font-display text-4xl font-semibold sm:text-5xl">Join the waitlist</h2>
                <p class="mt-4 text-lg leading-relaxed text-ink-soft">
                    Be first in when we open in {{ $marketing['launch_cities'][0] }}. We’ll send one WhatsApp message on launch day, plus early access to vendors taking bookings.
                </p>
            </div>
            @include('landing.partials.waitlist-form', ['role' => 'host'])
        </div>
    </div>
</section>
