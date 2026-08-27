// js/update-status.js - DEBUG VERSION
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ Status update script loaded');

    // Check if we're on the appointments page
    if (!document.querySelector('.status-dropdown')) {
        console.log('ℹ️ No status dropdowns found on this page');
        return;
    }

    // Add event listeners to all status dropdowns
    const statusDropdowns = document.querySelectorAll('.status-dropdown');
    console.log(`✅ Found ${statusDropdowns.length} status dropdowns`);

    statusDropdowns.forEach(dropdown => {
        // Store original value
        dropdown.dataset.originalValue = dropdown.value;

        dropdown.addEventListener('change', function() {
            console.log('🔄 Dropdown changed:', {
                appointmentId: this.dataset.appointmentId,
                newStatus: this.value,
                oldStatus: this.dataset.originalValue
            });

            const appointmentId = this.dataset.appointmentId;
            const newStatus = this.value;

            if (!appointmentId) {
                console.error('❌ No appointment ID found');
                showAlert('Error: No appointment ID', 'error');
                this.value = this.dataset.originalValue;
                return;
            }

            updateAppointmentStatus(appointmentId, newStatus, this);
        });
    });

    function updateAppointmentStatus(appointmentId, newStatus, dropdownElement) {
        console.log(`🔄 Updating appointment #${appointmentId} to "${newStatus}"`);

        // Show loading state
        const originalValue = dropdownElement.dataset.originalValue;
        dropdownElement.disabled = true;
        dropdownElement.style.opacity = '0.7';

        // Test if file exists first
        console.log(`🌐 Testing connection to update-appointment-status.php`);

        // Send AJAX request with timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout

        fetch('update-appointment-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `appointment_id=${appointmentId}&status=${newStatus}`,
            signal: controller.signal
        })
        .then(response => {
            clearTimeout(timeoutId);
            console.log('✅ Server responded:', response.status, response.statusText);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return response.json();
        })
        .then(data => {
            console.log('📊 Response data:', data);

            if (data.success) {
                // Update stored value
                dropdownElement.dataset.originalValue = newStatus;

                // Show success message
                showAlert('✅ Status updated successfully!', 'success');

                // Update UI
                updateUIAfterStatusChange(dropdownElement, newStatus);

                // Update calendar if function exists
                if (typeof updateCalendarStatus === 'function') {
                    updateCalendarStatus(appointmentId, newStatus);
                }
            } else {
                // Show error from server
                console.error('❌ Server error:', data.error);
                showAlert(`❌ ${data.error || 'Failed to update status'}`, 'error');
                // Revert to original value
                dropdownElement.value = originalValue;
            }
        })
        .catch(error => {
            clearTimeout(timeoutId);
            console.error('❌ Fetch error details:', {
                name: error.name,
                message: error.message,
                stack: error.stack
            });

            let errorMsg = 'Network error. ';

            if (error.name === 'AbortError') {
                errorMsg += 'Request timed out (10 seconds).';
            } else if (error.message.includes('HTTP error')) {
                errorMsg += `Server returned: ${error.message}`;
            } else if (error.name === 'TypeError' && error.message.includes('fetch')) {
                errorMsg += 'Cannot connect to server. Check if file exists.';
            } else {
                errorMsg += error.message;
            }

            showAlert(`❌ ${errorMsg}`, 'error');
            dropdownElement.value = originalValue;
        })
        .finally(() => {
            // Restore dropdown
            dropdownElement.disabled = false;
            dropdownElement.style.opacity = '1';
        });
    }

    function updateUIAfterStatusChange(dropdownElement, newStatus) {
        // Update the row's appearance if needed
        const row = dropdownElement.closest('tr');

        // You can add visual feedback here
        row.style.transition = 'background-color 0.3s';
        row.style.backgroundColor = '#d4edda'; // Light green

        setTimeout(() => {
            row.style.backgroundColor = '';
        }, 1000);
    }

    function showAlert(message, type) {
        console.log(`📢 Alert (${type}):`, message);

        // Remove existing alerts
        document.querySelectorAll('.status-update-alert').forEach(alert => {
            alert.remove();
        });

        // Create alert element
        const alertDiv = document.createElement('div');
        alertDiv.className = `status-update-alert alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // Insert at the top of main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            const firstChild = mainContent.firstChild;
            if (firstChild) {
                mainContent.insertBefore(alertDiv, firstChild);
            } else {
                mainContent.appendChild(alertDiv);
            }
        }

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.style.opacity = '0';
                setTimeout(() => alertDiv.remove(), 300);
            }
        }, 5000);
    }

    // Test connection on page load
    console.log('🧪 Testing if update-appointment-status.php is accessible...');
    fetch('update-appointment-status.php')
        .then(response => {
            console.log('✅ File exists, status:', response.status);
        })
        .catch(error => {
            console.error('❌ Cannot access update-appointment-status.php:', error);
            showAlert('⚠️ Status update feature may not work. File not accessible.', 'warning');
        });
});
