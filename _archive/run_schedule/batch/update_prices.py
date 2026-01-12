#!/usr/bin/env python3
"""
Update Prices Script

Fetches electricity prices from external APIs and saves them via the Data API.
Only updates prices when needed:
- Updates today's prices if missing
- Updates tomorrow's prices if missing and current hour >= tomorrowFetchHour (default 15:00)
"""

import requests
import json
import os
import sys
from datetime import datetime, timedelta

# Add parent directory to path to import config
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PARENT_DIR = os.path.dirname(SCRIPT_DIR)
CONFIG_FILE = os.path.join(PARENT_DIR, "config", "config.json")


def load_config():
    """
    Loads configuration from config.json file.
    
    Returns:
        dict: Configuration dictionary or None on error
    """
    try:
        with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
            config = json.load(f)
        
        # Validate required config fields
        if 'apiBasePath' not in config:
            print(f"ERROR: Missing 'apiBasePath' in config file {CONFIG_FILE}")
            return None
        
        if 'priceUrls' not in config:
            print(f"ERROR: Missing 'priceUrls' in config file {CONFIG_FILE}")
            return None
        
        if 'today' not in config['priceUrls'] or 'tomorrow' not in config['priceUrls']:
            print(f"ERROR: Missing 'today' or 'tomorrow' in priceUrls in config file {CONFIG_FILE}")
            return None
        
        # Set default for tomorrowFetchHour if not present
        if 'tomorrowFetchHour' not in config:
            config['tomorrowFetchHour'] = 15
        
        return config
    except FileNotFoundError:
        print(f"ERROR: Config file {CONFIG_FILE} not found")
        return None
    except json.JSONDecodeError as e:
        print(f"ERROR: Invalid JSON in config file {CONFIG_FILE}: {e}")
        return None
    except Exception as e:
        print(f"ERROR: Error reading config file {CONFIG_FILE}: {e}")
        return None


def get_data_api_url(config):
    """
    Constructs the Data API URL from base path.
    
    Args:
        config: Configuration dictionary
        
    Returns:
        str: Full Data API URL
    """
    base_path = config['apiBasePath'].rstrip('/')
    return f"{base_path}/data/api/data_api.php"


def check_price_exists(api_url, date_str):
    """
    Checks if price file exists for a given date via Data API.
    
    Args:
        api_url: Full Data API URL
        date_str: Date string in format YYYYMMDD
        
    Returns:
        bool: True if price file exists, False otherwise
    """
    try:
        response = requests.get(api_url, params={
            'type': 'price',
            'date': date_str
        }, timeout=10)
        
        response.raise_for_status()
        data = response.json()
        
        return data.get('success', False)
    except requests.exceptions.RequestException as e:
        print(f"❌ Error checking price existence for {date_str}: {e}")
        return False
    except (json.JSONDecodeError, KeyError) as e:
        print(f"❌ Error parsing response for {date_str}: {e}")
        return False


def save_prices(api_url, date_str, prices):
    """
    Saves price data via Data API.
    
    Args:
        api_url: Full Data API URL
        date_str: Date string in format YYYYMMDD
        prices: Dictionary with hour keys (00-23) and price values
        
    Returns:
        bool: True if successful, False otherwise
    """
    try:
        response = requests.post(
            api_url,
            params={'type': 'price', 'date': date_str},
            json=prices,
            timeout=10
        )
        
        response.raise_for_status()
        data = response.json()
        
        if data.get('success', False):
            print(f"✅ Saved prices for {date_str} via API")
            return True
        else:
            error = data.get('error', 'Unknown error')
            print(f"❌ API error saving prices for {date_str}: {error}")
            return False
    except requests.exceptions.RequestException as e:
        print(f"❌ Error saving prices for {date_str}: {e}")
        return False
    except (json.JSONDecodeError, KeyError) as e:
        print(f"❌ Error parsing response for {date_str}: {e}")
        return False


def fetch_prices_from_api(url):
    """
    Fetches price data from external API endpoint.
    
    Args:
        url: External API endpoint URL
        
    Returns:
        dict: JSON response data or None on error
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


def get_date_from_response(data):
    """
    Extracts date from API response to determine filename.
    
    Args:
        data: API response JSON data
        
    Returns:
        str: Date string in format YYYYMMDD or None
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
        
        # Format as YYYYMMDD
        return dt.strftime("%Y%m%d")
    except (KeyError, ValueError, IndexError) as e:
        print(f"❌ Error extracting date from data: {e}")
        return None


def extract_prices_from_response(data):
    """
    Extracts prijsNE values and organizes by hour.
    
    Args:
        data: API response JSON data
        
    Returns:
        dict: Dictionary with hour keys (00-23) and price values, or None on error
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


def fetch_and_update_prices(api_url, price_url, date_label, date_str):
    """
    Fetches prices from external API and saves via Data API.
    
    Args:
        api_url: Full Data API URL
        price_url: External API URL for fetching prices
        date_label: Label for logging (e.g., "today", "tomorrow")
        date_str: Expected date string in format YYYYMMDD
        
    Returns:
        bool: True if successful, False otherwise
    """
    print(f"\n📊 Fetching {date_label} prices...")
    
    # Fetch data from external API
    data = fetch_prices_from_api(price_url)
    if not data:
        return False
    
    # Extract date from response
    response_date = get_date_from_response(data)
    if not response_date:
        print(f"❌ Could not extract date from {date_label} data")
        return False
    
    # Verify date matches expected date
    if response_date != date_str:
        print(f"⚠️  Warning: Response date ({response_date}) doesn't match expected date ({date_str})")
        # Continue anyway, use the date from response
    
    # Extract prices
    prices = extract_prices_from_response(data)
    if not prices:
        print(f"❌ Could not extract prices from {date_label} data")
        return False
    
    # Save via Data API
    return save_prices(api_url, response_date, prices)


def main():
    """
    Main function to orchestrate price updates.
    """
    print("🔌 Electricity Price Updater")
    print("=" * 50)
    
    # Load configuration
    config = load_config()
    if not config:
        sys.exit(1)
    
    # Get Data API URL
    api_url = get_data_api_url(config)
    
    # Get current date and tomorrow's date
    today = datetime.now()
    today_str = today.strftime("%Y%m%d")
    tomorrow = today + timedelta(days=1)
    tomorrow_str = tomorrow.strftime("%Y%m%d")
    
    # Check and update today's prices
    if not check_price_exists(api_url, today_str):
        print(f"ℹ️  Today's prices ({today_str}) not found, fetching...")
        fetch_and_update_prices(
            api_url,
            config['priceUrls']['today'],
            "today",
            today_str
        )
    else:
        print(f"✅ Today's prices ({today_str}) already exist, skipping")
    
    # Check and update tomorrow's prices if needed
    current_hour = today.hour
    tomorrow_fetch_hour = config.get('tomorrowFetchHour', 15)
    
    if current_hour >= tomorrow_fetch_hour:
        if not check_price_exists(api_url, tomorrow_str):
            print(f"ℹ️  Tomorrow's prices ({tomorrow_str}) not found, fetching...")
            fetch_and_update_prices(
                api_url,
                config['priceUrls']['tomorrow'],
                "tomorrow",
                tomorrow_str
            )
        else:
            print(f"✅ Tomorrow's prices ({tomorrow_str}) already exist, skipping")
    else:
        print(f"ℹ️  Current hour is {current_hour:02d}:00, skipping tomorrow's prices (only fetch after {tomorrow_fetch_hour:02d}:00)")


if __name__ == "__main__":
    main()

