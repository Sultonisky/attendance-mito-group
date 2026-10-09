<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates Person List records for outsource persons mastered in HRIS.
 *
 * New persons start inactive, without cabang/pin assignments, and with the
 * default login PIN; an administrator activates them after assigning a cabang.
 * Existing records (including soft-deleted ones) are never modified.
 */
class ImportOutsourcePersonsFromHris implements Action
{
    public const STATUSES = ['created', 'skipped', 'conflict', 'would_create'];

    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param  list<array{outsource_id: string, full_name: string}>  $people
     * @return array{
     *   data: list<array{outsource_id: string, status: 'created'|'skipped'|'conflict'|'would_create'}>,
     *   meta: array{processed: int, created: int, skipped: int, conflict: int, would_create: int}
     * }
     */
    public function execute(array $people, bool $dryRun = false, ?Request $request = null): array
    {
        $results = [];
        $meta = ['processed' => 0, 'created' => 0, 'skipped' => 0, 'conflict' => 0, 'would_create' => 0];

        foreach ($people as $person) {
            $code = strtoupper(trim($person['outsource_id']));
            $name = trim($person['full_name']);
            $status = $this->resolveStatus($code, $name, $dryRun, $request);

            $meta[$status]++;
            $meta['processed']++;
            $results[] = ['outsource_id' => $code, 'status' => $status];
        }

        return ['data' => $results, 'meta' => $meta];
    }

    private function resolveStatus(string $code, string $name, bool $dryRun, ?Request $request): string
    {
        $existing = Outsource::withTrashed()->where('outsource_code', $code)->first();
        if ($existing !== null) {
            return ! $existing->trashed() && $this->sameName($existing->name, $name) ? 'skipped' : 'conflict';
        }

        if ($dryRun) {
            return 'would_create';
        }

        try {
            DB::transaction(function () use ($code, $name, $request): void {
                $person = Outsource::create([
                    'outsource_code' => $code,
                    'name' => $name,
                    'password' => Outsource::DEFAULT_LOGIN_PIN,
                    'status' => 'inactive',
                ]);

                $this->audit->execute(
                    null,
                    'outsource_person.created_from_hris',
                    $person,
                    null,
                    ['outsource_code' => $code, 'name' => $name, 'status' => 'inactive'],
                    $request,
                    ['actor_kind' => 'integration', 'source' => 'HRIS'],
                );
            });
        } catch (UniqueConstraintViolationException) {
            return 'skipped';
        }

        return 'created';
    }

    private function sameName(string $left, string $right): bool
    {
        $normalize = static fn (string $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');

        return $normalize($left) === $normalize($right);
    }
}
