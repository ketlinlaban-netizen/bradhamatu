export const MIKROTIK_TELEMETRY_GENERATOR_VERSION = "1.4.1";

export type TelemetryScriptInput = {
  routerId: string;
  token: string;
  intervalSeconds: number;
  endpoint: string;
  serverCertificateVerified: boolean;
};

export function generateMikroTikTelemetryScript(input: TelemetryScriptInput): string {
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(input.routerId)) {
    throw new Error("Router ID must be a UUID.");
  }
  if (!/^[0-9a-f]{64}$/.test(input.token)) {
    throw new Error("Telemetry token must be the 64-character generated secret.");
  }
  if (![5, 10, 60, 120, 300].includes(input.intervalSeconds)) {
    throw new Error("Telemetry interval must be 5, 10, 60, 120, or 300 seconds.");
  }
  if (!input.serverCertificateVerified) {
    throw new Error("First verify the public HTTPS certificate from this router with check-certificate=yes.");
  }
  let endpoint: URL;
  try {
    endpoint = new URL(input.endpoint);
  } catch {
    throw new Error("Telemetry endpoint must be a valid HTTPS ingestion URL.");
  }
  if (endpoint.protocol !== "https:"
    || endpoint.username !== ""
    || endpoint.password !== ""
    || endpoint.search !== ""
    || endpoint.hash !== ""
    || endpoint.pathname !== "/api/v1/internal/mikrotik/telemetry") {
    throw new Error("Telemetry endpoint must be the configured HTTPS ingestion URL.");
  }

  const suffix = input.routerId.slice(0, 8);
  const scriptName = `bm-telemetry-${suffix}`;
  const schedulerName = `${scriptName}-schedule`;
  const interval = `${input.intervalSeconds}s`;

  return `  # Bratha Matu telemetry schema v1; generator v${MIKROTIK_TELEMETRY_GENERATOR_VERSION} — self-contained read-only install package
  # RouterOS 7.23.1+ target. Importing this ONE file installs the collector and its scheduler.
  # This package creates or updates ONLY two named objects: script "${scriptName}" and scheduler "${schedulerName}".
  # It performs read-only find/get/print queries plus one outbound HTTPS POST. It never adds or changes
  #   DHCP leases, ARP, firewall, queues, hotspot, addresses, routes, interfaces, users, or services,
  #   so client connectivity, quotas and billing are unaffected. HTTPS certificate verification is mandatory.
  # Requires the router clock to be synchronized; preserves its configured time zone.
  :local installScriptName "${scriptName}"
  :local installSchedulerName "${schedulerName}"
  :local installScriptIds [/system script find where name=$installScriptName]
  :if ([:len $installScriptIds] > 1) do={ :error ("Multiple telemetry scripts named " . $installScriptName . " exist; remove the duplicates before installing.") }
  :if ([:len $installScriptIds] = 1) do={ /system script remove [:pick $installScriptIds 0] }
  /system script add name=$installScriptName policy=read,test source={
    :local routerId "${input.routerId}"
    :local token "${input.token}"
    :local scriptName "${scriptName}"
    :local runningJobs [/system script job find where script=$scriptName]
    :if ([:len $runningJobs] > 1) do={ :return }
    :local clockDate [/system clock get date]
    :local clockTime [/system clock get time]
    :local isoDate ""
    :if ([:pick $clockDate 4 5] = "-") do={
        :set isoDate $clockDate
    } else={
    :local monthText [:pick $clockDate 0 3]
    :local monthNumber "00"
    :if ($monthText = "jan") do={ :set monthNumber "01" }
    :if ($monthText = "feb") do={ :set monthNumber "02" }
    :if ($monthText = "mar") do={ :set monthNumber "03" }
    :if ($monthText = "apr") do={ :set monthNumber "04" }
    :if ($monthText = "may") do={ :set monthNumber "05" }
    :if ($monthText = "jun") do={ :set monthNumber "06" }
    :if ($monthText = "jul") do={ :set monthNumber "07" }
    :if ($monthText = "aug") do={ :set monthNumber "08" }
    :if ($monthText = "sep") do={ :set monthNumber "09" }
    :if ($monthText = "oct") do={ :set monthNumber "10" }
    :if ($monthText = "nov") do={ :set monthNumber "11" }
    :if ($monthText = "dec") do={ :set monthNumber "12" }
    :if ($monthNumber = "00") do={ :log error "Bratha telemetry: unsupported router date format"; :return }
    :local day [:pick $clockDate 4 6]
    :local year [:pick $clockDate 7 11]
        :set isoDate ($year . "-" . $monthNumber . "-" . $day)
    }
    :local offsetSeconds [/system clock get gmt-offset]
    :local offsetSign "+"
    :if ($offsetSeconds < 0) do={
        :set offsetSign "-"
        :set offsetSeconds (0 - $offsetSeconds)
    }
    :local offsetHours 0
    :while ($offsetSeconds >= 3600) do={
        :set offsetSeconds ($offsetSeconds - 3600)
        :set offsetHours ($offsetHours + 1)
    }
    :local offsetMinutes 0
    :while ($offsetSeconds >= 60) do={
        :set offsetSeconds ($offsetSeconds - 60)
        :set offsetMinutes ($offsetMinutes + 1)
    }
    :local offsetHourText [:tostr $offsetHours]
    :local offsetMinuteText [:tostr $offsetMinutes]
    :if ([:len $offsetHourText] = 1) do={ :set offsetHourText ("0" . $offsetHourText) }
    :if ([:len $offsetMinuteText] = 1) do={ :set offsetMinuteText ("0" . $offsetMinuteText) }
    :local utcOffset ($offsetSign . $offsetHourText . ":" . $offsetMinuteText)
    :local observedAt ($isoDate . "T" . $clockTime . $utcOffset)
    :local systemData {
        "identity"=[/system identity get name];
        "version"=[:tostr [/system resource get version]];
        "board_name"=[/system resource get board-name];
        "architecture"=[/system resource get architecture-name];
        "cpu_load"=[:tonum [/system resource get cpu-load]];
        "uptime"=[:tostr [/system resource get uptime]];
        "total_memory"=[:tonum [/system resource get total-memory]];
        "free_memory"=[:tonum [/system resource get free-memory]];
        "total_hdd_space"=[:tonum [/system resource get total-hdd-space]];
        "free_hdd_space"=[:tonum [/system resource get free-hdd-space]]
    }
    :local interfaces [:toarray ""]
    :local interfaceIds [:toarray ""]
    :local interfacesCollectedCount 0
    :local interfacesComplete true
    :local interfacesCollectionSucceeded false
    :do {
        :set interfaceIds [/interface find]
        :set interfacesCollectionSucceeded true
        :foreach interfaceId in=$interfaceIds do={
            :local interfaceName [/interface get $interfaceId name]
            :local interfaceData {
                "id"=[:tostr $interfaceId];
                "name"=$interfaceName;
                "type"=[/interface get $interfaceId type];
                "running"=[/interface get $interfaceId running];
                "disabled"=[/interface get $interfaceId disabled];
                "mac_address"=[/interface get $interfaceId mac-address];
                "rx_bytes"=[:tonum [/interface get $interfaceId rx-byte]];
                "tx_bytes"=[:tonum [/interface get $interfaceId tx-byte]];
                "ip_address"=""
            }
            :set ($interfaces->$interfacesCollectedCount) $interfaceData
            :set interfacesCollectedCount ($interfacesCollectedCount + 1)
            :if ($interfacesCollectedCount > 64) do={ :log error "Bratha telemetry: interface limit exceeded"; :return }
        }
    } on-error={
        :set interfacesComplete false
        :log warning "Bratha telemetry: interface collection was incomplete"
    }
    :if ($interfacesCollectedCount != [:len $interfaceIds]) do={ :set interfacesComplete false }
    :local routerMacAddress ""
    :local bridgeIds [/interface find where type="bridge"]
    :if ([:len $bridgeIds] = 1) do={
        :local bridgeMac [/interface get [:pick $bridgeIds 0] mac-address]
        :if ($bridgeMac != "00:00:00:00:00:00") do={ :set routerMacAddress $bridgeMac }
    }
    :local ipAddressIds [:toarray ""]
    :local ipAddresses [:toarray ""]
    :local ipAddressesCollectedCount 0
    :local ipAddressesComplete true
    :local ipAddressesCollectionSucceeded false
    :do {
        :set ipAddressIds [/ip address find]
        :set ipAddressesCollectionSucceeded true
        :foreach ipAddressId in=$ipAddressIds do={
            :local ipAddressData {
                "address"=[/ip address get $ipAddressId address];
                "network"=[/ip address get $ipAddressId network];
                "interface"=[/ip address get $ipAddressId interface];
                "disabled"=[/ip address get $ipAddressId disabled];
                "dynamic"=[/ip address get $ipAddressId dynamic]
            }
            :set ($ipAddresses->$ipAddressesCollectedCount) $ipAddressData
            :set ipAddressesCollectedCount ($ipAddressesCollectedCount + 1)
            :if ($ipAddressesCollectedCount > 128) do={ :log error "Bratha telemetry: IP address limit exceeded"; :return }
        }
    } on-error={
        :set ipAddressesComplete false
        :log warning "Bratha telemetry: IP address collection was incomplete"
    }
    :if ($ipAddressesCollectedCount != [:len $ipAddressIds]) do={ :set ipAddressesComplete false }
    :local routeIds [:toarray ""]
    :local routes [:toarray ""]
    :local routesCollectedCount 0
    :local routesComplete true
    :local routesCollectionSucceeded false
    :do {
        :set routeIds [/ip route find where dst-address="0.0.0.0/0"]
        :set routesCollectionSucceeded true
        :foreach routeId in=$routeIds do={
            :local routeData {
                "id"=[:tostr $routeId];
                "dst_address"=[/ip route get $routeId dst-address];
                "gateway"=[/ip route get $routeId gateway];
                "immediate_gateway"=[:tostr [/ip route get $routeId immediate-gw]];
                "distance"=[:tonum [/ip route get $routeId distance]];
                "active"=[/ip route get $routeId active];
                "dynamic"=[/ip route get $routeId dynamic];
                "static"=[/ip route get $routeId static];
                "disabled"=[/ip route get $routeId disabled];
                "routing_table"=[/ip route get $routeId routing-table]
            }
            :set ($routes->$routesCollectedCount) $routeData
            :set routesCollectedCount ($routesCollectedCount + 1)
            :if ($routesCollectedCount > 64) do={ :log error "Bratha telemetry: default route limit exceeded"; :return }
        }
    } on-error={
        :set routesComplete false
        :log warning "Bratha telemetry: default route collection was incomplete"
    }
    :if ($routesCollectedCount != [:len $routeIds]) do={ :set routesComplete false }
    :local dhcpLeaseIds [:toarray ""]
    :local leases [:toarray ""]
    :local dhcpLeasesCollectedCount 0
    :local dhcpLeasesComplete true
    :local dhcpLeasesCollectionSucceeded false
    :do {
        :set dhcpLeaseIds [/ip dhcp-server lease find]
        :set dhcpLeasesCollectionSucceeded true
        :foreach leaseId in=$dhcpLeaseIds do={
            :local leaseStatus [/ip dhcp-server lease get $leaseId status]
            :if ($leaseStatus != "bound") do={
                :if ($leaseStatus != "waiting") do={
                    :if ($leaseStatus != "offered") do={ :set leaseStatus "unknown" }
                }
            }
            :local leaseServer [/ip dhcp-server lease get $leaseId server]
            :local leaseInterface $leaseServer
            :local leaseServerIds [/ip dhcp-server find where name=$leaseServer]
            :if ([:len $leaseServerIds] > 0) do={
                :set leaseInterface [/ip dhcp-server get [:pick $leaseServerIds 0] interface]
            }
            :local leaseData {
                "mac_address"=[/ip dhcp-server lease get $leaseId mac-address];
                "host_name"=[/ip dhcp-server lease get $leaseId host-name];
                "address"=[/ip dhcp-server lease get $leaseId address];
                "interface"=$leaseInterface;
                "status"=$leaseStatus;
                "dynamic"=[/ip dhcp-server lease get $leaseId dynamic];
                "expires_after"=[/ip dhcp-server lease get $leaseId expires-after]
            }
            :set ($leases->$dhcpLeasesCollectedCount) $leaseData
            :set dhcpLeasesCollectedCount ($dhcpLeasesCollectedCount + 1)
            :if ($dhcpLeasesCollectedCount > 512) do={ :log error "Bratha telemetry: DHCP lease limit exceeded"; :return }
        }
    } on-error={
        :set dhcpLeasesComplete false
        :log warning "Bratha telemetry: DHCP lease collection was incomplete"
    }
    :if ($dhcpLeasesCollectedCount != [:len $dhcpLeaseIds]) do={ :set dhcpLeasesComplete false }
    :local wirelessRegistrationIds [:toarray ""]
    :local wirelessClients [:toarray ""]
    :local wirelessCollectedCount 0
    :local wirelessCollectionComplete true
    :local wirelessCollectionSucceeded false
    :local wirelessRegistrationPath "wifi"
    :do {
        :set wirelessRegistrationIds [/interface wifi registration-table find]
        :set wirelessCollectionSucceeded true
    } on-error={
        :set wirelessRegistrationPath "wireless"
        :do {
            :set wirelessRegistrationIds [/interface wireless registration-table find]
            :set wirelessCollectionSucceeded true
        } on-error={
            :set wirelessCollectionComplete false
            :log warning "Bratha telemetry: neither WiFi registration table was available"
        }
    }
    :if ($wirelessCollectionSucceeded) do={
        :if ([:len $wirelessRegistrationIds] = 0) do={
            :do {
                :local legacyRegistrationIds [/interface wireless registration-table find]
                :if ([:len $legacyRegistrationIds] > 0) do={
                    :set wirelessRegistrationIds $legacyRegistrationIds
                    :set wirelessRegistrationPath "wireless"
                }
            } on-error={
                :set wirelessRegistrationPath "wifi"
            }
        }
    }
    :if ($wirelessCollectionSucceeded) do={
      :foreach clientId in=$wirelessRegistrationIds do={
        :do {
            :local signalText ""
            :local clientInterface ""
            :local clientMac ""
            :local clientBytes ""
            :if ($wirelessRegistrationPath = "wifi") do={
                :set clientInterface [/interface wifi registration-table get $clientId interface]
                :set clientMac [/interface wifi registration-table get $clientId mac-address]
                :set signalText [:tostr [/interface wifi registration-table get $clientId signal]]
                :set clientBytes ([:tostr [/interface wifi registration-table get $clientId rx-bytes]] . "," . [:tostr [/interface wifi registration-table get $clientId tx-bytes]])
            } else={
                :set clientInterface [/interface wireless registration-table get $clientId interface]
                :set clientMac [/interface wireless registration-table get $clientId mac-address]
                :set signalText [:tostr [/interface wireless registration-table get $clientId signal-strength]]
                :set clientBytes [/interface wireless registration-table get $clientId bytes]
            }
            :local signalStrength 0
            :local signalEnd [:find $signalText "dBm"]
            :if ([:typeof $signalEnd] = "num") do={
                :set signalStrength [:tonum [:pick $signalText 0 $signalEnd]]
            } else={
                :set signalStrength [:tonum $signalText]
            }
            :local clientData {
                "id"=[:tostr $clientId];
                "interface"=$clientInterface;
                "mac_address"=$clientMac;
                "signal_strength"=$signalStrength;
                "bytes"=$clientBytes
            }
            :set ($wirelessClients->$wirelessCollectedCount) $clientData
            :set wirelessCollectedCount ($wirelessCollectedCount + 1)
            :if ($wirelessCollectedCount > 256) do={ :log error "Bratha telemetry: wireless client limit exceeded"; :return }
        } on-error={
            :set wirelessCollectionComplete false
            :log warning "Bratha telemetry: a wireless registration row could not be collected"
        }
      }
    }
    :if ($wirelessCollectedCount != [:len $wirelessRegistrationIds]) do={ :set wirelessCollectionComplete false }
    :local bridgeHostIds [:toarray ""]
    :local bridgeHosts [:toarray ""]
    :local bridgeHostCount 0
    :local bridgeHostsCollectedCount 0
    :local bridgeHostsCollectionSucceeded false
    :local bridgeHostsComplete true
    :do {
        :set bridgeHostIds [/interface bridge host find]
        :set bridgeHostsCollectionSucceeded true
        :foreach bridgeHostId in=$bridgeHostIds do={
            :do {
                :local bridgeHostDynamic [/interface bridge host get $bridgeHostId dynamic]
                :local bridgeHostLocal [/interface bridge host get $bridgeHostId local]
                :local bridgeHostMac [/interface bridge host get $bridgeHostId mac-address]
                :if ($bridgeHostDynamic = true) do={
                    :if ($bridgeHostLocal = false) do={
                        :if ([:pick $bridgeHostMac 0 5] != "00:00:") do={
                            :set bridgeHostCount ($bridgeHostCount + 1)
                            :local bridgeHostData {
                                "id"=[:tostr $bridgeHostId];
                                "mac_address"=$bridgeHostMac;
                                "bridge"=[/interface bridge host get $bridgeHostId bridge];
                                "on_interface"=[/interface bridge host get $bridgeHostId on-interface];
                                "local"=$bridgeHostLocal;
                                "dynamic"=$bridgeHostDynamic
                            }
                            :set ($bridgeHosts->$bridgeHostsCollectedCount) $bridgeHostData
                            :set bridgeHostsCollectedCount ($bridgeHostsCollectedCount + 1)
                            :if ($bridgeHostsCollectedCount > 512) do={ :log error "Bratha telemetry: bridge host limit exceeded"; :return }
                        }
                    }
                }
            } on-error={
                :set bridgeHostsComplete false
                :log warning "Bratha telemetry: a bridge host row could not be collected"
            }
        }
    } on-error={
        :set bridgeHostsCollectionSucceeded false
        :set bridgeHostsComplete false
        :log warning "Bratha telemetry: bridge host collection failed"
    }
    :if ($bridgeHostsCollectedCount != $bridgeHostCount) do={ :set bridgeHostsComplete false }
    :local arpNeighborIds [:toarray ""]
    :local arpNeighbors [:toarray ""]
    :local arpNeighborCount 0
    :local arpNeighborsCollectedCount 0
    :local arpNeighborsCollectionSucceeded false
    :local arpNeighborsComplete true
    :do {
        :set arpNeighborIds [/ip arp find]
        :set arpNeighborsCollectionSucceeded true
        :foreach arpNeighborId in=$arpNeighborIds do={
            :do {
                :local arpDynamic [/ip arp get $arpNeighborId dynamic]
                :if ($arpDynamic = true) do={
                    :local arpAddress [/ip arp get $arpNeighborId address]
                    :local arpMacAddress [/ip arp get $arpNeighborId mac-address]
                    :local arpInterface [/ip arp get $arpNeighborId interface]
                    :if ([:len $arpAddress] > 0) do={
                        :if ([:len $arpMacAddress] > 0) do={
                            :if ($arpMacAddress != "00:00:00:00:00:00") do={
                                :set arpNeighborCount ($arpNeighborCount + 1)
                                :local arpNeighborData {
                                    "address"=$arpAddress;
                                    "mac_address"=$arpMacAddress;
                                    "interface"=$arpInterface;
                                    "dynamic"=$arpDynamic
                                }
                                :set ($arpNeighbors->$arpNeighborsCollectedCount) $arpNeighborData
                                :set arpNeighborsCollectedCount ($arpNeighborsCollectedCount + 1)
                                :if ($arpNeighborsCollectedCount > 256) do={ :log error "Bratha telemetry: ARP neighbor limit exceeded"; :return }
                            }
                        }
                    }
                }
            } on-error={
                :set arpNeighborsComplete false
                :log warning "Bratha telemetry: an ARP neighbor row could not be collected"
            }
        }
    } on-error={
        :set arpNeighborsCollectionSucceeded false
        :set arpNeighborsComplete false
        :log warning "Bratha telemetry: ARP neighbor collection failed"
    }
    :if ($arpNeighborsCollectedCount != $arpNeighborCount) do={ :set arpNeighborsComplete false }
    :local snapshotId ($isoDate . "_" . [:pick $clockTime 0 2] . "_" . [:pick $clockTime 3 5] . "_" . [:pick $clockTime 6 8])
    :local payload {
        "schema_version"=1;
        "router_id"=$routerId;
        "snapshot_id"=$snapshotId;
        "observed_at"=$observedAt;
        "system"=$systemData;
        "interfaces"=$interfaces;
        "interfaces_collection_succeeded"=$interfacesCollectionSucceeded;
        "interfaces_complete"=$interfacesComplete;
        "interface_count"=[:len $interfaceIds];
        "interfaces_collected_count"=$interfacesCollectedCount;
        "dhcp_leases_collection_succeeded"=$dhcpLeasesCollectionSucceeded;
        "dhcp_lease_dynamic_supported"=true;
        "router_mac_address"=$routerMacAddress;
        "ip_addresses"=$ipAddresses;
        "ip_addresses_collection_succeeded"=$ipAddressesCollectionSucceeded;
        "ip_addresses_complete"=$ipAddressesComplete;
        "ip_address_count"=[:len $ipAddressIds];
        "ip_addresses_collected_count"=$ipAddressesCollectedCount;
        "routes"=$routes;
        "routes_collection_succeeded"=$routesCollectionSucceeded;
        "routes_complete"=$routesComplete;
        "route_count"=[:len $routeIds];
        "routes_collected_count"=$routesCollectedCount;
        "dhcp_leases"=$leases;
        "dhcp_leases_complete"=$dhcpLeasesComplete;
        "dhcp_lease_count"=[:len $dhcpLeaseIds];
        "dhcp_collected_count"=$dhcpLeasesCollectedCount;
        "wireless_clients"=$wirelessClients;
        "wireless_clients_collection_succeeded"=$wirelessCollectionSucceeded;
        "wireless_clients_complete"=$wirelessCollectionComplete;
        "wireless_registration_count"=[:len $wirelessRegistrationIds];
        "wireless_collected_count"=$wirelessCollectedCount;
        "bridge_hosts"=$bridgeHosts;
        "bridge_hosts_collection_succeeded"=$bridgeHostsCollectionSucceeded;
        "bridge_hosts_complete"=$bridgeHostsComplete;
        "bridge_host_count"=$bridgeHostCount;
        "bridge_hosts_collected_count"=$bridgeHostsCollectedCount;
        "arp_neighbors"=$arpNeighbors;
        "arp_neighbors_collection_succeeded"=$arpNeighborsCollectionSucceeded;
        "arp_neighbors_complete"=$arpNeighborsComplete;
        "arp_neighbor_count"=$arpNeighborCount;
        "arp_neighbors_collected_count"=$arpNeighborsCollectedCount
    }
    :local json [:serialize to=json value=$payload]
    :if ([:len $json] > 48000) do={ :log error "Bratha telemetry: payload limit exceeded"; :return }
    :do {
        :local fetchResult [/tool fetch url="${endpoint.toString()}" http-method=post http-header-field=("Content-Type: application/json,Authorization: Bearer " . $token) http-data=$json check-certificate=yes as-value output=user idle-timeout=10s]
        :if (($fetchResult->"status") != "finished") do={ :log warning "Bratha telemetry: HTTPS request did not finish successfully"; :return }
        :local responseCode ($fetchResult->"code")
        :if ([:typeof $responseCode] = "num") do={
            :if ($responseCode < 200) do={
                :log warning ("Bratha telemetry: endpoint returned HTTP " . [:tostr $responseCode])
            } else={
                :if ($responseCode >= 300) do={
                    :log warning ("Bratha telemetry: endpoint returned HTTP " . [:tostr $responseCode])
                }
            }
        } else={
            :log warning "Bratha telemetry: HTTPS finished without an HTTP response code"
        }
    } on-error={
        :log warning "Bratha telemetry: HTTPS submission failed; inspect RouterOS log and endpoint response"
    }
}
# Install the scheduler for the same interval. Guarded and idempotent: it only ever manages "${schedulerName}".
:local installSchedulerIds [/system scheduler find where name=$installSchedulerName]
:if ([:len $installSchedulerIds] > 1) do={ :error ("Multiple telemetry schedulers named " . $installSchedulerName . " exist; remove the duplicates before installing.") }
:if ([:len $installSchedulerIds] = 1) do={ /system scheduler remove [:pick $installSchedulerIds 0] }
/system scheduler add name=$installSchedulerName interval=${interval} start-time=startup policy=read,test on-event="/system script run ${scriptName}"
# Send the first report immediately so ingestion can be verified without waiting for the interval.
/system script run ${scriptName}
# Verify after import with read-only prints:
# /system script print detail where name="${scriptName}"
# /system scheduler print detail where name="${schedulerName}"
# Rollback — removes ONLY the two objects this package created:
# /system scheduler remove [find where name="${schedulerName}"]
# /system script remove [find where name="${scriptName}"]
`;
}
