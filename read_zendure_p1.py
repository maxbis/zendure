import requests
import json

class ZendureP1Meter:
    def __init__(self, ip_address):
        self.ip = ip_address
        # The endpoint /properties/report is standard for local Zendure devices
        self.url = f"http://{self.ip}/properties/report"
        
        # Initialize meter properties
        self.device_id = None
        self.total_power = None
        self.phase_a = None
        self.phase_b = None
        self.phase_c = None
        self.timestamp = None

    def update(self):
        """Fetches the latest grid readings from the Zendure P1 Meter."""
        try:
            response = requests.get(self.url, timeout=5)
            response.raise_for_status()
            data = response.json()
            
            # Map the exact keys from your specific response
            self.device_id = data.get("deviceId")
            self.total_power = data.get("total_power")
            self.phase_a = data.get("a_aprt_power")
            self.phase_b = data.get("b_aprt_power")
            self.phase_c = data.get("c_aprt_power")
            self.timestamp = data.get("timestamp")
            
            return True
        except Exception as e:
            print(f"Error connecting to Zendure P1 at {self.ip}: {e}")
            return False

    def print_readings(self):
        """Prints the grid power data in a readable format."""
        # Detect if you are importing or exporting based on the total_power sign
        status = "EXPORTING ☀️" if self.total_power < 0 else "IMPORTING ⚡"
        
        print(f"\n--- Zendure P1 Grid Report [{self.device_id}] ---")
        print(f"Status:        {status}")
        print(f"Total Power:   {self.total_power} W")
        print(f"Phase L1:      {self.phase_a} W")
        print(f"Phase L2:      {self.phase_b} W")
        print(f"Phase L3:      {self.phase_c} W")
        print(f"Timestamp:     {self.timestamp}")
        print("---------------------------------------------")

# --- Run the Script ---
if __name__ == "__main__":
    # Using the IP confirmed for your Meter device
    my_meter = ZendureP1Meter("192.168.2.94") 
    
    if my_meter.update():
        my_meter.print_readings()