<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * system:scan-health — watches the machine rather than the meters.
 *
 * The load-bearing behaviour is that a system alert carries NO device: it must
 * persist with device_id = NULL, must not duplicate while a condition persists,
 * and must resolve once the condition clears. Thresholds are config-driven
 * precisely so a test can force a condition without touching the real disk.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class SystemHealthAlertCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 12:00:00');

        // No systemd under test. An empty list disables the service check, which
        // is the documented way to run this on a machine without it.
        config(['system-health.services' => []]);

        // Keep the unrelated checks quiet so each test asserts one thing.
        $this->silenceDisk();
        $this->silenceFailedJobs();
        $this->silenceLogs();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function silenceDisk(): void
    {
        // A volume can never be more than 100% used.
        config(['system-health.disk.warning_percent' => 101]);
        config(['system-health.disk.critical_percent' => 101]);
    }

    private function silenceFailedJobs(): void
    {
        config(['system-health.failed_jobs.warning' => PHP_INT_MAX]);
        config(['system-health.failed_jobs.critical' => PHP_INT_MAX]);
    }

    private function silenceLogs(): void
    {
        config(['system-health.log_paths' => []]);
    }

    public function test_disk_pressure_opens_a_system_alert_with_no_device(): void
    {
        // 0% used is always met, so the condition is guaranteed to hold.
        config(['system-health.disk.warning_percent' => 0]);
        config(['system-health.disk.critical_percent' => 101]);

        $this->artisan('system:scan-health')->assertSuccessful();

        $alert = AlertEvent::where('alert_type', 'system_disk_space')->sole();

        $this->assertNull($alert->device_id, 'a system alert must not belong to a device');
        $this->assertSame('system', $alert->device_type);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('open', $alert->status);
        $this->assertArrayHasKey('used_percent', $alert->context);
    }

    public function test_disk_pressure_escalates_to_critical(): void
    {
        config(['system-health.disk.warning_percent' => 0]);
        config(['system-health.disk.critical_percent' => 0]);

        $this->artisan('system:scan-health')->assertSuccessful();

        $this->assertSame(
            'critical',
            AlertEvent::where('alert_type', 'system_disk_space')->sole()->severity,
        );
    }

    public function test_a_persisting_condition_does_not_open_a_second_alert(): void
    {
        config(['system-health.disk.warning_percent' => 0]);

        $this->artisan('system:scan-health')->assertSuccessful();
        $this->artisan('system:scan-health')->assertSuccessful();
        $this->artisan('system:scan-health')->assertSuccessful();

        $this->assertSame(
            1,
            AlertEvent::where('alert_type', 'system_disk_space')->where('status', 'open')->count(),
            'a persisting condition must not re-alert on every scan',
        );
    }

    public function test_alert_resolves_once_the_condition_clears(): void
    {
        config(['system-health.disk.warning_percent' => 0]);
        $this->artisan('system:scan-health')->assertSuccessful();

        $this->assertSame(1, AlertEvent::where('status', 'open')->count());

        $this->silenceDisk();
        $this->artisan('system:scan-health')->assertSuccessful();

        $alert = AlertEvent::where('alert_type', 'system_disk_space')->sole();
        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
    }

    public function test_failed_job_backlog_opens_an_alert(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);

        config(['system-health.failed_jobs.warning' => 1]);
        config(['system-health.failed_jobs.critical' => PHP_INT_MAX]);

        $this->artisan('system:scan-health')->assertSuccessful();

        $alert = AlertEvent::where('alert_type', 'system_failed_jobs')->sole();
        $this->assertSame('warning', $alert->severity);
        $this->assertNull($alert->device_id);
        $this->assertSame(1, $alert->context['failed_jobs']);
    }

    public function test_a_healthy_system_opens_nothing(): void
    {
        $this->artisan('system:scan-health')->assertSuccessful();

        $this->assertSame(0, AlertEvent::count());
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        config(['system-health.disk.warning_percent' => 0]);

        $this->artisan('system:scan-health', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, AlertEvent::count(), '--dry-run must not persist alerts');
    }

    public function test_system_alerts_are_hidden_from_users_who_only_see_their_own_devices(): void
    {
        // A system alert belongs to no device, so an "own devices only" viewer has
        // nothing to match on and must not see infrastructure problems.
        config(['system-health.disk.warning_percent' => 0]);
        $this->artisan('system:scan-health')->assertSuccessful();

        $owner = User::factory()->create();
        $owner->givePermissionTo('alerts.view_own');
        Device::factory()->create(['user_id' => $owner->id]);

        $this->assertSame(
            0,
            AlertEvent::visibleTo($owner->fresh())->count(),
            'own-devices viewers must not see system alerts',
        );
    }
}
