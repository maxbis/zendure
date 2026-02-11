import logging
import socket
import time
from zeroconf import Zeroconf, ServiceBrowser, ServiceListener, ZeroconfServiceTypes

# Enable internal zeroconf logging for deep debugging
logging.basicConfig(level=logging.DEBUG)

class DebugListener(ServiceListener):
    def add_service(self, zc, type_, name):
        info = zc.get_service_info(type_, name)
        print(f"\n📡 FOUND SOMETHING: {name}")
        if info:
            addresses = [socket.inet_ntoa(addr) for addr in info.addresses]
            print(f"   IP: {addresses[0] if addresses else 'Unknown'}")
            print(f"   Type: {type_}")
            # Look for Zendure hints
            if "zendure" in name.lower() or "aio" in name.lower():
                print("   ⭐ MATCH: This looks like your Zendure device!")

    def remove_service(self, zc, type_, name):
        pass

    def update_service(self, zc, type_, name):
        pass

def run_debug_discovery():
    # Attempt to find all available service types first
    print("🔍 Step 1: Scanning for all service types available on your network...")
    all_types = ZeroconfServiceTypes.find()
    print(f"Detected service types: {list(all_types)}")

    zc = Zeroconf()
    listener = DebugListener()
    
    # We will browse the most common ones simultaneously
    services = ["_http._tcp.local.", "_zendure._tcp.local.", "_hwenergy._tcp.local."]
    
    print(f"\n🔍 Step 2: Actively listening for {services}...")
    browsers = [ServiceBrowser(zc, s, listener) for s in services]
    
    try:
        # Give it 15 seconds to catch "sleepy" battery-powered signals
        time.sleep(15)
    except KeyboardInterrupt:
        pass
    finally:
        zc.close()
        print("\nScan Finished.")

if __name__ == "__main__":
    run_debug_discovery()