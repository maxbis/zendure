# Data API Documentation

## Overview

The Data API provides a centralized HTTP interface for accessing and managing JSON files in the `data/` directory. This API replaces direct filesystem access, providing better security, consistency, and error handling.

**Base URL:** `<base_dir>/data/api/data_api.php`

**Note:** Replace `<base_dir>` with your actual base directory (e.g., `/Energy` for development or your production path).

## Supported Data Types

- **price** - Electricity price files (`priceYYYYMMDD.json`)
- **zendure** - Zendure device data (`zendure_data.json`)
- **zendure_p1** - Zendure P1 meter data (`zendure_p1_data.json`)
- **schedule** - Charge schedule data (`charge_schedule.json`)
- **automation_status** - Automation status log (`automation_status.json`)
- **file** - Generic file operations (any JSON file)
- **list** - List files in the data directory

## HTTP Methods

- **GET** - Read data from files
- **POST** - Create or update files
- **DELETE** - Delete files (price type only)

## Response Format

All responses are JSON with the following structure:

**Success Response:**
```json
{
  "success": true,
  "data": { ... },
  "file": "filename.json",
  "timestamp": 1734710400
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message here"
}
```

---

## Price Files Operations

### Read Price Data

**GET** `?type=price&date=YYYYMMDD`

Reads price data for a specific date.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=price&date=20251220
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "00": 0.1234,
    "01": 0.1456,
    "02": 0.1345,
    ...
    "23": 0.1567
  },
  "file": "price20251220.json",
  "timestamp": 1734710400
}
```

**Python Example:**
```python
import requests

response = requests.get('http://localhost<base_dir>/data/api/data_api.php', 
                       params={'type': 'price', 'date': '20251220'})
data = response.json()

if data['success']:
    prices = data['data']
    print(f"Price at 00:00: {prices['00']} EUR/kWh")
else:
    print(f"Error: {data['error']}")
```

### List All Price Files

**GET** `?type=price&list=true`

Lists all available price files.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=price&list=true
```

**Example Response:**
```json
{
  "success": true,
  "files": [
    "price20251220.json",
    "price20251221.json",
    "price20251222.json"
  ],
  "count": 3
}
```

### Create/Update Price Data

**POST** `?type=price&date=YYYYMMDD`

Creates or updates price data for a specific date.

**Example Request:**
```bash
POST <base_dir>/data/api/data_api.php?type=price&date=20251220
Content-Type: application/json

{
  "00": 0.1234,
  "01": 0.1456,
  "02": 0.1345,
  ...
  "23": 0.1567
}
```

**Example Response:**
```json
{
  "success": true,
  "message": "File saved successfully",
  "file": "price20251220.json"
}
```

**Python Example:**
```python
import requests

prices = {
    '00': 0.1234,
    '01': 0.1456,
    '02': 0.1345,
    # ... all 24 hours
    '23': 0.1567
}

response = requests.post(
    'http://localhost<base_dir>/data/api/data_api.php',
    params={'type': 'price', 'date': '20251220'},
    json=prices
)

result = response.json()
if result['success']:
    print(f"Saved prices to {result['file']}")
else:
    print(f"Error: {result['error']}")
```

**Validation Rules:**
- Hour keys must be "00" through "23"
- All values must be numeric
- All 24 hours are not required, but recommended

### Delete Price File

**DELETE** `?type=price&date=YYYYMMDD`

Deletes a price file for a specific date.

**Example Request:**
```bash
DELETE <base_dir>/data/api/data_api.php?type=price&date=20251220
```

**Example Response:**
```json
{
  "success": true,
  "message": "File deleted successfully",
  "file": "price20251220.json"
}
```

**Python Example:**
```python
import requests

response = requests.delete(
    'http://localhost<base_dir>/data/api/data_api.php',
    params={'type': 'price', 'date': '20251220'}
)

result = response.json()
if result['success']:
    print(f"Deleted {result['file']}")
```

---

## Zendure Data Operations

### Read Zendure Data

**GET** `?type=zendure`

Reads the main Zendure device data file.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=zendure
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "electricLevel": 85,
    "inputLimit": 1200,
    "outputLimit": -800,
    ...
  },
  "file": "zendure_data.json",
  "timestamp": 1734710400
}
```

**Python Example:**
```python
import requests

response = requests.get('http://localhost<base_dir>/data/api/data_api.php',
                       params={'type': 'zendure'})
data = response.json()

if data['success']:
    zendure_data = data['data']
    battery_level = zendure_data.get('electricLevel', 0)
    print(f"Battery level: {battery_level}%")
```

### Update Zendure Data

**POST** `?type=zendure`

Updates the Zendure device data file.

**Example Request:**
```bash
POST <base_dir>/data/api/data_api.php?type=zendure
Content-Type: application/json

{
  "electricLevel": 85,
  "inputLimit": 1200,
  "outputLimit": -800
}
```

### Read Zendure P1 Data

**GET** `?type=zendure_p1`

Reads the Zendure P1 meter data file.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=zendure_p1
```

### Update Zendure P1 Data

**POST** `?type=zendure_p1`

Updates the Zendure P1 meter data file.

---

## Schedule Data Operations

### Read Charge Schedule

**GET** `?type=schedule`

Reads the charge schedule data.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=schedule
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "202512201200": 1200,
    "202512201300": 1200,
    "202512202000": -800
  },
  "file": "charge_schedule.json",
  "timestamp": 1734710400
}
```

### Update Charge Schedule

**POST** `?type=schedule`

Updates the charge schedule data.

---

## Automation Status Operations

### Read Automation Status

**GET** `?type=automation_status`

Reads the automation status log.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=automation_status
```

### Update Automation Status

**POST** `?type=automation_status`

Adds an entry to the automation status log.

---

## Generic File Operations

### Read Any JSON File

**GET** `?type=file&name=filename.json`

Reads any JSON file from the data directory.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=file&name=my_custom_data.json
```

**Security Note:** The filename is sanitized to prevent directory traversal attacks. Only files in the data directory can be accessed.

### Write Any JSON File

**POST** `?type=file&name=filename.json`

Writes any JSON file to the data directory.

**Example Request:**
```bash
POST <base_dir>/data/api/data_api.php?type=file&name=my_custom_data.json
Content-Type: application/json

{
  "key1": "value1",
  "key2": "value2"
}
```

---

## List Files Operation

### List All JSON Files

**GET** `?type=list`

Lists all JSON files in the data directory.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=list
```

**Example Response:**
```json
{
  "success": true,
  "files": [
    "automation_status.json",
    "charge_schedule.json",
    "price20251220.json",
    "price20251221.json",
    "zendure_data.json",
    "zendure_p1_data.json"
  ],
  "count": 6
}
```

### List Files with Pattern

**GET** `?type=list&pattern=price*.json`

Lists files matching a pattern.

**Example Request:**
```bash
GET <base_dir>/data/api/data_api.php?type=list&pattern=price*.json
```

**Example Response:**
```json
{
  "success": true,
  "files": [
    "price20251220.json",
    "price20251221.json",
    "price20251222.json"
  ],
  "count": 3
}
```

---

## Error Handling

### Common Error Responses

**Missing Parameter:**
```json
{
  "success": false,
  "error": "Missing 'date' parameter for price type"
}
```

**Invalid Type:**
```json
{
  "success": false,
  "error": "Invalid type: invalid_type. Allowed types: price, zendure, zendure_p1, schedule, automation_status, file, list"
}
```

**File Not Found:**
```json
{
  "success": false,
  "error": "File not found",
  "file": "price20251220.json"
}
```

**Invalid JSON:**
```json
{
  "success": false,
  "error": "Invalid JSON in request body: Syntax error"
}
```

**Invalid Price Data:**
```json
{
  "success": false,
  "error": "Invalid price data: Invalid hour key: 24 (must be 00-23)"
}
```

**Python Error Handling Example:**
```python
import requests

try:
    response = requests.get('http://localhost<base_dir>/data/api/data_api.php',
                          params={'type': 'price', 'date': '20251220'})
    response.raise_for_status()
    data = response.json()
    
    if data['success']:
        prices = data['data']
        # Process prices...
    else:
        print(f"API Error: {data['error']}")
        
except requests.exceptions.RequestException as e:
    print(f"Network Error: {e}")
except ValueError as e:
    print(f"JSON Parse Error: {e}")
```

---

## Security Features

1. **Filename Sanitization:** All filenames are sanitized to prevent directory traversal attacks
2. **Type Validation:** Only allowed types can be accessed
3. **JSON Validation:** All input JSON is validated before processing
4. **Atomic Writes:** Files are written atomically to prevent corruption
5. **Path Restrictions:** Only files in the data directory can be accessed

---

## Complete Python Example

Here's a complete example showing how to use the API from Python:

```python
import requests
from datetime import datetime, timedelta

BASE_URL = 'http://localhost<base_dir>/data/api/data_api.php'

class DataAPI:
    def __init__(self, base_url=BASE_URL):
        self.base_url = base_url
    
    def get_price(self, date):
        """Get price data for a specific date (YYYYMMDD format)"""
        response = requests.get(self.base_url, params={
            'type': 'price',
            'date': date
        })
        data = response.json()
        if data['success']:
            return data['data']
        else:
            raise Exception(f"Failed to get price: {data['error']}")
    
    def save_price(self, date, prices):
        """Save price data for a specific date"""
        response = requests.post(self.base_url, params={
            'type': 'price',
            'date': date
        }, json=prices)
        data = response.json()
        if data['success']:
            return True
        else:
            raise Exception(f"Failed to save price: {data['error']}")
    
    def list_price_files(self):
        """List all available price files"""
        response = requests.get(self.base_url, params={
            'type': 'price',
            'list': 'true'
        })
        data = response.json()
        if data['success']:
            return data['files']
        else:
            raise Exception(f"Failed to list files: {data['error']}")
    
    def get_zendure_data(self):
        """Get Zendure device data"""
        response = requests.get(self.base_url, params={'type': 'zendure'})
        data = response.json()
        if data['success']:
            return data['data']
        else:
            raise Exception(f"Failed to get Zendure data: {data['error']}")
    
    def file_exists(self, date):
        """Check if a price file exists"""
        try:
            self.get_price(date)
            return True
        except:
            return False

# Usage example
api = DataAPI()

# Get today's prices
today = datetime.now().strftime('%Y%m%d')
try:
    prices = api.get_price(today)
    print(f"Today's prices loaded: {len(prices)} hours")
except Exception as e:
    print(f"Error: {e}")

# List all price files
files = api.list_price_files()
print(f"Available price files: {len(files)}")

# Get Zendure data
zendure = api.get_zendure_data()
print(f"Battery level: {zendure.get('electricLevel', 'N/A')}%")
```

---

## Notes

- All timestamps in responses are Unix timestamps (seconds since epoch)
- File operations are atomic (write to temp file, then rename) to prevent corruption
- The API automatically creates the data directory if it doesn't exist
- Price data must have hour keys from "00" to "23" with numeric values
- Only JSON files can be accessed through the API
- DELETE operations are currently only supported for price files

---

## Migration from Direct File Access

If you're migrating from direct file access to the API:

**Before (Direct File Access):**
```python
import json
import os

data_dir = "data"
filename = os.path.join(data_dir, f"price{date}.json")
with open(filename, 'r') as f:
    prices = json.load(f)
```

**After (API Access):**
```python
import requests

response = requests.get('http://localhost<base_dir>/data/api/data_api.php',
                       params={'type': 'price', 'date': date})
data = response.json()
if data['success']:
    prices = data['data']
```

The API provides the same functionality with better error handling, security, and consistency.

