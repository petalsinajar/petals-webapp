<?php

namespace App\Filament\Admin\Resources\Campaigns;

use App\Filament\Admin\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Admin\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Admin\Resources\Campaigns\Pages\ViewCampaign;
use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSegment;
use App\Modules\Campaigns\Services\CampaignReport;
use App\Modules\Campaigns\Services\SendWindow;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Push, SMS and in-app campaigns to saved audiences. Admins with the "campaigns" permission
 * write and send them; sends over the approval limits wait for a second, super, admin.
 * Booking alerts are transactional and never sent from here.
 */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Campaigns';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasAdminPermission('campaigns');
    }

    public static function canEdit($record): bool
    {
        return $record->status === CampaignStatus::Draft && ! $record->isAutomation();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Campaign')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(120)->helperText('Only admins see this.'),
                Select::make('segment_id')->label('Audience')->required()->live()
                    ->options(fn () => CampaignSegment::orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Make audiences under Campaigns → Audiences.'),
                Select::make('recurrence')->label('Repeats')->placeholder('Once')
                    ->options(['daily' => 'Every day (digest)', 'weekly' => 'Every week (digest)']),
            ]),
            ...CampaignComposer::content(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('automation')->withCount([
                'recipients as reached_count' => fn ($r) => $r->whereIn('status', ['sent', 'delivered']),
                'recipients as opened_count' => fn ($r) => $r->whereNotNull('opened_at'),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Campaign $record) => $record->title),
                TextColumn::make('status')->badge()->formatStateUsing(fn (CampaignStatus $state) => str_replace('_', ' ', ucfirst($state->value)))
                    ->color(fn (CampaignStatus $state) => match ($state) {
                        CampaignStatus::Sent => 'success',
                        CampaignStatus::PendingApproval => 'warning',
                        CampaignStatus::Sending, CampaignStatus::Scheduled => 'info',
                        CampaignStatus::Cancelled => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('channels')->badge()->formatStateUsing(fn (string $state) => config("eventapp.campaigns.channels.{$state}", $state)),
                TextColumn::make('segment.name')->label('Audience'),
                TextColumn::make('scheduled_at')->label('Send at')->dateTime('j M Y, H:i', SendWindow::timezone())->sortable(),
                TextColumn::make('reached_count')->label('Sent')->numeric(),
                TextColumn::make('opened_count')->label('Opened')->numeric(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(CampaignStatus::cases())->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Report'),
                EditAction::make(),
                ActionGroup::make(CampaignActions::all()),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Several entries read it: work it out once per page load.
        $cache = [];
        $report = function (Campaign $record) use (&$cache) {
            return $cache[$record->id] ??= app(CampaignReport::class)->for($record);
        };

        return $schema->columns(1)->components([
            Section::make('Campaign')->columns(4)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (CampaignStatus $state) => str_replace('_', ' ', ucfirst($state->value))),
                TextEntry::make('category')->formatStateUsing(fn (string $state) => config("eventapp.campaigns.categories.{$state}", $state)),
                TextEntry::make('scheduled_at')->label('Send at')->dateTime('j M Y, H:i', SendWindow::timezone())->placeholder('—'),
                TextEntry::make('approver.display_name')->label('Approved by')->placeholder(fn (Campaign $record) => $record->requires_approval ? 'Waiting' : 'Not needed'),
                TextEntry::make('title')->columnSpan(2)->placeholder('—'),
                TextEntry::make('body')->columnSpan(2),
            ]),
            Section::make('Results')->columns(4)->schema([
                TextEntry::make('report_audience')->label('Audience')
                    ->state(fn (Campaign $record) => number_format($report($record)['audience'])),
                TextEntry::make('report_bookings')->label('Bookings within 7 days')
                    ->state(fn (Campaign $record) => number_format($report($record)['bookings'])),
                TextEntry::make('report_cost')->label('SMS cost')
                    ->state(fn (Campaign $record) => Money::format($report($record)['sms_cost_minor'], config('eventapp.currency'))),
                TextEntry::make('report_skipped')->label('Skipped')
                    ->state(fn (Campaign $record) => collect($report($record)['skipped'])->map(fn ($n, $why) => "{$why}: ".number_format($n))->implode(' · ') ?: 'None'),
                TextEntry::make('report_channels')->label('By channel')->columnSpanFull()->listWithLineBreaks()
                    ->state(fn (Campaign $record) => collect($report($record)['channels'])->map(fn (array $c, string $channel) => config("eventapp.campaigns.channels.{$channel}").': '
                        .number_format($c['sent']).' sent, '.number_format($c['delivered']).' delivered, '.number_format($c['failed']).' failed, '
                        .number_format($c['skipped']).' skipped, '.number_format($c['queued']).' waiting'
                        .($channel === 'sms' ? '' : ', '.number_format($c['opened']).' opened, '.number_format($c['clicked']).' tapped'))->values()->all()),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
