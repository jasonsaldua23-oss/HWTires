    </div>
    <!-- End Main Content Area -->
</div>
<!-- End Main Content Wrapper -->

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo esc_url(APP_URL . '/assets/js/search-suggestions.js'); ?>"></script>
<script src="<?php echo esc_url(APP_URL . '/assets/js/vehicle-make-model.js'); ?>"></script>

<script>
    // Toggle sidebar on mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('open');
    }

    // Show sidebar toggle on mobile
    function checkScreenSize() {
        const toggle = document.getElementById('sidebarToggle');
        if (window.innerWidth <= 768) {
            toggle.style.display = 'block';
        } else {
            toggle.style.display = 'none';
            document.getElementById('sidebar').classList.remove('open');
        }
    }

    // Check on load
    window.addEventListener('load', checkScreenSize);
    window.addEventListener('resize', checkScreenSize);

    function syncSidebarScrollPosition() {
        const sidebarNav = document.querySelector('.sidebar-nav');
        if (!sidebarNav) {
            return;
        }

        const storageKey = 'hwtires.sidebar.scrollTop';
        const activeItem = sidebarNav.querySelector('.sidebar-menu-item.active');
        const savedScrollTop = Number.parseInt(sessionStorage.getItem(storageKey) || '0', 10);

        if (!Number.isNaN(savedScrollTop) && savedScrollTop > 0) {
            sidebarNav.scrollTop = savedScrollTop;
        }

        requestAnimationFrame(function() {
            if (!activeItem) {
                return;
            }

            const navRect = sidebarNav.getBoundingClientRect();
            const activeRect = activeItem.getBoundingClientRect();

            if (activeRect.top < navRect.top || activeRect.bottom > navRect.bottom) {
                activeItem.scrollIntoView({ block: 'nearest' });
            }
        });

        sidebarNav.addEventListener('scroll', function() {
            sessionStorage.setItem(storageKey, String(sidebarNav.scrollTop));
        }, { passive: true });

        sidebarNav.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                sessionStorage.setItem(storageKey, String(sidebarNav.scrollTop));
            });
        });
    }

    window.addEventListener('load', syncSidebarScrollPosition);

    function syncNotificationState() {
        const rows = Array.from(document.querySelectorAll('.notification-row'));
        const badge = document.querySelector('[data-notification-badge]');
        const activeCount = document.querySelector('[data-notification-active-count]');
        const emptyState = document.querySelector('.notification-empty');
        const storagePrefix = 'hwtires.notifications.';
        const notificationEndpoint = <?php echo json_encode(APP_URL . '/api/notifications-api.php'); ?>;
        let visibleCount = 0;
        let visibleRows = 0;

        function persistNotificationState(row, action, useBeacon) {
            const dbIds = String(row.dataset.notificationDbIds || '').trim();
            if (!dbIds) {
                return;
            }

            const body = new FormData();
            body.append('action', action);
            body.append('ids', dbIds);

            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(notificationEndpoint, body);
                return;
            }

            fetch(notificationEndpoint, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                keepalive: true,
            }).catch(function() {});
        }

        function getNotificationMeta(row) {
            const id = row.dataset.notificationId || '';
            const count = Number.parseInt(row.dataset.notificationCount || '1', 10) || 1;

            return { id, count };
        }

        function renderNotificationReadState(row, read) {
            row.classList.toggle('notification-read', read);
            row.classList.toggle('notification-unread', !read);

            const toggleButton = row.querySelector('.notification-read-toggle');
            if (toggleButton) {
                toggleButton.textContent = read ? 'Mark as unread' : 'Mark as read';
                toggleButton.setAttribute(
                    'aria-label',
                    read ? 'Mark notification as unread' : 'Mark notification as read'
                );
            }
        }

        function setNotificationRead(row, read, options) {
            const meta = getNotificationMeta(row);
            const shouldPersist = options && options.persist;
            const useBeacon = options && options.useBeacon;
            const skipSync = options && options.skipSync;

            if (meta.id) {
                if (read) {
                    localStorage.setItem(storagePrefix + 'read.' + meta.id, String(meta.count));
                } else {
                    localStorage.removeItem(storagePrefix + 'read.' + meta.id);
                }
            }

            renderNotificationReadState(row, read);

            if (shouldPersist) {
                persistNotificationState(row, read ? 'mark_read' : 'mark_unread', useBeacon);
            }

            if (!skipSync) {
                syncNotificationState();
            }
        }

        rows.forEach(function(row) {
            const meta = getNotificationMeta(row);
            const dismissed = meta.id && localStorage.getItem(storagePrefix + 'dismissed.' + meta.id) === String(meta.count);
            const read = meta.id && localStorage.getItem(storagePrefix + 'read.' + meta.id) === String(meta.count);

            if (dismissed) {
                row.hidden = true;
                return;
            }

            row.hidden = false;
            visibleRows++;
            if (!read) {
                visibleCount += meta.count;
            }

            renderNotificationReadState(row, read);

            const link = row.querySelector('.notification-item');
            if (link && !link.dataset.notificationBound) {
                link.dataset.notificationBound = '1';
                link.addEventListener('click', function() {
                    setNotificationRead(row, true, { persist: true, useBeacon: true });
                });
            }

            const dismissButton = row.querySelector('.notification-dismiss');
            if (dismissButton && !dismissButton.dataset.notificationBound) {
                dismissButton.dataset.notificationBound = '1';
                dismissButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (meta.id) {
                        localStorage.setItem(storagePrefix + 'dismissed.' + meta.id, String(meta.count));
                        localStorage.setItem(storagePrefix + 'read.' + meta.id, String(meta.count));
                    }
                    persistNotificationState(row, 'mark_read', true);
                    row.hidden = true;
                    syncNotificationState();
                });
            }

            const readToggle = row.querySelector('.notification-read-toggle');
            if (readToggle && !readToggle.dataset.notificationBound) {
                readToggle.dataset.notificationBound = '1';
                readToggle.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    setNotificationRead(row, !row.classList.contains('notification-read'), { persist: true });
                });
            }
        });

        if (badge) {
            if (visibleCount > 0) {
                badge.hidden = false;
                badge.textContent = visibleCount > 99 ? '99+' : String(visibleCount);
            } else {
                badge.hidden = true;
            }
        }

        if (activeCount) {
            activeCount.textContent = visibleCount + ' unread';
        }

        if (emptyState) {
            emptyState.hidden = visibleRows > 0;
        }

        const dropdownToggle = document.getElementById('notificationDropdown');
        if (dropdownToggle && !dropdownToggle.dataset.notificationReadBound) {
            dropdownToggle.dataset.notificationReadBound = '1';
            dropdownToggle.addEventListener('shown.bs.dropdown', function() {
                rows.forEach(function(row) {
                    if (row.hidden) {
                        return;
                    }

                    setNotificationRead(row, true, {
                        persist: true,
                        useBeacon: true,
                        skipSync: true,
                    });
                });

                syncNotificationState();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', syncNotificationState);

    // Close sidebar when clicking outside
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('sidebarToggle');
        if (window.innerWidth <= 768 &&
            !sidebar.contains(event.target) &&
            !toggle.contains(event.target)) {
            sidebar.classList.remove('open');
        }
    });

    // Format current date
    document.addEventListener('DOMContentLoaded', function() {
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        const today = new Date().toLocaleDateString('en-US', options);
        const dateEl = document.getElementById('current-date');
        if (dateEl) {
            dateEl.textContent = today;
        }
    });

    // Flash message auto-dismiss
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (alert.classList.contains('alert-success') || alert.classList.contains('alert-info')) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 4000);
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        function normalizePlateNumber(value) {
            const raw = String(value || '').toUpperCase();
            let letters = '';
            let digits = '';

            for (const char of raw) {
                if (letters.length < 3) {
                    if (/[A-Z]/.test(char)) {
                        letters += char;
                    }
                    continue;
                }

                if (digits.length < 4 && /[0-9]/.test(char)) {
                    digits += char;
                }
            }

            return digits ? letters + '-' + digits : letters;
        }

        document.querySelectorAll('[data-plate-input]').forEach(function(input) {
            function syncPlateInput() {
                input.value = normalizePlateNumber(input.value);
            }

            syncPlateInput();
            input.addEventListener('input', syncPlateInput);
            input.addEventListener('blur', syncPlateInput);
        });
    });

    // Session Inactivity Timeout Handler (1 Hour limit: 59 min idle + 60s countdown modal)
    (function() {
        <?php if (is_logged_in()): ?>
        // =========================================================================
        // TIMEOUT SETTINGS: 1 Hour Total (59 minutes idle + 60s modal countdown)
        // =========================================================================
        const INACTIVITY_TIMEOUT_MS = 59 * 60 * 1000; // 59 minutes before warning modal appears
        const COUNTDOWN_SECONDS = 60;                 // 60 seconds countdown on warning modal
        const LOGOUT_URL = <?php echo json_encode(APP_URL . '/logout.php?timeout=1'); ?>;
        const PING_URL = <?php echo json_encode(APP_URL . '/api/notifications-api.php?action=ping'); ?>;

        let warningTimer = null;
        let countdownTimer = null;
        let remainingSeconds = COUNTDOWN_SECONDS;
        let modalInstance = null;

        const modalEl = document.getElementById('sessionTimeoutModal');
        const countdownEl = document.getElementById('sessionTimeoutCountdown');
        const stayBtn = document.getElementById('sessionStayLoggedInBtn');

        function startWarningTimer() {
            clearTimeout(warningTimer);
            clearInterval(countdownTimer);
            warningTimer = setTimeout(showTimeoutWarning, INACTIVITY_TIMEOUT_MS);
        }

        function showTimeoutWarning() {
            remainingSeconds = COUNTDOWN_SECONDS;
            if (countdownEl) countdownEl.textContent = remainingSeconds + 's';
            if (modalEl && typeof bootstrap !== 'undefined') {
                if (!modalInstance) {
                    modalInstance = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });
                }
                modalInstance.show();
            }

            countdownTimer = setInterval(function() {
                remainingSeconds--;
                if (countdownEl) countdownEl.textContent = remainingSeconds + 's';
                if (remainingSeconds <= 0) {
                    clearInterval(countdownTimer);
                    window.location.href = LOGOUT_URL;
                }
            }, 1000);
        }

        function resetInactivity() {
            if (modalEl && modalEl.classList.contains('show')) {
                // If warning is already showing, wait for user to explicitly click Stay Logged In
                return;
            }
            startWarningTimer();
        }

        if (stayBtn) {
            stayBtn.addEventListener('click', function() {
                clearInterval(countdownTimer);
                if (modalInstance) {
                    modalInstance.hide();
                }
                startWarningTimer();
                // Send heartbeat ping
                fetch(PING_URL).catch(function() {});
            });
        }

        ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'].forEach(function(evt) {
            window.addEventListener(evt, resetInactivity, { passive: true });
        });

        // Ensure timer restarts on page show (even when restoring from browser cache / back button)
        window.addEventListener('pageshow', function() {
            if (modalInstance) {
                try { modalInstance.hide(); } catch(e) {}
            }
            startWarningTimer();
        });

        startWarningTimer();
        <?php endif; ?>
    })();
</script>

<?php if (is_logged_in()): ?>
<!-- Session Timeout Warning Modal -->
<div class="modal fade" id="sessionTimeoutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content" style="border: 0; border-radius: 14px; box-shadow: 0 24px 60px rgba(0,0,0,0.3);">
            <div class="modal-header" style="background: #fff8eb; border-bottom: 1px solid #fed7aa; border-radius: 14px 14px 0 0; padding: 14px 20px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%; background: #fef3c7; color: #d97706; font-size: 16px;">
                        <i class="fas fa-clock"></i>
                    </span>
                    <h5 class="modal-title" style="color: #92400e; font-weight: 750; font-size: 1.05rem; margin: 0;">Session Timeout Warning</h5>
                </div>
            </div>
            <div class="modal-body" style="padding: 20px 24px; text-align: center;">
                <p style="color: #334155; font-size: 0.95rem; margin-bottom: 8px;">
                    You have been inactive for a while. For your security, your session will expire in:
                </p>
                <div style="font-size: 2.2rem; font-weight: 800; color: #dc2626; margin: 10px 0 12px; font-variant-numeric: tabular-nums;" id="sessionTimeoutCountdown">
                    60s
                </div>
                <p style="color: #64748b; font-size: 0.85rem; margin: 0;">
                    Click "Stay Logged In" to continue working.
                </p>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #f1f5f9; padding: 12px 20px; display: flex; justify-content: space-between; gap: 10px;">
                <a href="<?php echo esc_url(APP_URL . '/logout.php?timeout=1'); ?>" class="btn btn-outline-secondary btn-sm" style="padding: 6px 14px; font-size: 0.875rem;">Log Out</a>
                <button type="button" class="btn btn-primary btn-sm" id="sessionStayLoggedInBtn" style="padding: 6px 18px; font-size: 0.875rem;">Stay Logged In</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
