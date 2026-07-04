<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VMCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hypervisor.mode' => 'active']);
    }

    /**
     * Test querying host storage pools.
     */
    public function test_get_storage_pools_api_endpoint(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('storage.pools'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'name',
                'status',
                'path',
                'type'
            ]
        ]);
    }

    /**
     * Test querying host ISO list.
     */
    public function test_get_isos_api_endpoint(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('storage.isos'));

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    /**
     * Test VM Wizard creation flows.
     */
    public function test_create_vm_wizard_validates_and_creates_vm(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $response = $this->post(route('vms.store'), [
            'name' => 'ubuntu-vm-1',
            'vcpus' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
            'boot_type' => 'bios',
            'machine_type' => 'pc-q35-6.2',
            'disk_bus' => 'virtio',
            'network_bridge' => 'virbr0',
            'network_model' => 'virtio',
            'iso_volume' => 'ubuntu-24.04-server-amd64.iso',
            'description' => 'Ubuntu test VM',
            'usb_controller' => true,
            'start_after_created' => false,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success', 'VM created successfully.');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'vm.create',
        ]);
    }

    /**
     * Test VM Wizard creation flows with automatic start.
     */
    public function test_create_vm_wizard_handles_start_after_created(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $response = $this->post(route('vms.store'), [
            'name' => 'windows-vm-1',
            'vcpus' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 60,
            'boot_type' => 'uefi',
            'machine_type' => 'pc-q35-6.2',
            'disk_bus' => 'sata',
            'network_bridge' => 'virbr0',
            'network_model' => 'e1000',
            'iso_volume' => 'win11-english-x64.iso',
            'description' => 'Windows test VM',
            'usb_controller' => true,
            'start_after_created' => true,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success', 'VM created successfully.');

        // Assert both creation and boot actions were logged
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'vm.create'
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'vm.start'
        ]);
    }

    public function test_create_vm_blocked_in_readonly_mode(): void
    {
        $this->withoutExceptionHandling();
        config(['hypervisor.mode' => 'readonly']);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $this->expectException(\App\Exceptions\DestructiveCommandBlockedException::class);

        $this->post(route('vms.store'), [
            'name' => 'blocked-vm-1',
            'vcpus' => 2,
            'memory_mb' => 2048,
            'disk_gb' => 20,
            'boot_type' => 'bios',
            'machine_type' => 'pc-q35-6.2',
            'disk_bus' => 'virtio',
            'network_bridge' => 'virbr0',
            'network_model' => 'virtio',
            'iso_volume' => 'ubuntu-24.04-server-amd64.iso',
            'start_after_created' => false,
        ]);
    }
}
