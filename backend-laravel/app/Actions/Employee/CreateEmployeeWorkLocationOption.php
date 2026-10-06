<?php

namespace App\Actions\Employee;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\EmployeeWorkLocationOption;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeeWorkLocationOption implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param  array{type: string, name: string}  $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): EmployeeWorkLocationOption
    {
        return DB::transaction(function () use ($input, $actor, $request): EmployeeWorkLocationOption {
            $name = preg_replace('/\s+/', ' ', trim($input['name'])) ?? trim($input['name']);
            $normalizedName = mb_strtolower($name);

            if (EmployeeWorkLocationOption::query()
                ->where('type', $input['type'])
                ->where('normalized_name', $normalizedName)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'name' => ['This option already exists.'],
                ]);
            }

            $option = EmployeeWorkLocationOption::create([
                'type' => $input['type'],
                'name' => $name,
                'normalized_name' => $normalizedName,
            ]);

            $this->audit->execute(
                $actor?->getKey(),
                'employee_work_location_option.created',
                $option,
                null,
                ['type' => $option->type, 'name' => $option->name],
                $request,
            );

            return $option->fresh() ?? $option;
        });
    }
}
