"""
Zendure P1 Meter Reader
Fetches grid power readings from Zendure P1 Meter via HTTP API.
"""

import requests
from datetime import datetime


class P1Reader:
    """Reader for Zendure P1 meter device."""
    
    def __init__(self, ip_address):
        """
        Initialize the P1 meter reader.
        
        Args:
            ip_address: IP address of the P1 meter device
        """
        self.ip = ip_address
        self.report_url = f"http://{ip_address}/properties/report"
    
    def get_status(self):
        """
        Fetch current status from the P1 meter.
        
        Returns:
            dict: P1 meter data with total_power, phases, etc., or False on error
        """
        try:
            response = requests.get(self.report_url, timeout=5)
            response.raise_for_status()
            data = response.json()
            
            # Extract meter data
            device_id = data.get('deviceId')
            total_power = data.get('total_power')
            phase_a = data.get('a_aprt_power')
            phase_b = data.get('b_aprt_power')
            phase_c = data.get('c_aprt_power')
            meter_timestamp = data.get('timestamp')
            
            # Prepare reading data with timestamp
            reading_data = {
                'timestamp': datetime.now().isoformat(),
                'deviceId': device_id,
                'total_power': total_power,
                'a_aprt_power': phase_a,
                'b_aprt_power': phase_b,
                'c_aprt_power': phase_c,
                'meter_timestamp': meter_timestamp
            }
            
            return reading_data
            
        except requests.exceptions.RequestException as e:
            print(f"Error fetching P1 meter data: {e}")
            return False
        except Exception as e:
            print(f"Unexpected error: {e}")
            return False

