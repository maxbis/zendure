import requests
import sys
import os

class ZendurePowerControl:
    """
    OOP class to control Zendure SolarFlow charge/discharge speeds.
    
    Can be used as a library or via command line.
    Based on Zendure documentation: acMode 1 = Input (charge), acMode 2 = Output (discharge)
    """
    def __init__(self, ip_address, sn):
        """
        Initialize the Zendure Power Control.
        
        Args:
            ip_address: IP address of the Zendure device
            sn: Serial number of the device
        """
        self.ip = ip_address
        self.sn = sn
        self.write_url = f"http://{self.ip}/properties/write"

    def _send_command(self, properties):
        """
        Internal helper to send the POST request to the device.
        
        Args:
            properties: Dictionary of properties to set
            
        Returns:
            Response JSON or None on error
        """
        payload = {
            "sn": self.sn,
            "properties": properties
        }
        try:
            response = requests.post(self.write_url, json=payload, timeout=5)
            print(response.json())
            response.raise_for_status()
            print(f"✅ Success: Set {properties}")
            return response.json()
        except Exception as e:
            print(f"❌ Failed to send command: {e}")
            return None

    def set_power(self, watts: int):
        """
        Set charge/discharge power.
        
        Args:
            watts: Power in watts
                - Positive: Charge from grid (acMode: 1, inputLimit: watts, outputLimit: 0)
                - Negative: Discharge to home (acMode: 2, outputLimit: abs(watts), inputLimit: 0)
                - Zero: Stop all charging and discharging (inputLimit: 0, outputLimit: 0)
        
        Returns:
            Response JSON or None on error
        """
        if watts > 0:
            # Charge mode: acMode 1 = Input
            print(f"Setting charge to {watts} Watts")
            return self._send_command({
                "acMode": 1,
                "inputLimit": watts,
                "outputLimit": 0
            })
        elif watts < 0:
            # Discharge mode: acMode 2 = Output
            discharge_watts = abs(watts)
            print(f"Setting discharge to {discharge_watts} Watts")
            return self._send_command({
                "acMode": 2,
                "outputLimit": discharge_watts,
                "inputLimit": 0,
                "pvStatus": 1
            })
        else:
            # Stop all
            print("Stopping all charging and discharging")
            return self._send_command({
                "inputLimit": 0,
                "outputLimit": 0
            })


def main():
    """
    Command-line interface for set_zendure.py
    
    Usage:
        python set_zendure.py <watts>
        
    Examples:
        python set_zendure.py 400      # Charge at 400W
        python set_zendure.py -400     # Discharge at 400W
        python set_zendure.py 0        # Stop all
    """
    # Default IP and SN (can be overridden via environment variables)
    default_ip = "192.168.2.93"
    default_sn = "HOA1NAN9N385989"
    
    ip_address = os.getenv("ZENDURE_IP", default_ip)
    sn = os.getenv("ZENDURE_SN", default_sn)
    
    # Parse command line argument
    if len(sys.argv) < 2:
        print("Usage: python set_zendure.py <watts>")
        print("  watts: Positive for charge, negative for discharge, 0 to stop")
        print("  Examples:")
        print("    python set_zendure.py 400      # Charge at 400W")
        print("    python set_zendure.py -400     # Discharge at 400W")
        print("    python set_zendure.py 0        # Stop all")
        sys.exit(1)
    
    try:
        watts = int(sys.argv[1])
    except ValueError:
        print(f"❌ Error: '{sys.argv[1]}' is not a valid integer")
        sys.exit(1)
    
    # Create controller and set power
    controller = ZendurePowerControl(ip_address, sn)
    controller.set_power(watts)


if __name__ == "__main__":
    main()

