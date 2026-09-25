<?php

namespace App\Http\Resources\Outsource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutsourcePersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stores = is_array($this->stores_payload ?? null) ? $this->stores_payload : [];
        $primary = $stores[0] ?? null;

        return [
            'id'             => $this->id,
            'outsource_code' => $this->outsource_code,
            'name'           => $this->name,
            'status'         => $this->status,
            'has_password'   => (bool) ($this->has_password ?? false),
            // Primary (first active) for backward-compatible columns.
            'city'           => ($this->city_name ?? $primary['city_name'] ?? null)
                ? [
                    'id' => $this->city_id ?? $primary['city_id'] ?? null,
                    'name' => $this->city_name ?? $primary['city_name'],
                ]
                : null,
            'store'          => ($this->store_name ?? $primary['store_name'] ?? null)
                ? [
                    'id' => $this->store_id ?? $primary['store_id'] ?? null,
                    'name' => $this->store_name ?? $primary['store_name'],
                ]
                : null,
            'stores'         => array_map(static function (array $s): array {
                return [
                    'id' => (int) $s['store_id'],
                    'name' => $s['store_name'],
                    'city' => ($s['city_id'] ?? null)
                        ? ['id' => (int) $s['city_id'], 'name' => $s['city_name'] ?? '']
                        : null,
                    'pin_ids' => array_values($s['pin_ids'] ?? []),
                ];
            }, $stores),
            'store_ids'      => array_values(array_map(
                static fn (array $s) => (int) $s['store_id'],
                $stores,
            )),
            'pin_ids'        => is_array($this->pin_ids ?? null)
                ? array_values($this->pin_ids)
                : array_values(array_unique(array_merge(
                    ...array_map(static fn (array $s) => $s['pin_ids'] ?? [], $stores ?: [[]]),
                ))),
            'created_at'     => $this->created_at
                ? substr((string) $this->created_at, 0, 10)
                : null,
        ];
    }
}
