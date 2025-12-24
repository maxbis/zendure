from zeroconf import Zeroconf, ServiceBrowser, ServiceListener
import socket
import time

class SimpleDiscoveryListener(ServiceListener):
    def __init__(self):
        self.found_devices = {}

    def add_service(self, zc, type_, name):
        info = zc.get_service_info(type_, name)
        if info:
            # Convert binary addresses to human-readable strings
            addresses = [socket.inet_ntoa(addr) for addr in info.addresses]
            if addresses:
                # Store the device name and its first IP
                self.found_devices[name] = addresses[0]

    def remove_service(self, zc, type_, name):
        pass

    def update_service(self, zc, type_, name):
        pass

def get_device_ip_by_name(search_string, timeout=5):
    """
    Scans the network and returns the IP address of the first device 
    whose name contains the search_string.
    """
    zeroconf = Zeroconf()
    listener = SimpleDiscoveryListener()
    
    # Browse for standard HTTP services (used by Zendure and HomeWizard)
    services = ["_http._tcp.local.", "_zendure._tcp.local.", "_hwenergy._tcp.local.", "_homewizard._tcp.local."]
    browsers = [ServiceBrowser(zeroconf, s, listener) for s in services]
    
    try:
        # Wait for devices to report in
        time.sleep(timeout)
        
        # Look through found devices for a name match
        for name, ip in listener.found_devices.items():
            # print(f"Found device: {name} with IP: {ip}")
            if search_string.lower() in name.lower():
                return ip
                
    finally:
        zeroconf.close()
    
    return None

# --- Example Usage ---
if __name__ == "__main__":
    # 1. Find Zendure
    zendure_ip = get_device_ip_by_name("Zendure") 
    print(f"Zendure IP: {zendure_ip}")

    # 2. Find HomeWizard
    # homewizard_ip = get_device_ip_by_name("P1")
    # print(f"HomeWizard IP: {homewizard_ip}")