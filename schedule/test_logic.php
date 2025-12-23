<?php
require_once __DIR__ . '/api/charge_schedule_functions.php';

echo "Testing Match Pattern...\n";
// Test specific
$res = matchesAndBeforeTime("202512220800", "202512220900", "0900");
if (!$res)
    echo "FAIL: Specific match failed\n";

// Test wildcard date
$res = matchesAndBeforeTime("********0800", "202512220900", "0900");
if (!$res)
    echo "FAIL: Wildcard date match failed\n";

// Test wildcard time (should always match as fallback)
$res = matchesAndBeforeTime("20251222****", "202512220900", "0900");
if (!$res)
    echo "FAIL: Wildcard time match failed\n";

echo "Testing Resolution...\n";
$schedule = [
    "202512220000" => -498, // Specific date, midnight
    "202512220130" => 112,  // Specific date, 01:30
    "20251222****" => 0,    // Specific date, background
    "********0000" => -200, // Every day midnight
    "********0800" => "netzero", // Every day 8am
];

// Resolve for 20251222
$resolved = resolveScheduleForDate($schedule, "20251222");

// Helper to find slot
function findSlot($resolved, $time)
{
    foreach ($resolved as $s) {
        if ($s['time'] === $time)
            return $s;
    }
    return null;
}

// 1. Check 00:00 - Should match 202512220000 (-498) over ********0000 (-200) because both are specific time "0000" but specific date has higher specificity? 
// Wait, my sort logic: 
// 1. Wildcards last (Neither is wildcard time)
// 2. Most recent time first (Both 0000)
// 3. (Implicit) No date specificity check in `usort`!
// The spec said: "Select the first entry (most recent specific match, or wildcard if no specific)".
// But it didn't explicitly say "More specific patterns override general patterns" for the DATE part.
// However, the user prompt says "manage charge and discharge targets with **wildcard pattern matching**... specific rules override less specific ones." (implied or stated in "Notes"? No, notes say "Algorithm ensures sub-hour...").
// In "Core Algorithm": "Sort by entry time... Select the first entry".
// It does NOT mention specificity of the key itself (e.g. date matching).
// BUT in "Technical Requirements", `calculateSpecificity` is requested.
// And I implemented `calculateSpecificity` in `functions.php` but I did NOT use it in `resolveScheduleForDate`'s `usort`.
// I MUST FIX THIS. The specificity calculation was requested for a reason!

echo "Checking logic gap...\n";

// Check 00:00
$slot00 = findSlot($resolved, '0000');
echo "00:00 Value: " . $slot00['value'] . " (Expected -498)\n";

// Check 01:30
$slot0130 = findSlot($resolved, '0130');
echo "01:30 Value: " . $slot0130['value'] . " (Expected 112)\n";

// Check 02:00 - Should carry over 112 from 01:30?
// "Use the most recent matching entry".
// At 02:00:
// Candidates:
// - 202512220130 (Time 0130 < 0200) -> Recent!
// - 202512220000 (Time 0000 < 0200)
// - ********0000 (Time 0000 < 0200)
// - 20251222**** (Time null)
// Sort: Most recent time first.
// 0130 is > 0000. So 0130 wins.
echo "02:00 Value: " . findSlot($resolved, '0200')['value'] . " (Expected 112)\n";

// Check 08:00 - "netzero"
$slot0800 = findSlot($resolved, '0800');
echo "08:00 Value: " . $slot0800['value'] . " (Expected netzero)\n";

?>