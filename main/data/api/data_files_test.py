#!/usr/bin/env python3
"""
Test script to read zendure, zendure_p1, and price data
and store via local data_api.php endpoint.

This is a self-contained script that:
- Reads zendure device data from IP address
- Reads P1 meter data from IP address
- Reads price data from API URLs
- Stores all data via local data_api.php endpoint
"""

import json
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional, Dict, Any, Tuple

import requests


# ============================================================================
# CONFIGURATION
# ============================================================================

# Data API endpoint (hardcoded as localhost)
DATA_API_URL = "http://localhost/zendure/data/api/data_api.php"

# Request timeout in seconds
REQUEST_TIMEOUT = 5

# API endpoint paths
API_ENDPOINT_PROPERTIES_REPORT = "/properties/report"


# ============================================================================
# CONFIG LOADING FUNCTIONS
# ============================================================================

def find_config_file() -> Path:
    """
    Find config.json file with fallback logic.
    Checks project root config first, then local config.
    
    Returns:
        Path to the config file that exists
    
    Raises:
        FileNotFoundError: If neither config file exists
    """
    # Get the script directory (data/api/)
    script_dir = Path(__file__).parent
    
    # Try project root config first (../../config/config.json)
    root_config = script_dir.parent.parent / "config" / "config.json"
    
    # Try local config (../../automate/config/config.json)
    local_config = script_dir.parent.parent / "automate" / "config" / "config.json"
    
    if root_config.exists():
        return root_config
    elif local_config.exists():
        return local_config
    else:
        raise FileNotFoundError(
            f"Config file not found in either location:\n"
            f"  1. {root_config}\n"
            f"  2. {local_config}"
        )


def load_config(config_path: Optional[Path] = None) -> Dict[str, Any]:
    """
    Load configuration from config.json.
    
    Args:
        config_path: Optional path to config.json. If None, will search for it.
    
    Returns:
        dict: Configuration dictionary
    
    Raises:
        FileNotFoundError: If config file not found
        ValueError: If config is invalid
    """
    if config_path is None:
        config_path = find_config_file()
    
    try:
        with open(config_path, "r") as f:
            config = json.load(f)
    except FileNotFoundError:
        raise FileNotFoundError(f"Config file not found: {config_path}")
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON in config file {config_path}: {e}")
    
    return config


def load_price_urls() -> Tuple[Optional[str], Optional[str]]:
    """
    Loads API URLs from config file (lines 7 and 8).
    
    Returns:
        Tuple of (URL_TODAY, URL_TOMORROW) or (None, None) on error
    """
    # Get the script directory (data/api/)
    script_dir = Path(__file__).parent
    
    # Try project root config first (../../config/price_urls.txt)
    config_file = script_dir.parent.parent / "config" / "price_urls.txt"
    
    # If not found, try local config
    if not config_file.exists():
        config_file = script_dir.parent.parent / "automate" / "config" / "price_urls.txt"
    
    try:
        with open(config_file, 'r', encoding='utf-8') as f:
            lines = f.readlines()
            
        if len(lines) < 8:
            print(f"ERROR: Config file {config_file} must have at least 8 lines")
            return None, None
        
        # Lines are 1-indexed, so line 7 is index 6, line 8 is index 7
        url_today = lines[6].strip()
        url_tomorrow = lines[7].strip()
        
        if not url_today or not url_tomorrow:
            print(f"ERROR: URLs in config file {config_file} cannot be empty")
            return None, None
        
        return url_today, url_tomorrow
    except FileNotFoundError:
        print(f"ERROR: Config file {config_file} not found")
        return None, None
    except IOError as e:
        print(f"ERROR: Error reading config file {config_file}: {e}")
        return None, None


# ============================================================================
# DEVICE READING FUNCTIONS
# ============================================================================

def read_zendure(device_ip: str) -> Optional[Dict[str, Any]]:
    """
    Read data from Zendure battery device via API call.
    
    Args:
        device_ip: IP address of the Zendure device
    
    Returns:
        dict: Formatted reading data with timestamp, properties, and packData, or None on error
    """
    url = f"http://{device_ip}{API_ENDPOINT_PROPERTIES_REPORT}"
    
    try:
        response = requests.get(url, timeout=REQUEST_TIMEOUT)
        response.raise_for_status()
        data = response.json()
        
        # Extract properties and pack data
        props = data.get("properties", {})
        packs = data.get("packData", [])
        
        # Prepare reading data with timestamp
        reading_data = {
            "timestamp": datetime.now().isoformat(),
            "properties": props,
            "packData": packs,
        }
        
        return reading_data
        
    except requests.exceptions.RequestException as e:
        print(f"❌ Error reading from Zendure device at {device_ip}: {e}")
        return None
    except (json.JSONDecodeError, KeyError) as e:
        print(f"❌ Error parsing Zendure response: {e}")
        return None
    except Exception as e:
        print(f"❌ Unexpected error reading Zendure data: {e}")
        return None


def read_p1_meter(p1_meter_ip: str) -> Optional[Dict[str, Any]]:
    """
    Read data from P1 meter device via API call.
    
    Args:
        p1_meter_ip: IP address of the P1 meter device
    
    Returns:
        dict: Formatted reading data with timestamp and meter fields, or None on error
    """
    url = f"http://{p1_meter_ip}{API_ENDPOINT_PROPERTIES_REPORT}"
    
    try:
        response = requests.get(url, timeout=REQUEST_TIMEOUT)
        response.raise_for_status()
        data = response.json()
        
        # Extract P1 meter data fields
        device_id = data.get("deviceId")
        total_power = data.get("total_power")
        phase_a = data.get("a_aprt_power")
        phase_b = data.get("b_aprt_power")
        phase_c = data.get("c_aprt_power")
        meter_timestamp = data.get("timestamp")
        
        # Prepare reading data with timestamp
        reading_data = {
            "timestamp": datetime.now().isoformat(),
            "deviceId": device_id,
            "total_power": total_power,
            "a_aprt_power": phase_a,
            "b_aprt_power": phase_b,
            "c_aprt_power": phase_c,
            "meter_timestamp": meter_timestamp,
        }
        
        return reading_data
        
    except requests.exceptions.RequestException as e:
        print(f"❌ Error reading from P1 meter at {p1_meter_ip}: {e}")
        return None
    except (json.JSONDecodeError, KeyError) as e:
        print(f"❌ Error parsing P1 response: {e}")
        return None
    except Exception as e:
        print(f"❌ Unexpected error reading P1 meter data: {e}")
        return None


# ============================================================================
# PRICE FETCHING FUNCTIONS
# ============================================================================

def fetch_prices(url: str) -> Optional[Dict[str, Any]]:
    """
    Fetches price data from API endpoint.
    
    Args:
        url: API endpoint URL
        
    Returns:
        JSON response data or None on error
    """
    try:
        response = requests.get(url, timeout=10)
        response.raise_for_status()
        data = response.json()
        
        if data.get("status") != "true":
            print(f"❌ API returned status: {data.get('status')}")
            return None
            
        return data
    except requests.exceptions.RequestException as e:
        print(f"❌ Error fetching prices from API: {e}")
        return None
    except json.JSONDecodeError as e:
        print(f"❌ Error parsing JSON response: {e}")
        return None


def get_date_from_data(data: Dict[str, Any]) -> Optional[str]:
    """
    Extracts date from API response to determine filename.
    
    Args:
        data: API response JSON data
    
    Returns:
        Date string in format yyyymmdd or None
    """
    if not data or "data" not in data or not data["data"]:
        return None
    
    try:
        # Get first entry's datum field
        first_entry = data["data"][0]
        datum = first_entry.get("datum")
        
        if not datum:
            return None
        
        # Parse ISO datetime string (e.g., "2025-12-20T00:00:00+01:00")
        dt = datetime.fromisoformat(datum.replace("+01:00", "+01:00"))
        
        # Format as yyyymmdd
        return dt.strftime("%Y%m%d")
    except (KeyError, ValueError, IndexError) as e:
        print(f"❌ Error extracting date from data: {e}")
        return None


def extract_prices(data: Dict[str, Any]) -> Optional[Dict[str, float]]:
    """
    Extracts prijsNE values and organizes by hour.
    
    Args:
        data: API response JSON data
    
    Returns:
        Dictionary with hour keys (00-23) and price values, or None on error
    """
    if not data or "data" not in data:
        return None
    
    prices = {}
    
    try:
        for entry in data["data"]:
            datum = entry.get("datum")
            prijsne = entry.get("prijsNE")
            
            if not datum or prijsne is None:
                continue
            
            # Extract hour from datetime string
            dt = datetime.fromisoformat(datum.replace("+01:00", "+01:00"))
            hour = dt.strftime("%H")
            
            # Store price for this hour
            prices[hour] = float(prijsne)
        
        return prices if prices else None
    except (KeyError, ValueError, TypeError) as e:
        print(f"❌ Error extracting prices: {e}")
        return None


# ============================================================================
# API STORAGE FUNCTION
# ============================================================================

def store_via_api(api_url: str, data: Dict[str, Any], data_type: str) -> bool:
    """
    Store data via data_api.php endpoint.
    
    Args:
        api_url: Full API endpoint URL with query parameters
        data: Data dictionary to store
        data_type: Type of data for logging (e.g., "Zendure data", "P1 meter data", "Price data")
    
    Returns:
        bool: True if storage was successful, False otherwise
    """
    try:
        response = requests.post(
            api_url,
            json=data,
            timeout=REQUEST_TIMEOUT,
            headers={"Content-Type": "application/json"},
        )
        response.raise_for_status()
        
        # Try to parse JSON response
        try:
            result = response.json()
        except json.JSONDecodeError as e:
            # Show the actual response content for debugging
            response_text = response.text[:500]  # First 500 chars
            print(f"❌ Error parsing API response for {data_type}: {e}")
            print(f"   Response status: {response.status_code}")
            print(f"   Response content: {response_text}")
            if len(response.text) > 500:
                print(f"   ... (truncated, total length: {len(response.text)} chars)")
            return False
        
        if result.get("success", False):
            file_name = result.get("file", "data.json")
            print(f"✅ {data_type} stored successfully: {file_name}")
            return True
        else:
            error_msg = result.get("error", "Unknown API error")
            print(f"❌ API returned error when storing {data_type}: {error_msg}")
            return False
    except requests.exceptions.RequestException as e:
        print(f"❌ Network error storing {data_type}: {e}")
        return False
    except Exception as e:
        print(f"❌ Unexpected error storing {data_type}: {e}")
        return False


# ============================================================================
# MAIN FUNCTION
# ============================================================================

def main():
    """
    Main function to orchestrate reading and storing all data types.
    """
    print("=" * 60)
    print("Data Files Test Script")
    print("=" * 60)
    print()
    
    # Load configuration
    print("📋 Loading configuration...")
    try:
        config = load_config()
        device_ip = config.get("deviceIp")
        p1_meter_ip = config.get("p1MeterIp")
        
        if not device_ip:
            print("⚠️  Warning: deviceIp not found in config.json, skipping Zendure data")
        if not p1_meter_ip:
            print("⚠️  Warning: p1MeterIp not found in config.json, skipping P1 meter data")
    except (FileNotFoundError, ValueError) as e:
        print(f"❌ Error loading config: {e}")
        print("   Continuing with price data only...")
        config = {}
        device_ip = None
        p1_meter_ip = None
    
    # Load price URLs
    print("📋 Loading price URLs...")
    url_today, url_tomorrow = load_price_urls()
    if not url_today or not url_tomorrow:
        print("⚠️  Warning: Could not load price URLs, skipping price data")
        url_today = None
        url_tomorrow = None
    
    print()
    
    # Read and store Zendure data
    if device_ip:
        print("🔋 Reading Zendure device data...")
        zendure_data = read_zendure(device_ip)
        if zendure_data:
            api_url = f"{DATA_API_URL}?type=zendure"
            store_via_api(api_url, zendure_data, "Zendure data")
        else:
            print("❌ Failed to read Zendure device data")
        print()
    else:
        print("⏭️  Skipping Zendure data (no deviceIp in config)")
        print()
    
    # Read and store P1 meter data
    if p1_meter_ip:
        print("📊 Reading P1 meter data...")
        p1_data = read_p1_meter(p1_meter_ip)
        if p1_data:
            api_url = f"{DATA_API_URL}?type=zendure_p1"
            store_via_api(api_url, p1_data, "P1 meter data")
        else:
            print("❌ Failed to read P1 meter data")
        print()
    else:
        print("⏭️  Skipping P1 meter data (no p1MeterIp in config)")
        print()
    
    # Read and store price data
    if url_today:
        print("💰 Fetching price data...")
        
        # Always fetch today's prices
        print("  📅 Fetching today's prices...")
        price_data = fetch_prices(url_today)
        if price_data:
            date_str = get_date_from_data(price_data)
            if date_str:
                prices = extract_prices(price_data)
                if prices:
                    api_url = f"{DATA_API_URL}?type=price&date={date_str}"
                    store_via_api(api_url, prices, f"Price data for {date_str}")
                else:
                    print("❌ Failed to extract prices from today's data")
            else:
                print("❌ Failed to extract date from today's data")
        else:
            print("❌ Failed to fetch today's prices")
        
        # Fetch tomorrow's prices if after 1:00 AM
        current_hour = datetime.now().hour
        if current_hour >= 1:
            print("  📅 Fetching tomorrow's prices...")
            price_data = fetch_prices(url_tomorrow)
            if price_data:
                date_str = get_date_from_data(price_data)
                if date_str:
                    prices = extract_prices(price_data)
                    if prices:
                        api_url = f"{DATA_API_URL}?type=price&date={date_str}"
                        store_via_api(api_url, prices, f"Price data for {date_str}")
                    else:
                        print("❌ Failed to extract prices from tomorrow's data")
                else:
                    print("❌ Failed to extract date from tomorrow's data")
            else:
                print("❌ Failed to fetch tomorrow's prices")
        else:
            print(f"  ℹ️  Current hour is {current_hour:02d}:00, skipping tomorrow's prices (only fetch after 01:00)")
        
        print()
    else:
        print("⏭️  Skipping price data (no price URLs in config)")
        print()
    
    print("=" * 60)
    print("✅ Test script completed")
    print("=" * 60)


if __name__ == "__main__":
    main()
