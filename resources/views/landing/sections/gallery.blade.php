@php
    $instagram = $marketing['socials']['Instagram'];
    $handle = $marketing['instagram_handle'];
    $photos = [
        ['file' => 'gallery-01.jpg', 'alt' => 'A bride and groom under a canopy of white flowers'],
        ['file' => 'gallery-02.jpg', 'alt' => 'A pink and gold balloon arch at a first birthday'],
        ['file' => 'gallery-03.jpg', 'alt' => 'A stage lit in blue for a company awards night'],
        ['file' => 'gallery-04.jpg', 'alt' => 'Round tables with gold chairs and tall centrepieces'],
        ['file' => 'gallery-05.jpg', 'alt' => 'A dessert table with cupcakes and fairy lights'],
        ['file' => 'gallery-06.jpg', 'alt' => 'A DJ booth and dance floor at an evening party'],
    ];
@endphp
<section id="gallery" class="bg-white py-16 sm:py-24" aria-labelledby="gallery-title">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow text-pink-deep">Inspiration</p>
                <h2 id="gallery-title" class="mt-2 font-display text-4xl font-semibold sm:text-5xl">From our jar</h2>
            </div>
            <a href="{{ $instagram }}" class="btn btn-secondary" rel="noopener" target="_blank">
                Follow {{ '@'.$handle }}<span class="sr-only"> on Instagram (opens in a new tab)</span>
            </a>
        </div>

        <ul class="mt-10 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3">
            @foreach ($photos as $photo)
                <li>
                    <a href="{{ $instagram }}" rel="noopener" target="_blank" class="group block overflow-hidden rounded-3xl">
                        <img src="{{ asset('images/landing/'.$photo['file']) }}" alt="{{ $photo['alt'] }}" width="600" height="600"
                             loading="lazy" decoding="async" class="aspect-square w-full object-cover transition duration-300 group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100">
                        <span class="sr-only">(on Instagram, opens in a new tab)</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
