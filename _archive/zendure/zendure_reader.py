"""
Zendure SolarFlow Device Reader
Fetches status from Zendure SolarFlow device via HTTP API.
"""

import requests
from datetime import datetime


class ZendureReader:
    """Reader for Zendure SolarFlow 2400 device."""
    
    def __init__(self, ip_address, device_sn=None):
        """
        Initialize the Zendure reader.
        
        Args:
            ip_address: IP address of the Zendure device
            device_sn: Serial number (optional, needed for power control)
        """
        self.ip = ip_address
        self.sn = device_sn
        self.report_url = f"http://{ip_address}/properties/report"
        self.write_url = f"http://{ip_address}/properties/write"
    
    def get_status(self):
        """
        Fetch current status from the Zendure device.
        
        Returns:
            dict: Device data with properties and packData, or False on error
        """
        try:
            response = requests.get(self.report_url, timeout=5)
            response.raise_for_status()
            data = response.json()
            
            # Extract properties and pack data
            props = data.get('properties', {})
            packs = data.get('packData', [])
            
            # Prepare reading data with timestamp
            reading_data = {
                'timestamp': datetime.now().isoformat(),
                'properties': props,
                'packData': packs
            }
            
            return reading_data
            
        except requests.exceptions.RequestException as e:
            print(f"Error fetching Zendure data: {e}")
            return False
        except Exception as e:
            print(f"Unexpected error: {e}")
            return False
    
    def set_power(self, watts):
        """
        Set charge/discharge power.
        
        Args:
            watts: Power in watts
                - Positive: Charge from grid (acMode: 1, inputLimit: watts, outputLimit: 0)
                - Negative: Discharge to home (acMode: 2, outputLimit: abs(watts), inputLimit: 0)
                - Zero: Stop all charging and discharging (inputLimit: 0, outputLimit: 0)
        
        Returns:
            dict: Response JSON or None on error
        """
        if not self.sn:
            print("Error: Device serial number not set. Cannot control power.")
            return None
        
        try:
            if watts > 0:
                # Charge mode: acMode 1 = Input
                properties = {
                    "acMode": 1,
                    "inputLimit": watts,
                    "outputLimit": 0
                }
            elif watts < 0:
                # Discharge mode: acMode 2 = Output
                discharge_watts = abs(watts)
                properties = {
                    "acMode": 2,
                    "outputLimit": discharge_watts,
                    "inputLimit": 0,
                    "pvStatus": 1
                }
            else:
                # Stop all
                properties = {
                    "inputLimit": 0,
                    "outputLimit": 0
                }
            
            payload = {
                "sn": self.sn,
                "properties": properties
            }
            
            response = requests.post(self.write_url, json=payload, timeout=5)
            response.raise_for_status()
            return response.json()
            
        except requests.exceptions.RequestException as e:
            print(f"Error setting power: {e}")
            return None
        except Exception as e:
            print(f"Unexpected error: {e}")
            return None

