#!/usr/bin/env python3
"""
Simple test script for Zendure API
Tests all endpoints to verify the API is working correctly.
"""

import requests
import json
import sys

# Default API URL (change if running on different host/port)
API_BASE_URL = "http://localhost:5000"

def test_endpoint(method, endpoint, data=None, params=None):
    """Test an API endpoint."""
    url = f"{API_BASE_URL}{endpoint}"
    print(f"\n{'='*60}")
    print(f"Testing: {method} {endpoint}")
    print(f"{'='*60}")
    
    try:
        if method == 'GET':
            response = requests.get(url, params=params, timeout=10)
        elif method == 'POST':
            response = requests.post(url, json=data, params=params, timeout=10)
        else:
            print(f"Unsupported method: {method}")
            return False
        
        print(f"Status Code: {response.status_code}")
        print(f"Response:")
        try:
            response_json = response.json()
            print(json.dumps(response_json, indent=2))
            return response.status_code == 200 and response_json.get('success', True)
        except:
            print(response.text)
            return False
            
    except requests.exceptions.ConnectionError:
        print(f"❌ Error: Could not connect to {API_BASE_URL}")
        print("   Make sure the Flask app is running!")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False


def main():
    """Run all API tests."""
    print("Zendure API Test Suite")
    print("=" * 60)
    
    results = []
    
    # Test root endpoint
    results.append(("GET /", test_endpoint('GET', '/')))
    
    # Test health check
    results.append(("GET /api/health", test_endpoint('GET', '/api/health')))
    
    # Test Zendure status
    results.append(("GET /api/zendure/status", test_endpoint('GET', '/api/zendure/status')))
    
    # Test P1 meter status
    results.append(("GET /api/zendure/p1", test_endpoint('GET', '/api/zendure/p1')))
    
    # Test power control (optional - comment out if you don't want to test this)
    # results.append(("POST /api/zendure/power", test_endpoint('POST', '/api/zendure/power', data={'watts': 0})))
    
    # Summary
    print(f"\n{'='*60}")
    print("Test Summary")
    print(f"{'='*60}")
    passed = sum(1 for _, result in results if result)
    total = len(results)
    
    for name, result in results:
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{status}: {name}")
    
    print(f"\nTotal: {passed}/{total} tests passed")
    
    if passed == total:
        print("🎉 All tests passed!")
        return 0
    else:
        print("⚠️  Some tests failed")
        return 1


if __name__ == '__main__':
    # Allow custom API URL via command line
    if len(sys.argv) > 1:
        API_BASE_URL = sys.argv[1]
        print(f"Using custom API URL: {API_BASE_URL}")
    
    sys.exit(main())

