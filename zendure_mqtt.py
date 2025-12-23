import paho.mqtt.client as mqtt
import json

# Your credentials from the Zendure Developer API
APP_KEY = "aHR0cHM6Ly9hcHAuemVuZHVyZS50ZWNoL2V1LjYydWgwQzIwdg"
APP_SECRET = "ZEE1NBN9N420947"

# Use "mqtt-eu.zen-iot.com" for Europe or "mqtt.zen-iot.com" for Global
BROKER = "mqtt-eu.zen-iot.com" 
PORT = 1883

def on_connect(client, userdata, flags, rc, properties=None):
    """
    rc 0: Success
    rc 5: Invalid user/password
    """
    if rc == 0:
        print("✅ Successfully connected to Zendure!")
        # Subscribing to all devices under your key
        topic = f"{APP_KEY}/#"
        client.subscribe(topic)
        print(f"📡 Listening for data on: {topic}")
    elif rc == 5:
        print("❌ Connection Refused (RC 5): Invalid AppKey or AppSecret.")
    else:
        print(f"❌ Connection failed with result code {rc}")

def on_message(client, userdata, msg):
    try:
        payload = json.loads(msg.payload.decode())
        print(f"\n--- New Update from {msg.topic} ---")
        # Print nicely formatted JSON
        print(json.dumps(payload, indent=2))
    except Exception as e:
        print(f"Error parsing message: {e}")

# 1. Use CallbackAPIVersion.VERSION2 to fix the DeprecationWarning
client = mqtt.Client(callback_api_version=mqtt.CallbackAPIVersion.VERSION2)

# 2. Set credentials
client.username_pw_set(APP_KEY, APP_SECRET)

# 3. Assign callbacks
client.on_connect = on_connect
client.on_message = on_message

# 4. Connect and Loop
try:
    print(f"Attempting to connect to {BROKER}...")
    client.connect(BROKER, PORT, 60)
    client.loop_forever()
except Exception as e:
    print(f"Could not start MQTT client: {e}")