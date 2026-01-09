/**
 * Automation Status
 * Toggle functionality for automation entries list
 */

// Automation entries toggle functionality
window.toggleAutomationEntries = function () {
    const entriesWrapper = document.getElementById('automation-entries-wrapper');
    const entriesList = document.getElementById('automation-entries-list');
    if (!entriesWrapper || !entriesList) return;

    if (entriesWrapper.classList.contains('expanded')) {
        entriesWrapper.classList.remove('expanded');
        entriesList.classList.remove('expanded');
    } else {
        entriesWrapper.classList.add('expanded');
        entriesList.classList.add('expanded');
    }
};
