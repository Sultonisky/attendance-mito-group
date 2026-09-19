<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateOutsourcePerson implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name?: string, status?: string, store_id?: int|null} $input
     */
    public function execute(Outsource $person, array $input, ?User $actor, ?Request $request = null): Outsource
    {
        return DB::transaction(function () use ($person, $input, $actor, $request): Outsource {
            $old = ['name' => $person->name, 'status' => $person->status];

            $fillable = array_filter([
                'name'   => $input['name']   ?? null,
                'status' => $input['status'] ?? null,
            ], fn ($v) => $v !== null);

            if (!empty($fillable)) {
                $person->update($fillable);
            }

            // Update store assignment if store_id is explicitly provided
            if (array_key_exists('store_id', $input)) {
                // Deactivate all existing active assignments
                $person->stores()->updateExistingPivot(
                    $person->stores()->allRelatedIds(),
                    ['status' => 'inactive'],
                );

                if (!empty($input['store_id'])) {
                    // Reactivate or attach new store
                    $existing = $person->stores()->where('work_locations.id', $input['store_id'])->first();
                    if ($existing) {
                        $person->stores()->updateExistingPivot($input['store_id'], ['status' => 'active']);
                    } else {
                        $person->stores()->attach($input['store_id'], ['status' => 'active']);
                    }
                }
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
}
