<?php

namespace App\Filament\Admin\Resources\Campaigns;

use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignService;
use App\Modules\Campaigns\Services\SendWindow;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Send test, send now, schedule, approve, back to draft and cancel: each goes through CampaignService. */
final class CampaignActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::sendTest(), self::sendNow(), self::schedule(), self::approve(), self::unschedule(), self::cancel()];
    }

    public static function sendTest(): Action
    {
        return Action::make('sendTest')->label('Send test')->icon(Heroicon::OutlinedBeaker)->color('gray')
            ->visible(fn (Campaign $record) => $record->status->isEditable())
            ->action(self::guard(function (Campaign $record) {
                $results = app(CampaignService::class)->sendTest($record, Auth::user());
                Notification::make()->title('Test sent to you')->body(collect($results)->map(fn ($r, $c) => config("eventapp.campaigns.channels.{$c}").": {$r}")->implode("\n"))->success()->send();
            }));
    }

    public static function sendNow(): Action
    {
        return Action::make('sendNow')->label('Send now')->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (Campaign $record) => $record->status === CampaignStatus::Draft && ! $record->isAutomation())
            ->requiresConfirmation()
            ->modalDescription(fn (Campaign $record) => self::confirmation($record))
            ->action(self::guard(fn (Campaign $record) => self::submitted(app(CampaignService::class)->submit($record, Auth::user()))));
    }

    public static function schedule(): Action
    {
        return Action::make('schedule')->icon(Heroicon::OutlinedCalendar)->color('gray')
            ->visible(fn (Campaign $record) => $record->status === CampaignStatus::Draft && ! $record->isAutomation())
            ->schema([
                DateTimePicker::make('at')->label('Send at ('.SendWindow::timezone().')')->seconds(false)
                    ->timezone(SendWindow::timezone())->native(false)->required()->minDate(now()),
            ])
            ->modalDescription(fn (Campaign $record) => self::confirmation($record))
            ->action(self::guard(fn (Campaign $record, array $data) => self::submitted(
                app(CampaignService::class)->submit($record, Auth::user(), CarbonImmutable::parse($data['at'])->utc()),
            )));
    }

    public static function approve(): Action
    {
        return Action::make('approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
            ->visible(fn (Campaign $record) => $record->status === CampaignStatus::PendingApproval && Auth::user()?->isSuperAdmin() && Auth::id() !== $record->created_by)
            ->requiresConfirmation()
            ->modalDescription(fn (Campaign $record) => number_format($record->audience_count).' people'
                .($record->estimated_sms_cost_minor ? ', SMS about '.Money::format($record->estimated_sms_cost_minor, config('eventapp.currency')) : '')
                .', '.($record->scheduled_at?->isFuture() ? 'goes out '.$record->scheduled_at->setTimezone(SendWindow::timezone())->format('j M, H:i') : 'goes out now').'.')
            ->action(self::guard(function (Campaign $record) {
                app(CampaignService::class)->approve($record, Auth::user());
                Notification::make()->title('Approved')->success()->send();
            }));
    }

    public static function unschedule(): Action
    {
        return Action::make('unschedule')->label('Back to draft')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
            ->visible(fn (Campaign $record) => in_array($record->status, [CampaignStatus::PendingApproval, CampaignStatus::Scheduled], true) && ! $record->isAutomation())
            ->requiresConfirmation()
            ->action(self::guard(fn (Campaign $record) => app(CampaignService::class)->unschedule($record)));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->visible(fn (Campaign $record) => $record->status->isEditable() && ! $record->isAutomation())
            ->requiresConfirmation()
            ->action(self::guard(fn (Campaign $record) => app(CampaignService::class)->cancel($record)));
    }

    private static function confirmation(Campaign $record): string
    {
        $estimate = app(CampaignService::class)->estimate($record);
        $reach = collect($estimate['reachable'])->map(fn ($n, $c) => config("eventapp.campaigns.channels.{$c}").' '.number_format($n))->implode(', ');
        $line = number_format($estimate['audience']).' people in the audience. Reachable now: '.$reach.'.';
        if ($estimate['sms_cost_minor'] > 0) {
            $line .= ' SMS: '.$estimate['sms_segments'].' segment(s), about '.Money::format($estimate['sms_cost_minor'], config('eventapp.currency')).'.';
        }
        if (CampaignService::needsApproval($estimate)) {
            $line .= ' This is a large send: a super admin will need to approve it.';
        }

        return $line;
    }

    private static function submitted(Campaign $campaign): void
    {
        $body = match ($campaign->status) {
            CampaignStatus::PendingApproval => 'Waiting for a super admin to approve it.',
            CampaignStatus::Scheduled => 'Goes out '.$campaign->scheduled_at->setTimezone(SendWindow::timezone())->format('j M, H:i').'.',
            default => 'Sending now.',
        };
        Notification::make()->title('Campaign submitted')->body($body)->success()->send();
    }

    /** Validation and permission problems become a notification instead of an error page. */
    private static function guard(Closure $action): Closure
    {
        return function (Campaign $record, array $data = []) use ($action) {
            try {
                $action($record, $data);
            } catch (ValidationException $e) {
                Notification::make()->title('Can’t do that yet')->body(collect($e->errors())->flatten()->implode("\n"))->danger()->send();
            } catch (AuthorizationException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };
    }
}
