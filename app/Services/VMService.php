<?php

namespace App\Services;

use App\Drivers\Libvirt\LibvirtDriver;
use App\Models\VmMetadata;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class VMService
{
    protected LibvirtDriver $driver;

    public function __construct(LibvirtDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Get all VMs merged with database metadata.
     */
    public function getVMs(): array
    {
        $vms = $this->driver->getVMs();
        if (empty($vms)) {
            return [];
        }

        $uuids = array_column($vms, 'uuid');
        $metadata = VmMetadata::whereIn('vm_uuid', $uuids)->get()->keyBy('vm_uuid');

        return array_map(function ($vm) use ($metadata) {
            $meta = $metadata->get($vm['uuid']);
            $vm['tags'] = $meta ? ($meta->tags ?? []) : [];
            $vm['notes'] = $meta ? ($meta->notes ?? '') : '';
            return $vm;
        }, $vms);
    }

    /**
     * Get VM details by UUID merged with metadata.
     */
    public function getVMDetails(string $uuid): array
    {
        $vm = $this->driver->getVMDetails($uuid);
        $meta = VmMetadata::where('vm_uuid', $uuid)->first();
        $vm['tags'] = $meta ? ($meta->tags ?? []) : [];
        $vm['notes'] = $meta ? ($meta->notes ?? '') : '';
        return $vm;
    }

    /**
     * Start the VM.
     */
    public function startVM(string $uuid): void
    {
        $vm = $this->driver->getVMDetails($uuid);
        $this->driver->startVM($uuid);

        $this->logActivity('vm.start', [
            'uuid' => $uuid,
            'name' => $vm['name']
        ]);
    }

    /**
     * Stop the VM.
     */
    public function stopVM(string $uuid, bool $force = false): void
    {
        $vm = $this->driver->getVMDetails($uuid);
        $this->driver->stopVM($uuid, $force);

        $this->logActivity($force ? 'vm.force_stop' : 'vm.stop', [
            'uuid' => $uuid,
            'name' => $vm['name']
        ]);
    }

    /**
     * Reboot the VM.
     */
    public function rebootVM(string $uuid): void
    {
        $vm = $this->driver->getVMDetails($uuid);
        $this->driver->rebootVM($uuid);

        $this->logActivity('vm.reboot', [
            'uuid' => $uuid,
            'name' => $vm['name']
        ]);
    }

    /**
     * Suspend the VM.
     */
    public function suspendVM(string $uuid): void
    {
        $vm = $this->driver->getVMDetails($uuid);
        $this->driver->suspendVM($uuid);

        $this->logActivity('vm.suspend', [
            'uuid' => $uuid,
            'name' => $vm['name']
        ]);
    }

    /**
     * Resume the VM.
     */
    public function resumeVM(string $uuid): void
    {
        $vm = $this->driver->getVMDetails($uuid);
        $this->driver->resumeVM($uuid);

        $this->logActivity('vm.resume', [
            'uuid' => $uuid,
            'name' => $vm['name']
        ]);
    }

    /**
     * Get VM realtime stats.
     */
    public function getVMStats(string $uuid): array
    {
        return $this->driver->getVMStats($uuid);
    }

    /**
     * Update VM tags and notes.
     */
    public function updateMetadata(string $uuid, array $tags, string $notes): VmMetadata
    {
        $vm = $this->driver->getVMDetails($uuid);
        
        $meta = VmMetadata::updateOrCreate(
            ['vm_uuid' => $uuid],
            ['tags' => $tags, 'notes' => $notes]
        );

        $this->logActivity('vm.update_metadata', [
            'uuid' => $uuid,
            'name' => $vm['name'],
            'tags' => $tags,
            'notes' => substr($notes, 0, 100)
        ]);

        return $meta;
    }

    /**
     * Generate an execution plan for a VM lifecycle action.
     */
    public function getExecutionPlan(string $uuid, string $action): array
    {
        $vm = $this->driver->getVMDetails($uuid);
        $name = $vm['name'];
        
        $plan = [
            'vm_name' => $name,
            'vm_uuid' => $uuid,
            'action' => $action,
            'mode' => config('hypervisor.mode', 'readonly'),
        ];

        switch ($action) {
            case 'start':
                $plan['command'] = "virsh start {$name}";
                $plan['risk_level'] = "LOW";
                $plan['expected_result'] = "The virtual machine will boot into its guest OS.";
                $plan['rollback_option'] = "Run 'virsh destroy {$name}' to force stop.";
                break;
            case 'stop':
                $plan['command'] = "virsh shutdown {$name}";
                $plan['risk_level'] = "MEDIUM";
                $plan['expected_result'] = "A graceful ACPI shutdown signal is sent to the guest OS.";
                $plan['rollback_option'] = "Run 'virsh start {$name}' to power back on.";
                break;
            case 'force-stop':
                $plan['command'] = "virsh destroy {$name}";
                $plan['risk_level'] = "HIGH";
                $plan['expected_result'] = "The virtual machine process is immediately terminated.";
                $plan['rollback_option'] = "None. Guest state in RAM is lost. File changes must be recovered from backups.";
                break;
            case 'reboot':
                $plan['command'] = "virsh reboot {$name}";
                $plan['risk_level'] = "MEDIUM";
                $plan['expected_result'] = "A graceful reboot signal is sent to the guest OS.";
                $plan['rollback_option'] = "None.";
                break;
            case 'suspend':
                $plan['command'] = "virsh suspend {$name}";
                $plan['risk_level'] = "LOW";
                $plan['expected_result'] = "The VM runtime state is frozen in memory.";
                $plan['rollback_option'] = "Run 'virsh resume {$name}' to unfreeze.";
                break;
            case 'resume':
                $plan['command'] = "virsh resume {$name}";
                $plan['risk_level'] = "LOW";
                $plan['expected_result'] = "The VM unfreezes and resumes execution.";
                $plan['rollback_option'] = "Run 'virsh suspend {$name}' to freeze again.";
                break;
            default:
                throw new \InvalidArgumentException("Invalid VM action: {$action}");
        }

        return $plan;
    }

    /**
     * Get storage pools list.
     */
    public function getStoragePools(): array
    {
        return $this->driver->getStoragePools();
    }

    /**
     * Get available ISOs.
     */
    public function getISOs(): array
    {
        return $this->driver->getISOs();
    }

    /**
     * Build standard domain XML for KVM definition.
     */
    public function buildXMLDescriptor(array $params): string
    {
        $name = htmlspecialchars($params['name'], ENT_QUOTES);
        $vcpus = (int) $params['vcpus'];
        $memoryMb = (int) $params['memory_mb'];
        $memoryKb = $memoryMb * 1024;
        
        $machine = htmlspecialchars($params['machine_type'] ?? 'pc-q35-6.2', ENT_QUOTES);
        $bootType = $params['boot_type'] ?? 'bios';
        $diskBus = $params['disk_bus'] ?? 'virtio';
        $diskSizeGb = (int) $params['disk_gb'];
        $netBridge = htmlspecialchars($params['network_bridge'] ?? 'virbr0', ENT_QUOTES);
        $netModel = htmlspecialchars($params['network_model'] ?? 'virtio', ENT_QUOTES);
        
        $macAddress = !empty($params['mac_address']) 
            ? htmlspecialchars($params['mac_address'], ENT_QUOTES) 
            : '52:54:00:' . implode(':', str_split(substr(str_shuffle('abcdef0123456789'), 0, 6), 2));

        $vncPort = rand(5900, 6000);
        
        $bootXml = "";
        if ($bootType === 'uefi') {
            $bootXml = "\n    <loader readonly='yes' type='pflash'>/usr/share/OVMF/OVMF_CODE.fd</loader>\n    <nvram>/var/lib/libvirt/qemu/nvram/{$name}_VARS.fd</nvram>\n    <boot dev='cdrom'/>\n    <boot dev='hd'/>";
        } else {
            $bootXml = "\n    <boot dev='cdrom'/>\n    <boot dev='hd'/>";
        }

        $isoXml = "";
        if (!empty($params['iso_volume'])) {
            $isoName = htmlspecialchars($params['iso_volume'], ENT_QUOTES);
            $isAbsolute = str_starts_with($isoName, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $isoName);
            $isoPath = $isAbsolute ? $isoName : "/var/lib/libvirt/boot/{$isoName}";
            $isoXml = "\n    <disk type='file' device='cdrom'>\n      <driver name='qemu' type='raw'/>\n      <source file='{$isoPath}'/>\n      <target dev='sdb' bus='sata'/>\n      <readonly/>\n    </disk>";
        }

        $usbXml = "";
        if (!empty($params['usb_controller'])) {
            $usbXml = "\n    <controller type='usb' index='0' model='qemu-xhci' ports='15'/>";
        }

        $description = htmlspecialchars($params['description'] ?? 'Created via Mampa Hypervisor VM Wizard.', ENT_QUOTES);

        return <<<XML
<domain type='kvm'>
  <name>{$name}</name>
  <description>{$description}</description>
  <memory unit='KiB'>{$memoryKb}</memory>
  <currentMemory unit='KiB'>{$memoryKb}</currentMemory>
  <vcpu placement='static'>{$vcpus}</vcpu>
  <os>
    <type arch='x86_64' machine='{$machine}'>hvm</type>{$bootXml}
  </os>
  <features>
    <acpi/>
    <apic/>
  </features>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
    <disk type='file' device='disk' size='{$diskSizeGb}'>
      <driver name='qemu' type='qcow2'/>
      <source file='/var/lib/libvirt/images/{$name}.qcow2'/>
      <target dev='vda' bus='{$diskBus}'/>
    </disk>{$isoXml}
    <interface type='bridge'>
      <mac address='{$macAddress}'/>
      <source bridge='{$netBridge}'/>
      <model type='{$netModel}'/>
    </interface>{$usbXml}
    <graphics type='vnc' port='{$vncPort}' autoport='no' listen='0.0.0.0'>
      <listen type='address' address='0.0.0.0'/>
    </graphics>
    <video>
      <model type='qxl' ram='65536' vram='65536' vgamem='16384' heads='1' primary='yes'/>
    </video>
  </devices>
</domain>
XML;
    }

    /**
     * Create a new virtual machine.
     */
    public function createVM(array $params): string
    {
        $xmlDesc = $this->buildXMLDescriptor($params);
        $uuid = $this->driver->createVMFromXML($xmlDesc);

        $this->logActivity('vm.create', [
            'uuid' => $uuid,
            'name' => $params['name'],
            'vcpus' => $params['vcpus'],
            'memory_mb' => $params['memory_mb'],
            'disk_gb' => $params['disk_gb']
        ]);

        return $uuid;
    }

    /**
     * Delete/Undefine a virtual machine.
     */
    public function deleteVM(string $uuid): array
    {
        $vm = $this->getVMDetails($uuid);
        if ($vm['status'] === 'running' || $vm['status'] === 'paused') {
            return [
                'success' => false,
                'message' => "VM '{$vm['name']}' is currently {$vm['status']}. You must stop the VM before deleting it."
            ];
        }

        try {
            $this->driver->undefineVM($uuid);
            
            // Clean up DB metadata
            \App\Models\VmMetadata::where('vm_uuid', $uuid)->delete();
            \App\Models\RdpVncMapping::where('vm_uuid', $uuid)->delete();
            \App\Models\PublishedApplication::where('vm_uuid', $uuid)->delete();

            $this->logActivity('vm.delete', [
                'uuid' => $uuid,
                'name' => $vm['name']
            ]);

            return [
                'success' => true,
                'message' => "VM '{$vm['name']}' has been deleted successfully."
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Failed to delete VM: " . $e->getMessage()
            ];
        }
    }

    /**
     * Update VM configuration.
     */
    public function updateVM(string $uuid, array $params): array
    {
        $vm = $this->getVMDetails($uuid);
        if ($vm['status'] === 'running' || $vm['status'] === 'paused') {
            return [
                'success' => false,
                'message' => "VM '{$vm['name']}' is currently {$vm['status']}. You must stop the VM before editing it."
            ];
        }

        // Keep existing name and mac
        $params['name'] = $vm['name'];
        $params['mac_address'] = $vm['mac_address'] ?? '';

        try {
            $xmlDesc = $this->buildXMLDescriptor($params);
            $this->driver->createVMFromXML($xmlDesc);

            // Update VM cache config if cached
            Cache::put("vm_config_{$uuid}", [
                'vcpus' => (int) $params['vcpus'],
                'memory_mb' => (int) $params['memory_mb'],
            ], 300);

            $this->logActivity('vm.update', [
                'uuid' => $uuid,
                'name' => $vm['name'],
                'vcpus' => $params['vcpus'],
                'memory_mb' => $params['memory_mb'],
            ]);

            return [
                'success' => true,
                'message' => "VM '{$vm['name']}' configuration updated successfully."
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Failed to update VM configuration: " . $e->getMessage()
            ];
        }
    }

    /**
     * Log user activity.
     */
    protected function logActivity(string $action, array $details): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'details' => $details,
            'ip_address' => request()->ip(),
        ]);
    }
}
