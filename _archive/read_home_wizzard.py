import requests

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

            # print(data)

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

    def print_readings(self):
        """Prints the current stored properties in a readable format."""
        print("--- HomeWizard P1 Meter Status ---")
        print(f"Model:           {self.meter_model}")
        print(f"WiFi Strength:   {self.wifi_strength}%")
        print(f"Current Usage:   {self.active_power_w} W")
        print(f"Voltage L1:      {self.active_voltage_v} V")
        print(f"Total Import:    {self.total_power_import_kwh} kWh")
        print(f"Total Export:    {self.total_power_export_kwh} kWh")
        print(f"Total Gas:       {self.total_gas_m3} m3")
        print("----------------------------------")

# Usage
if __name__ == "__main__":
    # Replace with your actual IP if different
    my_meter = P1Meter("192.168.2.10")
    
    if my_meter.update_readings():
        my_meter.print_readings()