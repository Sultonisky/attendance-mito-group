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
     * @param array{name: string, store_id?: int|null, pin_ids?: list<int>|null, password?: string|null} $input
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

            // Assign to store if provided
            if (!empty($input['store_id'])) {
                $person->stores()->attach($input['store_id'], ['status' => 'active']);

                if (array_key_exists('pin_ids', $input)) {
                    $this->syncAssignmentPins->execute($person, (int) $input['store_id'], $input['pin_ids'] ?? []);
                }
            }

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_person.created',
                $person,
                null,
                [
                    'outsource_code' => $code,
                    'name' => $person->name,
                    'store_id' => $input['store_id'] ?? null,
                    'pin_ids' => $input['pin_ids'] ?? null,
                ],
                $request,
            );

            return $person->load('stores');
        });
    }
}
