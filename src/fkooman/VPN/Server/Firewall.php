<?php
/**
 * Copyright 2016 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace fkooman\VPN\Server;

class Firewall
{
    public static function getFirewall4(Pools $p, $asArray = false)
    {
        return self::getFirewall($p, 4, $asArray);
    }

    public static function getFirewall6(Pools $p, $asArray = false)
    {
        return self::getFirewall($p, 6, $asArray);
    }

    private static function getFirewall(Pools $p, $inetFamily, $asArray)
    {
        $firewall = [];

        // NAT
        $firewall = array_merge($firewall, self::getNat($p, $inetFamily));

        // FILTER
        $firewall = array_merge($firewall, self::getFilter($p, $inetFamily));

        if ($asArray) {
            return $firewall;
        }

        return implode(PHP_EOL, $firewall).PHP_EOL;
    }

    private static function getNat(Pools $p, $inetFamily)
    {
        $nat = [
            '*nat',
            ':PREROUTING ACCEPT [0:0]',
            ':INPUT ACCEPT [0:0]',
            ':OUTPUT ACCEPT [0:0]',
            ':POSTROUTING ACCEPT [0:0]',
        ];

        foreach ($p as $pool) {
            if ($pool->getUseNat()) {
                if (4 === $inetFamily) {
                    // get the IPv4 range
                    $srcNet = $pool->getRange()->getAddressPrefix();
                } else {
                    // get the IPv6 range
                    $srcNet = $pool->getRange6()->getAddressPrefix();
                }
                // -i (--in-interface) cannot be specified for POSTROUTING
                $nat[] = sprintf('-A POSTROUTING -s %s -o %s -j MASQUERADE', $srcNet, $pool->getExtIf());
            }
        }
        $nat[] = 'COMMIT';

        return $nat;
    }

    private static function getFilter(Pools $p, $inetFamily)
    {
        $filter = [
            '*filter',
            ':INPUT ACCEPT [0:0]',
            ':FORWARD ACCEPT [0:0]',
            ':OUTPUT ACCEPT [0:0]',
        ];

        // INPUT
        $filter = array_merge($filter, self::getInputChain($p, $inetFamily));

        // FORWARD
        $filter = array_merge($filter, self::getForwardChain($p, $inetFamily));

        $filter[] = 'COMMIT';

        return $filter;
    }

    private static function getInputChain(Pools $p, $inetFamily)
    {
        $inputChain = [
            '-A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT',
            sprintf('-A INPUT -p %s -j ACCEPT', 4 === $inetFamily ? 'icmp' : 'ipv6-icmp'),
            '-A INPUT -i lo -j ACCEPT',
        ];

        $inputPorts = self::getIngressPorts($p);
        foreach ($inputPorts as $inputPort) {
            list($proto, $port) = explode('/', $inputPort);
            $inputChain[] = sprintf('-A INPUT -m state --state NEW -m %s -p %s --dport %d -j ACCEPT', $proto, $proto, $port);
        }

        $inputChain[] = sprintf('-A INPUT -j REJECT --reject-with %s', 4 === $inetFamily ? 'icmp-host-prohibited' : 'icmp6-adm-prohibited');

        return $inputChain;
    }

    private static function getForwardChain(Pools $p, $inetFamily)
    {
        $forwardChain = [
            //sprintf('-A FORWARD -p %s -j ACCEPT', 4 === $inetFamily ? 'icmp' : 'ipv6-icmp'),
            '-A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT',
        ];

        foreach ($p as $pool) {
            if (6 === $inetFamily && !$pool->getForward6()) {
                // IPv6 forwarding was disabled
                continue;
            }

            if (4 === $inetFamily) {
                // get the IPv4 range
                $srcNet = $pool->getRange()->getAddressPrefix();
            } else {
                // get the IPv6 range
                $srcNet = $pool->getRange6()->getAddressPrefix();
            }
            $forwardChain[] = sprintf('-N vpn-%s', $pool->getId());

            $forwardChain[] = sprintf('-A FORWARD -i tun-%s+ -s %s -j vpn-%s', $pool->getId(), $srcNet, $pool->getId());

            // merge outgoing forwarding firewall rules to prevent certain
            // traffic
            $forwardChain = array_merge($forwardChain, self::getForwardFirewall($pool, $inetFamily));

            if ($pool->getClientToClient()) {
                // allow client-to-client
                $forwardChain[] = sprintf('-A vpn-%s -o tun-%s+ -d %s -j ACCEPT', $pool->getId(), $pool->getId(), $srcNet);
            }
            if ($pool->getDefaultGateway()) {
                // allow traffic to all outgoing destinations
                $forwardChain[] = sprintf('-A vpn-%s -o %s -j ACCEPT', $pool->getId(), $pool->getExtIf(), $srcNet);
            } else {
                // only allow certain traffic to the external interface
                foreach ($pool->getRoutes() as $route) {
                    if ($inetFamily === $route->getFamily()) {
                        $forwardChain[] = sprintf('-A vpn-%s -o %s -d %s -j ACCEPT', $pool->getId(), $pool->getExtIf(), $route->getAddressPrefix());
                    }
                }
            }
        }

        $forwardChain[] = sprintf('-A FORWARD -j REJECT --reject-with %s', 4 === $inetFamily ? 'icmp-host-prohibited' : 'icmp6-adm-prohibited');

        return $forwardChain;
    }

    private static function getForwardFirewall(Pool $pool, $inetFamily)
    {
        $forwardFirewall = [];

        if ($pool->getBlockSmb()) {
            // drop SMB outgoing traffic
            // @see https://medium.com/@ValdikSS/deanonymizing-windows-users-and-capturing-microsoft-and-vpn-accounts-f7e53fe73834
            foreach (['tcp', 'udp'] as $proto) {
                $forwardFirewall[] = sprintf(
                    '-A vpn-%s -o %s -m multiport -p %s --dports 137:139,445 -j REJECT --reject-with %s',
                    $pool->getId(),
                    $pool->getExtIf(),
                    $proto,
                    4 === $inetFamily ? 'icmp-host-prohibited' : 'icmp6-adm-prohibited');
            }
        }

        return $forwardFirewall;
    }

    private static function getIngressPorts(Pools $p)
    {
        $ingressPorts = ['tcp/22', 'tcp/80', 'tcp/443'];

        // we only care about additional UDP ports to connect to the OpenVPN
        // instances, as we only want UDP and fallback to tcp/443
        foreach ($p as $pool) {
            foreach ($pool->getInstances() as $instance) {
                if ('udp' === $instance->getProto()) {
                    $port = sprintf('udp/%d', $instance->getPort());
                    if (!in_array($port, $ingressPorts)) {
                        $ingressPorts[] = $port;
                    }
                }
            }
        }

        return $ingressPorts;
    }
}
