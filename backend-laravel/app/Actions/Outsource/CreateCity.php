<?php

namespace App\Actions\Outsource;

use App\Actions\Action;
use App\Actions\Audit\RecordAuditAction;
use App\Models\City;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCity implements Action
{
    public function __construct(
        private readonly RecordAuditAction $audit,
    ) {}

    /**
     * @param array{name: string, code?: string|null} $input
     */
    public function execute(array $input, ?User $actor, ?Request $request = null): City
    {
        return DB::transaction(function () use ($input, $actor, $request): City {
            $name = $this->normalizeName((string) $input['name']);
            $code = isset($input['code']) && trim((string) $input['code']) !== ''
                ? strtoupper(trim((string) $input['code']))
                : null;

            $existing = City::withTrashed()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing && ! $existing->trashed()) {
                throw ValidationException::withMessages([
                    'name' => ['A city with this name already exists.'],
                ]);
            }

            if ($existing && $existing->trashed()) {
                $existing->restore();
                $existing->name = $name;
                $existing->status = 'active';
                if ($code !== null) {
                    $existing->code = $code;
                } elseif (empty($existing->code)) {
                    $existing->code = $this->generateCityCode($name);
                }
                $existing->save();

                $this->audit->execute(
                    $actor?->getKey(),
                    'city.restored',
                    $existing,
                    null,
                    ['name' => $existing->name, 'code' => $existing->code, 'status' => $existing->status],
                    $request,
                );

                return $existing->refresh();
            }

            $city = City::create([
                'name' => $name,
                'code' => $code ?? $this->generateCityCode($name),
                'status' => 'active',
            ]);

            $this->audit->execute(
                $actor?->getKey(),
                'city.created',
                $city,
                null,
                ['name' => $city->name, 'code' => $city->code, 'status' => $city->status],
                $request,
            );

            return $city->fresh();
        });
    }

    private function normalizeName(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    }

    private function generateCityCode(string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9]+/', '-', strtoupper(trim($name)));
        $base = trim((string) $base, '-');

        if ($base === '') {
            $base = 'CITY';
        }

        $candidate = sprintf('%s-%s', $base, substr(md5($name), 0, 6));
        $suffix = 0;
        while (City::withTrashed()->where('code', $candidate)->exists()) {
            $suffix++;
            $candidate = sprintf('%s-%s-%d', $base, substr(md5($name), 0, 6), $suffix);
        }

        return $candidate;
    }
}
