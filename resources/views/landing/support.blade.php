@php
    $sent = session('support_sent');
    $topic = old('topic', $selectedTopic?->value);
    $invalid = fn (string $field) => $errors->has($field) ? 'true' : null;
@endphp
<x-landing.layout
    title="Support — Petals in a Jar"
    description="Contact Petals in a Jar about bookings, payments, selling, your data, or deleting your account."
>
    <main id="main" class="mx-auto max-w-2xl px-4 py-12 sm:px-6 sm:py-16">
        <a href="{{ route('landing') }}" class="font-semibold hover:underline">← Petals in a Jar</a>
        <h1 class="mt-6 font-display text-4xl font-semibold sm:text-5xl">Support</h1>
        <p class="mt-4 text-lg leading-relaxed text-ink-soft">
            Questions about a booking, a payment, selling with us, or your account? Send us a message below, or email
            <a href="mailto:{{ $marketing['support_email'] }}" class="font-semibold text-ink underline">{{ $marketing['support_email'] }}</a>.
            We usually reply within two working days.
        </p>

        <section id="contact" class="mt-10 scroll-mt-6" aria-labelledby="contact-title">
            <h2 id="contact-title" class="font-display text-2xl font-semibold sm:text-3xl">Contact us</h2>

            @if ($sent)
                <div class="mt-5 rounded-3xl border-2 border-ink/10 bg-white p-8 text-center" role="status">
                    <p class="font-display text-2xl font-semibold">Message sent</p>
                    <p class="mt-3 text-lg text-ink-soft">{{ $sent }}</p>
                </div>
            @else
                <form action="{{ route('support.store') }}" method="POST" class="mt-5 rounded-3xl border-2 border-ink/10 bg-white p-6 sm:p-8" novalidate>
                    @csrf
                    <div class="honeypot" aria-hidden="true">
                        <label for="support-website">Leave this field empty</label>
                        <input type="text" id="support-website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="support-topic" class="field-label">What’s it about?</label>
                            <select id="support-topic" name="topic" class="field" required
                                    aria-describedby="support-topic-error" aria-invalid="{{ $invalid('topic') }}">
                                <option value="">Choose a topic</option>
                                @foreach ($topics as $option)
                                    <option value="{{ $option->value }}" @selected($topic === $option->value)>{{ $option->label() }}</option>
                                @endforeach
                            </select>
                            <p id="support-topic-error" class="field-error">{{ $errors->first('topic') }}</p>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="support-name" class="field-label">Your name</label>
                            <input id="support-name" name="name" type="text" class="field" maxlength="80" required
                                   autocomplete="name" value="{{ old('name') }}"
                                   aria-describedby="support-name-error" aria-invalid="{{ $invalid('name') }}">
                            <p id="support-name-error" class="field-error">{{ $errors->first('name') }}</p>
                        </div>

                        <div>
                            <label for="support-email" class="field-label">Email</label>
                            <input id="support-email" name="email" type="email" class="field" maxlength="255"
                                   autocomplete="email" value="{{ old('email') }}"
                                   aria-describedby="support-contact-hint support-email-error" aria-invalid="{{ $invalid('email') }}">
                            <p id="support-email-error" class="field-error">{{ $errors->first('email') }}</p>
                        </div>

                        <div>
                            <label for="support-phone" class="field-label">Phone or WhatsApp</label>
                            <input id="support-phone" name="phone" type="tel" class="field" maxlength="30"
                                   inputmode="tel" autocomplete="tel" placeholder="024 123 4567" value="{{ old('phone') }}"
                                   aria-describedby="support-contact-hint support-phone-error" aria-invalid="{{ $invalid('phone') }}">
                            <p id="support-phone-error" class="field-error">{{ $errors->first('phone') }}</p>
                        </div>
                        <p id="support-contact-hint" class="-mt-3 text-sm text-ink-soft sm:col-span-2">
                            Give us at least one. If it’s about your account, use the phone number or email you signed up with.
                        </p>

                        <div class="sm:col-span-2">
                            <label for="support-message" class="field-label">Message</label>
                            <textarea id="support-message" name="message" rows="6" class="field py-3" maxlength="5000" required
                                      aria-describedby="support-message-hint support-message-error" aria-invalid="{{ $invalid('message') }}">{{ old('message') }}</textarea>
                            <p id="support-message-hint" class="mt-1.5 text-sm text-ink-soft">Include a booking number if you have one. Never send us your PIN or card details.</p>
                            <p id="support-message-error" class="field-error">{{ $errors->first('message') }}</p>
                        </div>
                    </div>

                    <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <button type="submit" class="btn btn-primary text-lg">Send message</button>
                        <p class="text-sm text-ink-soft">We’ll only use these details to reply. <a href="{{ route('privacy') }}" class="underline">Privacy</a></p>
                    </div>
                    <p class="mt-3 text-sm font-semibold text-pink-deep" role="alert">{{ $errors->any() ? 'Please check the highlighted fields.' : '' }}</p>
                </form>
            @endif
        </section>

        <section id="delete-account" class="mt-14 scroll-mt-6" aria-labelledby="delete-title">
            <h2 id="delete-title" class="font-display text-2xl font-semibold sm:text-3xl">Deleting your account</h2>

            {{-- DRAFT: have the retention wording reviewed before launch (Ghana Data Protection Act, 2012). --}}
            <div class="mt-4 space-y-5 text-lg leading-relaxed text-ink-soft">
                <p>
                    You can delete your account yourself in the app: <strong class="text-ink">Profile → Settings → Delete account</strong>.
                    It takes effect straight away. If you have bookings in progress, finish or cancel them first.
                </p>
                <p>
                    No longer have the app? You can also ask us to delete your Petals in a Jar account at any time.
                    <a href="{{ route('support', ['topic' => 'account_deletion']) }}#contact" class="font-semibold text-ink underline">Send a deletion request</a>
                    using the phone number or email on your account. We’ll contact you there to confirm it’s really you, then delete the account within {{ $marketing['deletion_days'] }} days.
                </p>

                <div>
                    <h3 class="font-semibold text-ink">What we delete</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li>Your profile: name, handle, photo, bio, phone number and email</li>
                        <li>Your posts, comments, likes, saved ideas and followers</li>
                        <li>Your events, wishlists and notification settings</li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-ink">What we keep, and why</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-6">
                        <li>Records of bookings and payments, which we must keep for accounting, tax and refunds. They’re no longer linked to your profile.</li>
                        <li>Reviews you wrote stay up so ratings remain fair, but show as from a former member.</li>
                    </ul>
                </div>

                <p>
                    If you have a booking that hasn’t finished yet, or money waiting to be paid out to your shop, we’ll settle it first and tell you when your account can be deleted.
                    Shop owners: deleting your account closes your shop too, unless another owner takes it over.
                </p>
            </div>
        </section>
    </main>
</x-landing.layout>
