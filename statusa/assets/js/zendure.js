(function() {
    const buttons = document.querySelectorAll('.power-control-button');
    const countdownDisplay = document.getElementById('countdown-display');
    const zendureConfig = window.zendureConfig || {};
    let countdownInterval = null;
    let countdownSeconds = 0;

    function disableButtons() {
        buttons.forEach(btn => btn.disabled = true);
    }

    function enableButtons() {
        buttons.forEach(btn => btn.disabled = false);
    }

    function clearCountdown() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
        countdownDisplay.textContent = '';
        countdownDisplay.classList.remove('active');
    }

    function startCountdown() {
        countdownSeconds = 8;
        countdownDisplay.classList.add('active');
        updateCountdown();

        countdownInterval = setInterval(() => {
            countdownSeconds--;
            if (countdownSeconds <= 0) {
                clearCountdown();
                // Trigger update data
                window.location.href = '?update=1';
            } else {
                updateCountdown();
            }
        }, 1000);
    }

    function updateCountdown() {
        countdownDisplay.textContent = `Updating data in ${countdownSeconds} seconds...`;
    }

    // Automation entries toggle functionality (global function for onclick)
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

    function formatTimeForWatts(watts) {
        if (!zendureConfig || zendureConfig.totalCapacityKwh === undefined) {
            return '';
        }

        const totalCapacityKwh = Number(zendureConfig.totalCapacityKwh);
        const batteryLevelPercent = Number(zendureConfig.batteryLevelPercent);
        const socSetPercent = Number(zendureConfig.socSetPercent);
        const minSocPercent = Number(zendureConfig.minSocPercent);
        const batteryRemainingAboveMinKwh = Number(zendureConfig.batteryRemainingAboveMinKwh);

        const power = Number(watts);
        const powerAbs = Math.abs(power);

        if (!powerAbs || !totalCapacityKwh || isNaN(batteryLevelPercent)) {
            return '';
        }

        let energyKwh = null;
        let mode = null;

        if (power > 0) {
            // Charging: time to reach target SOC
            if (batteryLevelPercent >= socSetPercent) return '';
            energyKwh = totalCapacityKwh * Math.max(0, (socSetPercent - batteryLevelPercent) / 100);
            mode = 'charging';
        } else if (power < 0) {
            // Discharging: time to reach minimum SOC
            if (batteryLevelPercent <= minSocPercent || batteryRemainingAboveMinKwh <= 0) return '';
            energyKwh = batteryRemainingAboveMinKwh;
            mode = 'discharging';
        }

        if (!energyKwh) {
            return '';
        }

        const hours = energyKwh * 1000 / powerAbs;
        if (!hours || !isFinite(hours)) return '';

        const totalMinutes = Math.round(hours * 60);
        const h = Math.floor(totalMinutes / 60);
        const m = totalMinutes % 60;

        const timeStr = `${h}:${m.toString().padStart(2, '0')} h`;
        const absPower = Math.abs(power);

        if (mode === 'charging') {
            return `Time to reach target level at +${absPower}W: ${timeStr}`;
        } else if (mode === 'discharging') {
            return `Time until min level at -${absPower}W: ${timeStr}`;
        }

        return '';
    }

    buttons.forEach(button => {
        // Hover tooltip with projected time at this wattage
        button.addEventListener('mouseenter', function() {
            const watts = parseInt(this.getAttribute('data-watts'));
            const tip = formatTimeForWatts(watts);
            if (tip) {
                this.setAttribute('title', tip);
            } else {
                this.removeAttribute('title');
            }
        });

        button.addEventListener('mouseleave', function() {
            // Let browser hide tooltip naturally; no special handling needed
        });

        button.addEventListener('click', function() {
            const watts = parseInt(this.getAttribute('data-watts'));
            
            // Disable all buttons
            disableButtons();
            clearCountdown();
            countdownDisplay.textContent = 'Sending command...';

            // Make AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'api/set_zendure.php?watts=' + watts, true);
            
            xhr.onload = function() {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    // Log to console
                    console.log('Power Control Response:', response);
                    
                    if (response.success) {
                        console.log('✅ Success: Set power to ' + watts + 'W');
                        console.log('Device Response:', response.response);
                        
                        // Start countdown
                        startCountdown();
                    } else {
                        console.error('❌ Failed:', response.error || 'Unknown error');
                        countdownDisplay.textContent = 'Error: ' + (response.error || 'Failed to send command');
                        countdownDisplay.style.color = '#ef5350';
                        enableButtons();
                        
                        // Clear error message after 3 seconds
                        setTimeout(() => {
                            countdownDisplay.textContent = '';
                            countdownDisplay.style.color = '';
                        }, 3000);
                    }
                } catch (e) {
                    console.error('❌ Error parsing response:', e);
                    console.error('Response text:', xhr.responseText);
                    countdownDisplay.textContent = 'Error: Invalid response from server';
                    countdownDisplay.style.color = '#ef5350';
                    enableButtons();
                    
                    setTimeout(() => {
                        countdownDisplay.textContent = '';
                        countdownDisplay.style.color = '';
                    }, 3000);
                }
            };
            
            xhr.onerror = function() {
                console.error('❌ Network error: Failed to connect to server');
                countdownDisplay.textContent = 'Error: Network error';
                countdownDisplay.style.color = '#ef5350';
                enableButtons();
                
                setTimeout(() => {
                    countdownDisplay.textContent = '';
                    countdownDisplay.style.color = '';
                }, 3000);
            };
            
            xhr.send();
        });
    });
})();

