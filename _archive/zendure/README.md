# Zendure API Flask Application

A Flask REST API for interacting with Zendure SolarFlow devices and P1 meters. Designed to run on a Raspberry Pi and serve data to web interfaces.

## Features

- **GET /api/zendure/status** - Fetch current status from Zendure SolarFlow device
- **GET /api/zendure/p1** - Fetch current status from Zendure P1 meter
- **POST /api/zendure/power** - Set charge/discharge power (optional)
- **GET /api/health** - Health check endpoint

## Installation

### 1. Install Python Dependencies

```bash
pip install -r requirements.txt
```

Or use a virtual environment (recommended):

```bash
python3 -m venv venv
source venv/bin/activate  # On Windows: venv\Scripts\activate
pip install -r requirements.txt
```

### 2. Configure

Edit `config.json` with your device IP addresses:

```json
{
  "deviceIp": "192.168.2.93",
  "deviceSn": "HOA1NAN9N385989",
  "p1MeterIp": "192.168.2.94"
}
```

## Running the Application

### Development Mode

```bash
python app.py
```

The API will be available at `http://localhost:5000`

### Production Mode (Raspberry Pi)

For production use on Raspberry Pi, use a WSGI server like Gunicorn:

```bash
pip install gunicorn
gunicorn -w 4 -b 0.0.0.0:5000 app:app
```

### Running as a Service (systemd)

Create a systemd service file `/etc/systemd/system/zendure-api.service`:

```ini
[Unit]
Description=Zendure API Flask Application
After=network.target

[Service]
User=pi
WorkingDirectory=/path/to/zendure
Environment="PATH=/path/to/zendure/venv/bin"
ExecStart=/path/to/zendure/venv/bin/gunicorn -w 4 -b 0.0.0.0:5000 app:app

[Install]
WantedBy=multi-user.target
```

Then enable and start the service:

```bash
sudo systemctl enable zendure-api.service
sudo systemctl start zendure-api.service
```

## API Endpoints

### GET /api/zendure/status

Get current status from Zendure SolarFlow device.

**Response:**
```json
{
  "success": true,
  "data": {
    "timestamp": "2024-01-01T12:00:00",
    "properties": {
      "electricLevel": 75,
      "solarInputPower": 500,
      "outputHomePower": 300,
      ...
    },
    "packData": [...]
  },
  "timestamp": "2024-01-01T12:00:00"
}
```

### GET /api/zendure/p1

Get current status from Zendure P1 meter.

**Response:**
```json
{
  "success": true,
  "data": {
    "timestamp": "2024-01-01T12:00:00",
    "deviceId": "ZEE1NBN9N420947",
    "total_power": -200,
    "a_aprt_power": -100,
    "b_aprt_power": -50,
    "c_aprt_power": -50,
    "meter_timestamp": "2024-01-01T12:00:00"
  },
  "timestamp": "2024-01-01T12:00:00"
}
```

### POST /api/zendure/power

Set charge/discharge power.

**Request Body:**
```json
{
  "watts": 400
}
```

Or as query parameter:
```
POST /api/zendure/power?watts=400
```

**Response:**
```json
{
  "success": true,
  "watts": 400,
  "response": {...},
  "timestamp": "2024-01-01T12:00:00"
}
```

**Power Values:**
- Positive: Charge from grid (e.g., `400` = charge at 400W)
- Negative: Discharge to home (e.g., `-400` = discharge at 400W)
- Zero: Stop all charging and discharging

### GET /api/health

Health check endpoint.

**Response:**
```json
{
  "status": "healthy",
  "timestamp": "2024-01-01T12:00:00"
}
```

## Integration with statusa/index.php

To use this API from `statusa/index.php`, replace the direct device calls with API calls:

```php
// Instead of:
$solarflow = new SolarFlow2400($config['deviceIp']);
$solarflow->getStatus(false);

// Use:
$apiUrl = 'http://raspberry-pi-ip:5000/api/zendure/status';
$response = file_get_contents($apiUrl);
$zendureData = json_decode($response, true)['data'];
```

## Troubleshooting

- **Connection errors**: Ensure the Flask app can reach the Zendure devices on your network
- **CORS errors**: The app includes CORS support, but ensure your web server allows cross-origin requests
- **Port conflicts**: Change the port in `app.py` if port 5000 is already in use

## License

MIT

