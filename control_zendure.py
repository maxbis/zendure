import requests
import json

class SolarFlowControl:
    def __init__(self, ip_address, sn):
        self.ip = ip_address
        self.sn = sn
        self.write_url = f"http://{self.ip}/properties/write"

    def _send_command(self, properties):
        """Internal helper to send the POST request."""
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

    def set_charge_400w(self, watts: int = 400):
        """Sets the grid charging input to 400 Watts."""
        # Note: inputLimit controls how much it takes FROM the grid
        print(f"Setting charge to {watts} Watts")
        return self._send_command({"inputLimit": watts})

    def force_charge_400w(self):
        """Sets mode to Manual (1) and attempts charging."""
        return self._send_command({
            "acMode": 1, 
            "inputLimit": 400,
            "outputLimit": 0
        })

    def set_discharge_400w(self):
        """Sets the AC output to the home to 400 Watts."""
        # Note: outputLimit controls how much it sends TO the home
        return self._send_command({"outputLimit": 400})

    def force_discharge_400w(self):
            """
            Forces the battery to discharge 400W to the home.
            1. acMode 1: Sets to Manual Mode.
            2. outputLimit 400: Sets discharge target.
            3. inputLimit 0: Ensures grid charging is OFF.
            """
            return self._send_command({
                "acMode": 2,
                "outputLimit": 400,
                "inputLimit": 0,
                "pvStatus": 1
            })

    def stop_all(self):
        """Stops both charging and discharging by setting limits to 0."""
        return self._send_command({
            "inputLimit": 0,
            "outputLimit": 0,
            "packState": 0
        })

# --- Usage ---
# Use the IP and SN you found earlier
my_sf = SolarFlowControl("192.168.2.93", "HOA1NAN9N385989")
# my_sf.stop_all()
#my_sf.set_charge_400w(100)
my_sf.stop_all()
# my_sf.force_discharge_400w()
# Example commands:
# my_sf.force_charge_400w()

# my_sf.stop_all()
# my_sf.set_discharge_400w()
# my_sf.stop_all()