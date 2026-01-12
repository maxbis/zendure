import requests
import json
import os
from datetime import datetime, timedelta

# ============================================================================
# CONSTANTS
# ============================================================================

MIN_PRICE_DIFF = 0.12  # Minimum price difference in EUR/kWh required
CHARGE_RATE = 1200  # Charge rate in watts (positive integer)
DISCHARGE_RATE = -800  # Discharge rate in watts (negative integer)
SCHEDULE_API_URL = "http://localhost/Energy/schedule/api/charge_schedule_api.php"  # Full URL to schedule API
CONFIG_FILE = os.path.join("config", "price_urls.txt")  # Path to price URLs config
DATA_DIR = "data"  # Data directory path

# ============================================================================
# PRICE LOADING FUNCTIONS (reused from get_prices.py)
# ============================================================================

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


def load_price_file(date_str):
    """
    Loads existing price file from data directory.
    
    Args:
        date_str: Date string in format yyyymmdd
        
    Returns:
        Dictionary with hour keys and price values, or None on error
    """
    filename = get_filename(date_str)
    
    if not os.path.exists(filename):
        return None
    
    try:
        with open(filename, 'r', encoding='utf-8') as f:
            prices = json.load(f)
        return prices
    except (IOError, json.JSONDecodeError) as e:
        print(f"❌ Error loading price file {filename}: {e}")
        return None


def should_fetch_tomorrow():
    """
    Check if current hour > 13:00 (returns True if hour > 13, False otherwise).
    
    Returns:
        True if current hour > 13:00, False otherwise
    """
    current_hour = datetime.now().hour
    return current_hour > 13


def ensure_prices_updated(url, date_label, date_str):
    """
    Separate caching function with specific caching strategy.
    
    For today: Check if today's price file exists, fetch from API only if missing.
    For tomorrow: Check if current hour > 13:00 AND tomorrow's price file doesn't exist.
    
    Args:
        url: API endpoint URL
        date_label: Label for logging (e.g., "today", "tomorrow")
        date_str: Date string to check (YYYYMMDD format)
        
    Returns:
        True if successful (cached or fetched), False on error
    """
    # For tomorrow: check if hour > 13:00
    if date_label == "tomorrow":
        if not should_fetch_tomorrow():
            current_hour = datetime.now().hour
            print(f"ℹ️  Current hour is {current_hour:02d}:00, skipping tomorrow's prices (only fetch after 13:00)")
            return True  # Not an error, just too early
    
    # Check if file already exists (cache hit)
    if file_exists(date_str):
        print(f"ℹ️  File for {date_label} ({date_str}) already exists, skipping API call")
        return True
    
    # Cache miss - fetch from API
    print(f"📊 Fetching {date_label} prices...")
    data = fetch_prices(url)
    if not data:
        return False
    
    # Extract date from API response to verify
    extracted_date = get_date_from_data(data)
    if not extracted_date:
        print(f"❌ Could not extract date from {date_label} data")
        return False
    
    # Verify extracted date matches expected date
    if extracted_date != date_str:
        print(f"⚠️  Warning: Extracted date ({extracted_date}) doesn't match expected date ({date_str})")
    
    # Extract prices
    prices = extract_prijsne(data)
    if not prices:
        print(f"❌ Could not extract prices from {date_label} data")
        return False
    
    # Save to file
    return save_prices(date_str, prices)


# ============================================================================
# PRICE ANALYSIS FUNCTIONS
# ============================================================================

def get_future_hours(today_prices, tomorrow_prices):
    """
    Get all future hours from current time, combining today and tomorrow prices.
    
    Args:
        today_prices: Dictionary with hour keys (00-23) and price values for today
        tomorrow_prices: Dictionary with hour keys (00-23) and price values for tomorrow, or None
        
    Returns:
        Dictionary with datetime keys (YYYYMMDDHHmm format) and price values for future hours only
    """
    now = datetime.now()
    today_date = now.strftime("%Y%m%d")
    tomorrow_date = (now + timedelta(days=1)).strftime("%Y%m%d")
    current_hour = now.hour
    current_minute = now.minute
    
    future_prices = {}
    
    # Add today's future hours
    if today_prices:
        for hour_str, price in today_prices.items():
            try:
                hour = int(hour_str)
                # Validate hour range
                if hour < 0 or hour > 23:
                    continue
                # Only include hours in the future
                if hour > current_hour or (hour == current_hour and current_minute == 0):
                    time_key = f"{today_date}{hour_str}00"
                    future_prices[time_key] = float(price)
            except (ValueError, TypeError) as e:
                print(f"⚠️  Warning: Invalid hour '{hour_str}' in today's prices: {e}")
                continue
    
    # Add tomorrow's hours (all of them)
    if tomorrow_prices:
        for hour_str, price in tomorrow_prices.items():
            try:
                hour = int(hour_str)
                # Validate hour range
                if hour < 0 or hour > 23:
                    continue
                time_key = f"{tomorrow_date}{hour_str}00"
                future_prices[time_key] = float(price)
            except (ValueError, TypeError) as e:
                print(f"⚠️  Warning: Invalid hour '{hour_str}' in tomorrow's prices: {e}")
                continue
    
    return future_prices


def find_min_max_hours(prices_dict):
    """
    Find hour(s) with minimum price and hour(s) with maximum price.
    
    Args:
        prices_dict: Dictionary with datetime keys (YYYYMMDDHHmm) and price values
        
    Returns:
        Tuple of (min_hours_list, max_hours_list, min_price, max_price)
        where min_hours_list and max_hours_list are lists of datetime keys
    """
    if not prices_dict:
        return [], [], None, None
    
    min_price = min(prices_dict.values())
    max_price = max(prices_dict.values())
    
    min_hours = [key for key, price in prices_dict.items() if price == min_price]
    max_hours = [key for key, price in prices_dict.items() if price == max_price]
    
    return min_hours, max_hours, min_price, max_price


def check_price_opportunity(min_hours, max_hours, prices_dict, min_diff):
    """
    Verify price difference >= N and min hours are before max hours.
    
    Args:
        min_hours: List of datetime keys (YYYYMMDDHHmm) with minimum price
        max_hours: List of datetime keys (YYYYMMDDHHmm) with maximum price
        prices_dict: Dictionary with datetime keys and price values
        min_diff: Minimum price difference required (EUR/kWh)
        
    Returns:
        Tuple of (is_opportunity, earliest_min_hour, latest_max_hour)
        where is_opportunity is bool, and hours are datetime strings or None
    """
    if not min_hours or not max_hours:
        return False, None, None
    
    min_price = prices_dict[min_hours[0]]
    max_price = prices_dict[max_hours[0]]
    price_diff = max_price - min_price
    
    # Check if price difference is sufficient
    if price_diff < min_diff:
        return False, None, None
    
    # Find earliest min hour and latest max hour
    earliest_min = min(min_hours)
    latest_max = max(max_hours)
    
    # Check if earliest min hour is before latest max hour
    if earliest_min < latest_max:
        return True, earliest_min, latest_max
    
    return False, None, None


# ============================================================================
# SCHEDULE MANAGEMENT FUNCTIONS
# ============================================================================

def format_schedule_key(date_str, hour_str):
    """
    Format as YYYYMMDDHHmm (12 characters).
    
    Args:
        date_str: Date string in format YYYYMMDD
        hour_str: Hour string in format HH
    
    Returns:
        Schedule key string in format YYYYMMDDHHmm
        
    Raises:
        ValueError: If date_str or hour_str format is invalid
    """
    if len(date_str) != 8 or not date_str.isdigit():
        raise ValueError(f"Invalid date format: {date_str} (expected YYYYMMDD)")
    if len(hour_str) != 2 or not hour_str.isdigit():
        raise ValueError(f"Invalid hour format: {hour_str} (expected HH)")
    
    key = f"{date_str}{hour_str}00"
    if len(key) != 12:
        raise ValueError(f"Generated key length is {len(key)}, expected 12: {key}")
    
    return key


def add_schedule_entry(date_str, hour_str, value, api_url):
    """
    POST to schedule API with key format YYYYMMDDHHmm and value (watts).
    
    Args:
        date_str: Date string in format YYYYMMDD
        hour_str: Hour string in format HH
        value: Charge/discharge value in watts (positive for charge, negative for discharge)
        api_url: Full URL to schedule API
        
    Returns:
        True if successful, False otherwise
    """
    try:
        key = format_schedule_key(date_str, hour_str)
    except ValueError as e:
        print(f"❌ Invalid schedule key format: {e}")
        return False
    
    # Validate value is numeric
    if not isinstance(value, (int, float)):
        print(f"❌ Invalid value type: {value} (expected numeric)")
        return False
    
    payload = {
        "key": key,
        "value": int(value)  # API expects integer
    }
    
    try:
        response = requests.post(api_url, json=payload, timeout=10)
        response.raise_for_status()
        
        result = response.json()
        if result.get("success"):
            print(f"✅ Added schedule entry: {key} → {value} W")
            return True
        else:
            error_msg = result.get("error", "Unknown error")
            print(f"❌ Failed to add schedule entry {key}: {error_msg}")
            return False
    except requests.exceptions.RequestException as e:
        print(f"❌ Error calling schedule API for {key}: {e}")
        return False
    except json.JSONDecodeError as e:
        print(f"❌ Error parsing API response for {key}: {e}")
        return False


# ============================================================================
# MAIN FUNCTION
# ============================================================================

def main():
    """
    Main function that orchestrates: update prices, load data, analyze, and add schedule entries.
    """
    print("⚡ Price Strategy Automation")
    print("=" * 50)
    
    # Validate constants
    if MIN_PRICE_DIFF <= 0:
        print(f"❌ Invalid MIN_PRICE_DIFF: {MIN_PRICE_DIFF} (must be > 0)")
        return 1
    if CHARGE_RATE <= 0:
        print(f"❌ Invalid CHARGE_RATE: {CHARGE_RATE} (must be > 0)")
        return 1
    if DISCHARGE_RATE >= 0:
        print(f"❌ Invalid DISCHARGE_RATE: {DISCHARGE_RATE} (must be < 0)")
        return 1
    if not SCHEDULE_API_URL or not SCHEDULE_API_URL.startswith(('http://', 'https://')):
        print(f"❌ Invalid SCHEDULE_API_URL: {SCHEDULE_API_URL}")
        return 1
    
    # Load URLs from config
    url_today, url_tomorrow = load_urls_from_config()
    if not url_today or not url_tomorrow:
        print("❌ Failed to load URLs from config file")
        return 1
    
    # Get current date strings
    now = datetime.now()
    today_date = now.strftime("%Y%m%d")
    tomorrow_date = (now + timedelta(days=1)).strftime("%Y%m%d")
    
    # Update prices with caching strategy
    print("\n📊 Updating price data...")
    if not ensure_prices_updated(url_today, "today", today_date):
        print("❌ Failed to update today's prices")
        return 1
    
    if not ensure_prices_updated(url_tomorrow, "tomorrow", tomorrow_date):
        print("❌ Failed to update tomorrow's prices")
        return 1
    
    # Load price data
    print("\n📂 Loading price data...")
    today_prices = load_price_file(today_date)
    tomorrow_prices = load_price_file(tomorrow_date)
    
    if not today_prices:
        print(f"❌ Failed to load today's prices ({today_date})")
        return 1
    
    # Tomorrow's prices are optional (may not be available if hour <= 13:00)
    if not tomorrow_prices:
        current_hour = datetime.now().hour
        if current_hour > 13:
            print(f"⚠️  Warning: Tomorrow's prices ({tomorrow_date}) not available")
        else:
            print(f"ℹ️  Tomorrow's prices not yet available (current hour: {current_hour:02d}:00)")
    
    # Get future hours
    future_prices = get_future_hours(today_prices, tomorrow_prices)
    
    if not future_prices:
        print("ℹ️  No future hours available for analysis")
        return 0
    
    print(f"📈 Analyzing {len(future_prices)} future hours...")
    
    # Find min/max hours
    min_hours, max_hours, min_price, max_price = find_min_max_hours(future_prices)
    
    if not min_hours or not max_hours:
        print("❌ Could not find min/max prices")
        return 1
    
    print(f"💰 Min price: {min_price:.4f} EUR/kWh at {len(min_hours)} hour(s)")
    print(f"💰 Max price: {max_price:.4f} EUR/kWh at {len(max_hours)} hour(s)")
    print(f"📊 Price difference: {max_price - min_price:.4f} EUR/kWh")
    
    # Check opportunity
    is_opportunity, earliest_min, latest_max = check_price_opportunity(
        min_hours, max_hours, future_prices, MIN_PRICE_DIFF
    )
    
    if not is_opportunity:
        print(f"ℹ️  No profitable opportunity found (price diff < {MIN_PRICE_DIFF} EUR/kWh or min after max)")
        return 0
    
    print(f"\n✅ Opportunity found!")
    print(f"   Charge during: {earliest_min}")
    print(f"   Discharge during: {latest_max}")
    
    # Extract date and hour from datetime keys
    earliest_min_date = earliest_min[:8]
    earliest_min_hour = earliest_min[8:10]
    latest_max_date = latest_max[:8]
    latest_max_hour = latest_max[8:10]
    
    # Add schedule entries for all min hours
    print(f"\n🔌 Adding charge entries for min price hours...")
    charge_success_count = 0
    for min_hour_key in min_hours:
        try:
            min_date = min_hour_key[:8]
            min_hour = min_hour_key[8:10]
            if add_schedule_entry(min_date, min_hour, CHARGE_RATE, SCHEDULE_API_URL):
                charge_success_count += 1
        except Exception as e:
            print(f"⚠️  Warning: Error processing min hour {min_hour_key}: {e}")
            continue
    
    print(f"✅ Added {charge_success_count}/{len(min_hours)} charge entries")
    
    # Add schedule entries for all max hours
    print(f"\n🔋 Adding discharge entries for max price hours...")
    discharge_success_count = 0
    for max_hour_key in max_hours:
        try:
            max_date = max_hour_key[:8]
            max_hour = max_hour_key[8:10]
            if add_schedule_entry(max_date, max_hour, DISCHARGE_RATE, SCHEDULE_API_URL):
                discharge_success_count += 1
        except Exception as e:
            print(f"⚠️  Warning: Error processing max hour {max_hour_key}: {e}")
            continue
    
    print(f"✅ Added {discharge_success_count}/{len(max_hours)} discharge entries")
    
    # Check if at least some entries were added successfully
    total_entries = len(min_hours) + len(max_hours)
    total_success = charge_success_count + discharge_success_count
    
    if total_success == 0:
        print("❌ Failed to add any schedule entries")
        return 1
    elif total_success < total_entries:
        print(f"⚠️  Warning: Only {total_success}/{total_entries} entries were added successfully")
        return 0  # Partial success is still considered success
    
    print("\n✅ Price strategy automation completed successfully!")
    return 0


if __name__ == "__main__":
    exit(main())

