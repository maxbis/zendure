/**
 * Schedule Panel Component
 * Manages the schedule display and entries table
 */
class SchedulePanelComponent extends Component {
    constructor(container, options = {}) {
        super(container, options);
        this.data = {
            entries: [],
            resolved: [],
            currentTime: null,
            currentHour: null
        };
    }
    
    init() {
        this.mount();
    }
    
    setupEventListeners() {
        // Listen for refresh button clicks
        const refreshBtn = this.$('#refresh-schedule-btn');
        if (refreshBtn) {
            this.on(refreshBtn, 'click', () => this.refresh());
        }
    }
    
    subscribeToState() {
        if (!this.stateManager) return;
        
        // Subscribe to schedule data changes
        this.subscribeToStateKey('schedule', (newState, prevState) => {
            if (newState.schedule !== prevState.schedule) {
                this.update(newState.schedule);
            }
        });
    }
    
    /**
     * Update component with new schedule data
     * @param {Object} scheduleData - Schedule data object
     */
    update(scheduleData) {
        if (!scheduleData) return;
        
        this.data = {
            entries: scheduleData.entries || [],
            resolved: scheduleData.resolved || [],
            currentTime: scheduleData.currentTime || scheduleData.currentHour || this._getCurrentTime(),
            currentHour: scheduleData.currentHour || this._getCurrentHour()
        };
        
        this.render();
    }
    
    render() {
        if (!this.rendered) {
            this._renderInitial();
        }
        
        this._renderToday();
        this._renderEntries();
    }
    
    _renderInitial() {
        // Initial render setup if needed
        this.rendered = true;
    }
    
    _renderToday() {
        const container = this.$('#today-schedule-grid');
        if (!container) return;
        
        container.innerHTML = '';
        
        const { resolved, currentTime } = this.data;
        if (!resolved || resolved.length === 0) {
            container.innerHTML = '<div class="empty-state">No schedule data available</div>';
            return;
        }
        
        let prevVal = null;
        const displayedSlots = [];
        
        // First pass: collect displayed slots
        resolved.forEach(slot => {
            const val = slot.value;
            if (prevVal !== null && val === prevVal) {
                return;
            }
            prevVal = val;
            displayedSlots.push(slot);
        });
        
        // Find current active entry
        let currentActiveTime = null;
        displayedSlots.forEach(slot => {
            const time = String(slot.time);
            if (time <= currentTime) {
                if (currentActiveTime === null || time > currentActiveTime) {
                    currentActiveTime = time;
                }
            }
        });
        
        // Render slots
        displayedSlots.forEach(slot => {
            const time = String(slot.time);
            const h = parseInt(time.substring(0, 2));
            const isCurrent = (time === currentActiveTime);
            
            const bgClass = getTimeClass(h);
            const valDisplay = getValueLabel(slot.value);
            const valClass = getValueClass(slot.value);
            
            const div = document.createElement('div');
            div.className = `schedule-item ${bgClass} ${isCurrent ? 'slot-current' : ''}`;
            div.innerHTML = `
                <div class="schedule-item-time">${formatTime(time)}</div>
                <div class="schedule-item-value ${valClass}">${valDisplay}</div>
                ${slot.key ? `<div class="schedule-item-key">${slot.key}</div>` : ''}
            `;
            container.appendChild(div);
        });
    }
    
    _renderEntries() {
        const tbody = this.$('#schedule-table tbody');
        if (!tbody) return;
        
        tbody.innerHTML = '';
        
        const { entries } = this.data;
        if (!entries || entries.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="empty-state">No schedule entries</td></tr>';
            return;
        }
        
        // Sort entries
        const sortedEntries = [...entries].sort((a, b) => 
            String(a.key).localeCompare(String(b.key))
        );
        
        sortedEntries.forEach((entry, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.key = entry.key;
            tr.dataset.value = entry.value;
            
            const keyStr = String(entry.key);
            const isWild = keyStr.includes('*');
            const displayVal = getValueLabel(entry.value);
            const valClass = getValueClass(entry.value);
            
            tr.innerHTML = `
                <td style="color:#888;">${idx + 1}</td>
                <td style="font-family:monospace;">${keyStr}</td>
                <td class="${valClass}" style="font-weight:500;">${displayVal}</td>
                <td><span class="badge ${isWild ? 'badge-wildcard' : 'badge-exact'}">${isWild ? 'Wildcard' : 'Exact'}</span></td>
            `;
            tbody.appendChild(tr);
        });
        
        // Update status bar
        const statusBar = this.$('#status-bar');
        if (statusBar) {
            statusBar.innerHTML = `<span>${entries.length} entries loaded.</span>`;
        }
    }
    
    /**
     * Refresh schedule data
     */
    async refresh() {
        if (!this.apiClient) {
            console.warn('SchedulePanelComponent: No API client available');
            return;
        }
        
        this.showLoading('Loading schedule...');
        
        try {
            const today = formatDateYYYYMMDD(new Date());
            const data = await this.apiClient.get('', { date: today });
            
            if (data.success) {
                this.update(data);
                if (window.notifications) {
                    window.notifications.success('Schedule refreshed');
                }
            } else {
                throw new Error(data.error || 'Failed to load schedule');
            }
        } catch (error) {
            console.error('SchedulePanelComponent: Refresh error:', error);
            this.showError('Failed to load schedule: ' + error.message);
            if (window.notifications) {
                window.notifications.error('Failed to refresh schedule');
            }
        } finally {
            this.hideLoading();
        }
    }
    
    _getCurrentTime() {
        const now = new Date();
        return String(now.getHours()).padStart(2, '0') + 
               String(now.getMinutes()).padStart(2, '0');
    }
    
    _getCurrentHour() {
        const now = new Date();
        return String(now.getHours()).padStart(2, '0') + '00';
    }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SchedulePanelComponent;
}
