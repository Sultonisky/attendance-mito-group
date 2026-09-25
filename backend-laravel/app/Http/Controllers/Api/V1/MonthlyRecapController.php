<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MonthlyRecap\ExportMonthlyRecap;
use App\Actions\MonthlyRecap\ExportMonthlyRecapsBulk;
use App\Actions\MonthlyRecap\FinalizeMonthlyRecap;
use App\Actions\MonthlyRecap\GenerateMonthlyRecap;
use App\Actions\MonthlyRecap\GenerateMonthlyRecapsForPeriod;
use App\Actions\MonthlyRecap\ReopenMonthlyRecap;
use App\Actions\MonthlyRecap\ReviewMonthlyRecap;
use App\Actions\MonthlyRecap\TransitionMonthlyRecapsBulk;
use App\Http\Controllers\Controller;
use App\Http\Requests\MonthlyRecap\ExportMonthlyRecapBulkRequest;
use App\Http\Requests\MonthlyRecap\GenerateMonthlyRecapBulkRequest;
use App\Http\Requests\MonthlyRecap\GenerateMonthlyRecapRequest;
use App\Http\Requests\MonthlyRecap\TransitionMonthlyRecapBulkRequest;
use App\Http\Resources\MonthlyRecapResource;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use App\Models\Outsource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonthlyRecapController extends Controller
{
    public function __construct(
        protected GenerateMonthlyRecap $generateAction,
        protected GenerateMonthlyRecapsForPeriod $generateBulkAction,
        protected ReviewMonthlyRecap $reviewAction,
        protected FinalizeMonthlyRecap $finalizeAction,
        protected ExportMonthlyRecap $exportAction,
        protected ExportMonthlyRecapsBulk $exportBulkAction,
        protected TransitionMonthlyRecapsBulk $transitionBulkAction,
        protected ReopenMonthlyRecap $reopenAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string', Rule::in(['employee', 'outsource'])],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'outsource_id' => ['nullable', 'integer', 'min:1'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $user = $request->user();
        $privileged = $user->can('monthly_recap.generate')
            || $user->can('monthly_recap.review')
            || $user->can('monthly_recap.finalize')
            || $user->can('monthly_recap.export');

        if (! $privileged) {
            $employee = $this->employeeFor($request);
            if ($employee === null) {
                return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
            }

            $query = MonthlyRecap::query()
                ->where('source', 'employee')
                ->where('employee_id', $employee->id);
        } else {
            $query = MonthlyRecap::query();
            $source = $validated['source'] ?? null;
            if ($source !== null) {
                $query->where('source', $source);
            }
            if (! empty($validated['employee_id'])) {
                $query->where('employee_id', (int) $validated['employee_id']);
            }
            if (! empty($validated['outsource_id'])) {
                $query->where('outsource_id', (int) $validated['outsource_id']);
            }
        }

        if (! empty($validated['period'])) {
            $query->where('period', $validated['period']);
        }

        $recaps = $query
            ->with(['employee:id,employee_code,full_name', 'outsource:id,outsource_code,name'])
            ->orderByDesc('period')
            ->paginate(30);

        return MonthlyRecapResource::collection($recaps)->response();
    }

    public function show(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $user = $request->user();
        $privileged = $user->can('monthly_recap.generate')
            || $user->can('monthly_recap.review')
            || $user->can('monthly_recap.finalize')
            || $user->can('monthly_recap.export');

        if (! $privileged) {
            $employee = $this->employeeFor($request);
            if ($employee === null) {
                return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
            }

            if ($monthlyRecap->source !== 'employee'
                || (int) $monthlyRecap->employee_id !== (int) $employee->id) {
                return response()->json(['success' => false, 'error' => 'Monthly recap not found.'], 404);
            }
        }

        $monthlyRecap->load([
            'details',
            'employee:id,employee_code,full_name',
            'outsource:id,outsource_code,name',
        ]);

        return (new MonthlyRecapResource($monthlyRecap))->response();
    }

    public function generate(GenerateMonthlyRecapRequest $request): JsonResponse
    {
        $data = $request->validated();
        $source = $data['source'] ?? 'employee';
        $periodStart = CarbonImmutable::create($data['year'], $data['month'], 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth();

        $privileged = $request->user()->can('monthly_recap.generate');
        $employee = $this->employeeFor($request);

        if ($source === 'outsource') {
            if (! $privileged) {
                return response()->json(['success' => false, 'error' => 'Monthly recap not found.'], 404);
            }

            $outsource = Outsource::query()->whereKey((int) $data['outsource_id'])->first();
            if ($outsource === null) {
                return response()->json(['success' => false, 'error' => 'Outsource not found.'], 404);
            }

            $recap = $this->generateAction->executeForOutsource(
                $outsource,
                $periodStart,
                $periodEnd,
                $request->user(),
                $request,
            );
        } else {
            if ($employee === null && ! $privileged) {
                return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
            }

            $targetEmployee = $employee;
            if ((int) $data['employee_id'] !== (int) ($employee?->id ?? 0)) {
                if (! $privileged) {
                    return response()->json(['success' => false, 'error' => 'Monthly recap not found.'], 404);
                }
                $targetEmployee = Employee::query()->whereKey((int) $data['employee_id'])->first();
            }

            if ($targetEmployee === null) {
                return response()->json(['success' => false, 'error' => 'Employee not found.'], 404);
            }

            $recap = $this->generateAction->execute(
                $targetEmployee,
                $periodStart,
                $periodEnd,
                $request->user(),
                $request,
            );
        }

        $recap->load([
            'details',
            'employee:id,employee_code,full_name',
            'outsource:id,outsource_code,name',
        ]);

        return (new MonthlyRecapResource($recap))->response();
    }

    public function generateBulk(GenerateMonthlyRecapBulkRequest $request): JsonResponse
    {
        $data = $request->validated();
        $source = $data['source'];
        $periodStart = CarbonImmutable::create($data['year'], $data['month'], 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth();
        $force = (bool) ($data['force'] ?? false);

        $result = $this->generateBulkAction->execute(
            source: $source,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            actor: $request->user(),
            request: $request,
            force: $force,
            onlyEmployeeId: isset($data['employee_id']) ? (int) $data['employee_id'] : null,
            onlyOutsourceId: isset($data['outsource_id']) ? (int) $data['outsource_id'] : null,
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function review(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->reviewAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load(['details', 'employee:id,employee_code,full_name', 'outsource:id,outsource_code,name']);

        return (new MonthlyRecapResource($recap))->response();
    }

    public function finalize(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->finalizeAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load(['details', 'employee:id,employee_code,full_name', 'outsource:id,outsource_code,name']);

        return (new MonthlyRecapResource($recap))->response();
    }

    public function export(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->exportAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load(['details', 'employee:id,employee_code,full_name', 'outsource:id,outsource_code,name']);

        return (new MonthlyRecapResource($recap))->response();
    }

    public function exportBulk(ExportMonthlyRecapBulkRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated('ids'));
        $result = $this->exportBulkAction->execute($ids, $request->user(), $request);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function transitionBulk(TransitionMonthlyRecapBulkRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ids = array_map('intval', $data['ids']);
        $result = $this->transitionBulkAction->execute(
            $ids,
            (string) $data['action'],
            $request->user(),
            $request,
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function reopen(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->reopenAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load(['details', 'employee:id,employee_code,full_name', 'outsource:id,outsource_code,name']);

        return (new MonthlyRecapResource($recap))->response();
    }

    private function employeeFor(Request $request): ?Employee
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        return Employee::where('user_id', $user->getKey())->first()
            ?? ($user->employee()->first() ?? Employee::where('email', $user->email)->first());
    }
}
