/**
 * Highway Tires Management System
 * Philippine Hierarchical Address Selector (Region > Province > City > Barangay > Street)
 */

(function () {
    'use strict';

    let cachedAddressData = null;
    let loadPromise = null;

    /**
     * Get the JSON dataset URL reliably for localhost (/hwtires) and production (/)
     */
    function getAddressDataUrl() {
        if (typeof window !== 'undefined' && window.HWTIRES_ADDRESS_DATA_URL) {
            return window.HWTIRES_ADDRESS_DATA_URL;
        }
        const appUrl = (typeof window !== 'undefined' && typeof window.HWTIRES_APP_URL === 'string')
            ? window.HWTIRES_APP_URL
            : '';
        return appUrl + '/assets/data/philippine-addresses.json';
    }

    /**
     * Lazily load and cache the Philippine address dataset
     */
    function loadAddressData() {
        if (cachedAddressData) {
            return Promise.resolve(cachedAddressData);
        }
        if (loadPromise) {
            return loadPromise;
        }

        const url = getAddressDataUrl();
        loadPromise = fetch(url)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' while fetching address data');
                }
                return response.json();
            })
            .then(function (data) {
                cachedAddressData = data;
                loadPromise = null;
                return data;
            })
            .catch(function (error) {
                loadPromise = null;
                throw error;
            });

        return loadPromise;
    }

    /**
     * Format administrative names to title case with proper handling of Roman numerals and acronyms
     */
    function formatAddressName(text) {
        if (!text || typeof text !== 'string') return '';
        const trimmed = text.trim();
        if (!trimmed) return '';

        const specialRegions = {
            'NCR': 'NCR',
            'CAR': 'CAR',
            'ARMM': 'ARMM',
            'REGION I': 'Region I',
            'REGION II': 'Region II',
            'REGION III': 'Region III',
            'REGION IV-A': 'Region IV-A',
            'REGION IV-B': 'Region IV-B',
            'REGION V': 'Region V',
            'REGION VI': 'Region VI',
            'REGION VII': 'Region VII',
            'REGION VIII': 'Region VIII',
            'REGION IX': 'Region IX',
            'REGION X': 'Region X',
            'REGION XI': 'Region XI',
            'REGION XII': 'Region XII',
            'REGION XIII': 'Region XIII'
        };

        const upper = trimmed.toUpperCase();
        if (specialRegions[upper]) {
            return specialRegions[upper];
        }

        const tokens = trimmed.split(/(\s+|[-–—\(\)\/])/);
        const lowercaseTokens = ['of', 'de', 'del', 'and', 'the', 'da', 'ng', 'sa'];
        let result = '';
        let isFirst = true;

        for (let i = 0; i < tokens.length; i++) {
            const token = tokens[i];
            if (!token) continue;
            if (/^(\s+|[-–—\(\)\/])$/.test(token)) {
                result += token;
                if (token.trim() !== '') isFirst = true;
                continue;
            }
            const lower = token.toLowerCase();
            if (/^(i|ii|iii|iv|v|vi|vii|viii|ix|x|xi|xii|xiii)$/i.test(token)) {
                result += token.toUpperCase();
            } else if (/^pob\.?$/i.test(token)) {
                result += 'Pob.';
            } else if (/^brgy\.?$/i.test(token)) {
                result += 'Brgy.';
            } else if (!isFirst && lowercaseTokens.indexOf(lower) !== -1) {
                result += lower;
            } else {
                result += lower.charAt(0).toUpperCase() + lower.slice(1);
            }
            isFirst = false;
        }
        return result;
    }

    /**
     * Create a default placeholder option
     */
    function createPlaceholderOption(label) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = label;
        opt.selected = true;
        opt.disabled = true;
        return opt;
    }

    /**
     * Clear and reset a select element to disabled with placeholder
     */
    function resetSelect(selectEl, placeholderLabel) {
        if (!selectEl) return;
        selectEl.innerHTML = '';
        selectEl.appendChild(createPlaceholderOption(placeholderLabel));
        selectEl.value = '';
        selectEl.disabled = true;
    }

    /**
     * Initialize a single address component container
     */
    function initAddressComponent(container) {
        if (!container || container.dataset.phAddressInit === '1') {
            return;
        }
        container.dataset.phAddressInit = '1';

        const regionSelect = container.querySelector('.ph-region-select');
        const provinceSelect = container.querySelector('.ph-province-select');
        const citySelect = container.querySelector('.ph-city-select');
        const barangaySelect = container.querySelector('.ph-barangay-select');
        const streetInput = container.querySelector('.ph-street-input');
        const alertEl = container.querySelector('.address-error-alert');

        if (!regionSelect || !provinceSelect || !citySelect || !barangaySelect) {
            return;
        }

        function showError(msg) {
            if (alertEl) {
                alertEl.textContent = msg;
                alertEl.style.display = 'block';
            }
        }

        function hideError() {
            if (alertEl) {
                alertEl.textContent = '';
                alertEl.style.display = 'none';
            }
        }

        // Populate regions
        loadAddressData()
            .then(function (data) {
                hideError();

                // Build regions list
                const regions = [];
                for (const code in data) {
                    if (Object.prototype.hasOwnProperty.call(data, code)) {
                        const rawName = data[code].region_name || code;
                        regions.push({
                            code: code,
                            rawName: rawName,
                            displayName: formatAddressName(rawName)
                        });
                    }
                }

                // Preserve placeholder
                regionSelect.innerHTML = '';
                regionSelect.appendChild(createPlaceholderOption('-- Select Region --'));

                regions.forEach(function (r) {
                    const opt = document.createElement('option');
                    opt.value = r.displayName;
                    opt.textContent = r.displayName;
                    opt.dataset.regionCode = r.code;
                    regionSelect.appendChild(opt);
                });

                regionSelect.disabled = false;
            })
            .catch(function (err) {
                console.error('Failed to load Philippine address data:', err);
                showError('Unable to load Philippine address list. Please reload the page.');
                regionSelect.disabled = true;
                provinceSelect.disabled = true;
                citySelect.disabled = true;
                barangaySelect.disabled = true;
            });

        // Region change handler
        regionSelect.addEventListener('change', function () {
            hideError();
            resetSelect(provinceSelect, '-- Select Province --');
            resetSelect(citySelect, '-- Select City / Municipality --');
            resetSelect(barangaySelect, '-- Select Barangay --');

            const selectedOpt = regionSelect.selectedOptions[0];
            const regionCode = selectedOpt ? selectedOpt.dataset.regionCode : null;
            if (!regionCode || !cachedAddressData || !cachedAddressData[regionCode]) {
                return;
            }

            const provList = cachedAddressData[regionCode].province_list;
            if (!provList || typeof provList !== 'object') {
                return;
            }

            const provinces = [];
            for (const pKey in provList) {
                if (Object.prototype.hasOwnProperty.call(provList, pKey)) {
                    provinces.push({
                        key: pKey,
                        displayName: formatAddressName(pKey)
                    });
                }
            }

            provinces.sort(function (a, b) {
                return a.displayName.localeCompare(b.displayName);
            });

            provinceSelect.innerHTML = '';
            provinceSelect.appendChild(createPlaceholderOption('-- Select Province --'));

            provinces.forEach(function (p) {
                const opt = document.createElement('option');
                opt.value = p.displayName;
                opt.textContent = p.displayName;
                opt.dataset.provinceKey = p.key;
                opt.dataset.regionCode = regionCode;
                provinceSelect.appendChild(opt);
            });

            provinceSelect.disabled = false;
        });

        // Province change handler
        provinceSelect.addEventListener('change', function () {
            hideError();
            resetSelect(citySelect, '-- Select City / Municipality --');
            resetSelect(barangaySelect, '-- Select Barangay --');

            const selectedOpt = provinceSelect.selectedOptions[0];
            const regionCode = selectedOpt ? selectedOpt.dataset.regionCode : null;
            const provKey = selectedOpt ? selectedOpt.dataset.provinceKey : null;

            if (!regionCode || !provKey || !cachedAddressData) return;
            const regionData = cachedAddressData[regionCode];
            if (!regionData || !regionData.province_list || !regionData.province_list[provKey]) return;

            const munList = regionData.province_list[provKey].municipality_list;
            if (!munList || typeof munList !== 'object') return;

            const cities = [];
            for (const mKey in munList) {
                if (Object.prototype.hasOwnProperty.call(munList, mKey)) {
                    cities.push({
                        key: mKey,
                        displayName: formatAddressName(mKey)
                    });
                }
            }

            cities.sort(function (a, b) {
                return a.displayName.localeCompare(b.displayName);
            });

            citySelect.innerHTML = '';
            citySelect.appendChild(createPlaceholderOption('-- Select City / Municipality --'));

            cities.forEach(function (c) {
                const opt = document.createElement('option');
                opt.value = c.displayName;
                opt.textContent = c.displayName;
                opt.dataset.cityKey = c.key;
                opt.dataset.provinceKey = provKey;
                opt.dataset.regionCode = regionCode;
                citySelect.appendChild(opt);
            });

            citySelect.disabled = false;
        });

        // City change handler
        citySelect.addEventListener('change', function () {
            hideError();
            resetSelect(barangaySelect, '-- Select Barangay --');

            const selectedOpt = citySelect.selectedOptions[0];
            const regionCode = selectedOpt ? selectedOpt.dataset.regionCode : null;
            const provKey = selectedOpt ? selectedOpt.dataset.provinceKey : null;
            const cityKey = selectedOpt ? selectedOpt.dataset.cityKey : null;

            if (!regionCode || !provKey || !cityKey || !cachedAddressData) return;
            const regionData = cachedAddressData[regionCode];
            if (!regionData || !regionData.province_list || !regionData.province_list[provKey]) return;
            const provData = regionData.province_list[provKey];
            if (!provData.municipality_list || !provData.municipality_list[cityKey]) return;

            const rawBrgys = provData.municipality_list[cityKey].barangay_list;
            if (!Array.isArray(rawBrgys)) return;

            const barangays = rawBrgys.map(function (b) {
                return {
                    key: b,
                    displayName: formatAddressName(b)
                };
            });

            barangays.sort(function (a, b) {
                return a.displayName.localeCompare(b.displayName);
            });

            barangaySelect.innerHTML = '';
            barangaySelect.appendChild(createPlaceholderOption('-- Select Barangay --'));

            barangays.forEach(function (b) {
                const opt = document.createElement('option');
                opt.value = b.displayName;
                opt.textContent = b.displayName;
                barangaySelect.appendChild(opt);
            });

            barangaySelect.disabled = false;
        });
    }

    /**
     * Reset an address component container back to initial blank state
     */
    function resetAddressComponent(container) {
        if (!container) return;
        const regionSelect = container.querySelector('.ph-region-select');
        const provinceSelect = container.querySelector('.ph-province-select');
        const citySelect = container.querySelector('.ph-city-select');
        const barangaySelect = container.querySelector('.ph-barangay-select');
        const streetInput = container.querySelector('.ph-street-input');
        const alertEl = container.querySelector('.address-error-alert');

        if (regionSelect) regionSelect.value = '';
        resetSelect(provinceSelect, '-- Select Province --');
        resetSelect(citySelect, '-- Select City / Municipality --');
        resetSelect(barangaySelect, '-- Select Barangay --');
        if (streetInput) streetInput.value = '';
        if (alertEl) {
            alertEl.textContent = '';
            alertEl.style.display = 'none';
        }
    }

    /**
     * Initialize all address components on the page
     */
    function initAll() {
        const containers = document.querySelectorAll('.ph-address-component');
        containers.forEach(function (c) {
            initAddressComponent(c);
        });
    }

    // Auto-init on DOMContentLoaded or modal show
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Lazy load when modals open
    document.addEventListener('show.bs.modal', function (e) {
        const modal = e.target;
        if (!modal) return;
        const comp = modal.querySelector('.ph-address-component');
        if (comp) {
            initAddressComponent(comp);
        }
    });

    // Expose global API
    window.HWTIRES_PH_ADDRESS = {
        loadData: loadAddressData,
        initComponent: initAddressComponent,
        resetComponent: resetAddressComponent,
        formatName: formatAddressName,
        initAll: initAll
    };
})();
