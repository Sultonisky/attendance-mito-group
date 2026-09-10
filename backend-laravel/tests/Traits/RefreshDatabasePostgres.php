<?php

namespace Tests\Traits;

use Illuminate\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the database using migrate:fresh once per test run, then
 * cleans up using TRUNCATE between tests so each test starts from a clean
 * set of tables without wrapping every request in a database transaction.
 *
 * This is required because PostgreSQL does not support transactional DDL.
 * PostGIS operations such as CREATE EXTENSION, geography(Point, 4326)
 * columns, and GIST spatial indexes cannot run inside a transaction and
 * therefore break the standard Laravel RefreshDatabase trait on a PostgreSQL
 * backend.
 */
trait RefreshDatabasePostgres
{
    use CanConfigureMigrationCommands;

    /**
     * Whether migrations have been run for this test run.
     */
    protected bool $migrated = false;

    /**
     * Whether the database has been truncated for the current test.
     */
    protected bool $truncated = false;

    /**
     * {@inheritdoc}
     */
    public function refreshDatabase(): void
    {
        if (! $this->migrated) {
            $this->runMigrations();
            $this->migrated = true;
        }

        $this->beforeApplicationDestroyed(function () {
            $this->migrated = false;
            $this->truncated = false;
        });

        // Truncate tables at the end of each test so the next test starts
        // clean. We call this here instead of in tearDown so that assertions
        // against existence of rows still pass.
    }

    public function tearDown(): void
    {
        if ($this->migrated && $this->truncated === false) {
            $this->truncateDatabase();
            $this->truncated = true;
        }

        parent::tearDown();
    }

    /**
     * Run the full database migration stack once per run.
     */
    protected function runMigrations(): void
    {
        $this->artisan('migrate:fresh', array_merge($this->migrateFreshUsing(), [
            '--force' => true,
            '--no-interaction' => true,
        ]));
    }

    /**
     * Truncate every application table so tests start clean.
     */
    protected function truncateDatabase(): void
    {
        // Disable foreign key checks, truncate, then re-enable.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $tables = [
            'audit_logs',
            'monthly_recap_details',
            'monthly_recaps',
            'penalty_records',
            'penalty_rules',
            'overtime_records',
            'overtime_requests',
            'permission_requests',
            'leave_transactions',
            'leave_balances',
            'leave_requests',
            'leave_types',
            'attendance_verifications',
            'attendance_events',
            'attendance_sessions',
            'attendance_records',
            'employee_face_embeddings',
            'employee_face_profiles',
            'policy_assignments',
            'policies',
            'shifts',
            'work_schedules',
            'schedule_assignments',
            'work_locations',
            'employees',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'permissions',
            'roles',
            'personal_access_tokens',
            'sessions',
            'cache',
            'cache_locks',
        ];

        foreach ($tables as $table) {
            try {
                DB::statement("TRUNCATE TABLE \"{$table}\" CASCADE");
            } catch (\Throwable $e) {
                // Table might not exist in some edge cases; ignore.
            }
        }

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
