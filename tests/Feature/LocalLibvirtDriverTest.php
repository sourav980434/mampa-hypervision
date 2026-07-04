<?php

namespace Tests\Feature;

use App\Drivers\Libvirt\LocalLibvirtDriver;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LocalLibvirtDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('local_libvirt_vms_registry');
        Cache::forget('vm_uuid_by_name_vm-prod-database');
        Cache::forget('vm_uuid_by_name_vm-win11-rdp');
        Cache::forget('vm_config_5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');
        Cache::forget('vm_config_b182a933-7e45-4299-b1d5-992a7eef7141');
    }

    /**
     * Test parsing of virsh list --all output.
     */
    public function test_local_driver_parses_list_output_correctly(): void
    {
        Process::fake([
            'virsh list --all' => Process::result("
 Id    Name                           State
----------------------------------------------------
 1     vm-prod-database               running
 -     vm-win11-rdp                   shut off
"),
            'virsh domuuid vm-prod-database' => Process::result("5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a\n"),
            'virsh domuuid vm-win11-rdp' => Process::result("b182a933-7e45-4299-b1d5-992a7eef7141\n"),
            'virsh dumpxml *' => Process::result("
<domain type='kvm'>
  <name>vm-prod-database</name>
  <memory unit='KiB'>8388608</memory>
  <vcpu placement='static'>4</vcpu>
</domain>
"),
            'virsh domifaddr *' => Process::result("
 Name       MAC address          Protocol     Address
-------------------------------------------------------------------------------
 vnet0      52:54:00:12:34:56    ipv4         192.168.122.50/24
"),
        ]);

        $driver = new LocalLibvirtDriver();
        $vms = $driver->getVMs();

        $this->assertCount(2, $vms);

        $this->assertEquals('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a', $vms[0]['uuid']);
        $this->assertEquals('vm-prod-database', $vms[0]['name']);
        $this->assertEquals('running', $vms[0]['status']);
        $this->assertEquals('192.168.122.50', $vms[0]['ip_address']);

        $this->assertEquals('b182a933-7e45-4299-b1d5-992a7eef7141', $vms[1]['uuid']);
        $this->assertEquals('vm-win11-rdp', $vms[1]['name']);
        $this->assertEquals('shutoff', $vms[1]['status']);
    }

    /**
     * Test parsing of virsh dominfo output.
     */
    public function test_local_driver_parses_dominfo_details(): void
    {
        Process::fake([
            'virsh list --all --name' => Process::result("vm-prod-database\n"),
            'virsh domuuid vm-prod-database' => Process::result("5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a\n"),
            'virsh dominfo vm-prod-database' => Process::result("
Id:             1
Name:           vm-prod-database
UUID:           5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a
OS Type:        hvm
State:          running
CPU(s):         4
Max memory:     8388608 KiB
Used memory:    8388608 KiB
Persistent:     yes
"),
            'virsh dumpxml vm-prod-database' => Process::result("
<domain type='kvm'>
  <name>vm-prod-database</name>
  <description>Production DB Server</description>
  <memory unit='KiB'>8388608</memory>
  <vcpu placement='static'>4</vcpu>
  <devices>
    <interface type='network'>
      <mac address='52:54:00:12:34:56'/>
      <source network='default'/>
    </interface>
    <graphics type='vnc' port='5901'/>
  </devices>
</domain>
"),
            'virsh domifaddr vm-prod-database' => Process::result("vnet0 52:54:00:12:34:56 ipv4 192.168.122.50/24"),
        ]);

        $driver = new LocalLibvirtDriver();
        $details = $driver->getVMDetails('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');

        $this->assertEquals('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a', $details['uuid']);
        $this->assertEquals('vm-prod-database', $details['name']);
        $this->assertEquals('running', $details['status']);
        $this->assertEquals(4, $details['vcpus']);
        $this->assertEquals(8192, $details['memory_mb']);
        $this->assertEquals('52:54:00:12:34:56', $details['mac_address']);
        $this->assertEquals(5901, $details['vnc_port']);
        $this->assertEquals('Production DB Server', $details['description']);
    }

    /**
     * Test that LocalLibvirtDriver throws DestructiveCommandBlockedException in readonly mode.
     */
    public function test_local_driver_throws_exception_in_readonly_mode(): void
    {
        config(['hypervisor.mode' => 'readonly']);
        
        $driver = new LocalLibvirtDriver();
        
        // Seed cache registry for Uuid to Name mapping
        Cache::forever('local_libvirt_vms_registry', [
            '5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a' => 'vm-prod-database'
        ]);

        $this->expectException(\App\Exceptions\DestructiveCommandBlockedException::class);
        $driver->startVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');
    }

    /**
     * Test that LocalLibvirtDriver executes shell commands under active safety mode.
     */
    public function test_local_driver_executes_commands_in_active_mode(): void
    {
        config(['hypervisor.mode' => 'active']);
        
        // Seed registry
        Cache::forever('local_libvirt_vms_registry', [
            '5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a' => 'vm-prod-database'
        ]);

        Process::fake([
            'virsh start vm-prod-database' => Process::result("", "", 0),
            'virsh shutdown vm-prod-database' => Process::result("", "", 0),
            'virsh reboot vm-prod-database' => Process::result("", "", 0),
            'virsh suspend vm-prod-database' => Process::result("", "", 0),
            'virsh resume vm-prod-database' => Process::result("", "", 0),
        ]);

        $driver = new LocalLibvirtDriver();
        
        $driver->startVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');
        $driver->stopVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a', false); // shutdown
        $driver->rebootVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');
        $driver->suspendVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');
        $driver->resumeVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');

        Process::assertRan('virsh start vm-prod-database');
        Process::assertRan('virsh shutdown vm-prod-database');
        Process::assertRan('virsh reboot vm-prod-database');
        Process::assertRan('virsh suspend vm-prod-database');
        Process::assertRan('virsh resume vm-prod-database');
    }

    /**
     * Test that create VM is blocked in readonly mode.
     */
    public function test_local_driver_creation_blocked_in_readonly(): void
    {
        config(['hypervisor.mode' => 'readonly']);
        
        $driver = new LocalLibvirtDriver();

        $this->expectException(\App\Exceptions\DestructiveCommandBlockedException::class);
        $driver->createVMFromXML('<domain><name>test-vm</name></domain>');
    }

    /**
     * Test that undefine VM is blocked in readonly mode.
     */
    public function test_local_driver_undefine_blocked_in_readonly(): void
    {
        config(['hypervisor.mode' => 'readonly']);
        
        $driver = new LocalLibvirtDriver();

        $this->expectException(\App\Exceptions\DestructiveCommandBlockedException::class);
        $driver->undefineVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');
    }

    /**
     * Test that local driver defines VM in active mode.
     */
    public function test_local_driver_defines_vm_in_active_mode(): void
    {
        config(['hypervisor.mode' => 'active']);
        
        $xml = <<<XML
<domain type='kvm'>
  <name>test-vm-active</name>
  <memory unit='KiB'>2048</memory>
  <vcpu placement='static'>1</vcpu>
</domain>
XML;

        Process::fake([
            'virsh define *' => Process::result("Domain test-vm-active defined\n"),
            'virsh domuuid *' => Process::result("abcdef12-3456-7890-abcd-ef1234567890\n")
        ]);

        $driver = new LocalLibvirtDriver();
        $uuid = $driver->createVMFromXML($xml);

        $this->assertEquals('abcdef12-3456-7890-abcd-ef1234567890', $uuid);
        Process::assertRan(fn ($process) => str_contains($process->command, 'virsh define'));
    }

    /**
     * Test that local driver undefines VM in active mode.
     */
    public function test_local_driver_undefines_vm_in_active_mode(): void
    {
        config(['hypervisor.mode' => 'active']);

        Process::fake([
            'virsh list --all --name' => Process::result("vm-prod-database\n"),
            'virsh domuuid *' => Process::result("5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a\n"),
            'virsh undefine *' => Process::result("Domain vm-prod-database has been undefined\n")
        ]);

        $driver = new LocalLibvirtDriver();
        $driver->undefineVM('5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a');

        Process::assertRan(fn ($process) => str_contains($process->command, 'virsh undefine'));
    }
}
