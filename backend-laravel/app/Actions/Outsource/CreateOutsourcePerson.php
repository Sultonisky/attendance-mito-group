<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\Outsource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOutsourcePerson implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name: string, store_id?: int|null} $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): Outsource
    {
        return DB::transaction(function () use ($input, $actor, $request): Outsource {
            $code = $this->generateCode($input['name']);

            $person = Outsource::create([
                'outsource_code' => $code,
                'name'           => $input['name'],
                'status'         => 'active',
            ]);

            // Assign to store if provided
            if (!empty($input['store_id'])) {
                $person->stores()->attach($input['store_id'], ['status' => 'active']);
            }

            $this->audit->execute(
                $actor?->getKey(),
                'outsource_person.created',
                $person,
                null,
                ['outsource_code' => $code, 'name' => $person->name, 'store_id' => $input['store_id'] ?? null],
                $request,
            );

            return $person->load('stores');
        });
    }

    private function generateCode(string $name): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)));
        $slug = rtrim($slug, '-');
        $hash = substr(Str::uuid()->toString(), 0, 8);

        return "{$slug}-{$hash}";
    }
}
