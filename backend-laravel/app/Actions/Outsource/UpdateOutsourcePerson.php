<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use App\Models\User;
use App\Models\WorkLocationPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateOutsourcePerson implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
        private readonly SyncOutsourceAssignmentPins $syncAssignmentPins,
    ) {}

    /**
     * @param array{
     *   name?: string,
     *   status?: string,
     *   store_id?: int|null,
     *   store_ids?: list<int>|null,
     *   pin_ids?: list<int>|null,
     *   password?: string|null
     * } $input
     */
    public function execute(Outsource $person, array $input, ?User $actor, ?Request $request = null): Outsource
    {
        return DB::transaction(function () use ($person, $input, $actor, $request): Outsource {
            $old = ['name' => $person->name, 'status' => $person->status];

            $fillable = array_filter([
                'name'   => $input['name']   ?? null,
                'status' => $input['status'] ?? null,
            ], fn ($v) => $v !== null);

            $password = isset($input['password']) ? trim((string) $input['password']) : '';
            if ($password !== '') {
                $fillable['password'] = $password;
            }

            if (! empty($fillable)) {
                $person->update($fillable);
            }

            $activeStoreIds = null;

            if (array_key_exists('store_ids', $input) || array_key_exists('store_id', $input)) {
                $activeStoreIds = $this->normalizeStoreIds($input);

                $person->stores()->updateExistingPivot(
                    $person->stores()->allRelatedIds(),
                    ['status' => 'inactive'],
                );

                foreach ($activeStoreIds as $storeId) {
                    $existing = $person->stores()->where('work_locations.id', $storeId)->first();
                    if ($existing) {
                        $person->stores()->updateExistingPivot($storeId, ['status' => 'active']);
                    } else {
                        $person->stores()->attach($storeId, ['status' => 'active']);
                    }
                }
            } else {
                $activeStoreIds = $person->stores()
                    ->wherePivot('status', 'active')
                    ->pluck('work_locations.id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            if (array_key_exists('pin_ids', $input) && $activeStoreIds !== []) {
                $this->syncPinsAcrossStores($person, $activeStoreIds, $input['pin_ids'] ?? []);
            }

            $person->refresh();

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_person.updated',
                $person,
                $old,
                array_filter($input, fn ($v) => $v !== null),
                $request,
            );

            return $person->load('stores');
        });
    }

    /**
     * @param array{store_id?: int|null, store_ids?: list<int>|null} $input
     * @return list<int>
     */
    private function normalizeStoreIds(array $input): array
    {
        if (array_key_exists('store_ids', $input) && is_array($input['store_ids'])) {
            $ids = array_map('intval', $input['store_ids']);
            if (count($ids) !== count(array_unique($ids))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'store_ids' => ['Duplicate cabang assignments are not allowed.'],
                ]);
            }

            return array_values(array_unique($ids));
        }

        if (! empty($input['store_id'])) {
            return [(int) $input['store_id']];
        }

        return [];
    }

    /**
     * @param  list<int>  $storeIds
     * @param  list<int>  $pinIds
     */
    private function syncPinsAcrossStores(Outsource $person, array $storeIds, array $pinIds): void
    {
        $pinIds = array_values(array_unique(array_map('intval', $pinIds)));

        foreach ($storeIds as $storeId) {
            if ($pinIds === []) {
                $this->syncAssignmentPins->execute($person, $storeId, []);

                continue;
            }

            $pinsForStore = WorkLocationPin::query()
                ->where('work_location_id', $storeId)
                ->whereIn('id', $pinIds)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->syncAssignmentPins->execute($person, $storeId, $pinsForStore);
        }
    }
}
