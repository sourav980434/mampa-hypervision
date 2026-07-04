<?php

namespace App\Drivers\Firewall;

use App\Models\PortMapping;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class LocalIptablesFirewallDriver implements FirewallDriver
{
    /**
     * Check if safety mode blocks this command.
     */
    protected function checkSafetyMode(): void
    {
        if (config('hypervisor.mode', 'readonly') === 'readonly') {
            throw new \App\Exceptions\DestructiveCommandBlockedException("Firewall modifications are blocked because the Hypervisor is running in Read-Only safety mode.");
        }
    }

    /**
     * Apply a NAT port forwarding rule using iptables.
     */
    public function applyPortForward(PortMapping $mapping): void
    {
        $this->checkSafetyMode();

        $proto = $mapping->protocol;
        $pubPort = (int) $mapping->public_port;
        $intIp = $mapping->internal_ip;
        $intPort = (int) $mapping->internal_port;

        $cmd1 = "sudo iptables -t nat -A PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}";
        $cmd2 = "sudo iptables -A FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
        $cmd3 = "sudo iptables -t nat -A POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE";

        $this->runCommand($cmd1);
        $this->runCommand($cmd2);
        $this->runCommand($cmd3);
    }

    /**
     * Remove a NAT port forwarding rule using iptables.
     */
    public function removePortForward(PortMapping $mapping): void
    {
        $this->checkSafetyMode();

        $proto = $mapping->protocol;
        $pubPort = (int) $mapping->public_port;
        $intIp = $mapping->internal_ip;
        $intPort = (int) $mapping->internal_port;

        $cmd1 = "sudo iptables -t nat -D PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}";
        $cmd2 = "sudo iptables -D FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
        $cmd3 = "sudo iptables -t nat -D POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE";

        // Try deleting, ignore if rules don't exist
        $this->runCommand($cmd1, false);
        $this->runCommand($cmd2, false);
        $this->runCommand($cmd3, false);
    }

    /**
     * Helper to run commands.
     */
    protected function runCommand(string $cmd, bool $throwOnError = true): void
    {
        $res = Process::run($cmd);
        if ($res->exitCode() !== 0 && $throwOnError) {
            throw new \RuntimeException("Firewall command failed: {$cmd}. Error: " . $res->errorOutput());
        }
    }
}
