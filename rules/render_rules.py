#!/usr/bin/env python3
"""
Conditional Rules Renderer
Evaluates conditional rules and generates schedule entries.
"""

import json
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Optional, Any, Union
import requests
import logging

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# Timezone
TIMEZONE = 'Europe/Amsterdam'

# Paths (relative to script location)
SCRIPT_DIR = Path(__file__).parent.absolute()
ROOT_DIR = SCRIPT_DIR.parent
RULES_FILE = SCRIPT_DIR / 'data' / 'rules.json'
CONFIG_FILE = ROOT_DIR / 'config' / 'config.json'
ZENDURE_DATA_FILE = ROOT_DIR / 'data' / 'zendure_data.json'
OUTPUT_DIR = ROOT_DIR / 'schedule' / 'data'
OUTPUT_FILE = OUTPUT_DIR / 'conditional_schedule.json'


def load_rules() -> Optional[Dict[str, Any]]:
    """Load and validate rules JSON."""
    try:
        if not RULES_FILE.exists():
            logger.error(f"Rules file not found: {RULES_FILE}")
            return None
        
        with open(RULES_FILE, 'r', encoding='utf-8') as f:
            rules_data = json.load(f)
        
        if not isinstance(rules_data, dict):
            logger.error("Rules file must contain a JSON object")
            return None
        
        if 'rules' not in rules_data:
            logger.error("Rules file missing 'rules' key")
            return None
        
        if not isinstance(rules_data['rules'], list):
            logger.error("'rules' must be an array")
            return None
        
        logger.info(f"Loaded {len(rules_data['rules'])} rules")
        return rules_data
    
    except json.JSONDecodeError as e:
        logger.error(f"Invalid JSON in rules file: {e}")
        return None
    except Exception as e:
        logger.error(f"Error loading rules: {e}")
        return None


def load_config() -> Optional[Dict[str, Any]]:
    """Load configuration from config.json."""
    try:
        if not CONFIG_FILE.exists():
            logger.warning(f"Config file not found: {CONFIG_FILE}")
            return None
        
        with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
            config = json.load(f)
        
        return config
    except Exception as e:
        logger.warning(f"Error loading config: {e}")
        return None


def fetch_battery_level() -> Optional[int]:
    """Get current battery level from cached JSON or API."""
    # Try cached file first
    if ZENDURE_DATA_FILE.exists():
        try:
            with open(ZENDURE_DATA_FILE, 'r', encoding='utf-8') as f:
                data = json.load(f)
            
            battery_level = data.get('properties', {}).get('electricLevel')
            if battery_level is not None:
                logger.info(f"Battery level from cache: {battery_level}%")
                return int(battery_level)
        except Exception as e:
            logger.warning(f"Error reading cached battery data: {e}")
    
    # Try API if config available
    config = load_config()
    if config:
        data_api_url = config.get('dataApiUrl') or config.get('dataApiUrl-local')
        if data_api_url:
            try:
                url = f"{data_api_url}?type=zendure"
                response = requests.get(url, timeout=5)
                if response.status_code == 200:
                    data = response.json()
                    if isinstance(data, dict) and data.get('success'):
                        battery_level = data.get('data', {}).get('properties', {}).get('electricLevel')
                        if battery_level is not None:
                            logger.info(f"Battery level from API: {battery_level}%")
                            return int(battery_level)
            except Exception as e:
                logger.warning(f"Error fetching battery level from API: {e}")
    
    logger.warning("Could not fetch battery level")
    return None


def fetch_prices() -> Optional[Dict[str, Dict[str, float]]]:
    """Get price data for today and tomorrow from API."""
    config = load_config()
    if not config:
        logger.error("Config not available, cannot fetch prices")
        return None
    
    price_api_url = config.get('priceUrls', {}).get('get_prices')
    if not price_api_url:
        # Try local version
        price_api_url = config.get('priceUrls', {}).get('get_prices-local')
    
    if not price_api_url:
        logger.error("Price API URL not found in config")
        return None
    
    try:
        response = requests.get(price_api_url, timeout=10)
        if response.status_code != 200:
            logger.error(f"Price API returned status {response.status_code}")
            return None
        
        data = response.json()
        
        # Expected format: {"today": {"00": 0.25, ...}, "tomorrow": {...}, "dates": {...}}
        result = {
            'today': data.get('today', {}),
            'tomorrow': data.get('tomorrow', {}),
            'dates': data.get('dates', {})
        }
        
        if not result['today']:
            logger.warning("No price data for today")
        else:
            logger.info(f"Loaded prices for today ({len(result['today'])} hours)")
        
        if result['tomorrow']:
            logger.info(f"Loaded prices for tomorrow ({len(result['tomorrow'])} hours)")
        
        return result
    
    except Exception as e:
        logger.error(f"Error fetching prices: {e}")
        return None


def evaluate_condition(condition: Dict[str, Any], battery_level: Optional[int], 
                       prices: Optional[Dict[str, Dict[str, float]]]) -> Optional[bool]:
    """Evaluate a single condition."""
    condition_type = None
    condition_data = None
    
    # Determine condition type
    if 'battery_level' in condition:
        condition_type = 'battery_level'
        condition_data = condition['battery_level']
    elif 'price' in condition:
        condition_type = 'price'
        condition_data = condition['price']
    else:
        logger.warning(f"Unknown condition type: {list(condition.keys())}")
        return None
    
    if not isinstance(condition_data, dict):
        logger.warning(f"Invalid condition data format: {condition_data}")
        return None
    
    operator = condition_data.get('operator')
    value = condition_data.get('value')
    
    if operator is None or value is None:
        logger.warning(f"Missing operator or value in condition: {condition_data}")
        return None
    
    # Evaluate battery_level condition
    if condition_type == 'battery_level':
        if battery_level is None:
            logger.warning("Battery level not available, cannot evaluate condition")
            return None
        
        return _compare_values(battery_level, operator, value)
    
    # Evaluate price condition
    elif condition_type == 'price':
        hour = condition_data.get('hour')
        if hour is None:
            logger.warning("Price condition missing 'hour' field")
            return None
        
        # Get price for today (use today's prices)
        if not prices or 'today' not in prices or not prices['today']:
            logger.warning("Price data not available, cannot evaluate condition")
            return None
        
        hour_str = f"{hour:02d}"
        price = prices['today'].get(hour_str)
        
        if price is None:
            logger.warning(f"Price not available for hour {hour}")
            return None
        
        return _compare_values(price, operator, value)
    
    return None


def _compare_values(actual: Union[int, float], operator: str, expected: Union[int, float]) -> bool:
    """Compare actual value with expected value using operator."""
    try:
        if operator == '>':
            return actual > expected
        elif operator == '<':
            return actual < expected
        elif operator == '>=':
            return actual >= expected
        elif operator == '<=':
            return actual <= expected
        elif operator == '==':
            return actual == expected
        elif operator == '!=':
            return actual != expected
        else:
            logger.warning(f"Unknown operator: {operator}")
            return False
    except Exception as e:
        logger.warning(f"Error comparing values: {e}")
        return False


def evaluate_rule(rule: Dict[str, Any], battery_level: Optional[int], 
                 prices: Optional[Dict[str, Dict[str, float]]], 
                 current_date: datetime) -> bool:
    """Check if all rule conditions are met."""
    # Check if rule is enabled
    if not rule.get('enabled', True):
        return False
    
    # Check days of week
    days_of_week = rule.get('days_of_week')
    if days_of_week is not None:
        # Python: Monday=0, Sunday=6. Rule format: Monday=1, Sunday=7
        python_weekday = current_date.weekday()  # 0=Monday, 6=Sunday
        current_day = python_weekday + 1  # Convert to 1=Monday, 7=Sunday
        
        if current_day not in days_of_week:
            return False
    
    # Check date range (if specified)
    date_range = rule.get('date_range')
    if date_range:
        # TODO: Implement date range checking if needed
        pass
    
    # Evaluate all conditions
    conditions = rule.get('conditions', {})
    if not conditions:
        logger.warning(f"Rule {rule.get('id')} has no conditions")
        return False
    
    for condition_key, condition_value in conditions.items():
        result = evaluate_condition({condition_key: condition_value}, battery_level, prices)
        if result is None:
            # Condition couldn't be evaluated (missing data)
            return False
        if not result:
            # Condition failed
            return False
    
    # All conditions passed
    return True


def generate_schedule_entries(rule: Dict[str, Any], current_date: datetime, 
                             tomorrow_date: datetime) -> Dict[str, Any]:
    """Generate schedule entries for a matching rule."""
    action = rule.get('action')
    time_range = rule.get('time_range', {})
    start_time = time_range.get('start', '0000')
    end_time = time_range.get('end', '0000')
    
    entries = {}
    
    # Generate entries for today
    today_key_start = f"{current_date.strftime('%Y%m%d')}{start_time}"
    today_key_end = f"{current_date.strftime('%Y%m%d')}{end_time}"
    entries[today_key_start] = action
    entries[today_key_end] = 0
    
    # Generate entries for tomorrow
    tomorrow_key_start = f"{tomorrow_date.strftime('%Y%m%d')}{start_time}"
    tomorrow_key_end = f"{tomorrow_date.strftime('%Y%m%d')}{end_time}"
    entries[tomorrow_key_start] = action
    entries[tomorrow_key_end] = 0
    
    return entries


def write_schedule(schedule: Dict[str, Any]) -> bool:
    """Write schedule to output file."""
    try:
        # Create output directory if it doesn't exist
        OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
        
        # Write to temporary file first, then rename (atomic write)
        temp_file = OUTPUT_FILE.with_suffix('.tmp')
        
        with open(temp_file, 'w', encoding='utf-8') as f:
            json.dump(schedule, f, indent=2, ensure_ascii=False)
        
        # Atomic rename
        temp_file.replace(OUTPUT_FILE)
        
        logger.info(f"Schedule written to {OUTPUT_FILE}")
        return True
    
    except Exception as e:
        logger.error(f"Error writing schedule: {e}")
        return False


def main():
    """Main execution function."""
    logger.info("Starting conditional rules renderer")
    
    # Load rules
    rules_data = load_rules()
    if not rules_data:
        logger.error("Failed to load rules")
        sys.exit(1)
    
    if not rules_data.get('enabled', True):
        logger.info("Rules system is disabled")
        sys.exit(0)
    
    # Get current date/time
    current_date = datetime.now()
    tomorrow_date = current_date + timedelta(days=1)
    
    logger.info(f"Current date: {current_date.strftime('%Y-%m-%d')}")
    
    # Fetch data
    battery_level = fetch_battery_level()
    prices = fetch_prices()
    
    # Generate schedule
    schedule = {}
    rules = rules_data.get('rules', [])
    
    for rule in rules:
        rule_id = rule.get('id', 'unknown')
        rule_name = rule.get('name', 'unnamed')
        
        logger.info(f"Evaluating rule: {rule_id} - {rule_name}")
        
        # Check if rule matches
        if evaluate_rule(rule, battery_level, prices, current_date):
            logger.info(f"  ✓ Rule {rule_id} matches - generating entries")
            entries = generate_schedule_entries(rule, current_date, tomorrow_date)
            schedule.update(entries)
            logger.info(f"  Generated {len(entries)} entries")
        else:
            logger.info(f"  ✗ Rule {rule_id} does not match")
    
    # Write schedule
    if schedule:
        logger.info(f"Generated {len(schedule)} total schedule entries")
        if write_schedule(schedule):
            logger.info("Schedule generation completed successfully")
        else:
            logger.error("Failed to write schedule")
            sys.exit(1)
    else:
        logger.info("No schedule entries generated")
        # Write empty schedule
        write_schedule({})


if __name__ == '__main__':
    main()
