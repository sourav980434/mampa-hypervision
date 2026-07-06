<?php

namespace App\Services\Firewall;

use App\Models\PortMapping;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class FirewallRuleEngine
{
    /**
     * Get the host's external network interface.
     */
    public function getExternalInterface(): string
    {
        $interface = env('EXTERNAL_INTERFACE');
        if ($interface) {
            return $interface;
        }
        try {
            $res = Process::run("ip route show | grep default");
            if ($res->exitCode() === 0 && preg_match('/dev\s+(\S+)/', $res->output(), $matches)) {
                return $matches[1];
            }
        } catch (\Exception $e) {
            // fallback
        }
        return 'enp1s0'; // manual tested default fallback
    }

    /**
     * Get the virtual network bridge interface.
     */
    public function getInternalBridge(): string
    {
        return env('INTERNAL_BRIDGE', 'virbr0');
    }

    /**
     * Get the internal VM network subnet.
     */
    public function getInternalSubnet(): string
    {
        return env('INTERNAL_SUBNET', '192.168.122.0/24');
    }

    /**
     * Generate all firewall rules for a given mapping.
     */
    public function generateRules(PortMapping $mapping): array
    {
        $proto = strtolower($mapping->protocol);
        $pubPort = (int) $mapping->public_port;
        $intIp = $mapping->internal_ip;
        $intPort = (int) $mapping->internal_port;

        $ext = $this->getExternalInterface();
        $bridge = $this->getInternalBridge();
        $subnet = $this->getInternalSubnet();

        return [
            'dnat' => new FirewallRule('nat', 'PREROUTING', [
                '-i', $ext,
                '-p', $proto,
                '--dport', (string)$pubPort,
                '-j', 'DNAT',
                '--to-destination', "{$intIp}:{$intPort}"
            ]),
            'forward_in' => new FirewallRule('filter', 'FORWARD', [
                '-i', $ext,
                '-o', $bridge,
                '-p', $proto,
                '-d', $intIp,
                '--dport', (string)$intPort,
                '-j', 'ACCEPT'
            ]),
            'forward_out' => new FirewallRule('filter', 'FORWARD', [
                '-i', $bridge,
                '-o', $ext,
                '-p', $proto,
                '-s', $intIp,
                '--sport', (string)$intPort,
                '-j', 'ACCEPT'
            ]),
            'masquerade' => new FirewallRule('nat', 'POSTROUTING', [
                '-s', $subnet,
                '-o', $ext,
                '-j', 'MASQUERADE'
            ])
        ];
    }

    /**
     * Verify if a firewall rule exists in iptables.
     */
    public function checkRuleExists(FirewallRule $rule): bool
    {
        $cmd = $rule->getCommand('C');
        $res = Process::run($cmd);
        return $res->exitCode() === 0;
    }

    /**
     * Apply all rules for a mapping.
     */
    public function applyMapping(PortMapping $mapping): void
    {
        $this->ensureDNATRule($mapping);
        $this->ensureForwardInboundRule($mapping);
        $this->ensureForwardReturnRule($mapping);
        $this->ensureMasqueradeRule($mapping);
    }

    /**
     * Remove all rules for a mapping.
     */
    public function removeMapping(PortMapping $mapping): void
    {
        $rules = $this->generateRules($mapping);
        foreach ($rules as $rule) {
            if ($this->checkRuleExists($rule)) {
                $cmd = $rule->getCommand('D');
                Process::run($cmd);
            }
        }
    }

    public function ensureDNATRule(PortMapping $mapping): void
    {
        $rules = $this->generateRules($mapping);
        $rule = $rules['dnat'];
        if (!$this->checkRuleExists($rule)) {
            $cmd = $rule->getCommand('A');
            $this->executeCommand($cmd);
        }
    }

    public function ensureForwardInboundRule(PortMapping $mapping): void
    {
        $rules = $this->generateRules($mapping);
        $rule = $rules['forward_in'];
        if (!$this->checkRuleExists($rule)) {
            $cmd = $rule->getCommand('I');
            $this->executeCommand($cmd);
        }
    }

    public function ensureForwardReturnRule(PortMapping $mapping): void
    {
        $rules = $this->generateRules($mapping);
        $rule = $rules['forward_out'];
        if (!$this->checkRuleExists($rule)) {
            $cmd = $rule->getCommand('I');
            $this->executeCommand($cmd);
        }
    }

    public function ensureMasqueradeRule(PortMapping $mapping): void
    {
        $rules = $this->generateRules($mapping);
        $rule = $rules['masquerade'];
        if (!$this->checkRuleExists($rule)) {
            $cmd = $rule->getCommand('I');
            $this->executeCommand($cmd);
        }
    }

    protected function executeCommand(string $cmd): void
    {
        $res = Process::run($cmd);
        if ($res->exitCode() !== 0) {
            throw new \RuntimeException("Firewall command failed: {$cmd}. Error: " . $res->errorOutput());
        }
    }
}
