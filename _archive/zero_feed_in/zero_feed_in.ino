/*
 * P1 Meter Zero Feed-In Controller for ESP32
 * Reads total power from Zendure P1 meter periodically and adjusts power feed 
 * to maintain zero feed-in.
 * 
 * For ESP32-WROOM-32D with WiFi
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <EEPROM.h>
#include <time.h>

// ============================================================================
// COMPILE-TIME CONFIGURATION
// ============================================================================

// WiFi defaults (can be changed via Serial commands)
#define DEFAULT_WIFI_SSID "WLAN"
#define DEFAULT_WIFI_PASSWORD "ditisgeheim"

// Device defaults (can be changed via Serial commands)
#define DEFAULT_P1_METER_IP "192.168.2.94"
#define DEFAULT_DEVICE_IP "192.168.2.93"
#define DEFAULT_DEVICE_SN "HOA1NAN9N385989"

// Operation mode
#define MODE_BOTH 0
#define MODE_CHARGE_ONLY 1
#define MODE_DISCHARGE_ONLY 2
#define MODE MODE_BOTH  // Change to MODE_CHARGE_ONLY or MODE_DISCHARGE_ONLY if needed

// Timing and control parameters
#define READ_INTERVAL_SECONDS 15
#define POWER_FEED_ADJUSTMENT_THRESHOLD 10
#define MAX_ADJUSTMENT_STEP 200
#define POWER_FEED_MIN -800
#define POWER_FEED_MAX 800
#define POWER_FEED_MIN_THRESHOLD 20

// HTTP timeout (milliseconds)
#define HTTP_TIMEOUT 5000

// ============================================================================
// EEPROM CONFIGURATION STRUCTURE
// ============================================================================

#define EEPROM_SIZE 512
#define EEPROM_MAGIC_BYTE 0xAA
#define EEPROM_MAGIC_ADDR 0
#define EEPROM_WIFI_SSID_ADDR 1
#define EEPROM_WIFI_SSID_SIZE 32
#define EEPROM_WIFI_PASS_ADDR (EEPROM_WIFI_SSID_ADDR + EEPROM_WIFI_SSID_SIZE)
#define EEPROM_WIFI_PASS_SIZE 64
#define EEPROM_P1_IP_ADDR (EEPROM_WIFI_PASS_ADDR + EEPROM_WIFI_PASS_SIZE)
#define EEPROM_P1_IP_SIZE 16
#define EEPROM_DEVICE_IP_ADDR (EEPROM_P1_IP_ADDR + EEPROM_P1_IP_SIZE)
#define EEPROM_DEVICE_IP_SIZE 16
#define EEPROM_DEVICE_SN_ADDR (EEPROM_DEVICE_IP_ADDR + EEPROM_DEVICE_IP_SIZE)
#define EEPROM_DEVICE_SN_SIZE 32

// ============================================================================
// GLOBAL VARIABLES
// ============================================================================

String wifiSSID;
String wifiPassword;
String p1MeterIP;
String deviceIP;
String deviceSN;

int powerFeed = 0;
int lastSentPowerFeed = -999;  // Use invalid value to force first send

unsigned long lastReadTime = 0;
unsigned long startTime = 0;

// ============================================================================
// EEPROM HELPER FUNCTIONS
// ============================================================================

void writeStringToEEPROM(int address, const String& str, int maxSize) {
  int len = str.length();
  if (len >= maxSize) len = maxSize - 1;
  
  for (int i = 0; i < len; i++) {
    EEPROM.write(address + i, str.charAt(i));
  }
  EEPROM.write(address + len, '\0');  // Null terminator
  EEPROM.commit();
}

String readStringFromEEPROM(int address, int maxSize) {
  String result = "";
  for (int i = 0; i < maxSize; i++) {
    char c = EEPROM.read(address + i);
    if (c == '\0') break;
    if (c != 0xFF) result += c;  // Ignore uninitialized EEPROM bytes
  }
  return result;
}

bool isEEPROMInitialized() {
  return EEPROM.read(EEPROM_MAGIC_ADDR) == EEPROM_MAGIC_BYTE;
}

void initializeEEPROM() {
  Serial.println("Initializing EEPROM with default values...");
  
  // Write magic byte
  EEPROM.write(EEPROM_MAGIC_ADDR, EEPROM_MAGIC_BYTE);
  
  // Write defaults
  writeStringToEEPROM(EEPROM_WIFI_SSID_ADDR, DEFAULT_WIFI_SSID, EEPROM_WIFI_SSID_SIZE);
  writeStringToEEPROM(EEPROM_WIFI_PASS_ADDR, DEFAULT_WIFI_PASSWORD, EEPROM_WIFI_PASS_SIZE);
  writeStringToEEPROM(EEPROM_P1_IP_ADDR, DEFAULT_P1_METER_IP, EEPROM_P1_IP_SIZE);
  writeStringToEEPROM(EEPROM_DEVICE_IP_ADDR, DEFAULT_DEVICE_IP, EEPROM_DEVICE_IP_SIZE);
  writeStringToEEPROM(EEPROM_DEVICE_SN_ADDR, DEFAULT_DEVICE_SN, EEPROM_DEVICE_SN_SIZE);
  
  Serial.println("EEPROM initialized successfully.");
}

void loadConfig() {
  if (!isEEPROMInitialized()) {
    initializeEEPROM();
  }
  
  wifiSSID = readStringFromEEPROM(EEPROM_WIFI_SSID_ADDR, EEPROM_WIFI_SSID_SIZE);
  wifiPassword = readStringFromEEPROM(EEPROM_WIFI_PASS_ADDR, EEPROM_WIFI_PASS_SIZE);
  p1MeterIP = readStringFromEEPROM(EEPROM_P1_IP_ADDR, EEPROM_P1_IP_SIZE);
  deviceIP = readStringFromEEPROM(EEPROM_DEVICE_IP_ADDR, EEPROM_DEVICE_IP_SIZE);
  deviceSN = readStringFromEEPROM(EEPROM_DEVICE_SN_ADDR, EEPROM_DEVICE_SN_SIZE);
  
  // Verify we got valid strings (not all empty)
  if (wifiSSID.length() == 0) wifiSSID = DEFAULT_WIFI_SSID;
  if (p1MeterIP.length() == 0) p1MeterIP = DEFAULT_P1_METER_IP;
  if (deviceIP.length() == 0) deviceIP = DEFAULT_DEVICE_IP;
  if (deviceSN.length() == 0) deviceSN = DEFAULT_DEVICE_SN;
}

void saveConfig() {
  EEPROM.write(EEPROM_MAGIC_ADDR, EEPROM_MAGIC_BYTE);
  writeStringToEEPROM(EEPROM_WIFI_SSID_ADDR, wifiSSID, EEPROM_WIFI_SSID_SIZE);
  writeStringToEEPROM(EEPROM_WIFI_PASS_ADDR, wifiPassword, EEPROM_WIFI_PASS_SIZE);
  writeStringToEEPROM(EEPROM_P1_IP_ADDR, p1MeterIP, EEPROM_P1_IP_SIZE);
  writeStringToEEPROM(EEPROM_DEVICE_IP_ADDR, deviceIP, EEPROM_DEVICE_IP_SIZE);
  writeStringToEEPROM(EEPROM_DEVICE_SN_ADDR, deviceSN, EEPROM_DEVICE_SN_SIZE);
  Serial.println("Configuration saved to EEPROM.");
}

// ============================================================================
// HTTP FUNCTIONS
// ============================================================================

int readP1Meter(const String& ip) {
  HTTPClient http;
  String url = "http://" + ip + "/properties/report";
  
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT);
  
  int httpCode = http.GET();
  
  if (httpCode > 0) {
    if (httpCode == HTTP_CODE_OK) {
      String payload = http.getString();
      http.end();
      
      // Parse JSON
      DynamicJsonDocument doc(1024);
      DeserializationError error = deserializeJson(doc, payload);
      
      if (error) {
        Serial.print("Error parsing JSON: ");
        Serial.println(error.c_str());
        return -999;  // Return invalid value to indicate error
      }
      
      if (doc.containsKey("total_power")) {
        return doc["total_power"].as<int>();
      } else {
        Serial.println("Error: 'total_power' not found in response");
        return -999;
      }
    } else {
      Serial.print("HTTP Error: ");
      Serial.println(httpCode);
      http.end();
      return -999;
    }
  } else {
    Serial.print("Error connecting to P1 meter: ");
    Serial.println(http.errorToString(httpCode));
    http.end();
    return -999;
  }
}

bool sendPowerFeed(const String& deviceIp, const String& deviceSn, int powerFeedValue) {
  HTTPClient http;
  String url = "http://" + deviceIp + "/properties/write";
  
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT);
  http.addHeader("Content-Type", "application/json");
  
  // Negate power_feed as per Python code (line 102)
  powerFeedValue = -powerFeedValue;
  
  // Build JSON payload
  DynamicJsonDocument doc(512);
  doc["sn"] = deviceSn;
  
  JsonObject properties = doc.createNestedObject("properties");
  
  if (powerFeedValue > 0) {
    // Charge mode: acMode 1 = Input
    properties["acMode"] = 1;
    properties["inputLimit"] = powerFeedValue;
    properties["outputLimit"] = 0;
    properties["smartMode"] = 1;
  } else if (powerFeedValue < 0) {
    // Discharge mode: acMode 2 = Output
    properties["acMode"] = 2;
    properties["outputLimit"] = abs(powerFeedValue);
    properties["inputLimit"] = 0;
    properties["smartMode"] = 1;
  } else {
    // Stop all
    properties["inputLimit"] = 0;
    properties["outputLimit"] = 0;
    properties["smartMode"] = 1;
  }
  
  String payload;
  serializeJson(doc, payload);
  
  int httpCode = http.POST(payload);
  
  bool success = false;
  if (httpCode > 0) {
    if (httpCode == HTTP_CODE_OK) {
      success = true;
    } else {
      Serial.print("HTTP Error: ");
      Serial.println(httpCode);
    }
    http.getString();  // Read response even if we don't use it
  } else {
    Serial.print("Error sending power feed: ");
    Serial.println(http.errorToString(httpCode));
  }
  
  http.end();
  return success;
}

// ============================================================================
// POWER FEED CALCULATION
// ============================================================================

struct PowerFeedResult {
  int newPowerFeed;
  int adjustmentApplied;
};

PowerFeedResult calculatePowerFeed(int totalPower, int oldPowerFeed) {
  PowerFeedResult result;
  result.adjustmentApplied = 0;
  
  int desiredPowerFeed;
  
  // Apply mode logic with additive formula
  // MODE is compile-time constant, compiler will optimize this
  if (MODE == MODE_CHARGE_ONLY) {
    // Only charge (positive power_feed) when total_power is negative (excess power)
    if (totalPower < 0) {
      int adjustment = abs(totalPower);  // Add charge to consume excess power
      desiredPowerFeed = oldPowerFeed + adjustment;
    } else {
      desiredPowerFeed = oldPowerFeed;  // Don't charge when consuming from grid
    }
  } else if (MODE == MODE_DISCHARGE_ONLY) {
    // Only discharge (negative power_feed) when total_power is positive (consuming from grid)
    if (totalPower > 0) {
      int adjustment = -totalPower;  // Subtract discharge to offset consumption
      desiredPowerFeed = oldPowerFeed + adjustment;
    } else {
      desiredPowerFeed = oldPowerFeed;  // Don't discharge when feeding into grid
    }
  } else {  // MODE_BOTH
    // Additive behavior: add reading to current feed to incrementally adjust toward zero
    desiredPowerFeed = oldPowerFeed + totalPower;
  }
  
  // Calculate adjustment based on desired_power_feed
  int adjustment = desiredPowerFeed - oldPowerFeed;
  
  // Only apply adjustment if it exceeds threshold
  if (abs(adjustment) >= POWER_FEED_ADJUSTMENT_THRESHOLD) {
    Serial.println("adjustment exceeds threshold");
    if (abs(adjustment) < MAX_ADJUSTMENT_STEP) {
      Serial.println("adjustment is less than MAX_ADJUSTMENT_STEP");
      result.newPowerFeed = desiredPowerFeed;
      result.adjustmentApplied = adjustment;
    } else {
      Serial.println("adjustment is greater than MAX_ADJUSTMENT_STEP");
      // Limit adjustment to MAX_ADJUSTMENT_STEP, preserving sign
      int sign = (adjustment >= 0) ? 1 : -1;
      result.newPowerFeed = oldPowerFeed + MAX_ADJUSTMENT_STEP * sign;
      result.adjustmentApplied = MAX_ADJUSTMENT_STEP * sign;
      Serial.print("new_power_feed: ");
      Serial.print(result.newPowerFeed);
      Serial.print(", adjustment_applied: ");
      Serial.println(result.adjustmentApplied);
    }
  } else {
    // Keep power_feed unchanged if adjustment is too small
    result.newPowerFeed = oldPowerFeed;
    result.adjustmentApplied = 0;
  }
  
  // Clamp to valid range
  if (result.newPowerFeed < POWER_FEED_MIN) result.newPowerFeed = POWER_FEED_MIN;
  if (result.newPowerFeed > POWER_FEED_MAX) result.newPowerFeed = POWER_FEED_MAX;
  Serial.print("new_power_feed: ");
  Serial.println(result.newPowerFeed);
  
  // Apply minimum threshold: if between -MIN and +MIN (excluding 0), revert to old value
  if (abs(result.newPowerFeed) < POWER_FEED_MIN_THRESHOLD && result.newPowerFeed != 0) {
    Serial.println("new_power_feed is less than POWER_FEED_MIN_THRESHOLD");
    result.newPowerFeed = oldPowerFeed;
    result.adjustmentApplied = 0;
  }
  
  // Round to integer (already int, but ensure it's rounded)
  result.newPowerFeed = (int)round(result.newPowerFeed);
  
  return result;
}

// ============================================================================
// SERIAL COMMAND HANDLING
// ============================================================================

void handleSerialCommands() {
  if (Serial.available() > 0) {
    String command = Serial.readStringUntil('\n');
    command.trim();
    String originalCommand = command;  // Keep original for value extraction
    command.toUpperCase();
    
    if (command.startsWith("SET_WIFI_SSID")) {
      int spaceIndex = originalCommand.indexOf(' ');
      if (spaceIndex > 0 && spaceIndex < originalCommand.length() - 1) {
        wifiSSID = originalCommand.substring(spaceIndex + 1);
        wifiSSID.trim();
        saveConfig();
        Serial.print("WiFi SSID set to: ");
        Serial.println(wifiSSID);
        Serial.println("Use REBOOT command to apply WiFi changes.");
      } else {
        Serial.println("Error: Missing value. Use: SET_WIFI_SSID <ssid>");
      }
    }
    else if (command.startsWith("SET_WIFI_PASS")) {
      int spaceIndex = originalCommand.indexOf(' ');
      if (spaceIndex > 0 && spaceIndex < originalCommand.length() - 1) {
        wifiPassword = originalCommand.substring(spaceIndex + 1);
        wifiPassword.trim();
        saveConfig();
        Serial.println("WiFi password updated. Use REBOOT command to apply.");
      } else {
        Serial.println("Error: Missing value. Use: SET_WIFI_PASS <password>");
      }
    }
    else if (command.startsWith("SET_P1_IP")) {
      int spaceIndex = originalCommand.indexOf(' ');
      if (spaceIndex > 0 && spaceIndex < originalCommand.length() - 1) {
        p1MeterIP = originalCommand.substring(spaceIndex + 1);
        p1MeterIP.trim();
        saveConfig();
        Serial.print("P1 Meter IP set to: ");
        Serial.println(p1MeterIP);
      } else {
        Serial.println("Error: Missing value. Use: SET_P1_IP <ip>");
      }
    }
    else if (command.startsWith("SET_DEVICE_IP")) {
      int spaceIndex = originalCommand.indexOf(' ');
      if (spaceIndex > 0 && spaceIndex < originalCommand.length() - 1) {
        deviceIP = originalCommand.substring(spaceIndex + 1);
        deviceIP.trim();
        saveConfig();
        Serial.print("Device IP set to: ");
        Serial.println(deviceIP);
      } else {
        Serial.println("Error: Missing value. Use: SET_DEVICE_IP <ip>");
      }
    }
    else if (command.startsWith("SET_DEVICE_SN")) {
      int spaceIndex = originalCommand.indexOf(' ');
      if (spaceIndex > 0 && spaceIndex < originalCommand.length() - 1) {
        deviceSN = originalCommand.substring(spaceIndex + 1);
        deviceSN.trim();
        saveConfig();
        Serial.print("Device SN set to: ");
        Serial.println(deviceSN);
      } else {
        Serial.println("Error: Missing value. Use: SET_DEVICE_SN <sn>");
      }
    }
    else if (command == "GET_CONFIG") {
      Serial.println("=== Current Configuration ===");
      Serial.print("WiFi SSID: ");
      Serial.println(wifiSSID);
      Serial.print("WiFi Password: ");
      Serial.println(wifiPassword);
      Serial.print("P1 Meter IP: ");
      Serial.println(p1MeterIP);
      Serial.print("Device IP: ");
      Serial.println(deviceIP);
      Serial.print("Device SN: ");
      Serial.println(deviceSN);
      Serial.print("Power Feed: ");
      Serial.println(powerFeed);
      Serial.println("============================");
    }
    else if (command == "RESET") {
      powerFeed = 0;
      lastSentPowerFeed = -999;
      Serial.println("Controller state reset.");
    }
    else if (command == "REBOOT") {
      Serial.println("Rebooting...");
      delay(1000);
      ESP.restart();
    }
    else if (command.length() > 0) {
      Serial.print("Unknown command: ");
      Serial.println(command);
      Serial.println("Available commands: SET_WIFI_SSID, SET_WIFI_PASS, SET_P1_IP, SET_DEVICE_IP, SET_DEVICE_SN, GET_CONFIG, RESET, REBOOT");
    }
  }
}

// ============================================================================
// TIMESTAMP FORMATTING
// ============================================================================

String formatTimestamp() {
  unsigned long now = millis();
  unsigned long seconds = now / 1000;
  unsigned long minutes = seconds / 60;
  unsigned long hours = minutes / 60;
  
  seconds = seconds % 60;
  minutes = minutes % 60;
  hours = hours % 24;
  
  char buffer[20];
  snprintf(buffer, sizeof(buffer), "%02lu:%02lu:%02lu", hours, minutes, seconds);
  return String(buffer);
}

// ============================================================================
// MAIN FUNCTIONS
// ============================================================================

void setup() {
  Serial.begin(115200);
  delay(1000);
  
  Serial.println("\nP1 Meter Zero Feed-In Controller");
  Serial.println("==========================================");
  
  // Initialize EEPROM
  EEPROM.begin(EEPROM_SIZE);
  
  // Load configuration
  loadConfig();
  
  Serial.print("WiFi SSID: ");
  Serial.println(wifiSSID);
  Serial.print("P1 Meter IP: ");
  Serial.println(p1MeterIP);
  Serial.print("Device IP: ");
  Serial.println(deviceIP);
  Serial.print("Device SN: ");
  Serial.println(deviceSN);
  Serial.println("==========================================");
  
  // Connect to WiFi
  Serial.print("Connecting to WiFi");
  WiFi.mode(WIFI_STA);
  WiFi.begin(wifiSSID.c_str(), wifiPassword.c_str());
  
  int wifiTimeout = 30;  // 30 seconds timeout
  while (WiFi.status() != WL_CONNECTED && wifiTimeout > 0) {
    delay(1000);
    Serial.print(".");
    wifiTimeout--;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println();
    Serial.print("WiFi connected! IP address: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println();
    Serial.println("WiFi connection failed!");
    Serial.println("System will continue but HTTP requests will fail.");
  }
  
  Serial.println("Starting controller...");
  Serial.println();
  
  startTime = millis();
  lastReadTime = 0;
}

void loop() {
  // Handle serial commands
  handleSerialCommands();
  
  // Check if it's time to read P1 meter
  unsigned long now = millis();
  unsigned long elapsed = now - lastReadTime;
  
  if (elapsed >= (READ_INTERVAL_SECONDS * 1000)) {
    lastReadTime = now;
    
    // Read from P1 meter
    int totalPower = readP1Meter(p1MeterIP);
    
    if (totalPower != -999) {  // Valid reading
      // Calculate power_feed
      PowerFeedResult result = calculatePowerFeed(totalPower, powerFeed);
      powerFeed = result.newPowerFeed;
      
      // Send power_feed to device if value changed
      bool sendStatus = false;
      String sendStatusStr = "";
      
      if (powerFeed != lastSentPowerFeed) {
        bool success = sendPowerFeed(deviceIP, deviceSN, powerFeed);
        if (success) {
          lastSentPowerFeed = powerFeed;
          sendStatusStr = " -> Sent";
        } else {
          sendStatusStr = " -> Send failed";
        }
      }
      
      // Format output
      String timestamp = formatTimestamp();
      Serial.print("[");
      Serial.print(timestamp);
      Serial.print("] Grid in: ");
      Serial.print(totalPower);
      Serial.print(" W | Power Feed: ");
      Serial.print(powerFeed);
      Serial.print(" W");
      
      if (result.adjustmentApplied != 0) {
        Serial.print(" (adj: ");
        if (result.adjustmentApplied > 0) Serial.print("+");
        Serial.print(result.adjustmentApplied);
        Serial.print(" W)");
      }
      
      if (sendStatusStr.length() > 0) {
        Serial.print(sendStatusStr);
      }
      
      Serial.println();
    } else {
      // Error reading meter
      String timestamp = formatTimestamp();
      Serial.print("[");
      Serial.print(timestamp);
      Serial.println("] Failed to read meter");
    }
  }
  
  // Small delay to prevent excessive CPU usage
  delay(100);
}

