<?php

namespace App\Filament\Admin\Resources\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSegment;
use App\Modules\Campaigns\Services\DeepLinks;
use App\Modules\Campaigns\Services\MessageRenderer;
use App\Modules\Campaigns\Services\SegmentQuery;
use App\Modules\Campaigns\Services\SmsCounter;
use App\Modules\Catalog\Models\Item;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\Media;
use App\Modules\Social\Models\Post;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** The message part of the campaign form, shared by campaigns and automations. */
final class CampaignComposer
{
    /** @return list<Component|Field> */
    public static function content(bool $automation = false): array
    {
        $has = fn (Get $get, string ...$channels) => array_intersect($channels, $get('channels') ?? []) !== [];
        $tokens = 'Personalise with '.implode(' ', $automation ? MessageRenderer::TOKENS : array_slice(MessageRenderer::TOKENS, 0, 3)).'.';

        return [
            Section::make('Message')->columns(2)->schema([
                CheckboxList::make('channels')->options(config('eventapp.campaigns.channels'))->required()->live()->columns(3),
                Select::make('category')->options(config('eventapp.campaigns.categories'))->required()
                    ->helperText('People can turn each category off per channel in the app.'),
                TextInput::make('title')->maxLength(120)->live(onBlur: true)->columnSpanFull()
                    ->required(fn (Get $get) => $has($get, 'push', 'in_app'))
                    ->visible(fn (Get $get) => $has($get, 'push', 'in_app')),
                Textarea::make('body')->required()->rows(3)->maxLength(1000)->live(onBlur: true)->columnSpanFull()
                    ->helperText($tokens),
                Textarea::make('sms_body')->label('SMS text')->rows(3)->maxLength(1000)->live(onBlur: true)->columnSpanFull()
                    ->placeholder('Same as the message')
                    ->helperText(fn (Get $get) => self::smsSummary($get))
                    ->visible(fn (Get $get) => $has($get, 'sms')),
                Select::make('deep_link.type')->label('Opens')->options(DeepLinks::options())->placeholder('Just the app')->live(),
                Select::make('deep_link.id')->label(fn (Get $get) => config('eventapp.campaigns.deep_links.'.$get('deep_link.type').'.label', 'Target'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search, Get $get) => self::searchTargets((string) $get('deep_link.type'), $search))
                    ->getOptionLabelUsing(fn ($value, Get $get) => self::targetLabel((string) $get('deep_link.type'), (string) $value))
                    ->required(fn (Get $get) => ! $automation && DeepLinks::needsId($get('deep_link.type')))
                    ->helperText($automation ? 'Leave empty on an event link to open the recipient’s own event.' : null)
                    ->visible(fn (Get $get) => DeepLinks::needsId($get('deep_link.type'))),
                Select::make('image_media_id')->label('Push picture')
                    ->options(fn (?Campaign $record) => self::imageOptions($record))
                    ->placeholder('None')
                    ->helperText('A ready image you uploaded from the app.')
                    ->visible(fn (Get $get) => $has($get, 'push')),
            ]),
            Section::make('Preview')->schema([
                View::make('filament.campaigns.preview')->viewData(fn (Get $get) => self::preview($get)),
            ]),
        ];
    }

    /** "112 characters · 1 segment (GSM-7) · about GHS 4.48 for 112 people" */
    public static function smsSummary(Get $get): string
    {
        $text = MessageRenderer::sample((string) ($get('sms_body') ?: $get('body')));
        $category = (string) $get('category');
        if (in_array($category, config('eventapp.campaigns.marketing_categories'), true)) {
            $text = rtrim($text)."\n".config('eventapp.campaigns.sms.opt_out_line');
        }
        $count = SmsCounter::count($text);
        $summary = number_format($count['units']).' characters · '.$count['segments'].' '.Str::plural('segment', $count['segments'])
            .' ('.($count['encoding'] === 'gsm7' ? 'GSM-7, 160 per message' : 'Unicode, 70 per message').')'
            .' · '.Money::format(SmsCounter::costMinor($count['segments']), config('eventapp.currency')).' each';

        $segment = $get('segment_id') ? CampaignSegment::find($get('segment_id')) : null;
        if ($segment) {
            $people = SegmentQuery::count($segment->filters ?? []);
            $summary .= ' · at most '.Money::format(SmsCounter::costMinor($count['segments'], $people), config('eventapp.currency')).' for '.number_format($people).' people';
        }

        return $summary.'. The opt-out line is added to marketing SMS.';
    }

    /** @return array<string, mixed> for the preview view */
    public static function preview(Get $get): array
    {
        $channels = $get('channels') ?? [];

        return [
            'title' => MessageRenderer::sample((string) $get('title')),
            'body' => MessageRenderer::sample((string) $get('body')),
            'sms' => in_array('sms', $channels, true) ? MessageRenderer::sample((string) ($get('sms_body') ?: $get('body'))) : null,
            'push' => in_array('push', $channels, true),
            'in_app' => in_array('in_app', $channels, true),
            'image' => $get('image_media_id') ? Media::find($get('image_media_id'))?->url('feed') : null,
            'link' => DeepLinks::path(['type' => $get('deep_link.type'), 'id' => $get('deep_link.id')], ['event_id' => '…']),
        ];
    }

    /** @return array<string, string> */
    private static function imageOptions(?Campaign $record): array
    {
        return Media::query()
            ->where('owner_id', Auth::id())->where('kind', 'image')->where('status', MediaStatus::Ready->value)
            ->where(fn ($q) => $q->whereNull('attachable_type')
                ->orWhere(fn ($q) => $q->where('attachable_type', 'campaign')->where('attachable_id', $record?->id)))
            ->latest()->limit(50)->get()
            ->mapWithKeys(fn (Media $m) => [$m->id => 'Image from '.$m->created_at->format('j M Y, H:i')])
            ->all();
    }

    /** @return array<string, string> */
    private static function searchTargets(string $type, string $search): array
    {
        $like = '%'.$search.'%';

        return match ($type) {
            'event' => Event::where('title', 'like', $like)->latest('starts_at')->limit(20)->pluck('title', 'id')->all(),
            'vendor' => Vendor::where('name', 'like', $like)->limit(20)->pluck('name', 'id')->all(),
            'item' => Item::where('title', 'like', $like)->limit(20)->pluck('title', 'id')->all(),
            'post' => Post::where('caption', 'like', $like)->latest()->limit(20)->get(['id', 'caption'])
                ->mapWithKeys(fn ($p) => [$p->id => Str::limit((string) $p->caption, 60)])->all(),
            default => [],
        };
    }

    private static function targetLabel(string $type, string $id): ?string
    {
        return match ($type) {
            'event' => Event::find($id)?->title,
            'vendor' => Vendor::find($id)?->name,
            'item' => Item::find($id)?->title,
            'post' => Str::limit((string) Post::find($id)?->caption, 60),
            default => null,
        };
    }
}
