<?php

namespace App\Filament\Admin\Resources\Automations;

use App\Filament\Admin\Resources\Automations\Pages\ManageAutomations;
use App\Filament\Admin\Resources\Campaigns\CampaignComposer;
use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\SendWindow;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Lifecycle messages (welcome series, event countdowns, inactivity nudges, vendor summary).
 * Who gets them is decided in code (LifecycleAutomations); admins switch them on and write
 * the copy. They go through the same pipeline as campaigns: preferences, quiet hours and caps.
 */
class AutomationResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $modelLabel = 'automation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Campaigns';

    protected static ?int $navigationSort = 3;

    /** Who each automation reaches, for the table. */
    public const WHO = [
        'welcome_day_0' => 'New users, on sign-up day',
        'welcome_day_1' => 'New users, 1 day after sign-up',
        'welcome_day_3' => 'New users, 3 days after sign-up',
        'welcome_day_7' => 'New users, 7 days after sign-up',
        'event_countdown' => 'Hosts and co-hosts, 7, 3 and 1 days before their event',
        'inactive_14d_push' => 'Not seen for 14 days',
        'inactive_30d_sms' => 'Not seen for 30 days',
        'vendor_weekly_summary' => 'Business owners and managers, every Monday',
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasAdminPermission('campaigns');
    }

    public static function canCreate(): bool
    {
        return false; // the set of automations is in code
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('automation');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(CampaignComposer::content(automation: true));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (Campaign $record) => self::WHO[$record->automation] ?? null),
                TextColumn::make('channels')->badge()->formatStateUsing(fn (string $state) => config("eventapp.campaigns.channels.{$state}", $state)),
                TextColumn::make('title')->limit(40)->placeholder('—'),
                TextColumn::make('last_run_at')->label('Last run')->since(SendWindow::timezone())->placeholder('Never'),
                ToggleColumn::make('active')->label('On'),
            ])
            ->defaultSort('name')
            ->paginated(false)
            ->recordActions([
                Action::make('report')->icon(Heroicon::OutlinedChartBar)->color('gray')
                    ->url(fn (Campaign $record) => CampaignResource::getUrl('view', ['record' => $record])),
                EditAction::make()->label('Edit copy'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAutomations::route('/')];
    }
}
