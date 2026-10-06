<?php

namespace App\Jobs;

use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Notification\Models\NotificationDeliveryLog;
use App\Domain\Notification\Models\NotificationInAppMessage;
use App\Domain\Notification\Services\NotificationTemplateService;
use App\Domain\Notification\Services\RecipientLocaleResolver;
use App\Domain\Shared\Support\Messages;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Section 34: the actual send always happens here, in a queued job
 * dispatched with ->afterCommit() — so a slow or failing mail provider (or
 * any other failure below) can never affect the HTTP request or the DB
 * transaction that triggered the event; it only ever updates this job's
 * own DeliveryLog row. Every failure is caught and recorded rather than
 * left to the queue's generic failed-job handling, so FAILED here always
 * means "we know why," not "the job silently vanished."
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(private readonly string $deliveryLogId, private readonly array $context) {}

    public function handle(NotificationTemplateService $templates, RecipientLocaleResolver $locales): void
    {
        $log = NotificationDeliveryLog::query()->withoutGlobalScopes()->find($this->deliveryLogId);
        if (! $log || $log->status !== NotificationDeliveryLog::STATUS_QUEUED) {
            return;
        }

        try {
            $templateVersion = $log->template_configuration_version_id
                ? ConfigurationVersion::query()->find($log->template_configuration_version_id)
                : null;

            if (! $templateVersion) {
                throw new \RuntimeException('No published notification template found for this event.');
            }

            // Rows queued before the locale was recorded resolve the recipient's language now.
            $locale = $log->locale ?? $locales->resolve(array_filter(['user_id' => $log->recipient_user_id, 'email' => $log->recipient_email]), $log->tenant_id);
            $rendered = $templates->render($templateVersion, $log->channel, $this->context, $locale);

            match ($log->channel) {
                'IN_APP' => $this->deliverInApp($log, $rendered),
                'EMAIL' => $this->deliverEmail($log, $rendered, $locale),
                default => throw new \RuntimeException("Unsupported delivery channel '{$log->channel}'."),
            };

            $log->update(['status' => NotificationDeliveryLog::STATUS_SENT, 'sent_at' => now()]);
        } catch (Throwable $e) {
            $log->update(['status' => NotificationDeliveryLog::STATUS_FAILED, 'failed_at' => now(), 'failure_reason' => $e->getMessage()]);
        }
    }

    private function deliverInApp(NotificationDeliveryLog $log, array $rendered): void
    {
        if (! $log->recipient_user_id) {
            throw new \RuntimeException('IN_APP delivery requires a recipient user.');
        }

        NotificationInAppMessage::query()->create([
            'tenant_id' => $log->tenant_id,
            'recipient_user_id' => $log->recipient_user_id,
            'event_code' => $log->event_code,
            'subject' => $rendered['subject'] ?? $log->event_code,
            'body' => $rendered['body'],
        ]);
    }

    private function deliverEmail(NotificationDeliveryLog $log, array $rendered, string $locale): void
    {
        $email = $log->recipient_email ?? User::query()->find($log->recipient_user_id)?->email;
        if (! $email) {
            throw new \RuntimeException('No email address could be resolved for this recipient.');
        }

        $subject = $rendered['subject'] ?? Messages::text('app.labels.notification', [], $locale);
        Mail::raw($rendered['body'], function ($message) use ($email, $subject) {
            $message->to($email)->subject($subject);
        });
    }
}
