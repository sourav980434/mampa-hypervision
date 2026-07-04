<?php

namespace App\Drivers\Firewall;

use App\Models\PortMapping;
use Illuminate\Support\Facades\Log;

class MockFirewallDriver implements FirewallDriver
{
    public function applyPortForward(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        
        Log::info("MockFirewallDriver: Applying port forwarding rule.", [
            'public_port' => $mapping->public_port,
            'internal_ip' => $mapping->internal_ip,
            'internal_port' => $mapping->internal_port,
            'protocol' => $mapping->protocol,
            'command_preview' => $this->getIptablesCommandPreview('A', $mapping),
        ]);
    }

    public function removePortForward(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        
        Log::info("MockFirewallDriver: Removing port forwarding rule.", [
            'public_port' => $mapping->public_port,
            'internal_ip' => $mapping->internal_ip,
            'internal_port' => $mapping->internal_port,
            'protocol' => $mapping->protocol,
            'command_preview' => $this->getIptablesCommandPreview('D', $mapping),
        ]);
    }

    /**
     * Check safety mode config.
     */
    protected function checkSafetyMode(): void
    {
        if (config('hypervisor.mode', 'readonly') === 'readonly') {
            throw new \App\Exceptions\DestructiveCommandBlockedException("Firewall modifications are blocked because the Hypervisor is running in Read-Only safety mode.");
        }
    }

    /**
     * Helper to show what the iptables command looks like for debugging.
     */
    private function getIptablesCommandPreview(string $action, PortMapping $mapping): string
    {
        $proto = strtolower($mapping->protocol);
        return "sudo iptables -t nat -{$action} PREROUTING -p {$proto} --dport {$mapping->public_port} -j DNAT --to-destination {$mapping->internal_ip}:{$mapping->internal_port}";
    }
}
