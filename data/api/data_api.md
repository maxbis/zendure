Files that can be read/written
Based on the code in data_functions.php and data_api.php, these files are supported:
1. Price files (type=price)
Pattern: priceYYYYMMDD.json (e.g., price20241222.json)
Read: GET with ?type=price&date=YYYYMMDD
Write: POST with ?type=price&date=YYYYMMDD
Delete: DELETE with ?type=price&date=YYYYMMDD
List: GET with ?type=price&list=true or ?type=list&pattern=price*.json
Auto-archiving: Files older than 4 days are moved to price_archive/
2. Zendure device data (type=zendure)
File: zendure_data.json
Read: GET with ?type=zendure
Write: POST with ?type=zendure
3. Zendure P1 meter data (type=zendure_p1)
File: zendure_p1_data.json
Read: GET with ?type=zendure_p1
Write: POST with ?type=zendure_p1
4. Charge schedule (type=schedule)
File: charge_schedule.json
Read: GET with ?type=schedule (or ?type=schedule&resolved=true for resolved format)
Write: POST with ?type=schedule (supports multiple formats)
Update: PUT with ?type=schedule (single entry)
Delete entry: DELETE with ?type=schedule (single entry)
Cleanup: POST with {"action": "simulate"} or {"action": "delete"}
5. Automation status (type=automation_status)
File: automation_status.json
Read: GET with ?type=automation_status
Write: POST with ?type=automation_status
6. Generic JSON files (type=file)
Pattern: Any .json file in the data directory
Read: GET with ?type=file&name=filename.json
Write: POST with ?type=file&name=filename.json
Restrictions: Must end with .json, filename is sanitized
7. List files (type=list)
List all: GET with ?type=list
List filtered: GET with ?type=list&pattern=price*.json
Returns: Array of filenames matching the pattern
How to discover existing files
You can use the API to list existing files:
# List all JSON filesGET /data/api/data_api.php?type=list# List only price filesGET /data/api/data_api.php?type=list&pattern=price*.json# Or use the price-specific list endpointGET /data/api/data_api.php?type=price&list=true
Currently, there are no JSON files in /data/, so these files are created on first write.
Should I add this to the README, or create a script to list existing files?