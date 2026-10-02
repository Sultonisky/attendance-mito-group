<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Switch to per-user access: role permissions become templates only, so copy
 * what each user currently receives through their role onto the user.
 * Nobody gains or loses access. Runs once; later role-template edits do not
 * touch existing users.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');
        $permissionKey = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $roleKey = config('permission.column_names.role_pivot_key') ?? 'role_id';

        DB::table($tables['model_has_permissions'])->insertUsing(
            [$permissionKey, 'model_type', $modelKey],
            DB::table($tables['model_has_roles'].' as mhr')
                ->join($tables['role_has_permissions'].' as rhp', "rhp.{$roleKey}", '=', "mhr.{$roleKey}")
                ->select("rhp.{$permissionKey}", 'mhr.model_type', "mhr.{$modelKey}")
                ->distinct()
                ->whereNotExists(fn (Builder $q) => $q
                    ->from($tables['model_has_permissions'].' as existing')
                    ->whereColumn("existing.{$permissionKey}", "rhp.{$permissionKey}")
                    ->whereColumn('existing.model_type', 'mhr.model_type')
                    ->whereColumn("existing.{$modelKey}", "mhr.{$modelKey}")),
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Copied grants are indistinguishable from later per-user edits.
    }
};
