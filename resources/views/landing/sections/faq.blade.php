@php
    $cities = $marketing['launch_cities'];
    $next = array_slice($cities, 1);
    $nextList = count($next) > 1 ? implode(', ', array_slice($next, 0, -1)).' and '.end($next) : ($next[0] ?? '');
    $citiesAnswer = $nextList
        ? "We’re starting in {$cities[0]}, then {$nextList}."
        : "We’re starting in {$cities[0]}.";
    $faqs = [
        [
            'q' => 'When do you launch?',
            'a' => "We’re aiming for {$marketing['launch_window']}. Join the waitlist and we’ll message you on WhatsApp the day we open, with early access for the first sign-ups.",
        ],
        [
            'q' => 'Which cities will you cover?',
            'a' => "{$citiesAnswer} Vendors outside these cities can still sign up, and we’ll let you know when we reach you.",
        ],
        [
            'q' => 'How do payments and deposits work?',
            'a' => "You pay a deposit (usually {$depositPercent}%) by mobile money or card to secure your booking, and the rest before the event. We hold your money until your rentals are delivered. Vendors are paid after the event, once the {$disputeHours}-hour window to report a problem has passed.",
        ],
        [
            'q' => 'What does it cost vendors?',
            'a' => "Nothing to join and no monthly fee. We take a {$commissionPercent}% commission only when a booking is completed, and you set your own prices.",
        ],
    ];
@endphp
<section id="faq" class="py-16 sm:py-24" aria-labelledby="faq-title">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
        <p class="eyebrow text-blue-deep">Questions</p>
        <h2 id="faq-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">Good to know</h2>

        {{-- Native <details>: keyboard and screen-reader support with no JavaScript. --}}
        <div class="mt-10 space-y-3">
            @foreach ($faqs as $faq)
                <details class="group rounded-3xl border border-ink/10 bg-white open:shadow-sm">
                    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 rounded-3xl px-6 py-4 font-display text-xl font-semibold [&::-webkit-details-marker]:hidden">
                        {{ $faq['q'] }}
                        <svg class="size-6 shrink-0 transition group-open:rotate-45 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    </summary>
                    <p class="px-6 pb-6 leading-relaxed text-ink-soft">{{ $faq['a'] }}</p>
                </details>
            @endforeach
        </div>

        <p class="mt-8 text-center text-ink-soft">
            Something else? Email <a href="mailto:{{ $marketing['contact_email'] }}" class="font-semibold text-ink underline">{{ $marketing['contact_email'] }}</a>
        </p>
    </div>
</section>
