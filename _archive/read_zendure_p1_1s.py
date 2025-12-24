import requests
import json
import time

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

# --- Run the Script ---
if __name__ == "__main__":
    # Using the IP confirmed for your Meter device
    my_meter = ZendureP1Meter("192.168.2.94")
    
    second_count = 0
    
    try:
        while True:
            second_count += 1
            current_time = time.time()
            
            if my_meter.update():
                if my_meter.total_power is not None:
                    # Format: 001 sec: 0217 W (convert float to int)
                    power_int = int(round(my_meter.total_power))
                    print(f"{second_count:03d} sec: {power_int:04d} W")
                else:
                    print(f"{second_count:03d} sec: N/A")
            else:
                print(f"{second_count:03d} sec: Error reading meter")
            
            # Sleep until next second
            elapsed = time.time() - current_time
            sleep_time = 1.0 - elapsed
            if sleep_time > 0:
                time.sleep(sleep_time)
                
    except KeyboardInterrupt:
        print("\nStopped by user")

