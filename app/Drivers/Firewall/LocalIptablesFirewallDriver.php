<?php

namespace App\Drivers\Firewall;

use App\Models\PortMapping;
use App\Services\Firewall\FirewallRuleEngine;

class LocalIptablesFirewallDriver implements FirewallDriver
{
    protected FirewallRuleEngine $engine;

    public function __construct(FirewallRuleEngine $engine)
    {
        $this->engine = $engine;
    }

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
        $this->engine->applyMapping($mapping);
    }

    /**
     * Remove a NAT port forwarding rule using iptables.
     */
    public function removePortForward(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        $this->engine->removeMapping($mapping);
    }

    public function ensureDNATRule(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        $this->engine->ensureDNATRule($mapping);
    }

    public function ensureForwardInboundRule(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        $this->engine->ensureForwardInboundRule($mapping);
    }

    public function ensureForwardReturnRule(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        $this->engine->ensureForwardReturnRule($mapping);
    }

    public function ensureMasqueradeRule(PortMapping $mapping): void
    {
        $this->checkSafetyMode();
        $this->engine->ensureMasqueradeRule($mapping);
    }
}
