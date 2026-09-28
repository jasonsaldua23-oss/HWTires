/**
 * Universal Advance Live Typeahead & Instant Autocomplete Search Engine
 * Automatically attaches to all search inputs across Admin and Front Desk.
 */
(function() {
    'use strict';

    const scriptSrc = document.currentScript && document.currentScript.src ? document.currentScript.src : '';
    const endpoint = scriptSrc.includes('/assets/js/search-suggestions.js')
        ? scriptSrc.replace('/assets/js/search-suggestions.js', '/api/search-suggestions.php')
        : '/hwtires/api/search-suggestions.php';

    const MIN_CHARS = 1;
    const DEBOUNCE_MS = 120;

    function inferContext() {
        const path = window.location.pathname.toLowerCase();
        if (path.includes('/customers')) return 'customers';
        if (path.includes('/vehicles')) return 'vehicles';
        if (path.includes('/quotations')) return 'service_operations';
        if (path.includes('/job-orders')) return 'job_orders';
        if (path.includes('/service-status')) return 'service_status';
        if (path.includes('/forecasting')) return 'forecasting';
        if (path.includes('/transfers')) return 'transfers';
        if (path.includes('/tire-inventory') || path.includes('/inventory')) return 'inventory';
        if (path.includes('/reports')) return 'reports';
        return 'all';
    }

    function getFormValue(form, names) {
        if (!form) return '';
        for (const name of names) {
            const field = form.querySelector(`[name="${name}"]`);
            if (field && field.value !== '') {
                return field.value;
            }
        }
        return '';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function highlightMatch(text, query) {
        if (!text) return '';
        if (!query) return escapeHtml(text);
        const safeText = String(text);
        const q = String(query).trim().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (!q) return escapeHtml(safeText);
        const regex = new RegExp('(' + q + ')', 'gi');
        return safeText.replace(regex, '<strong style="color: #007496; font-weight: 800;">$1</strong>');
    }

    function ensureHost(input) {
        let host = input.parentElement;
        if (!host) return null;
        const style = window.getComputedStyle(host);
        if (style.position === 'static') {
            host.style.position = 'relative';
        }
        host.classList.add('hw-autocomplete-host');
        return host;
    }

    function createPanel() {
        const panel = document.createElement('div');
        panel.className = 'hw-search-suggestions';
        panel.hidden = true;
        panel.style.cssText = `
            position: fixed;
            z-index: 9999999;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2), 0 4px 12px rgba(0, 0, 0, 0.08);
            max-height: 340px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 8px;
            display: none;
            box-sizing: border-box;
        `;
        document.body.appendChild(panel);
        return panel;
    }

    function isInputVisible(input) {
        if (!input || !input.isConnected) return false;
        if (input.offsetParent === null && window.getComputedStyle(input).position !== 'fixed') {
            return false;
        }
        const rect = input.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    function updatePanelPosition(input, panel) {
        if (!input || !panel) return;
        if (!isInputVisible(input)) {
            closePanel(panel);
            return;
        }
        const rect = input.getBoundingClientRect();
        const panelWidth = Math.min(480, Math.max(380, rect.width));
        let left = rect.right - panelWidth;
        if (left < 10) left = Math.max(10, rect.left);
        const top = rect.bottom + 6;

        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
        panel.style.width = panelWidth + 'px';
    }

    function positionClearButton(input, clearBtn) {
        if (!input || !clearBtn) return;
        const top = input.offsetTop + (input.offsetHeight / 2);
        clearBtn.style.top = top + 'px';
        clearBtn.style.transform = 'translateY(-50%)';
        if (input.id === 'jobQuotationSearch') {
            clearBtn.style.right = '14px';
        } else {
            clearBtn.style.right = '8px';
        }
    }

    function updateClearBtnState(input, clearBtn) {
        if (!input || !clearBtn) return;
        const hasText = String(input.value || '').trim().length > 0;
        clearBtn.style.display = hasText ? 'flex' : 'none';
        if (hasText) {
            positionClearButton(input, clearBtn);
        }
    }

    function ensureClearButton(input, host) {
        let clearBtn = host.querySelector(':scope > .hw-search-clear-btn');
        if (!clearBtn) {
            clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'hw-search-clear-btn';
            clearBtn.setAttribute('aria-label', 'Clear search');
            clearBtn.title = 'Clear search';
            clearBtn.innerHTML = '<i class="fas fa-times"></i>';
            clearBtn.tabIndex = -1;
            host.appendChild(clearBtn);
        }
        positionClearButton(input, clearBtn);
        return clearBtn;
    }

    function bindClearButton(input) {
        if (!input || input.dataset.hwClearBound === '1') {
            return;
        }
        if (input.type === 'hidden' || input.readOnly || input.disabled) {
            return;
        }

        const host = ensureHost(input);
        if (!host) return;

        input.dataset.hwClearBound = '1';
        input.classList.add('has-search-clear');

        const clearBtn = ensureClearButton(input, host);
        updateClearBtnState(input, clearBtn);

        input.addEventListener('input', () => updateClearBtnState(input, clearBtn));
        input.addEventListener('change', () => updateClearBtnState(input, clearBtn));
        input.addEventListener('keyup', () => updateClearBtnState(input, clearBtn));
        input.addEventListener('paste', () => {
            window.setTimeout(() => updateClearBtnState(input, clearBtn), 0);
        });
        input.addEventListener('focus', () => {
            positionClearButton(input, clearBtn);
            updateClearBtnState(input, clearBtn);
        });

        clearBtn.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });

        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const hadValue = String(input.value || '').trim() !== '';
            input.value = '';
            updateClearBtnState(input, clearBtn);
            input.focus();

            // Trigger events so live search / filters update immediately
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('search', { bubbles: true }));

            // If suggestions panel exists, close it
            document.querySelectorAll('.hw-search-suggestions').forEach(closePanel);

            // If this input was previously submitted in a GET form with search in URL, submit to reset
            const formMethod = (input.form && input.form.method ? input.form.method : 'get').toLowerCase();
            const urlParams = new URLSearchParams(window.location.search);
            const searchParamName = input.name || 'search';
            if (hadValue && input.form && formMethod === 'get' && urlParams.has(searchParamName) && urlParams.get(searchParamName) !== '') {
                const hiddenSearch = input.form.querySelector(`input[type="hidden"][name="${searchParamName}"]`);
                if (hiddenSearch) {
                    hiddenSearch.value = '';
                }
                submitSearch(input);
            }
        });
    }

    function closePanel(panel) {
        if (!panel) return;
        panel.style.display = 'none';
        panel.hidden = true;
        panel.innerHTML = '';
        panel.dataset.activeIndex = '-1';
    }

    function setActive(panel, index) {
        const items = Array.from(panel.querySelectorAll('.hw-search-suggestion'));
        if (!items.length) return;

        const safeIndex = Math.max(0, Math.min(index, items.length - 1));
        items.forEach((item, itemIndex) => {
            const isActive = itemIndex === safeIndex;
            item.classList.toggle('active', isActive);
            item.style.background = isActive ? '#ecfdff' : '#ffffff';
            item.style.borderColor = isActive ? '#a5f3fc' : '#f1f5f9';
        });
        panel.dataset.activeIndex = String(safeIndex);

        const activeItem = items[safeIndex];
        if (activeItem) {
            activeItem.scrollIntoView({ block: 'nearest' });
        }
    }

    function submitSearch(input) {
        if (input.form) {
            if (typeof input.form.requestSubmit === 'function') {
                input.form.requestSubmit();
            } else {
                input.form.submit();
            }
        }
    }

    const typeIcons = {
        'customer': 'fa-phone',
        'vehicle': 'fa-car',
        'inventory': 'fa-box',
        'tire': 'fa-circle-dot',
        'job order': 'fa-wrench',
        'quotation': 'fa-file-lines',
        'default': 'fa-tag'
    };

    const typeConfig = {
        'customer': { bg: '#e0f2fe', color: '#0369a1', border: '#bae6fd' },
        'vehicle': { bg: '#dcfce7', color: '#15803d', border: '#bbf7d0' },
        'inventory': { bg: '#ccfbf1', color: '#0f766e', border: '#99f6e4' },
        'tire': { bg: '#ccfbf1', color: '#0f766e', border: '#99f6e4' },
        'job order': { bg: '#ede9fe', color: '#6d28d9', border: '#ddd6fe' },
        'quotation': { bg: '#fae8ff', color: '#a21caf', border: '#f5d0fe' },
        'default': { bg: '#f1f5f9', color: '#475569', border: '#e2e8f0' }
    };

    function renderSuggestions(input, panel, suggestions, query, onSelect) {
        if (!isInputVisible(input) || document.activeElement !== input || input.value.trim() !== String(query).trim()) {
            closePanel(panel);
            return;
        }

        panel.innerHTML = '';
        panel.dataset.activeIndex = '-1';

        if (!Array.isArray(suggestions) || suggestions.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'hw-search-suggestion-empty';
            empty.style.cssText = 'padding: 14px 16px; color: #94a3b8; font-size: 0.88rem; text-align: center; font-style: italic;';
            empty.textContent = 'No matching results found';
            panel.appendChild(empty);
            updatePanelPosition(input, panel);
            panel.hidden = false;
            panel.style.display = 'block';
            return;
        }

        suggestions.forEach((suggestion, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'hw-search-suggestion';
            button.dataset.value = suggestion.value || suggestion.label || '';
            button.dataset.index = String(index);
            button.style.cssText = `
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
                padding: 10px 14px;
                margin-bottom: 4px;
                border: 1px solid #f1f5f9;
                border-radius: 8px;
                background: #ffffff;
                text-align: left;
                cursor: pointer;
                transition: all 0.15s ease;
                font-family: inherit;
                box-sizing: border-box;
            `;

            const rawType = (suggestion.type || 'default').toLowerCase();
            const config = typeConfig[rawType] || typeConfig['default'];
            const iconName = typeIcons[rawType] || typeIcons['default'];

            const leftContent = document.createElement('div');
            leftContent.style.cssText = 'display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1;';

            const label = document.createElement('div');
            label.style.cssText = 'font-size: 0.92rem; font-weight: 700; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.25;';
            label.innerHTML = highlightMatch(suggestion.label || suggestion.value || '', query);
            leftContent.appendChild(label);

            if (suggestion.detail) {
                const detail = document.createElement('div');
                detail.style.cssText = 'font-size: 0.78rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.2; display: flex; align-items: center; gap: 5px;';
                detail.innerHTML = `<i class="fas ${iconName}" style="font-size: 10px; opacity: 0.75;"></i><span>${highlightMatch(suggestion.detail, query)}</span>`;
                leftContent.appendChild(detail);
            }

            const type = document.createElement('span');
            type.style.cssText = `
                padding: 4px 10px;
                font-size: 0.72rem;
                font-weight: 700;
                color: ${config.color};
                background: ${config.bg};
                border: 1px solid ${config.border};
                border-radius: 999px;
                white-space: nowrap;
                margin-left: 10px;
                flex-shrink: 0;
            `;
            type.textContent = suggestion.type || 'Match';

            button.appendChild(leftContent);
            button.appendChild(type);

            button.addEventListener('mouseenter', () => {
                setActive(panel, index);
            });

            button.addEventListener('mousedown', function(event) {
                event.preventDefault();
            });

            button.addEventListener('click', function() {
                if (typeof onSelect === 'function') {
                    onSelect();
                }
                input.value = button.dataset.value;
                const clearBtn = input.parentElement ? input.parentElement.querySelector('.hw-search-clear-btn') : null;
                if (clearBtn) {
                    updateClearBtnState(input, clearBtn);
                }
                closePanel(panel);
                submitSearch(input);
            });

            panel.appendChild(button);
        });

        const footer = document.createElement('div');
        footer.style.cssText = 'padding: 8px 12px 4px; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;';
        footer.innerHTML = `<span><strong style="color: #64748b;">↵ Enter</strong> to select &amp; search</span><span><strong style="color: #64748b;">↑↓</strong> to navigate</span>`;
        panel.appendChild(footer);

        updatePanelPosition(input, panel);
        panel.hidden = false;
        panel.style.display = 'block';
    }

    function isDedicatedPickerOrModalInput(input) {
        if (!input) return true;
        if (input.dataset.noAutocomplete === 'true' || input.getAttribute('data-no-autocomplete') === 'true') {
            return true;
        }
        if (input.closest('.modal, [role="dialog"], .job-quote-picker-search, .job-technician-picker-search, .quote-picker-search, .quote-customer-selector, .catalog-autocomplete-wrapper, .catalog-search-wrapper, .customer-advanced-search')) {
            return true;
        }
        if (input.id === 'stockOutCustomerInput' || input.id === 'stockOutVehicleInput' || input.id === 'addVehicleCustomerInput' || input.id === 'add_vehicle_customer_input') {
            return true;
        }
        return false;
    }

    function bindInput(input) {
        bindClearButton(input);

        if (input.dataset.hwSearchSuggestBound === '1') {
            return;
        }

        if (isDedicatedPickerOrModalInput(input)) {
            return;
        }

        input.dataset.hwSearchSuggestBound = '1';
        input.classList.add('hw-search-input');
        input.setAttribute('autocomplete', 'off');

        const host = ensureHost(input);
        if (!host) return;

        const panel = createPanel();
        const clearBtn = host.querySelector('.hw-search-clear-btn');
        const updateClearBtn = () => {
            if (clearBtn) {
                updateClearBtnState(input, clearBtn);
            }
        };
        let timer = null;
        let controller = null;
        let activeRequestId = 0;

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                activeRequestId++;
                if (controller) {
                    controller.abort();
                    controller = null;
                }
                window.clearTimeout(timer);
                closePanel(panel);
            });
        }

        function fetchAndRender(query) {
            const value = query.trim();
            window.clearTimeout(timer);

            if (controller) {
                controller.abort();
                controller = null;
            }

            if (value.length < MIN_CHARS) {
                closePanel(panel);
                return;
            }

            const currentRequestId = ++activeRequestId;

            timer = window.setTimeout(function() {
                controller = new AbortController();
                const params = new URLSearchParams();
                params.set('q', value);
                params.set('context', input.dataset.searchContext || inferContext());
                params.set('limit', '10');

                const form = input.form;
                const branch = getFormValue(form, ['branch_id', 'branch']);
                const category = getFormValue(form, ['category']);

                if (branch) params.set('branch', branch);
                if (category) params.set('category', category);

                fetch(`${endpoint}?${params.toString()}`, {
                    credentials: 'same-origin',
                    signal: controller.signal,
                })
                    .then(response => response.ok ? response.json() : { suggestions: [] })
                    .then(data => {
                        if (currentRequestId !== activeRequestId) {
                            return;
                        }
                        if (!isInputVisible(input) || document.activeElement !== input || input.value.trim() !== value) {
                            closePanel(panel);
                            return;
                        }
                        renderSuggestions(input, panel, data.suggestions || [], value, () => {
                            activeRequestId++;
                            if (controller) {
                                controller.abort();
                                controller = null;
                            }
                        });
                    })
                    .catch(error => {
                        if (error.name !== 'AbortError') {
                            closePanel(panel);
                        }
                    });
            }, DEBOUNCE_MS);
        }

        input.addEventListener('input', function() {
            updateClearBtn();
            fetchAndRender(this.value);
        });

        input.addEventListener('focus', function() {
            updateClearBtn();
            if (this.value.trim().length >= MIN_CHARS) {
                fetchAndRender(this.value);
            }
        });

        input.addEventListener('blur', function() {
            window.setTimeout(() => {
                if (document.activeElement !== input && (!document.activeElement || !panel.contains(document.activeElement))) {
                    closePanel(panel);
                }
            }, 180);
        });

        input.addEventListener('keydown', function(event) {
            if (panel.hidden || panel.style.display === 'none') {
                return;
            }

            const items = Array.from(panel.querySelectorAll('.hw-search-suggestion'));
            if (!items.length) {
                if (event.key === 'Escape') {
                    closePanel(panel);
                }
                return;
            }

            const current = Number.parseInt(panel.dataset.activeIndex || '-1', 10);

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(panel, current < 0 ? 0 : current + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(panel, current <= 0 ? items.length - 1 : current - 1);
            } else if (event.key === 'Enter' && current >= 0) {
                event.preventDefault();
                activeRequestId++;
                if (controller) {
                    controller.abort();
                    controller = null;
                }
                input.value = items[current].dataset.value || input.value;
                updateClearBtn();
                closePanel(panel);
                submitSearch(input);
            } else if (event.key === 'Escape') {
                closePanel(panel);
            }
        });

        const onReposition = () => {
            if (clearBtn) {
                positionClearButton(input, clearBtn);
            }
            if (!panel.hidden && panel.style.display !== 'none') {
                if (!isInputVisible(input)) {
                    closePanel(panel);
                } else {
                    updatePanelPosition(input, panel);
                }
            }
        };
        window.addEventListener('scroll', onReposition, { passive: true });
        window.addEventListener('resize', onReposition);

        document.addEventListener('click', function(e) {
            if (!host.contains(e.target) && !panel.contains(e.target)) {
                closePanel(panel);
            }
        });
    }

    function initSearchClearButtons() {
        const clearSelectors = [
            'input[type="search"]:not([type="hidden"])',
            'input[name="search"]:not([type="hidden"])',
            'input.hw-search-input:not([type="hidden"])',
            '#frontQuoteSearch',
            '#reportsSearchInput',
            '#frontReportsSearch',
            '#frontJobSearch',
            '#jobQuotationSearch',
            '#jobTechnicianSearch',
            '#customerAdvancedSearch',
            '#servicePickerSearch',
            '#itemPickerSearch',
            '#adminInventorySearch',
            '.job-quote-search input',
            '.job-technician-search input',
            '.quote-picker-search input',
            '.quote-customer-selector input',
            '.catalog-search-wrapper input',
            '.customer-advanced-search input',
            '.records-search-input:not([type="hidden"])',
            '.inventory-search-input:not([type="hidden"])',
            '.customer-search-input:not([type="hidden"])'
        ];

        document.querySelectorAll(clearSelectors.join(', ')).forEach(input => {
            bindClearButton(input);
            const host = input.parentElement;
            if (host) {
                const clearBtn = host.querySelector(':scope > .hw-search-clear-btn');
                if (clearBtn) {
                    positionClearButton(input, clearBtn);
                    updateClearBtnState(input, clearBtn);
                }
            }
        });
    }

    function initSearchSuggestions() {
        const modalExclude = ':not(.modal input):not([role="dialog"] input):not([data-no-autocomplete="true"])';
        const searchSelectors = [
            `input[name="search"]:not([type="hidden"])${modalExclude}`,
            `input[type="search"]:not([type="hidden"])${modalExclude}`,
            `input[name="q"]:not([type="hidden"])${modalExclude}`,
            `input.records-search-input:not([type="hidden"])${modalExclude}`,
            `input.inventory-search-input:not([type="hidden"])${modalExclude}`,
            `input.customer-search-input:not([type="hidden"])${modalExclude}`,
            `input[placeholder*="Search"]:not([type="hidden"])${modalExclude}`,
            `input[placeholder*="search"]:not([type="hidden"])${modalExclude}`,
            `#customerSearchInput:not([data-no-autocomplete="true"])`,
            `#inventorySearchInput:not([data-no-autocomplete="true"])`,
            `#recordsSearchInput:not([data-no-autocomplete="true"])`
        ];

        document.querySelectorAll(searchSelectors.join(', ')).forEach(bindInput);
    }

    document.addEventListener('hide.bs.modal', function() {
        document.querySelectorAll('.hw-search-suggestions').forEach(closePanel);
    });
    document.addEventListener('hidden.bs.modal', function() {
        document.querySelectorAll('.hw-search-suggestions').forEach(closePanel);
    });

    document.addEventListener('shown.bs.modal', function(e) {
        initSearchClearButtons();
        if (e.target && e.target.querySelectorAll) {
            e.target.querySelectorAll('.hw-search-clear-btn').forEach(btn => {
                const input = btn.parentElement ? btn.parentElement.querySelector('input') : null;
                if (input) {
                    positionClearButton(input, btn);
                    updateClearBtnState(input, btn);
                }
            });
        }
    });

    window.addEventListener('resize', () => {
        document.querySelectorAll('.hw-search-clear-btn').forEach(btn => {
            const input = btn.parentElement ? btn.parentElement.querySelector('input') : null;
            if (input) {
                positionClearButton(input, btn);
            }
        });
    });

    function init() {
        initSearchClearButtons();
        initSearchSuggestions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.setTimeout(init, 300);
    window.setTimeout(init, 1000);
})();
