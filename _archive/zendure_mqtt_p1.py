#!/usr/bin/env python3
"""
Zendure P1 Meter MQTT Reader
Connects to Zendure MQTT broker, filters P1 meter messages, and updates JSON file
"""

import paho.mqtt.client as mqtt
import json
import os
import sys
import signal
import time
from datetime import datetime, timezone
from pathlib import Path

# Configuration - can be overridden by environment variables
APP_KEY = "aHR0cHM6Ly9hcHAuemVuZHVyZS50ZWNoL2V1LjYydWgwQzIwdg"
APP_SECRET = "ZEE1NBN9N420947"
BROKER = "mqtt-eu.zen-iot.com"
PORT = 1883
P1_DEVICE_ID = "ZEE1NBN9N420947"

# Data file path (relative to script location)
SCRIPT_DIR = Path(__file__).parent.absolute()
DATA_DIR = SCRIPT_DIR / "data"
DATA_FILE = DATA_DIR / "zendure_p1_data.json"

# Connection settings
CONNECT_TIMEOUT = 60
RECONNECT_DELAY = 5
MAX_RECONNECT_ATTEMPTS = 10

# Global client reference for signal handling
client = None
running = True


def signal_handler(sig, frame):
    """Handle graceful shutdown on SIGINT/SIGTERM"""
    global running
    print("\n🛑 Shutting down gracefully...")
    running = False
    if client:
        client.disconnect()
    sys.exit(0)


def ensure_data_dir():
    """Ensure data directory exists"""
    try:
        DATA_DIR.mkdir(parents=True, exist_ok=True)
        return True
    except Exception as e:
        print(f"❌ Failed to create data directory: {e}")
        return False


def write_json_atomic(data):
    """
    Write JSON data atomically to avoid concurrency issues.
    Writes to a temporary file first, then atomically renames it.
    """
    if not ensure_data_dir():
        return False
    
    temp_file = DATA_FILE.with_suffix('.tmp')
    
    try:
        # Write to temporary file first
        json_content = json.dumps(data, indent=4, ensure_ascii=False)
        temp_file.write_text(json_content, encoding='utf-8')
        
        # Atomically replace the target file
        temp_file.replace(DATA_FILE)
        return True
    except Exception as e:
        print(f"⚠️  Warning: Failed to write JSON file: {e}")
        # Clean up temp file if something goes wrong
        if temp_file.exists():
            try:
                temp_file.unlink()
            except:
                pass
        return False


def on_connect(client, userdata, flags, rc, properties=None):
    """
    Callback for when the client receives a CONNACK response from the server.
    
    rc values:
    0: Success
    1: Incorrect protocol version
    2: Invalid client identifier
    3: Server unavailable
    4: Bad username or password
    5: Not authorized
    """
    if rc == 0:
        print("✅ Successfully connected to Zendure MQTT broker!")
        # Subscribe to all devices under your key
        topic = f"{APP_KEY}/#"
        result = client.subscribe(topic, qos=1)
        if result[0] == mqtt.MQTT_ERR_SUCCESS:
            print(f"📡 Subscribed to topic: {topic}")
            print(f"🔍 Filtering for P1 meter deviceId: {P1_DEVICE_ID}")
        else:
            print(f"❌ Failed to subscribe: {result}")
    elif rc == 1:
        print("❌ Connection Refused (RC 1): Incorrect protocol version")
    elif rc == 2:
        print("❌ Connection Refused (RC 2): Invalid client identifier")
    elif rc == 3:
        print("❌ Connection Refused (RC 3): Server unavailable")
    elif rc == 4:
        print("❌ Connection Refused (RC 4): Bad username or password")
    elif rc == 5:
        print("❌ Connection Refused (RC 5): Not authorized - Invalid AppKey or AppSecret")
    else:
        print(f"❌ Connection failed with result code {rc}")


def on_disconnect(client, userdata, rc, properties=None):
    """Callback for when the client disconnects from the server"""
    if rc != 0:
        print(f"⚠️  Unexpected disconnection (RC {rc}). Will attempt to reconnect...")
    else:
        print("ℹ️  Disconnected from MQTT broker")


def on_subscribe(client, userdata, mid, granted_qos, properties=None):
    """Callback for when the client subscribes to a topic"""
    print(f"✅ Subscription confirmed (QoS: {granted_qos})")


def on_message(client, userdata, msg):
    """Callback for when a PUBLISH message is received from the server"""
    try:
        payload_str = msg.payload.decode('utf-8')
        payload = json.loads(payload_str)
        
        # Check if this message is from the P1 meter
        # The deviceId might be in the payload or in the topic
        device_id = None
        
        # Try to get deviceId from payload
        if isinstance(payload, dict):
            device_id = payload.get('deviceId') or payload.get('device_id')
        
        # Also check topic structure: {APP_KEY}/{deviceId}/...
        topic_parts = msg.topic.split('/')
        if len(topic_parts) > 1:
            # Second part might be deviceId
            potential_device_id = topic_parts[1]
            if potential_device_id and potential_device_id != APP_KEY:
                device_id = device_id or potential_device_id
        
        # Filter for P1 meter
        if device_id == P1_DEVICE_ID:
            print(f"\n📊 P1 Meter Update from {msg.topic}")
            print(f"   Device ID: {device_id}")
            
            # Extract P1 meter data
            p1_data = extract_p1_data(payload)
            
            if p1_data:
                # Update JSON file
                if write_json_atomic(p1_data):
                    print(f"✅ P1 meter data saved to {DATA_FILE}")
                    print(f"   Total Power: {p1_data.get('total_power', 'N/A')} W")
                else:
                    print("❌ Failed to save P1 meter data")
            else:
                print("⚠️  Could not extract P1 meter data from message")
        else:
            # Not a P1 meter message, optionally log for debugging
            if device_id:
                print(f"ℹ️  Message from device {device_id} (not P1 meter), ignoring")
            
    except json.JSONDecodeError as e:
        print(f"⚠️  Error parsing JSON from {msg.topic}: {e}")
    except UnicodeDecodeError as e:
        print(f"⚠️  Error decoding message from {msg.topic}: {e}")
    except Exception as e:
        print(f"❌ Error processing message from {msg.topic}: {e}")


def extract_p1_data(payload):
    """
    Extract P1 meter data from MQTT payload and format it to match JSON structure.
    
    Returns dict with: timestamp, deviceId, total_power, a_aprt_power, b_aprt_power, c_aprt_power, meter_timestamp
    """
    if not isinstance(payload, dict):
        return None
    
    # The payload structure may vary, try different possible field names
    total_power = (
        payload.get('total_power') or 
        payload.get('totalPower') or 
        payload.get('power') or
        payload.get('grid_power')
    )
    
    # Phase powers
    phase_a = (
        payload.get('a_aprt_power') or 
        payload.get('aAprtPower') or 
        payload.get('phase_a') or
        payload.get('phaseA')
    )
    
    phase_b = (
        payload.get('b_aprt_power') or 
        payload.get('bAprtPower') or 
        payload.get('phase_b') or
        payload.get('phaseB')
    )
    
    phase_c = (
        payload.get('c_aprt_power') or 
        payload.get('cAprtPower') or 
        payload.get('phase_c') or
        payload.get('phaseC')
    )
    
    # Timestamp
    meter_timestamp = (
        payload.get('timestamp') or 
        payload.get('time') or
        payload.get('meter_timestamp')
    )
    
    device_id = payload.get('deviceId') or payload.get('device_id') or P1_DEVICE_ID
    
    # Only proceed if we have at least total_power
    if total_power is None:
        return None
    
    # Prepare data in the format expected by PHP
    # PHP uses date('c') which is ISO 8601 with timezone
    # Use timezone-aware datetime to match PHP format
    current_time = datetime.now(timezone.utc).astimezone()
    p1_data = {
        'timestamp': current_time.isoformat(),
        'deviceId': device_id,
        'total_power': total_power,
        'a_aprt_power': phase_a if phase_a is not None else 0,
        'b_aprt_power': phase_b if phase_b is not None else 0,
        'c_aprt_power': phase_c if phase_c is not None else 0,
        'meter_timestamp': meter_timestamp
    }
    
    return p1_data


def connect_with_retry(client, max_attempts=MAX_RECONNECT_ATTEMPTS):
    """Connect to MQTT broker with retry logic"""
    attempt = 0
    while attempt < max_attempts and running:
        attempt += 1
        try:
            print(f"🔌 Attempting to connect to {BROKER}:{PORT} (attempt {attempt}/{max_attempts})...")
            result = client.connect(BROKER, PORT, CONNECT_TIMEOUT)
            
            if result == mqtt.MQTT_ERR_SUCCESS:
                return True
            else:
                print(f"⚠️  Connection attempt failed with code {result}")
                
        except Exception as e:
            print(f"❌ Connection error: {e}")
        
        if attempt < max_attempts and running:
            print(f"⏳ Waiting {RECONNECT_DELAY} seconds before retry...")
            time.sleep(RECONNECT_DELAY)
    
    return False


def main():
    """Main function"""
    global client
    
    # Register signal handlers for graceful shutdown
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    
    print("=" * 60)
    print("Zendure P1 Meter MQTT Reader")
    print("=" * 60)
    print(f"Broker: {BROKER}:{PORT}")
    print(f"App Key: {APP_KEY[:20]}...")
    print(f"P1 Device ID: {P1_DEVICE_ID}")
    print(f"Data File: {DATA_FILE}")
    print("=" * 60)
    
    # Ensure data directory exists
    if not ensure_data_dir():
        print("❌ Cannot proceed without data directory")
        sys.exit(1)
    
    # Create MQTT client
    try:
        client = mqtt.Client(
            callback_api_version=mqtt.CallbackAPIVersion.VERSION2,
            client_id=f"zendure_p1_reader_{os.getpid()}"
        )
    except Exception as e:
        print(f"❌ Failed to create MQTT client: {e}")
        sys.exit(1)
    
    # Set credentials
    client.username_pw_set(APP_KEY, APP_SECRET)
    
    # Assign callbacks
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_subscribe = on_subscribe
    client.on_message = on_message
    
    # Connect with retry
    if not connect_with_retry(client):
        print("❌ Failed to connect after all retry attempts")
        sys.exit(1)
    
    # Start the loop
    print("\n🔄 Starting MQTT message loop...")
    print("Press Ctrl+C to stop\n")
    
    try:
        client.loop_start()
        
        # Keep running until interrupted
        while running:
            time.sleep(1)
            # Check if client is still connected
            if not client.is_connected():
                if running:
                    print("⚠️  Connection lost, attempting to reconnect...")
                    if connect_with_retry(client):
                        client.loop_start()
                    else:
                        print("❌ Reconnection failed")
                        break
        
    except KeyboardInterrupt:
        signal_handler(signal.SIGINT, None)
    except Exception as e:
        print(f"❌ Unexpected error: {e}")
    finally:
        client.loop_stop()
        client.disconnect()
        print("👋 Goodbye!")


if __name__ == "__main__":
    main()

