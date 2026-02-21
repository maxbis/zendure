"""
Scan the local network for Zendure devices via Zeroconf and keep
`config.json` in sync with the discovered IP addresses.

Targets:
- Zendure-solarFlow2400AC-HOA1NAN9N385989._zendure._tcp.local. -> deviceIp
- Zendure-MeterReader-ZEE1NBN9N420947._zendure._tcp.local. -> p1MeterIp
"""

import json
import logging
import socket
import time
from pathlib import Path
from typing import Dict, Optional

from zeroconf import Zeroconf, ZeroconfServiceTypes, ServiceBrowser, ServiceListener


CONFIG_PATH = Path(__file__).with_name("config.json")

# Map service instance names to config keys we need to maintain.
TARGET_SERVICES = {
    "Zendure-solarFlow2400AC-HOA1NAN9N385989._zendure._tcp.local.": "deviceIp",
    "Zendure-MeterReader-ZEE1NBN9N420947._zendure._tcp.local.": "p1MeterIp",
}

# Services to listen for, mirroring the original debug script for context.
LISTEN_SERVICES = ["_zendure._tcp.local."]


def _ipv4_from_info(info) -> Optional[str]:
    """Return first IPv4 address from Zeroconf service info, if present."""
    if not info or not info.addresses:
        return None
    for addr in info.addresses:
        ip = socket.inet_ntoa(addr)
        # Ignore obvious IPv6 placeholders; Zeroconf returns IPv4 bytes for IPv4.
        if "." in ip:
            return ip
    return None


class CollectListener(ServiceListener):
    def __init__(self, targets: Dict[str, str]):
        self.targets = targets
        self.found: Dict[str, Optional[str]] = {}

    def add_service(self, zc: Zeroconf, type_: str, name: str) -> None:
        info = zc.get_service_info(type_, name)
        print(f"\n📡 FOUND SOMETHING: {name}")
        if info:
            ip = _ipv4_from_info(info)
            print(f"   IP: {ip or 'Unknown'}")
            print(f"   Type: {type_}")
            if "zendure" in name.lower():
                print("   ⭐ MATCH: This looks like your Zendure device!")
            if name in self.targets:
                self.found[name] = ip

    def remove_service(self, zc: Zeroconf, type_: str, name: str) -> None:
        # Not required for this diagnostic script.
        return

    def update_service(self, zc: Zeroconf, type_: str, name: str) -> None:
        # Not required for this diagnostic script.
        return


def load_config() -> Dict:
    with CONFIG_PATH.open("r", encoding="utf-8") as fh:
        return json.load(fh)


def write_config(config: Dict) -> None:
    with CONFIG_PATH.open("w", encoding="utf-8") as fh:
        json.dump(config, fh, indent=2)
        fh.write("\n")


def sync_config(found_ips: Dict[str, Optional[str]]) -> None:
    config = load_config()
    changed = False

    for service_name, config_key in TARGET_SERVICES.items():
        discovered_ip = found_ips.get(service_name)
        if not discovered_ip:
            print(f"⚠️  No IP found for {service_name} (config {config_key} stays {config.get(config_key)})")
            continue

        current_ip = config.get(config_key)
        if current_ip != discovered_ip:
            print(f"🔄 Updating {config_key}: {current_ip} -> {discovered_ip}")
            config[config_key] = discovered_ip
            changed = True
        else:
            print(f"✅ {config_key} already matches discovered IP {discovered_ip}")

    if changed:
        write_config(config)
        print(f"💾 config.json updated at {CONFIG_PATH}")
    else:
        print("ℹ️  config.json already up to date; no changes written.")


def run_scan(duration_seconds: int = 15) -> None:
    logging.basicConfig(level=logging.INFO)

    # print("🔍 Scanning for all service types available on your network...")
    # all_types = ZeroconfServiceTypes.find()
    # print(f"Detected service types: {list(all_types)}")

    print(f"\n🔍 Actively listening for {LISTEN_SERVICES} ...")
    zc = Zeroconf()
    listener = CollectListener(TARGET_SERVICES)
    browsers = [ServiceBrowser(zc, service, listener) for service in LISTEN_SERVICES]

    try:
        time.sleep(duration_seconds)
    except KeyboardInterrupt:
        pass
    finally:
        zc.close()
        print("\nScan Finished.")

    print("\n=== Discovery Summary ===")
    for target in TARGET_SERVICES:
        ip = listener.found.get(target)
        print(f"{target}: {ip or 'Not found'}")

    print("\n=== Config Sync ===")
    sync_config(listener.found)


if __name__ == "__main__":
    run_scan()
