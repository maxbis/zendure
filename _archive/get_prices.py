import requests
import json
import os
from datetime import datetime, timedelta

# API URLs - loaded from config file
CONFIG_FILE = os.path.join("config", "price_urls.txt")

def load_urls_from_config():
    """
    Loads API URLs from config file (lines 7 and 8).
    
    Returns:
        Tuple of (URL_TODAY, URL_TOMORROW) or (None, None) on error
    """
    try:
        with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
            lines = f.readlines()
            
        if len(lines) < 8:
            print(f"ERROR: Config file {CONFIG_FILE} must have at least 8 lines")
            return None, None
        
        # Lines are 1-indexed, so line 7 is index 6, line 8 is index 7
        url_today = lines[6].strip()
        url_tomorrow = lines[7].strip()
        
        if not url_today or not url_tomorrow:
            print(f"ERROR: URLs in config file {CONFIG_FILE} cannot be empty")
            return None, None
        
        return url_today, url_tomorrow
    except FileNotFoundError:
        print(f"ERROR: Config file {CONFIG_FILE} not found")
        return None, None
    except IOError as e:
        print(f"ERROR: Error reading config file {CONFIG_FILE}: {e}")
        return None, None

URL_TODAY, URL_TOMORROW = load_urls_from_config()
if not URL_TODAY or not URL_TOMORROW:
    raise RuntimeError(f"Failed to load URLs from {CONFIG_FILE}")

# Data directory
DATA_DIR = "data"


def fetch_prices(url):
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


def get_date_from_data(data):
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


def extract_prijsne(data):
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


def get_filename(date_str):
    """
    Generates filename for price data.
    
    Args:
        date_str: Date string in format yyyymmdd
        
    Returns:
        Full file path
    """
    return os.path.join(DATA_DIR, f"price{date_str}.json")


def file_exists(date_str):
    """
    Checks if price file already exists.
    
    Args:
        date_str: Date string in format yyyymmdd
        
    Returns:
        True if file exists, False otherwise
    """
    filename = get_filename(date_str)
    return os.path.exists(filename)


def save_prices(date_str, prices):
    """
    Saves price data to JSON file.
    
    Args:
        date_str: Date string in format yyyymmdd
        prices: Dictionary with hour keys and price values
        
    Returns:
        True if successful, False otherwise
    """
    # Ensure data directory exists
    os.makedirs(DATA_DIR, exist_ok=True)
    
    filename = get_filename(date_str)
    
    try:
        with open(filename, 'w', encoding='utf-8') as f:
            json.dump(prices, f, indent=2)
        print(f"✅ Saved prices to {filename}")
        return True
    except IOError as e:
        print(f"❌ Error saving file {filename}: {e}")
        return False


def should_fetch_tomorrow():
    """
    Checks if current hour >= 1:00 (local time).
    
    Returns:
        True if current hour >= 1:00, False otherwise
    """
    current_hour = datetime.now().hour
    return current_hour >= 1


def fetch_and_save_prices(url, date_label):
    """
    Fetches prices from URL and saves to file if not already exists.
    
    Args:
        url: API endpoint URL
        date_label: Label for logging (e.g., "today", "tomorrow")
        
    Returns:
        True if successful, False otherwise
    """
    print(f"\n📊 Fetching {date_label} prices...")
    
    # Fetch data from API
    data = fetch_prices(url)
    if not data:
        return False
    
    # Extract date
    date_str = get_date_from_data(data)
    if not date_str:
        print(f"❌ Could not extract date from {date_label} data")
        return False
    
    # Check if file already exists
    if file_exists(date_str):
        print(f"ℹ️  File for {date_label} ({date_str}) already exists, skipping API call")
        return True
    
    # Extract prices
    prices = extract_prijsne(data)
    if not prices:
        print(f"❌ Could not extract prices from {date_label} data")
        return False
    
    # Save to file
    return save_prices(date_str, prices)


def main():
    """
    Main function to orchestrate fetching today's and tomorrow's prices.
    """
    print("🔌 Electricity Price Fetcher")
    print("=" * 50)
    
    # Always fetch today's prices if file doesn't exist
    fetch_and_save_prices(URL_TODAY, "today")
    
    # Fetch tomorrow's prices only if:
    # 1. Current hour >= 1:00 (local time)
    # 2. Tomorrow's price file doesn't exist
    if should_fetch_tomorrow():
        tomorrow_date = (datetime.now() + timedelta(days=1)).strftime("%Y%m%d")
        
        if not file_exists(tomorrow_date):
            fetch_and_save_prices(URL_TOMORROW, "tomorrow")
        else:
            print(f"\nℹ️  Tomorrow's prices ({tomorrow_date}) already exist, skipping")
    else:
        current_hour = datetime.now().hour
        print(f"\nℹ️  Current hour is {current_hour:02d}:00, skipping tomorrow's prices (only fetch after 01:00)")


if __name__ == "__main__":
    main()

