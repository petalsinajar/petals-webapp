@php
    $steps = [
        [
            'title' => 'See it',
            'text' => 'Browse real celebrations from hosts and vendors near you, from intimate birthdays to 500-guest weddings.',
            'tone' => 'bg-gold-soft text-gold-deep',
            'icon' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        ],
        [
            'title' => 'Rent it',
            'text' => 'Tap any tagged chair, canopy, light or speaker to check dates and book it straight from the vendor.',
            'tone' => 'bg-pink-soft text-pink-deep',
            'icon' => '<path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
        ],
        [
            'title' => 'Celebrate it',
            'text' => 'Everything arrives on the day. Share your photos afterwards and inspire the next host.',
            'tone' => 'bg-blue-soft text-blue-deep',
            'icon' => '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
        ],
    ];
@endphp
<section id="how-it-works" class="bg-white py-16 sm:py-24" aria-labelledby="how-title">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <p class="eyebrow text-pink-deep">How it works</p>
        <h2 id="how-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">See it. Rent it. Celebrate it.</h2>

        <ol class="mt-10 grid gap-5 md:grid-cols-3">
            @foreach ($steps as $i => $step)
                <li class="rounded-3xl border border-ink/10 bg-cream p-6">
                    <div class="flex items-center gap-3">
                        <span class="grid size-12 place-items-center rounded-2xl {{ $step['tone'] }}" aria-hidden="true">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $step['icon'] !!}</svg>
                        </span>
                        <span class="text-sm font-bold text-ink-soft">Step {{ $i + 1 }}</span>
                    </div>
                    <h3 class="mt-4 font-display text-2xl font-semibold">{{ $step['title'] }}</h3>
                    <p class="mt-2 leading-relaxed text-ink-soft">{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>
