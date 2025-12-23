import requests
import time

class P1Meter:
    def __init__(self, ip_address):
        self.url = f"http://{ip_address}/api/v1/data"
        # Initializing core properties as None
        self.wifi_strength = None
        self.active_power_w = None
        self.total_power_import_kwh = None
        self.total_power_export_kwh = None
        self.total_gas_m3 = None
        self.meter_model = None
        self.active_voltage_v = None

    def update_readings(self):
        """Fetches data from the API and stores it in class properties."""
        try:
            headers = {
                'User-Agent': 'Mozilla/5.0',
                'Accept': 'application/json'
            }
            response = requests.get(self.url, headers=headers, timeout=5)
            response.raise_for_status()  # Check for HTTP errors
            data = response.json()

            # Mapping JSON keys to class properties
            self.wifi_strength = data.get("wifi_strength")
            self.meter_model = data.get("meter_model")
            self.active_power_w = data.get("active_power_w")
            self.total_power_import_kwh = data.get("total_power_import_kwh")
            self.total_power_export_kwh = data.get("total_power_export_kwh")
            self.total_gas_m3 = data.get("total_gas_m3")
            self.active_voltage_v = data.get("active_voltage_l1_v")
            
            return True
        except Exception as e:
            print(f"Error fetching data: {e}")
            return False

# Usage
if __name__ == "__main__":
    # Replace with your actual IP if different
    my_meter = P1Meter("192.168.2.10")
    
    second_count = 0
    
    try:
        while True:
            second_count += 1
            current_time = time.time()
            
            if my_meter.update_readings():
                if my_meter.active_power_w is not None:
                    # Format: 001 sec: 0217 W (convert float to int)
                    power_int = int(round(my_meter.active_power_w))
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

