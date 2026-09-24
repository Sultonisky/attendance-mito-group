<?php

namespace App\Services\Notification;

use App\Models\LeaveRequest;
use App\Models\MonthlyRecap;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\AdminSystemAlert;
use Carbon\CarbonImmutable;

/**
 * Upserts today's operational alerts into the SUPER_ADMIN notification inbox.
 *
 * Idempotent per (user, alert_key, day) so repeated polls do not spam.
 */
class SyncAdminNotificationFeed
{
    public function execute(User $user): void
    {
        if (! $user->hasRole('SUPER_ADMIN')) {
            return;
        }

        $today = CarbonImmutable::now()->toDateString();

        foreach ($this->buildAlerts($today) as $alert) {
            $this->upsertAlert($user, $alert, $today);
        }
    }

    /**
     * @return list<array{alert_key: string, sender: string, icon: string, body: string, href: string|null}>
     */
    private function buildAlerts(string $today): array
    {
        $alerts = [];

        $pendingLeave = LeaveRequest::query()->where('status', 'pending')->count();
        if ($pendingLeave > 0) {
            $alerts[] = [
                'alert_key' => "leave.pending.{$today}",
                'sender' => 'Leave Module',
                'icon' => 'i-lucide-calendar-off',
                'body' => $pendingLeave === 1
                    ? '1 leave request is pending approval.'
                    : "{$pendingLeave} leave requests are pending approval.",
                'href' => '/dashboard/reports/leave',
            ];
        }

        $pendingOvertime = OvertimeRequest::query()->where('status', 'pending')->count();
        if ($pendingOvertime > 0) {
            $alerts[] = [
                'alert_key' => "overtime.pending.{$today}",
                'sender' => 'Overtime Module',
                'icon' => 'i-lucide-clock-arrow-up',
                'body' => $pendingOvertime === 1
                    ? '1 overtime request is pending approval.'
                    : "{$pendingOvertime} overtime requests are pending approval.",
                'href' => '/dashboard/reports/overtime',
            ];
        }

        $recapsInReview = MonthlyRecap::query()->where('status', 'review')->count();
        if ($recapsInReview > 0) {
            $alerts[] = [
                'alert_key' => "monthly_recap.review.{$today}",
                'sender' => 'Monthly Recap',
                'icon' => 'i-lucide-file-text',
                'body' => $recapsInReview === 1
                    ? '1 monthly recap is ready for review.'
                    : "{$recapsInReview} monthly recaps are ready for review.",
                'href' => '/dashboard/reports/monthly-recaps',
            ];
        }

        return $alerts;
    }

    /**
     * @param  array{alert_key: string, sender: string, icon: string, body: string, href: string|null}  $alert
     */
    private function upsertAlert(User $user, array $alert, string $today): void
    {
        // Filter alert_key in PHP: notification `data` may be text/json/jsonb
        // depending on DB driver, so avoid SQL JSON operators here.
        $existing = $user->notifications()
            ->where('type', AdminSystemAlert::class)
            ->whereDate('created_at', $today)
            ->get()
            ->first(function ($notification) use ($alert) {
                $data = is_array($notification->data) ? $notification->data : [];

                return ($data['alert_key'] ?? null) === $alert['alert_key'];
            });

        if ($existing !== null) {
            if ($existing->read_at === null) {
                $existing->forceFill(['data' => $alert])->save();
            }

            return;
        }

        $user->notify(new AdminSystemAlert($alert));
    }
}
