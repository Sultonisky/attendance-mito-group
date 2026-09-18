<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MonthlyRecap\ExportMonthlyRecap;
use App\Actions\MonthlyRecap\FinalizeMonthlyRecap;
use App\Actions\MonthlyRecap\GenerateMonthlyRecap;
use App\Actions\MonthlyRecap\ReopenMonthlyRecap;
use App\Actions\MonthlyRecap\ReviewMonthlyRecap;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateMonthlyRecapRequest;
use App\Http\Resources\MonthlyRecapResource;
use App\Models\Employee;
use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthlyRecapController extends Controller
{
    public function __construct(
        protected GenerateMonthlyRecap $generateAction,
        protected ReviewMonthlyRecap $reviewAction,
        protected FinalizeMonthlyRecap $finalizeAction,
        protected ExportMonthlyRecap $exportAction,
        protected ReopenMonthlyRecap $reopenAction,
    ) {}

    public function index(Request $request): JsonResponse
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

            $query = MonthlyRecap::where('employee_id', $employee->id);
        } else {
            $query = MonthlyRecap::query();
        }

        if ($privileged && $request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->integer('employee_id'));
        }

        $recaps = $query->orderByDesc('period')->paginate(30);

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

            if ((int) $monthlyRecap->employee_id !== (int) $employee->id) {
                return response()->json(['success' => false, 'error' => 'Monthly recap not found.'], 404);
            }
        }

        return (new MonthlyRecapResource($monthlyRecap))->response();
    }

    public function generate(GenerateMonthlyRecapRequest $request): JsonResponse
    {
        $data = $request->validated();
        $periodStart = CarbonImmutable::create($data['year'], $data['month'], 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth();

        $privileged = $request->user()->can('monthly_recap.generate');
        $employee = $this->employeeFor($request);

        if ($employee === null && ! $privileged) {
            return response()->json(['success' => false, 'error' => 'Employee record not found.'], 404);
        }

        $targetEmployee = $employee;
        if ($data['employee_id'] !== ($employee?->id ?? 0)) {
            if (! $privileged) {
                return response()->json(['success' => false, 'error' => 'Monthly recap not found.'], 404);
            }
            $targetEmployee = Employee::where('id', (int) $data['employee_id'])->first();
        }

        if ($targetEmployee === null) {
            return response()->json(['success' => false, 'error' => 'Employee not found.'], 404);
        }

        $recap = $this->generateAction->execute($targetEmployee, $periodStart, $periodEnd, $request->user(), $request);
        $recap->load('details');

        return (new MonthlyRecapResource($recap))->response();
    }

    public function review(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->reviewAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load('details');

        return (new MonthlyRecapResource($recap))->response();
    }

    public function finalize(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->finalizeAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load('details');

        return (new MonthlyRecapResource($recap))->response();
    }

    public function export(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->exportAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load('details');

        return (new MonthlyRecapResource($recap))->response();
    }

    public function reopen(Request $request, MonthlyRecap $monthlyRecap): JsonResponse
    {
        $recap = $this->reopenAction->execute($monthlyRecap, $request->user(), $request);
        $recap->load('details');

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
