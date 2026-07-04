<?php

namespace Tests\Feature;

use App\Drivers\Libvirt\LibvirtDriver;
use App\Drivers\Libvirt\MockLibvirtDriver;
use App\Services\VMService;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LibvirtDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear mock cache before each test
        Cache::forget('mock_libvirt_vms');
        
        // Force active mode for existing baseline tests
        config(['hypervisor.mode' => 'active']);
    }

    /**
     * Test driver DI binding resolves correctly.
     */
    public function test_driver_resolves_to_mock_implementation(): void
    {
        $driver = app(LibvirtDriver::class);
        $this->assertInstanceOf(MockLibvirtDriver::class, $driver);
    }

    /**
     * Test VM list fetching and caching.
     */
    public function test_service_returns_vms_with_metadata(): void
    {
        $service = app(VMService::class);
        $vms = $service->getVMs();

        $this->assertNotEmpty($vms);
        $this->assertArrayHasKey('uuid', $vms[0]);
        $this->assertArrayHasKey('tags', $vms[0]);
        $this->assertArrayHasKey('notes', $vms[0]);
    }

    /**
     * Test VM Lifecycle state changes are persistent.
     */
    public function test_vm_lifecycle_updates_state_and_logs_activity(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $service = app(VMService::class);
        $vms = $service->getVMs();
        $targetUuid = $vms[0]['uuid'];

        // Get initial VM details (default is running or mock state)
        $details = $service->getVMDetails($targetUuid);
        $initialState = $details['status'];

        // Stop VM
        $service->stopVM($targetUuid, true); // force stop

        // Assert state changed to shutoff in cache
        $updatedDetails = $service->getVMDetails($targetUuid);
        $this->assertEquals('shutoff', $updatedDetails['status']);

        // Assert audit log was recorded
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'vm.force_stop',
        ]);
    }

    /**
     * Test that lifecycle actions throw DestructiveCommandBlockedException in readonly mode.
     */
    public function test_vm_lifecycle_throws_exception_in_readonly_mode(): void
    {
        config(['hypervisor.mode' => 'readonly']);
        
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $service = app(VMService::class);
        $vms = $service->getVMs();
        $targetUuid = $vms[0]['uuid'];

        $this->expectException(\App\Exceptions\DestructiveCommandBlockedException::class);
        $service->startVM($targetUuid);
    }

    /**
     * Test the execution plan preview API endpoint.
     */
    public function test_vm_execution_plan_endpoint_returns_data(): void
    {
        config(['hypervisor.mode' => 'readonly']);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $service = app(VMService::class);
        $vms = $service->getVMs();
        $targetUuid = $vms[0]['uuid'];

        $response = $this->get(route('vms.execution-plan', [
            'uuid' => $targetUuid,
            'action' => 'start'
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'vm_name',
            'vm_uuid',
            'action',
            'mode',
            'command',
            'risk_level',
            'expected_result',
            'rollback_option'
        ]);
        
        $response->assertJson([
            'mode' => 'readonly',
            'action' => 'start',
            'risk_level' => 'LOW'
        ]);
    }
}
