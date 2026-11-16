<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventMember;
use App\Modules\Events\Models\EventType;
use App\Modules\Events\Services\EventInvites;
use App\Modules\Events\Services\NearbyEvents;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Services\MediaLibrary;
use App\Support\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function __construct(private readonly MediaLibrary $media) {}

    /** POST /v1/events */
    public function store(Request $request): EventResource
    {
        $data = $request->validate($this->rules());
        $user = $request->user();
        $cover = $this->media->resolveOne($data['cover_media_id'] ?? null, $user, null, 'cover_media_id');
        $data['cover_media_id'] = $cover?->id;

        $event = DB::transaction(function () use ($data, $user, $cover) {
            $event = new Event($data);
            $event->host_id = $user->id;
            $event->currency ??= config('eventapp.currency');
            $event->slug = Str::slug(Str::limit($data['title'], 60, '')).'-'.Str::lower(Str::random(6));
            $event->status = $event->starts_at->isFuture() ? 'planning' : 'completed';
            $event->going_count = 1;
            $event->save();

            $event->members()->create(['user_id' => $user->id, 'role' => 'host', 'rsvp' => 'going']);
            $this->media->attach([$cover], $event);

            return $event;
        });

        return new EventResource($event->load('host'));
    }

    /**
     * GET /v1/events?q= — upcoming events by title: public ones, and any the viewer belongs to.
     * Soonest first, one page.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $like = '%'.$data['q'].'%';
        $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $mine = EventMember::query()->where('user_id', $request->user()->id);

        $events = Event::query()
            ->where('title', $op, $like)
            ->where('ends_at', '>=', now())
            ->where(fn ($q) => $q->where('visibility', 'public')->orWhereIn('id', $mine->clone()->select('event_id')))
            ->addSelect(['events.*', 'my_rsvp' => $mine->clone()->select('rsvp')->whereColumn('event_id', 'events.id')->limit(1)])
            ->with('host')
            ->orderBy('starts_at')->orderBy('id')
            ->limit(20)->get();

        return Paginated::manual($events, null, EventResource::class);
    }

    /**
     * GET /v1/events/nearby?lat=&lng= — "Happening near you". Works signed out. See
     * NearbyEvents for what counts as near.
     */
    public function nearby(Request $request, NearbyEvents $nearby): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $events = $nearby->for(
            $request->user(),
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return Paginated::manual($events, null, EventResource::class);
    }

    /** GET /v1/events/{event} */
    public function show(Request $request, Event $event): EventResource
    {
        Gate::authorize('view', $event);
        $event->setAttribute('my_rsvp', $event->memberFor($request->user())?->rsvp);

        return new EventResource($event->load('host'));
    }

    /** PATCH /v1/events/{event} */
    public function update(Request $request, Event $event): EventResource
    {
        Gate::authorize('manage', $event);
        $data = $request->validate($this->rules(partial: true));
        $cover = array_key_exists('cover_media_id', $data)
            ? $this->media->resolveOne($data['cover_media_id'], $request->user(), null, 'cover_media_id', for: $event)
            : false;
        if ($cover !== false) {
            $data['cover_media_id'] = $cover?->id;
        }

        DB::transaction(function () use ($event, $data, $cover) {
            $event->fill($data)->save();
            if ($cover !== false) {
                $this->media->attach([$cover], $event);
                $this->media->releaseExcept($event, [$event->cover_media_id]);
                $event->unsetRelation('cover');
            }
        });

        return new EventResource($event->load('host'));
    }

    /** GET /v1/me/events?scope=upcoming|past */
    public function mine(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'upcoming');
        $mine = EventMember::query()->where('user_id', $request->user()->id);

        $events = Event::query()
            ->whereIn('id', $mine->clone()->select('event_id'))
            // How I answered, so the app can sort invites from hosting.
            ->addSelect(['my_rsvp' => $mine->clone()->select('rsvp')->whereColumn('event_id', 'events.id')->limit(1)])
            ->when($scope === 'past', fn ($q) => $q->where('ends_at', '<', now())->orderByDesc('starts_at')->orderByDesc('id'))
            ->when($scope !== 'past', fn ($q) => $q->where('ends_at', '>=', now())->orderBy('starts_at')->orderBy('id'))
            ->with('host')
            ->cursorPaginate(20);

        return Paginated::response($events, EventResource::class);
    }

    /** GET /v1/events/{event}/members */
    public function members(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('view', $event);

        $members = $event->members()->with('user')->whereNotNull('user_id')
            ->when($request->query('rsvp'), fn ($q, $rsvp) => $q->where('rsvp', $rsvp))
            ->orderBy('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => collect($members->items())->map(fn (EventMember $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'rsvp' => $m->rsvp,
                'plus_ones' => $m->plus_ones,
                'user' => (new UserResource($m->user))->resolve(),
            ]),
            'next_cursor' => $members->nextCursor()?->encode(),
        ]);
    }

    /**
     * POST /v1/events/{event}/members — invite guests or co-hosts by user id, or by phone for
     * people not on the app yet. Inviting a guest as a co-host promotes them.
     */
    public function invite(Request $request, Event $event, EventInvites $invites): JsonResponse
    {
        Gate::authorize('manage', $event);
        $data = $request->validate([
            'invites' => ['required', 'array', 'min:1', 'max:200'],
            'invites.*.user_id' => ['nullable', 'string', 'size:26', 'exists:users,id'],
            'invites.*.phone' => ['nullable', 'string', 'max:20', 'required_without:invites.*.user_id'],
            'invites.*.role' => ['nullable', Rule::in(['guest', 'cohost'])],
        ]);

        return response()->json(['invited' => $invites->invite($event, $request->user(), $data['invites'])], 201);
    }

    /**
     * PATCH /v1/events/{event}/members/{member} { role: guest|cohost } — only the event's
     * creator makes or unmakes co-hosts.
     */
    public function updateMember(Request $request, Event $event, EventMember $member): JsonResponse
    {
        abort_unless($member->event_id === $event->id, 404);
        abort_unless($request->user()->id === $event->host_id, 403, 'Only the host can change co-hosts.');
        abort_if($member->user_id === $event->host_id, 422, 'The host stays the host.');
        $data = $request->validate(['role' => ['required', Rule::in(['guest', 'cohost'])]]);

        $member->update(['role' => $data['role']]);

        return response()->json(['id' => $member->id, 'role' => $member->role]);
    }

    /**
     * DELETE /v1/events/{event}/members/{member} — the host removes anyone but themself;
     * co-hosts remove guests.
     */
    public function removeMember(Request $request, Event $event, EventMember $member): JsonResponse
    {
        abort_unless($member->event_id === $event->id, 404);
        Gate::authorize('manage', $event);
        abort_if($member->user_id === $event->host_id, 422, 'The host can\'t be removed.');
        abort_if($member->role === 'cohost' && $request->user()->id !== $event->host_id, 403, 'Only the host can remove co-hosts.');

        $member->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /** PUT /v1/events/{event}/rsvp */
    public function rsvp(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('rsvp', $event);
        $data = $request->validate([
            'rsvp' => ['required', Rule::in(['going', 'maybe', 'declined'])],
            'plus_ones' => ['nullable', 'integer', 'between:0,10'],
        ]);

        DB::transaction(function () use ($event, $request, $data) {
            $member = EventMember::lockForUpdate()->firstOrNew(['event_id' => $event->id, 'user_id' => $request->user()->id]);
            $was = $member->exists ? $member->rsvp : null;
            $member->fill(['rsvp' => $data['rsvp'], 'plus_ones' => $data['plus_ones'] ?? 0]);
            $member->role ??= 'guest';
            $member->save();

            // Move the guest between the going / maybe / declined counters.
            if ($was !== $data['rsvp']) {
                $query = $event->newQuery()->whereKey($event->id);
                if (in_array($was, ['going', 'maybe', 'declined'], true)) {
                    $query->clone()->decrement("{$was}_count");
                }
                $query->increment("{$data['rsvp']}_count");
            }
        });

        return response()->json(['rsvp' => $data['rsvp']]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'type' => [$req, EventType::activeRule()],
            'title' => [$req, 'string', 'max:140'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cover_media_id' => ['sometimes', 'nullable', 'ulid'], // a ready image from POST /v1/uploads
            'starts_at' => [$req, 'date'],
            'ends_at' => [$req, 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'timezone:all'],
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:140'],
            'venue_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'venue_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'venue_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'guest_count' => ['sometimes', 'nullable', 'integer', 'between:1,100000'],
            'budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'visibility' => ['sometimes', Rule::in(Event::VISIBILITIES)],
            'theme' => ['sometimes', 'nullable', 'array:colors,tags'],
            'company' => ['sometimes', 'nullable', 'array:name,tax_id,address'],
            'status' => $partial ? ['sometimes', Rule::in(['planning', 'upcoming', 'cancelled'])] : ['prohibited'],
        ];
    }
}
