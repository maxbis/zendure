// Automation entries toggle functionality (global function for onclick)
(function() {
    window.toggleAutomationEntries = function() {
        const entriesWrapper = document.getElementById('automation-entries-wrapper');
        const entriesList = document.getElementById('automation-entries-list');
        const toggleButton = document.getElementById('automation-toggle-button');
        if (!entriesWrapper || !entriesList || !toggleButton) return;
        
        const toggleText = toggleButton.querySelector('.automation-toggle-text');
        
        if (entriesWrapper.classList.contains('expanded')) {
            entriesWrapper.classList.remove('expanded');
            entriesList.classList.remove('expanded');
            const totalEntries = entriesList.querySelectorAll('.automation-entry').length;
            const remainingCount = totalEntries - 1;
            toggleText.textContent = `Show more (${remainingCount} more)`;
        } else {
            entriesWrapper.classList.add('expanded');
            entriesList.classList.add('expanded');
            toggleText.textContent = 'Show less';
        }
    };
})();

