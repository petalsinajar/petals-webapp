<x-landing.layout
    title="Privacy — Petals in a Jar"
    description="How Petals in a Jar uses the details you give us when you join the waitlist."
>
    <main id="main" class="mx-auto max-w-2xl px-4 py-12 sm:px-6 sm:py-16">
        <a href="{{ route('landing') }}" class="font-semibold hover:underline">← Petals in a Jar</a>
        <h1 class="mt-6 font-display text-4xl font-semibold sm:text-5xl">Privacy</h1>

        {{-- DRAFT: have this reviewed before launch (Ghana Data Protection Act, 2012). --}}
        <div class="mt-8 space-y-5 text-lg leading-relaxed text-ink-soft">
            <p>When you join the waitlist we keep your name, WhatsApp number, city, the events you’re interested in and, for vendors, your business name and category. We also note which link or campaign brought you to us.</p>
            <p>We use these details only to tell you about Petals in a Jar: when we launch, early access, and, for vendors, setting up your shop. We don’t sell them or share them with advertisers.</p>
            <p>To be removed from the list, or to see what we hold about you, email
                <a href="mailto:{{ $marketing['contact_email'] }}" class="font-semibold text-ink underline">{{ $marketing['contact_email'] }}</a>.
            </p>
        </div>
    </main>
</x-landing.layout>
