import requests
import json
import os
from datetime import datetime

class SolarFlow2400:
    def __init__(self, ip_address):
        self.url = f"http://{ip_address}/properties/report"
        self.ip = ip_address
        self.data_dir = "data"
        self.data_file = os.path.join(self.data_dir, "zendure_data.json")
    
    def _write_json_atomic(self, data):
        """
        Write JSON data atomically to avoid concurrency issues.
        Writes to a temporary file first, then atomically renames it.
        """
        # Ensure data directory exists
        os.makedirs(self.data_dir, exist_ok=True)
        
        # Write to temporary file first
        temp_file = self.data_file + ".tmp"
        try:
            with open(temp_file, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            
            # Atomically replace the target file with the temp file
            os.replace(temp_file, self.data_file)
        except Exception as e:
            # Clean up temp file if something goes wrong
            if os.path.exists(temp_file):
                try:
                    os.remove(temp_file)
                except:
                    pass
            raise e

    def get_status(self):
        try:
            response = requests.get(self.url, timeout=5)
            response.raise_for_status()
            data = response.json()
            
            # The main system data is inside 'properties'
            props = data.get("properties", {})
            packs = data.get("packData", [])

            for key, value in props.items():
                print(f"{key}: {value}")

            print(f"\n--- Zendure SolarFlow 2400 AC ({self.ip}) ---")
            print(f"System SoC:      {props.get('electricLevel')}%")
            print(f"Solar Input:     {props.get('solarInputPower')} W")
            print(f"Home Output:     {props.get('outputHomePower')} W")
            print(f"Battery Voltage: {props.get('BatVolt') / 100:.2f} V")
            print(f"Unit Temp:       {props.get('hyperTmp') / 100:.1f}°C")

            print(f"Grid Charge:     {props.get('gridInputPower')} W")
            print(f"Pack Input:      {props.get('packInputPower')} W")
            
            print("\n--- Battery Pack Details ---")
            for i, pack in enumerate(packs):
                print(f"Pack {i+1} [{pack.get('sn')}]:")
                print(f"  Level: {pack.get('socLevel')}% | Temp: {pack.get('maxTemp')/100:.1f}°C | State: {pack.get('state')}")
            
            print("--------------------------------------------")
            
            # Save the reading to JSON file atomically
            reading_data = {
                "timestamp": datetime.now().isoformat(),
                "properties": props,
                "packData": packs
            }
            try:
                self._write_json_atomic(reading_data)
                print(f"✅ Reading saved to {self.data_file}")
            except Exception as e:
                print(f"⚠️  Warning: Failed to save reading to file: {e}")
            
            return reading_data
            
        except Exception as e:
            print(f"Error fetching data: {e}")
            return False

# Usage
my_solarflow = SolarFlow2400("192.168.2.93") # Use your specific IP
my_solarflow.get_status()