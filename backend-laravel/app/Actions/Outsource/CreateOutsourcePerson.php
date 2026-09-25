<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateOutsourcePerson implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
        private readonly SyncOutsourceAssignmentPins $syncAssignmentPins,
    ) {}

    /**
     * @param array{
     *   name: string,
     *   store_id?: int|null,
     *   store_ids?: list<int>|null,
     *   pin_ids?: list<int>|null,
     *   password?: string|null
     * } $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): Outsource
    {
        return DB::transaction(function () use ($input, $actor, $request): Outsource {
            $code = Outsource::generateNextCode();
            $password = trim((string) ($input['password'] ?? ''));

            $person = Outsource::create([
                'outsource_code' => $code,
                'name'           => $input['name'],
                'password'       => $password !== '' ? $password : Outsource::DEFAULT_LOGIN_PIN,
                'status'         => 'active',
            ]);

            $storeIds = $this->normalizeStoreIds($input);

            foreach ($storeIds as $storeId) {
                $person->stores()->attach($storeId, ['status' => 'active']);
            }

            if (array_key_exists('pin_ids', $input) && $storeIds !== []) {
                $this->syncPinsAcrossStores($person, $storeIds, $input['pin_ids'] ?? []);
            }

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_person.created',
                $person,
                null,
                [
                    'outsource_code' => $code,
                    'name' => $person->name,
                    'store_ids' => $storeIds,
                    'pin_ids' => $input['pin_ids'] ?? null,
                ],
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
            // Empty global list => all pins per cabang.
            if ($pinIds === []) {
                $this->syncAssignmentPins->execute($person, $storeId, []);

                continue;
            }

            $pinsForStore = \App\Models\WorkLocationPin::query()
                ->where('work_location_id', $storeId)
                ->whereIn('id', $pinIds)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // No selected pins for this cabang => allow all pins of that cabang.
            $this->syncAssignmentPins->execute($person, $storeId, $pinsForStore);
        }
    }
}
