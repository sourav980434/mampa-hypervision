<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\PortMapping;
use App\Services\Firewall\FirewallRuleEngine;

class FirewallRuleEngineTest extends TestCase
{
    public function test_rules_generation_matches_manual_verified_rules(): void
    {
        $engine = new FirewallRuleEngine();
        $mapping = new PortMapping([
            'public_port' => 50003,
            'internal_ip' => '192.168.122.110',
            'internal_port' => 3389,
            'protocol' => 'tcp',
        ]);

        $rules = $engine->generateRules($mapping);

        $this->assertArrayHasKey('dnat', $rules);
        $this->assertArrayHasKey('forward_in', $rules);
        $this->assertArrayHasKey('forward_out', $rules);
        $this->assertArrayHasKey('masquerade', $rules);

        $ext = $engine->getExternalInterface();
        $bridge = $engine->getInternalBridge();
        $subnet = $engine->getInternalSubnet();

        // 1. DNAT rule
        $dnatCmd = $rules['dnat']->getCommand('A');
        $expectedDnat = "sudo iptables -t nat -A PREROUTING -i {$ext} -p tcp --dport 50003 -j DNAT --to-destination 192.168.122.110:3389";
        $this->assertEquals($expectedDnat, $dnatCmd);

        // 2. FORWARD Inbound
        $forwardInCmd = $rules['forward_in']->getCommand('I');
        $expectedForwardIn = "sudo iptables -I FORWARD -i {$ext} -o {$bridge} -p tcp -d 192.168.122.110 --dport 3389 -j ACCEPT";
        $this->assertEquals($expectedForwardIn, $forwardInCmd);

        // 3. FORWARD Return
        $forwardOutCmd = $rules['forward_out']->getCommand('I');
        $expectedForwardOut = "sudo iptables -I FORWARD -i {$bridge} -o {$ext} -p tcp -s 192.168.122.110 --sport 3389 -j ACCEPT";
        $this->assertEquals($expectedForwardOut, $forwardOutCmd);

        // 4. POSTROUTING Masquerade
        $masqueradeCmd = $rules['masquerade']->getCommand('I');
        $expectedMasquerade = "sudo iptables -t nat -I POSTROUTING -s {$subnet} -o {$ext} -j MASQUERADE";
        $this->assertEquals($expectedMasquerade, $masqueradeCmd);
    }
}
