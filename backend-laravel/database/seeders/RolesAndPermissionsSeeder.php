<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deterministic, idempotent RBAC foundation.
 *
 * - Roles: SUPER_ADMIN, ADMIN, USER.
 * - Permissions: minimal module.action set used to prove RBAC. Business
 *   module permissions are added in their own phases.
 *
 * Re-running this seeder never duplicates roles or permissions.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Permission metadata: name => description.
     *
     * @var array<string, string>
     */
    protected const PERMISSION_DESCRIPTIONS = [
        'dashboard.view' => 'View the main dashboard and KPI widgets.',
        'employees.view' => 'View employee master data.',
        'employees.create' => 'Create new employee records.',
        'employees.update' => 'Update employee master data.',
        'employees.delete' => 'Delete employee records.',
        'employees.manage-faces' => 'Enroll and manage employee face biometrics.',
        'face.verify' => 'Run face verification/liveness checks.',
        'penalty.view' => 'View penalty records.',
        'penalty.create' => 'Create manual penalty records.',
        'penalty.adjust' => 'Adjust penalty points or amounts.',
        'penalty.void' => 'Void a penalty record.',
        'leave.view' => 'View leave requests and balances.',
        'leave.create' => 'Create leave requests.',
        'leave.approve' => 'Approve pending leave requests.',
        'leave.reject' => 'Reject pending leave requests.',
        'leave.cancel' => 'Cancel approved leave requests.',
        'overtime.view' => 'View overtime records and requests.',
        'overtime.create' => 'Submit overtime requests.',
        'overtime.approve' => 'Approve overtime requests.',
        'overtime.reject' => 'Reject overtime requests.',
        'overtime.cancel' => 'Cancel approved overtime requests.',
        'monthly_recap.view' => 'View monthly recap reports.',
        'monthly_recap.generate' => 'Generate a new monthly recap.',
        'monthly_recap.review' => 'Review draft monthly recaps.',
        'monthly_recap.finalize' => 'Finalize monthly recaps.',
        'monthly_recap.export' => 'Export finalized monthly recaps.',
        'attendance.view' => 'View internal attendance records.',
        'attendance.create' => 'Create employee attendance records (admin correction).',
        'attendance.update' => 'Update employee attendance records (admin correction).',
        'attendance.void' => 'Void employee attendance records.',
        'attendance.correction.view' => 'View attendance correction requests.',
        'attendance.correction.create' => 'Submit attendance correction requests for forgotten clock in/out.',
        'attendance.correction.approve' => 'Approve attendance correction requests.',
        'attendance.correction.reject' => 'Reject attendance correction requests.',
        'attendance.correction.cancel' => 'Cancel pending attendance correction requests.',
        'outsource_attendance.view' => 'View outsource attendance reports.',
        'outsource_attendance.create' => 'Create outsource attendance records (admin correction).',
        'outsource_attendance.update' => 'Update outsource attendance records (admin correction).',
        'outsource_attendance.void' => 'Void outsource attendance records.',
        'outsource_person.view' => 'View outsource persons.',
        'outsource_person.create' => 'Create outsource persons.',
        'outsource_person.update' => 'Update outsource persons.',
        'outsource_person.delete' => 'Delete outsource persons.',
        'outsource_work_location.view' => 'View outsource work locations.',
        'outsource_work_location.create' => 'Create work locations.',
        'outsource_work_location.update' => 'Update work locations.',
        'outsource_work_location.delete' => 'Delete work locations.',
        'user.view' => 'View users.',
        'user.create' => 'Create new users.',
        'user.update' => 'Update user data, role, status, and permissions.',
        'user.delete' => 'Delete users.',
        'permission.view' => 'View available permissions.',
        'permission.create' => 'Create new permissions.',
        'permission.update' => 'Edit permission names.',
        'permission.delete' => 'Delete permissions.',
        'audit.view' => 'View system audit log records.',
    ];

    /**
     * Minimal permission foundation for Phase 3 (module.action convention).
     *
     * @var list<string>
     */
    protected const PERMISSIONS = [
        'dashboard.view',
        'employees.view',
        'employees.create',
        'employees.update',
        'employees.delete',
        'employees.manage-faces',
        'face.verify',
        'penalty.view',
        'penalty.create',
        'penalty.adjust',
        'penalty.void',
        'leave.view',
        'leave.create',
        'leave.approve',
        'leave.reject',
        'leave.cancel',
        'overtime.view',
        'overtime.create',
        'overtime.approve',
        'overtime.reject',
        'overtime.cancel',
        'monthly_recap.view',
        'monthly_recap.generate',
        'monthly_recap.review',
        'monthly_recap.finalize',
        'monthly_recap.export',
        'attendance.view',
        'attendance.create',
        'attendance.update',
        'attendance.void',
        'attendance.correction.view',
        'attendance.correction.create',
        'attendance.correction.approve',
        'attendance.correction.reject',
        'attendance.correction.cancel',
        'outsource_attendance.view',
        'outsource_attendance.create',
        'outsource_attendance.update',
        'outsource_attendance.void',
        'outsource_person.view',
        'outsource_person.create',
        'outsource_person.update',
        'outsource_person.delete',
        'outsource_work_location.view',
        'outsource_work_location.create',
        'outsource_work_location.update',
        'outsource_work_location.delete',
        'user.view',
        'user.create',
        'user.update',
        'user.delete',
        'permission.view',
        'permission.create',
        'permission.update',
        'permission.delete',
        'audit.view',
    ];

    /**
     * Explicit role -> permission assignments.
     *
     * SUPER_ADMIN is intentionally NOT granted explicit permissions; it
     * bypasses permission checks centrally via Gate::before.
     *
     * @var array<string, list<string>>
     */
    protected const ROLE_PERMISSIONS = [
        'ADMIN' => [
            'dashboard.view',
            'employees.view',
            'employees.create',
            'employees.update',
            'employees.delete',
            'employees.manage-faces',
            'face.verify',
            'penalty.view',
            'penalty.create',
            'penalty.adjust',
            'penalty.void',
            'leave.view',
            'leave.create',
            'leave.approve',
            'leave.reject',
            'leave.cancel',
            'overtime.view',
            'overtime.create',
            'overtime.approve',
            'overtime.reject',
            'overtime.cancel',
            'monthly_recap.view',
            'monthly_recap.generate',
            'monthly_recap.review',
            'monthly_recap.finalize',
            'monthly_recap.export',
            'attendance.view',
            'attendance.create',
            'attendance.update',
            'attendance.void',
            'attendance.correction.view',
            'attendance.correction.create',
            'attendance.correction.approve',
            'attendance.correction.reject',
            'attendance.correction.cancel',
            'outsource_attendance.view',
            'outsource_attendance.create',
            'outsource_attendance.update',
            'outsource_attendance.void',
            'outsource_person.view',
            'outsource_person.create',
            'outsource_person.update',
            'outsource_person.delete',
            'outsource_work_location.view',
            'outsource_work_location.create',
            'outsource_work_location.update',
            'outsource_work_location.delete',
            // Users / Permissions / Audit Logs / Systems stay SUPER_ADMIN-only.
            // Systems is gated by role (superAdminOnly), not a permission.
        ],
        'USER' => [
            'dashboard.view',
            'attendance.view',
            'attendance.correction.view',
            'attendance.correction.create',
            'attendance.correction.cancel',
            'penalty.view',
            'leave.view',
            'leave.create',
            'leave.cancel',
            'overtime.view',
            'overtime.create',
            'overtime.cancel',
            'monthly_recap.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['description' => self::PERMISSION_DESCRIPTIONS[$permission] ?? null],
            );
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'SUPER_ADMIN']);
        $adminRole = Role::firstOrCreate(['name' => 'ADMIN']);
        $userRole = Role::firstOrCreate(['name' => 'USER']);

        // ADMIN gets only explicitly listed permissions (no Permission::all() wildcard).
        // New permissions must be added to ROLE_PERMISSIONS['ADMIN'] or assigned per user.
        $adminRole->syncPermissions(self::ROLE_PERMISSIONS['ADMIN']);
        $userRole->syncPermissions(self::ROLE_PERMISSIONS['USER']);

        // SUPER_ADMIN bypasses checks via Gate::before — role stays empty.
        // Still refresh direct grants on SUPER_ADMIN users so /auth/me permission
        // lists stay complete when new permissions are introduced.
        $superAdminRole->syncPermissions([]);
        $allPermissions = Permission::query()->get();
        User::role('SUPER_ADMIN')->each(function (User $user) use ($allPermissions): void {
            $user->syncPermissions($allPermissions);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
