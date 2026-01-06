(function() {
    const buttons = document.querySelectorAll('.power-control-button');
    const countdownDisplay = document.getElementById('countdown-display');
    const slider = document.getElementById('power-control-slider');
    const sliderValueDisplay = document.getElementById('power-control-slider-value');
    const countdownDisplaySlider = document.getElementById('countdown-display-slider');
    const zendureConfig = window.zendureConfig || {};
    let countdownInterval = null;
    let countdownSeconds = 0;
    let sliderCountdownInterval = null;
    let sliderCountdownSeconds = 0;

    function disableButtons() {
        buttons.forEach(btn => btn.disabled = true);
        if (slider) {
            slider.disabled = true;
        }
    }

    function enableButtons() {
        buttons.forEach(btn => btn.disabled = false);
        if (slider) {
            slider.disabled = false;
        }
    }

    function clearCountdown() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
        countdownDisplay.textContent = '';
        countdownDisplay.classList.remove('active');
    }

    function clearSliderCountdown() {
        if (sliderCountdownInterval) {
            clearInterval(sliderCountdownInterval);
            sliderCountdownInterval = null;
        }
        if (countdownDisplaySlider) {
            countdownDisplaySlider.textContent = '';
            countdownDisplaySlider.classList.remove('active');
        }
    }

    function startCountdown() {
        countdownSeconds = 18;
        countdownDisplay.classList.add('active');
        updateCountdown();

        countdownInterval = setInterval(() => {
            countdownSeconds--;
            if (countdownSeconds <= 0) {
                clearCountdown();
                // Reload page to refresh data
                window.location.reload();
            } else {
                updateCountdown();
            }
        }, 1000);
    }

    function startSliderCountdown() {
        sliderCountdownSeconds = 18;
        if (countdownDisplaySlider) {
            countdownDisplaySlider.classList.add('active');
            updateSliderCountdown();
        }

        sliderCountdownInterval = setInterval(() => {
            sliderCountdownSeconds--;
            if (sliderCountdownSeconds <= 0) {
                clearSliderCountdown();
                // Reload page to refresh data
                window.location.reload();
            } else {
                updateSliderCountdown();
            }
        }, 1000);
    }

    function updateCountdown() {
        countdownDisplay.textContent = `Updating data in ${countdownSeconds} seconds...`;
    }

    function updateSliderCountdown() {
        if (countdownDisplaySlider) {
            countdownDisplaySlider.textContent = `Updating data in ${sliderCountdownSeconds} seconds...`;
        }
    }

    function updateSliderValueDisplay(value) {
        if (sliderValueDisplay) {
            const watts = parseInt(value);
            sliderValueDisplay.textContent = watts + 'W';
            // Update color based on value
            if (watts > 0) {
                sliderValueDisplay.style.color = '#66bb6a'; // Green for charge
            } else if (watts < 0) {
                sliderValueDisplay.style.color = '#ef5350'; // Red for discharge
            } else {
                sliderValueDisplay.style.color = '#9e9e9e'; // Gray for zero
            }
        }
    }

    function sendPowerCommand(watts, displayElement, countdownFunction, clearCountdownFunction) {
        // Disable all controls
        disableButtons();
        clearCountdownFunction();
        if (displayElement) {
            displayElement.textContent = 'Sending command...';
        }

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
                    countdownFunction();
                } else {
                    console.error('❌ Failed:', response.error || 'Unknown error');
                    if (displayElement) {
                        displayElement.textContent = 'Error: ' + (response.error || 'Failed to send command');
                        displayElement.style.color = '#ef5350';
                    }
                    enableButtons();
                    
                    // Clear error message after 3 seconds
                    setTimeout(() => {
                        if (displayElement) {
                            displayElement.textContent = '';
                            displayElement.style.color = '';
                        }
                    }, 3000);
                }
            } catch (e) {
                console.error('❌ Error parsing response:', e);
                console.error('Response text:', xhr.responseText);
                if (displayElement) {
                    displayElement.textContent = 'Error: Invalid response from server';
                    displayElement.style.color = '#ef5350';
                }
                enableButtons();
                
                setTimeout(() => {
                    if (displayElement) {
                        displayElement.textContent = '';
                        displayElement.style.color = '';
                    }
                }, 3000);
            }
        };
        
        xhr.onerror = function() {
            console.error('❌ Network error: Failed to connect to server');
            if (displayElement) {
                displayElement.textContent = 'Error: Network error';
                displayElement.style.color = '#ef5350';
            }
            enableButtons();
            
            setTimeout(() => {
                if (displayElement) {
                    displayElement.textContent = '';
                    displayElement.style.color = '';
                }
            }, 3000);
        };
        
        xhr.send();
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
            sendPowerCommand(watts, countdownDisplay, startCountdown, clearCountdown);
        });
    });

    // Slider functionality
    if (slider && sliderValueDisplay) {
        // Update display on input (real-time)
        slider.addEventListener('input', function() {
            const value = parseInt(this.value);
            updateSliderValueDisplay(value);
        });

        // Send command on change (when user releases slider)
        slider.addEventListener('change', function() {
            const watts = parseInt(this.value);
            sendPowerCommand(watts, countdownDisplaySlider, startSliderCountdown, clearSliderCountdown);
        });

        // Initialize display
        updateSliderValueDisplay(slider.value);
    }
})();

