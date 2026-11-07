@php
    $benefits = [
        ['title' => 'Get seen', 'text' => 'Every event that uses your items can tag them, so your work shows up in front of people planning the same thing.'],
        ['title' => 'Bookings and calendar', 'text' => 'Requests, availability and stock in one place. Items booked for a date are blocked automatically, so you never double-book.'],
        ['title' => 'Payments by mobile money', 'text' => 'Customers pay deposits by MoMo or card. Payouts go to your mobile money or bank account after the event.'],
        ['title' => 'Free to join', 'text' => "No sign-up or monthly fees. We take {$commissionPercent}% only when a booking is completed."],
    ];
@endphp
<section id="vendors" class="bg-ink py-16 text-cream sm:py-24" aria-labelledby="vendors-title">
    <div class="mx-auto grid max-w-6xl gap-12 px-4 sm:px-6 lg:grid-cols-[1fr_1.15fr]">
        <div>
            <p class="eyebrow text-gold">For vendors</p>
            <h2 id="vendors-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">Grow your rental business</h2>
            <p class="mt-4 text-lg leading-relaxed text-cream/80">
                For businesses that rent chairs, tents, decor, lighting, sound and more. Set up your shop before launch and be ready on day one.
            </p>

            <dl class="mt-10 grid gap-6 sm:grid-cols-2">
                @foreach ($benefits as $benefit)
                    <div class="border-t border-cream/20 pt-4">
                        <dt class="font-display text-xl font-semibold">{{ $benefit['title'] }}</dt>
                        <dd class="mt-1.5 leading-relaxed text-cream/80">{{ $benefit['text'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="text-ink">
            <h3 class="sr-only">Register your business</h3>
            @include('landing.partials.waitlist-form', ['role' => 'vendor'])
        </div>
    </div>
</section>
