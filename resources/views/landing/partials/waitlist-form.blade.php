{{--
    Waitlist form for hosts or vendors. Posts normally without JavaScript (errors come
    back in the "host"/"vendor" bag); landing.js submits it with fetch instead.
    Expects: $role ('host'|'vendor'), $marketing, $utm.
--}}
@php
    $isVendor = $role === 'vendor';
    $errs = $errors->getBag($role);
    $joined = session('waitlist_joined');
    $justJoined = ($joined['role'] ?? null) === $role;
    $id = fn (string $field) => "{$role}-{$field}";
    $errorId = fn (string $field) => "{$role}-{$field}-error";
    $invalid = fn (string $field) => $errs->has($field) || $errs->has("{$field}.*") ? 'true' : null;
    $oldEventTypes = old('role') === $role ? (array) old('event_types', []) : [];
    $old = fn (string $field) => old('role') === $role ? old($field) : null;
@endphp

<div id="{{ $role }}-success" class="rounded-3xl border-2 border-ink/10 bg-white p-8 text-center" tabindex="-1" @unless ($justJoined) hidden @endunless role="status">
    <p class="font-display text-3xl font-semibold">{{ $isVendor ? 'Welcome aboard!' : 'Yay, you’re in!' }}</p>
    <p class="mt-3 text-lg text-ink-soft" data-success-message>{{ $justJoined ? $joined['message'] : '' }}</p>
</div>

<form
    action="{{ route('waitlist.store') }}"
    method="POST"
    class="rounded-3xl border-2 border-ink/10 bg-white p-6 sm:p-8"
    data-waitlist-form
    data-success="{{ $role }}-success"
    novalidate
    @if ($justJoined) hidden @endif
>
    @csrf
    <input type="hidden" name="role" value="{{ $role }}">
    @foreach ($utm as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach

    <div class="honeypot" aria-hidden="true">
        <label for="{{ $id('website') }}">Leave this field empty</label>
        <input type="text" id="{{ $id('website') }}" name="website" tabindex="-1" autocomplete="off">
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        @if ($isVendor)
            <div class="sm:col-span-2">
                <label for="{{ $id('business_name') }}" class="field-label">Business name</label>
                <input id="{{ $id('business_name') }}" name="business_name" type="text" class="field" maxlength="120" required
                       autocomplete="organization" value="{{ $old('business_name') }}"
                       aria-describedby="{{ $errorId('business_name') }}" aria-invalid="{{ $invalid('business_name') }}">
                <p id="{{ $errorId('business_name') }}" class="field-error" data-error-for="business_name">{{ $errs->first('business_name') }}</p>
            </div>
        @endif

        <div>
            <label for="{{ $id('name') }}" class="field-label">Your name</label>
            <input id="{{ $id('name') }}" name="name" type="text" class="field" maxlength="80" required
                   autocomplete="name" value="{{ $old('name') }}"
                   aria-describedby="{{ $errorId('name') }}" aria-invalid="{{ $invalid('name') }}">
            <p id="{{ $errorId('name') }}" class="field-error" data-error-for="name">{{ $errs->first('name') }}</p>
        </div>

        <div>
            <label for="{{ $id('whatsapp_phone') }}" class="field-label">WhatsApp number</label>
            <input id="{{ $id('whatsapp_phone') }}" name="whatsapp_phone" type="tel" class="field" maxlength="30" required
                   inputmode="tel" autocomplete="tel" placeholder="024 123 4567" value="{{ $old('whatsapp_phone') }}"
                   aria-describedby="{{ $id('whatsapp_phone') }}-hint {{ $errorId('whatsapp_phone') }}" aria-invalid="{{ $invalid('whatsapp_phone') }}">
            <p id="{{ $id('whatsapp_phone') }}-hint" class="mt-1.5 text-sm text-ink-soft">Ghana numbers can start with 0. Others, add your country code.</p>
            <p id="{{ $errorId('whatsapp_phone') }}" class="field-error" data-error-for="whatsapp_phone">{{ $errs->first('whatsapp_phone') }}</p>
        </div>

        <div>
            <label for="{{ $id('city') }}" class="field-label">City</label>
            <input id="{{ $id('city') }}" name="city" type="text" class="field" maxlength="80" required
                   autocomplete="address-level2" list="{{ $id('cities') }}" value="{{ $old('city') }}"
                   aria-describedby="{{ $errorId('city') }}" aria-invalid="{{ $invalid('city') }}">
            <datalist id="{{ $id('cities') }}">
                @foreach ($marketing['launch_cities'] as $city)
                    <option value="{{ $city }}"></option>
                @endforeach
            </datalist>
            <p id="{{ $errorId('city') }}" class="field-error" data-error-for="city">{{ $errs->first('city') }}</p>
        </div>

        @if ($isVendor)
            <div>
                <label for="{{ $id('category') }}" class="field-label">What do you rent out?</label>
                <select id="{{ $id('category') }}" name="category" class="field" required
                        aria-describedby="{{ $errorId('category') }}" aria-invalid="{{ $invalid('category') }}">
                    <option value="">Choose a category</option>
                    @foreach ($marketing['vendor_categories'] as $slug => $label)
                        <option value="{{ $slug }}" @selected($old('category') === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
                <p id="{{ $errorId('category') }}" class="field-error" data-error-for="category">{{ $errs->first('category') }}</p>
            </div>
        @endif

        <fieldset class="sm:col-span-2" aria-describedby="{{ $errorId('event_types') }}">
            <legend class="field-label">{{ $isVendor ? 'Events you serve' : 'What are you planning?' }} <span class="font-normal text-ink-soft">(optional)</span></legend>
            <div class="flex flex-wrap gap-2">
                @foreach ($marketing['event_types'] as $slug => $label)
                    <label class="chip-check">
                        <input type="checkbox" name="event_types[]" value="{{ $slug }}" class="size-4 accent-ink" @checked(in_array($slug, $oldEventTypes, true))>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            <p id="{{ $errorId('event_types') }}" class="field-error" data-error-for="event_types">{{ $errs->first('event_types') ?: $errs->first('event_types.*') }}</p>
        </fieldset>
    </div>

    <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
        <button type="submit" class="btn {{ $isVendor ? 'btn-dark' : 'btn-primary' }} text-lg disabled:opacity-60">
            {{ $isVendor ? 'Register my business' : 'Join the waitlist' }}
        </button>
        <p class="text-sm text-ink-soft">
            We’ll only use your number to message you about Petals in a Jar. <a href="{{ route('privacy') }}" class="underline">Privacy</a>
        </p>
    </div>
    <p class="mt-3 text-sm font-semibold text-pink-deep" data-form-status role="alert">{{ $errs->any() ? 'Please check the highlighted fields.' : '' }}</p>
</form>
