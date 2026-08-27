    </div>
    <!-- End Main Content Area -->
</div>
<!-- End Main Content Wrapper -->

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo esc_url(APP_URL . '/assets/js/search-suggestions.js'); ?>"></script>

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
</script>

</body>
</html>
