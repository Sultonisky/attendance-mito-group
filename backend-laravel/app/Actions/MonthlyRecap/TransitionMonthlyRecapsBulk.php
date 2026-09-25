<?php

namespace App\Actions\MonthlyRecap;

use App\Domain\MonthlyRecap\Exceptions\MonthlyRecapException;
use App\Enums\MonthlyRecapStatus;
use App\Models\MonthlyRecap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bulk lifecycle transition for monthly recaps.
 *
 * All selected rows must share the same status, and the action must be legal
 * for that status. Fail-fast before mutating any row when validation fails.
 */
class TransitionMonthlyRecapsBulk
{
    public const ACTION_REVIEW = 'review';

    public const ACTION_FINALIZE = 'finalize';

    public const ACTION_EXPORT = 'export';

    public const ACTION_REOPEN = 'reopen';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_REVIEW,
        self::ACTION_FINALIZE,
        self::ACTION_EXPORT,
        self::ACTION_REOPEN,
    ];

    /**
     * Required source status(es) for each action.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_FROM = [
        self::ACTION_REVIEW => ['draft'],
        self::ACTION_FINALIZE => ['review'],
        self::ACTION_EXPORT => ['finalized'],
        self::ACTION_REOPEN => ['finalized', 'exported'],
    ];

    public function __construct(
        private ReviewMonthlyRecap $review,
        private FinalizeMonthlyRecap $finalize,
        private ExportMonthlyRecap $export,
        private ReopenMonthlyRecap $reopen,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array{
     *   action: string,
     *   from_status: string,
     *   updated: int,
     *   skipped: int,
     *   failed: int,
     *   failures: list<array{id: int, message: string}>
     * }
     */
    public function execute(array $ids, string $action, User $actor, ?Request $request = null): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new MonthlyRecapException('Invalid bulk status action.');
        }

        $uniqueIds = array_values(array_unique(array_map('intval', $ids)));
        if ($uniqueIds === []) {
            throw new MonthlyRecapException('Select at least one monthly recap.');
        }

        $recaps = MonthlyRecap::query()
            ->whereIn('id', $uniqueIds)
            ->get()
            ->keyBy('id');

        if ($recaps->count() !== count($uniqueIds)) {
            $missing = array_values(array_diff($uniqueIds, $recaps->keys()->all()));
            throw new MonthlyRecapException(
                'Some monthly recaps were not found: #'.implode(', #', $missing).'.'
            );
        }

        $statuses = $recaps
            ->pluck('status')
            ->map(fn ($status) => MonthlyRecapStatus::normalize(is_string($status) ? $status : (string) $status))
            ->unique()
            ->values();
        if ($statuses->count() !== 1) {
            throw new MonthlyRecapException(
                'Bulk status change requires all selected rows to have the same status. Selected statuses: '
                .$statuses->implode(', ').'.'
            );
        }

        $fromStatus = (string) $statuses->first();
        $allowedFrom = self::ALLOWED_FROM[$action] ?? [];
        if (! in_array($fromStatus, $allowedFrom, true)) {
            throw new MonthlyRecapException(
                sprintf(
                    'Action "%s" is not allowed for status "%s". Allowed source status(es): %s.',
                    $action,
                    $fromStatus,
                    implode(', ', $allowedFrom)
                )
            );
        }

        // Repair legacy alias before per-row transitions.
        MonthlyRecap::query()
            ->whereIn('id', $uniqueIds)
            ->where('status', 'reviewed')
            ->update(['status' => MonthlyRecapStatus::Review->value]);
        $recaps = MonthlyRecap::query()->whereIn('id', $uniqueIds)->get()->keyBy('id');

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $failures = [];

        foreach ($uniqueIds as $id) {
            /** @var MonthlyRecap $recap */
            $recap = $recaps->get($id);

            try {
                $before = $recap->status;
                $result = match ($action) {
                    self::ACTION_REVIEW => $this->review->execute($recap, $actor, $request),
                    self::ACTION_FINALIZE => $this->finalize->execute($recap, $actor, $request),
                    self::ACTION_EXPORT => $this->export->execute($recap, $actor, $request),
                    self::ACTION_REOPEN => $this->reopen->execute($recap, $actor, $request),
                };

                if ($result->status === $before) {
                    $skipped++;
                } else {
                    $updated++;
                }
            } catch (MonthlyRecapException $e) {
                $failed++;
                $failures[] = ['id' => $id, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = ['id' => $id, 'message' => $e->getMessage()];
                Log::warning('monthly_recap.bulk_transition_failed', [
                    'monthly_recap_id' => $id,
                    'action' => $action,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'action' => $action,
            'from_status' => $fromStatus,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }
}
