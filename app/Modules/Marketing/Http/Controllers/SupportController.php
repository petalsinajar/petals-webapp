<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Enums\SupportTopic;
use App\Modules\Marketing\Models\SupportRequest;
use App\Modules\Marketing\Notifications\SupportRequestReceived;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/** The public support page: contact us about anything, including deleting an account. */
class SupportController extends Controller
{
    /** Hidden from people; bots fill it in. */
    public const HONEYPOT = 'website';

    /** GET /support (?topic=account_deletion preselects the topic) */
    public function show(Request $request): View
    {
        return view('landing.support', [
            'marketing' => config('eventapp.marketing'),
            'topics' => SupportTopic::cases(),
            'selectedTopic' => SupportTopic::tryFrom((string) $request->query('topic')),
        ]);
    }

    /** POST /support */
    public function store(Request $request): RedirectResponse
    {
        // Look like a normal success so bots don't learn to skip the field.
        if (filled($request->input(self::HONEYPOT))) {
            return $this->sent();
        }

        $data = $request->validate([
            'topic' => ['required', Rule::enum(SupportTopic::class)],
            'name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || PhoneNumber::normalize($value) === null) {
                    $fail('Enter a phone number we can reach, like 024 123 4567.');
                }
            }],
            'message' => ['required', 'string', 'max:5000'],
        ], [
            'email.required_without' => 'Give us an email or a phone number so we can reply.',
            'phone.required_without' => 'Give us an email or a phone number so we can reply.',
        ]);

        $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
        $phone = isset($data['phone']) ? PhoneNumber::normalize($data['phone']) : null;

        $supportRequest = SupportRequest::create([
            'topic' => $data['topic'],
            'name' => trim($data['name']),
            'email' => $email,
            'phone' => $phone,
            'message' => trim($data['message']),
            'user_id' => $this->matchingUser($email, $phone)?->id,
        ]);

        Notification::route('mail', config('eventapp.marketing.support_email'))
            ->notify(new SupportRequestReceived($supportRequest));

        return $this->sent($supportRequest->topic);
    }

    /** The account these details belong to, for the team's reference. Never shown to the sender. */
    private function matchingUser(?string $email, ?string $phone): ?User
    {
        if (! $email && ! $phone) {
            return null;
        }

        return User::query()
            ->where(fn ($q) => $q
                ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
                ->when($email, fn ($q) => $q->orWhereRaw('lower(email) = ?', [$email])))
            ->first();
    }

    /** The same reply whether or not an account matched, so the form can't be used to find accounts. */
    private function sent(?SupportTopic $topic = null): RedirectResponse
    {
        $message = $topic === SupportTopic::AccountDeletion
            ? 'We’ve got your request. We’ll contact you on the phone number or email on your account to confirm it’s you before we delete anything.'
            : 'Thanks, we’ve got your message. We usually reply within two working days.';

        return redirect()->to(route('support').'#contact')->with('support_sent', $message);
    }
}
