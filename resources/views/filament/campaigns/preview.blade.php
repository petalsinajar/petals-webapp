{{-- Campaign composer preview: an Android and an iPhone lock screen, plus the SMS. Sample recipient: Ama in Accra. --}}
@php
    $title = $title ?: 'Title';
    $body = $body ?: 'Your message';
@endphp
<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
    @if ($push || $in_app || $sms)
        {{-- Android --}}
        <figure style="margin:0;width:260px">
            <div style="border-radius:28px;background:#1f2937;padding:14px 10px 22px;box-shadow:0 6px 18px rgba(0,0,0,.18)">
                <div style="display:flex;justify-content:space-between;color:#e5e7eb;font:500 11px system-ui;padding:0 10px 10px">
                    <span>10:00</span><span>▾ ◢ ▮</span>
                </div>
                @if ($push)
                    <div style="background:#f3f4f6;border-radius:18px;padding:10px 12px;font-family:Roboto,system-ui,sans-serif">
                        <div style="display:flex;gap:6px;align-items:center;font-size:11px;color:#6b7280">
                            <span style="width:14px;height:14px;border-radius:50%;background:#e11d48;display:inline-block"></span>
                            Petalsinajar · now
                        </div>
                        <div style="font-weight:600;font-size:13px;color:#111827;margin-top:4px">{{ $title }}</div>
                        <div style="font-size:12px;color:#374151;margin-top:2px;line-height:1.35">{{ $body }}</div>
                        @if ($image)
                            <img src="{{ $image }}" alt="" style="margin-top:8px;width:100%;border-radius:10px;max-height:120px;object-fit:cover">
                        @endif
                    </div>
                @endif
                @if ($sms)
                    <div style="background:#f3f4f6;border-radius:18px;padding:10px 12px;margin-top:8px;font-family:Roboto,system-ui,sans-serif">
                        <div style="font-size:11px;color:#6b7280">Messages · {{ config('eventapp.sms.cbmessaging.sender_id') ?: 'Sender ID' }}</div>
                        <div style="font-size:12px;color:#374151;margin-top:2px;white-space:pre-line">{{ \Illuminate\Support\Str::limit($sms, 120) }}</div>
                    </div>
                @endif
            </div>
            <figcaption style="text-align:center;font-size:12px;color:#6b7280;margin-top:6px">Android</figcaption>
        </figure>

        {{-- iPhone --}}
        <figure style="margin:0;width:260px">
            <div style="border-radius:40px;background:linear-gradient(160deg,#6366f1,#ec4899);padding:14px 10px 26px;box-shadow:0 6px 18px rgba(0,0,0,.18)">
                <div style="width:90px;height:22px;border-radius:12px;background:#000;margin:0 auto 18px"></div>
                <div style="text-align:center;color:#fff;font:300 38px -apple-system,system-ui;margin-bottom:14px">10:00</div>
                @if ($push)
                    <div style="background:rgba(255,255,255,.78);backdrop-filter:blur(8px);border-radius:16px;padding:10px 12px;font-family:-apple-system,system-ui,sans-serif">
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:#4b5563">
                            <span style="display:flex;gap:6px;align-items:center"><span style="width:16px;height:16px;border-radius:4px;background:#e11d48;display:inline-block"></span>PETALSINAJAR</span>
                            <span>now</span>
                        </div>
                        <div style="font-weight:600;font-size:13px;color:#111827;margin-top:4px">{{ $title }}</div>
                        <div style="font-size:12px;color:#1f2937;margin-top:2px;line-height:1.35">{{ \Illuminate\Support\Str::limit($body, 110) }}</div>
                    </div>
                @endif
                @if ($sms)
                    <div style="background:rgba(255,255,255,.78);border-radius:16px;padding:10px 12px;margin-top:8px;font-family:-apple-system,system-ui,sans-serif">
                        <div style="font-size:11px;color:#4b5563">MESSAGES</div>
                        <div style="font-size:12px;color:#1f2937;margin-top:2px;white-space:pre-line">{{ \Illuminate\Support\Str::limit($sms, 100) }}</div>
                    </div>
                @endif
            </div>
            <figcaption style="text-align:center;font-size:12px;color:#6b7280;margin-top:6px">iPhone</figcaption>
        </figure>
    @endif

    <div style="flex:1;min-width:220px;font-size:13px;line-height:1.5">
        @if ($in_app)
            <p style="margin:0 0 6px;font-weight:600">In-app notification</p>
            <div style="border:1px solid rgba(127,127,127,.3);border-radius:12px;padding:10px 12px;margin-bottom:12px">
                <div style="font-weight:600">{{ $title }}</div>
                <div style="opacity:.8">{{ $body }}</div>
            </div>
        @endif
        <p style="margin:0;opacity:.75">Tapping opens: <code>{{ $link ?? 'the app' }}</code></p>
        <p style="margin:4px 0 0;opacity:.75">Shown for a sample recipient (Ama, Accra). Each person sees their own name, city and event.</p>
    </div>
</div>
