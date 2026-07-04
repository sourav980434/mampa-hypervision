<?php

namespace App\Drivers\Libvirt;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MockLibvirtDriver implements LibvirtDriver
{
    private const CACHE_KEY = 'mock_libvirt_vms';

    public function __construct()
    {
        $this->initializeMockVMs();
    }

    /**
     * Seed initial mock VMs if not already in cache.
     */
    private function initializeMockVMs(): void
    {
        if (!Cache::has(self::CACHE_KEY)) {
            $initialVMs = [
                '5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a' => [
                    'uuid' => '5cd6a8d7-5ef7-47b2-a6f9-710899f8d16a',
                    'name' => 'vm-prod-database',
                    'status' => 'running',
                    'vcpus' => 4,
                    'memory_mb' => 8192,
                    'disk_gb' => 120,
                    'ip_address' => '192.168.122.50',
                    'mac_address' => '52:54:00:12:34:56',
                    'vnc_port' => 5901,
                    'description' => 'Primary Production Database server running MariaDB.',
                ],
                'a8e932b1-cf8d-47a3-bdc1-841103f69022' => [
                    'uuid' => 'a8e932b1-cf8d-47a3-bdc1-841103f69022',
                    'name' => 'vm-nginx-reverse-proxy',
                    'status' => 'running',
                    'vcpus' => 2,
                    'memory_mb' => 2048,
                    'disk_gb' => 30,
                    'ip_address' => '192.168.122.12',
                    'mac_address' => '52:54:00:ab:cd:ef',
                    'vnc_port' => 5902,
                    'description' => 'Nginx gateway for routing public requests to apps.',
                ],
                'b182a933-7e45-4299-b1d5-992a7eef7141' => [
                    'uuid' => 'b182a933-7e45-4299-b1d5-992a7eef7141',
                    'name' => 'vm-win11-rdp',
                    'status' => 'shutoff',
                    'vcpus' => 4,
                    'memory_mb' => 8192,
                    'disk_gb' => 150,
                    'ip_address' => '192.168.122.212',
                    'mac_address' => '52:54:00:fe:dc:ba',
                    'vnc_port' => 5903,
                    'description' => 'Windows 11 dev workstation with RDP enabled.',
                ],
                'f10a89d2-349c-48be-81f1-098d7ee12349' => [
                    'uuid' => 'f10a89d2-349c-48be-81f1-098d7ee12349',
                    'name' => 'vm-staging-api',
                    'status' => 'paused',
                    'vcpus' => 2,
                    'memory_mb' => 4096,
                    'disk_gb' => 60,
                    'ip_address' => '192.168.122.88',
                    'mac_address' => '52:54:00:88:99:aa',
                    'vnc_port' => 5904,
                    'description' => 'API Backend environment for QA testing.',
                ],
            ];

            Cache::forever(self::CACHE_KEY, $initialVMs);
        }
    }

    private function getStoredVMs(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }

    private function saveStoredVMs(array $vms): void
    {
        Cache::forever(self::CACHE_KEY, $vms);
    }

    public function getVMs(): array
    {
        $vms = $this->getStoredVMs();
        return array_map(function ($vm) {
            return [
                'uuid' => $vm['uuid'],
                'name' => $vm['name'],
                'status' => $vm['status'],
                'vcpus' => $vm['vcpus'],
                'memory_mb' => $vm['memory_mb'],
                'ip_address' => $vm['ip_address'],
            ];
        }, array_values($vms));
    }

    public function getVMDetails(string $uuid): array
    {
        $vms = $this->getStoredVMs();
        if (!isset($vms[$uuid])) {
            throw new \Exception("Virtual machine with UUID {$uuid} not found.");
        }
        return $vms[$uuid];
    }

    public function startVM(string $uuid): void
    {
        $this->checkSafetyMode();
        $vms = $this->getStoredVMs();
        if (isset($vms[$uuid])) {
            $vms[$uuid]['status'] = 'running';
            $this->saveStoredVMs($vms);
        }
    }

    public function stopVM(string $uuid, bool $force = false): void
    {
        $this->checkSafetyMode();
        $vms = $this->getStoredVMs();
        if (isset($vms[$uuid])) {
            $vms[$uuid]['status'] = 'shutoff';
            $this->saveStoredVMs($vms);
        }
    }

    public function rebootVM(string $uuid): void
    {
        $this->checkSafetyMode();
        $vms = $this->getStoredVMs();
        if (isset($vms[$uuid])) {
            $vms[$uuid]['status'] = 'running';
            $this->saveStoredVMs($vms);
        }
    }

    public function suspendVM(string $uuid): void
    {
        $this->checkSafetyMode();
        $vms = $this->getStoredVMs();
        if (isset($vms[$uuid])) {
            $vms[$uuid]['status'] = 'paused';
            $this->saveStoredVMs($vms);
        }
    }

    public function resumeVM(string $uuid): void
    {
        $this->checkSafetyMode();
        $vms = $this->getStoredVMs();
        if (isset($vms[$uuid])) {
            $vms[$uuid]['status'] = 'running';
            $this->saveStoredVMs($vms);
        }
    }

    public function getVMStats(string $uuid): array
    {
        $vm = $this->getVMDetails($uuid);

        if ($vm['status'] !== 'running') {
            return [
                'cpu_usage_pct' => 0.0,
                'memory_usage_mb' => 0,
                'memory_usage_pct' => 0.0,
                'disk_read_kbps' => 0.0,
                'disk_write_kbps' => 0.0,
                'net_rx_kbps' => 0.0,
                'net_tx_kbps' => 0.0,
            ];
        }

        // Generate dynamic mock statistics to simulate live graphs
        $cpu = round(rand(50, 450) / 10, 1); // 5% to 45% CPU
        $memPct = round(rand(250, 750) / 10, 1); // 25% to 75% memory
        $memMb = round(($vm['memory_mb'] * $memPct) / 100);

        return [
            'cpu_usage_pct' => $cpu,
            'memory_usage_mb' => (int) $memMb,
            'memory_usage_pct' => $memPct,
            'disk_read_kbps' => round(rand(10, 5000) / 10, 1),
            'disk_write_kbps' => round(rand(10, 12000) / 10, 1),
            'net_rx_kbps' => round(rand(5, 2000) / 10, 1),
            'net_tx_kbps' => round(rand(5, 500) / 10, 1),
        ];
    }

    public function getXMLDesc(string $uuid): string
    {
        $vm = $this->getVMDetails($uuid);
        return <<<XML
<domain type='kvm'>
  <name>{$vm['name']}</name>
  <uuid>{$vm['uuid']}</uuid>
  <description>{$vm['description']}</description>
  <memory unit='KiB'>{$vm['memory_mb']}</memory>
  <vcpu placement='static'>{$vm['vcpus']}</vcpu>
  <os>
    <type arch='x86_64' machine='pc-q35-6.2'>hvm</type>
    <boot dev='hd'/>
  </os>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2'/>
      <source file='/var/lib/libvirt/images/{$vm['name']}.qcow2'/>
      <target dev='vda' bus='virtio'/>
    </disk>
    <interface type='network'>
      <mac address='{$vm['mac_address']}'/>
      <source network='default'/>
      <model type='virtio'/>
    </interface>
    <graphics type='vnc' port='{$vm['vnc_port']}' autoport='no' listen='127.0.0.1'>
      <listen type='address' address='127.0.0.1'/>
    </graphics>
  </devices>
</domain>
XML;
    }

    public function createVMFromXML(string $xmlDesc): string
    {
        $this->checkSafetyMode();
        // Simply parse name, vcpu, memory from basic XML parser to insert
        $xml = simplexml_load_string($xmlDesc);
        $name = (string) $xml->name;
        $vcpus = (int) $xml->vcpu;
        $memoryKb = (int) $xml->memory;
        $uuid = Str::uuid()->toString();
        
        $diskGb = 20;
        if (isset($xml->devices->disk)) {
            foreach ($xml->devices->disk as $disk) {
                if ((string) $disk['device'] === 'disk' && isset($disk['size'])) {
                    $diskGb = (int) $disk['size'];
                    break;
                }
            }
        }

        $bootType = isset($xml->os->loader) ? 'uefi' : 'bios';
        $machineType = isset($xml->os->type['machine']) ? (string) $xml->os->type['machine'] : 'pc-q35-6.2';
        
        $diskBus = 'virtio';
        if (isset($xml->devices->disk)) {
            foreach ($xml->devices->disk as $disk) {
                if ((string)$disk['device'] === 'disk' && isset($disk->target['bus'])) {
                    $diskBus = (string) $disk->target['bus'];
                    break;
                }
            }
        }

        $networkBridge = 'virbr0';
        if (isset($xml->devices->interface)) {
            foreach ($xml->devices->interface as $iface) {
                if ((string)$iface['type'] === 'bridge' && isset($iface->source['bridge'])) {
                    $networkBridge = (string) $iface->source['bridge'];
                    break;
                }
            }
        }

        $networkModel = 'virtio';
        if (isset($xml->devices->interface)) {
            foreach ($xml->devices->interface as $iface) {
                if (isset($iface->model['type'])) {
                    $networkModel = (string) $iface->model['type'];
                    break;
                }
            }
        }

        $usbController = false;
        if (isset($xml->devices->controller)) {
            foreach ($xml->devices->controller as $ctrl) {
                if ((string)$ctrl['type'] === 'usb') {
                    $usbController = true;
                    break;
                }
            }
        }

        $vms = $this->getStoredVMs();
        $vms[$uuid] = [
            'uuid' => $uuid,
            'name' => $name,
            'status' => 'shutoff',
            'vcpus' => $vcpus,
            'memory_mb' => round($memoryKb / 1024),
            'disk_gb' => $diskGb,
            'ip_address' => '192.168.122.' . rand(100, 250),
            'mac_address' => '52:54:00:' . implode(':', str_split(substr(str_shuffle('abcdef0123456789'), 0, 6), 2)),
            'vnc_port' => 5900 + count($vms) + 1,
            'description' => (string) $xml->description ?: 'Newly created virtual machine.',
            'boot_type' => $bootType,
            'machine_type' => $machineType,
            'disk_bus' => $diskBus,
            'network_bridge' => $networkBridge,
            'network_model' => $networkModel,
            'usb_controller' => $usbController,
        ];

        $this->saveStoredVMs($vms);
        return $uuid;
    }

    public function undefineVM(string $uuid): void
    {
        $this->checkSafetyMode();
        $vms = $this->getStoredVMs();
        if (isset($vms[$uuid])) {
            unset($vms[$uuid]);
            $this->saveStoredVMs($vms);
        }
    }

    /**
     * Get list of storage pools (Mock).
     */
    public function getStoragePools(): array
    {
        return [
            ['name' => 'default', 'status' => 'active', 'path' => '/var/lib/libvirt/images', 'type' => 'dir'],
            ['name' => 'local-images', 'status' => 'active', 'path' => '/var/lib/libvirt/boot', 'type' => 'dir'],
        ];
    }

    /**
     * Get list of available ISOs (Mock).
     */
    public function getISOs(): array
    {
        return [
            'ubuntu-24.04-server-amd64.iso',
            'ubuntu-22.04.4-live-server-amd64.iso',
            'win11-english-x64.iso',
            'debian-12.5.0-amd64-netinst.iso',
            'CentOS-Stream-9-latest-x86_64-dvd1.iso',
            '/media/root/KINGSTON_16G/win10_pro_x64.iso',
            '/media/root/TOSHIBA_USB/ubuntu-24.04-desktop-amd64.iso',
        ];
    }

    /**
     * Throw exception if hypervisor is in read-only mode.
     */
    protected function checkSafetyMode(): void
    {
        if (config('hypervisor.mode', 'readonly') === 'readonly') {
            throw new \App\Exceptions\DestructiveCommandBlockedException();
        }
    }
}
