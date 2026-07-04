<?php

namespace Tests\Feature;

use App\Services\NetworkService;
use App\Models\User;
use App\Models\PortMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hypervisor.mode' => 'active']);
    }

    /**
     * Test creating a port forwarding rule.
     */
    public function test_create_port_mapping_saves_to_db_and_logs(): void
    {
        $user = User::factory()->create(['role' => 'operator']);
        $this->actingAs($user);

        $service = app(NetworkService::class);
        $mapping = $service->createMapping([
            'public_port' => 50001,
            'internal_ip' => '192.168.122.212',
            'internal_port' => 3389,
            'protocol' => 'tcp',
            'description' => 'Test RDP forward'
        ]);

        $this->assertDatabaseHas('port_mappings', [
            'public_port' => 50001,
            'internal_ip' => '192.168.122.212',
            'internal_port' => 3389,
            'protocol' => 'tcp',
            'status' => 'active'
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'port.create',
        ]);
    }

    /**
     * Test toggling active state.
     */
    public function test_toggle_port_mapping_changes_status(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $mapping = PortMapping::create([
            'public_port' => 50002,
            'internal_ip' => '192.168.122.12',
            'internal_port' => 80,
            'protocol' => 'tcp',
            'status' => 'active'
        ]);

        $service = app(NetworkService::class);
        
        // Deactivate rule
        $service->toggleMapping($mapping->id);
        $this->assertEquals('inactive', $mapping->refresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'port.deactivate'
        ]);

        // Reactivate rule
        $service->toggleMapping($mapping->id);
        $this->assertEquals('active', $mapping->refresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'port.activate'
        ]);
    }

    /**
     * Test that firewall modifications are blocked in readonly mode.
     */
    public function test_port_forwarding_throws_exception_in_readonly_mode(): void
    {
        config(['hypervisor.mode' => 'readonly']);
        
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $service = app(NetworkService::class);

        $this->expectException(\App\Exceptions\DestructiveCommandBlockedException::class);
        $service->createMapping([
            'public_port' => 50003,
            'internal_ip' => '192.168.122.212',
            'internal_port' => 3389,
            'protocol' => 'tcp',
        ]);
    }

    /**
     * Test the execution plan preview endpoint for port forwarding actions.
     */
    public function test_port_forwarding_execution_plan_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $response = $this->get(route('port-forwarding.execution-plan', [
            'action' => 'create',
            'protocol' => 'tcp',
            'public_port' => 50004,
            'internal_ip' => '192.168.122.50',
            'internal_port' => 80,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'action',
            'mode',
            'risk_level',
            'command',
            'expected_result',
            'rollback_option'
        ]);

        $response->assertJson([
            'action' => 'create',
            'risk_level' => 'MEDIUM'
        ]);
    }

    /**
     * Test that duplicate public ports are rejected during creation.
     */
    public function test_port_forwarding_duplicate_prevention_validation(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        PortMapping::create([
            'public_port' => 50005,
            'internal_ip' => '192.168.122.12',
            'internal_port' => 80,
            'protocol' => 'tcp',
            'status' => 'active'
        ]);

        $response = $this->post(route('port-forwarding.store', [
            'public_port' => 50005,
            'internal_ip' => '192.168.122.13',
            'internal_port' => 80,
            'protocol' => 'tcp'
        ]));

        $response->assertSessionHasErrors(['public_port']);
    }

    /**
     * Test the connectivity probe endpoint.
     */
    public function test_port_forwarding_connectivity_test_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $mapping = PortMapping::create([
            'public_port' => 50006,
            'internal_ip' => '127.0.0.1', // self
            'internal_port' => 9999,      // likely closed
            'protocol' => 'tcp',
            'status' => 'active'
        ]);

        $response = $this->post(route('port-forwarding.test', [
            'id' => $mapping->id
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
        
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'port.test'
        ]);
    }
}
