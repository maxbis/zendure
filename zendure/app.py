"""
Zendure API Flask Application
Provides REST API endpoints to interact with Zendure SolarFlow devices and P1 meters.
Designed to run on Raspberry Pi and serve data to web interfaces.
"""

from flask import Flask, jsonify, request
from flask_cors import CORS
import json
import os
from datetime import datetime
from zendure_reader import ZendureReader
from p1_reader import P1Reader

app = Flask(__name__)
CORS(app)  # Enable CORS for cross-origin requests

# Load configuration
CONFIG_FILE = os.path.join(os.path.dirname(__file__), 'config.json')

def load_config():
    """Load configuration from config.json file."""
    try:
        with open(CONFIG_FILE, 'r') as f:
            return json.load(f)
    except FileNotFoundError:
        # Return default config if file doesn't exist
        return {
            "deviceIp": "192.168.2.93",
            "deviceSn": "HOA1NAN9N385989",
            "p1MeterIp": "192.168.2.94"
        }

config = load_config()

# Initialize readers
zendure_reader = ZendureReader(
    config.get('deviceIp', '192.168.2.93'),
    config.get('deviceSn')
)
p1_reader = P1Reader(config.get('p1MeterIp', '192.168.2.94'))


@app.route('/')
def index():
    """Root endpoint - API information."""
    return jsonify({
        'name': 'Zendure API',
        'version': '1.0.0',
        'endpoints': {
            'GET /api/zendure/status': 'Get Zendure device status',
            'GET /api/zendure/p1': 'Get P1 meter status',
            'POST /api/zendure/power': 'Set charge/discharge power (watts parameter)'
        }
    })


@app.route('/api/zendure/status', methods=['GET'])
def get_zendure_status():
    """
    Get current status from Zendure SolarFlow device.
    
    Returns:
        JSON response with device properties and pack data
    """
    try:
        data = zendure_reader.get_status()
        
        if data is False:
            return jsonify({
                'success': False,
                'error': 'Failed to fetch data from Zendure device'
            }), 500
        
        return jsonify({
            'success': True,
            'data': data,
            'timestamp': datetime.now().isoformat()
        })
    
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500


@app.route('/api/zendure/p1', methods=['GET'])
def get_p1_status():
    """
    Get current status from Zendure P1 meter.
    
    Returns:
        JSON response with P1 meter data (total_power, phases, etc.)
    """
    try:
        data = p1_reader.get_status()
        
        if data is False:
            return jsonify({
                'success': False,
                'error': 'Failed to fetch data from P1 meter'
            }), 500
        
        return jsonify({
            'success': True,
            'data': data,
            'timestamp': datetime.now().isoformat()
        })
    
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500


@app.route('/api/zendure/power', methods=['POST'])
def set_power():
    """
    Set charge/discharge power for Zendure device.
    
    Request body (JSON):
        {
            "watts": 400  // Positive for charge, negative for discharge, 0 to stop
        }
    
    Or query parameter:
        ?watts=400
    
    Returns:
        JSON response with success status
    """
    try:
        # Get watts from JSON body or query parameter
        if request.is_json:
            watts = request.json.get('watts')
        else:
            watts = request.args.get('watts', type=int)
        
        if watts is None:
            return jsonify({
                'success': False,
                'error': 'Missing "watts" parameter'
            }), 400
        
        # Set power using the reader
        result = zendure_reader.set_power(watts)
        
        if result is None:
            return jsonify({
                'success': False,
                'error': 'Failed to set power on device'
            }), 500
        
        return jsonify({
            'success': True,
            'watts': watts,
            'response': result,
            'timestamp': datetime.now().isoformat()
        })
    
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500


@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'timestamp': datetime.now().isoformat()
    })


if __name__ == '__main__':
    # Run the Flask app
    # In production, use a WSGI server like gunicorn
    app.run(
        host='0.0.0.0',  # Listen on all interfaces
        port=5000,
        debug=True  # Set to False in production
    )

