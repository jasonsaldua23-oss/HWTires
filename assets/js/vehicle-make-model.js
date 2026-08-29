/**
 * Highway Tires Management System
 * Vehicle Make and Model Dropdown Catalog & Dynamic Selector
 */

(function () {
    'use strict';

    const VEHICLE_MAKE_MODEL_CATALOG = {
        "Audi": [
            "A1", "A3", "A4", "A5", "A6", "A7", "A8",
            "Q2", "Q3", "Q5", "Q7", "Q8", "e-tron", "TT", "R8"
        ],
        "BMW": [
            "1 Series", "2 Series", "3 Series", "4 Series", "5 Series", "6 Series", "7 Series", "8 Series",
            "X1", "X2", "X3", "X4", "X5", "X6", "X7", "Z4",
            "M2", "M3", "M4", "M5", "M8", "i4", "iX", "iX3", "i7"
        ],
        "BYD": [
            "Atto 3", "Dolphin", "Seal", "Sealion 6", "Tang", "Han", "Song Plus", "Yuan Plus"
        ],
        "Changan": [
            "Alsvin", "CS35 Plus", "CS55 Plus", "UNI-T", "UNI-K", "UNI-V", "X7 Plus"
        ],
        "Chery": [
            "Tiggo 2", "Tiggo 2 Pro", "Tiggo 5X", "Tiggo 5X Pro", "Tiggo 7 Pro", "Tiggo 8 Pro", "Arrizo 5"
        ],
        "Chevrolet": [
            "Captiva", "Colorado", "Corvette", "Cruze", "Optra", "Sail",
            "Spark", "Suburban", "Tahoe", "Tracker", "Trailblazer", "Trax"
        ],
        "Ford": [
            "EcoSport", "Escape", "Everest", "Expedition", "Explorer",
            "F-150", "Fiesta", "Focus", "Lynx", "Mustang",
            "Ranger", "Ranger Raptor", "Territory", "Transit"
        ],
        "Foton": [
            "Gratour", "Thunder", "Toplander", "Tornado", "Transvan", "Traveller", "View Transvan"
        ],
        "GAC": [
            "Empow", "Emkoo", "GA4", "GN6", "GS3", "GS3 Emzoom", "GS4", "GS8", "M6 Pro", "M8"
        ],
        "Geely": [
            "Azkarra", "Coolray", "Emgrand", "GX3 Pro", "Monjaro", "Okavango", "Tugella"
        ],
        "Hino": [
            "300 Series", "500 Series", "700 Series", "Dutro"
        ],
        "Honda": [
            "Accord", "BR-V", "Brio", "City", "City Hatchback",
            "Civic", "Civic Type R", "CR-V", "CR-Z", "HR-V",
            "Jazz", "Mobilio", "Odyssey", "Pilot"
        ],
        "Hyundai": [
            "Accent", "Creta", "Custin", "Elantra", "Grand Starex", "H-100",
            "Ioniq 5", "Ioniq 6", "Kona", "Santa Fe", "Sonata",
            "Stargazer", "Staria", "Tucson", "Venue"
        ],
        "Isuzu": [
            "Crosswind", "D-Max", "D-Max Boondock", "Highlander", "mu-X",
            "N-Series (Elf)", "Panther", "Traviz", "Trooper"
        ],
        "Jaguar": [
            "E-Pace", "F-Pace", "F-Type", "I-Pace", "XE", "XF", "XJ"
        ],
        "Jeep": [
            "Cherokee", "Compass", "Gladiator", "Grand Cherokee", "Renegade", "Wrangler", "Wrangler Rubicon"
        ],
        "Kia": [
            "Carens", "Carnival", "EV6", "Forte", "K2500", "Picanto",
            "Rio", "Seltos", "Soluto", "Sonet", "Sorento", "Soul", "Sportage", "Stonic"
        ],
        "Land Rover": [
            "Defender", "Discovery", "Discovery Sport",
            "Range Rover", "Range Rover Evoque", "Range Rover Sport", "Range Rover Velar"
        ],
        "Lexus": [
            "ES", "GX", "IS", "LC", "LBX", "LM", "LS", "LX", "NX", "RC", "RX", "UX"
        ],
        "Mazda": [
            "BT-50", "CX-3", "CX-30", "CX-5", "CX-60", "CX-8", "CX-9", "CX-90",
            "Mazda 2", "Mazda 3", "Mazda 6", "MX-5 Miata"
        ],
        "Mercedes-Benz": [
            "A-Class", "AMG GT", "B-Class", "C-Class", "CLA", "CLE", "CLS",
            "E-Class", "G-Class", "GLA", "GLB", "GLC", "GLE", "GLS",
            "S-Class", "SLK / SLC", "Sprinter", "V-Class"
        ],
        "MG": [
            "MG 3", "MG 4 EV", "MG 5", "MG Cyberster", "MG GT", "MG HS", "MG One", "MG ZS", "MG ZS EV", "RX5"
        ],
        "Mini": [
            "Clubman", "Cooper", "Cooper S", "Countryman", "John Cooper Works"
        ],
        "Mitsubishi": [
            "Adventure", "Eclipse Cross", "Grandis", "L200", "L300",
            "Mirage", "Mirage G4", "Montero Sport", "Outlander",
            "Pajero", "Strada", "Triton", "Xforce", "Xpander", "Xpander Cross"
        ],
        "Nissan": [
            "Almera", "Cefiro", "GT-R", "Juke", "Kicks e-Power", "Livina",
            "Navara", "Patrol", "Patrol Royale", "Sentra", "Sylphy",
            "Terra", "Urvan / NV350", "X-Trail"
        ],
        "Peugeot": [
            "2008", "3008", "5008", "508", "Traveller"
        ],
        "Porsche": [
            "718 Boxster", "718 Cayman", "911", "Cayenne", "Macan", "Panamera", "Taycan"
        ],
        "Subaru": [
            "BRZ", "Crosstrek", "Evoltis", "Forester", "Impreza", "Legacy", "Levorg", "Outback", "WRX", "XV"
        ],
        "Suzuki": [
            "Alto", "APV", "Carry", "Celerio", "Ciaz", "Dzire",
            "Ertiga", "Ertiga Hybrid", "Grand Vitara", "Jimny", "S-Presso",
            "Swift", "SX4", "Vitara", "XL7"
        ],
        "Toyota": [
            "86", "Alphard", "Avanza", "Camry", "Corolla Altis", "Corolla Cross",
            "Fortuner", "GR 86", "GR Yaris", "Hiace", "Hiace Commuter", "Hiace Super Grandia",
            "Hilux", "Hilux Conquest", "Hilux GR-S", "Innova", "Land Cruiser 300",
            "Land Cruiser Prado", "Prius", "Raize", "RAV4", "Revo", "Rush",
            "Supra", "Tamaraw FX", "Veloz", "Vios", "Wigo", "Yaris", "Yaris Cross"
        ],
        "Volkswagen": [
            "Beetle", "Golf", "Lamando", "Lavida", "Passat", "Polo",
            "Santana", "T-Cross", "Teramont", "Tiguan", "Transporter"
        ],
        "Volvo": [
            "C40 Recharge", "S60", "S90", "V60", "V90", "XC40", "XC60", "XC90"
        ]
    };

    function initVehicleMakeModelSelector(makeSelect) {
        if (!makeSelect || makeSelect.dataset.vehicleSelectorInit === '1') {
            return;
        }

        makeSelect.dataset.vehicleSelectorInit = '1';

        const form = makeSelect.closest('form') || document;
        const modelTargetSelector = makeSelect.getAttribute('data-model-target');
        let modelSelect = null;

        if (modelTargetSelector) {
            modelSelect = form.querySelector(modelTargetSelector);
        }

        if (!modelSelect) {
            // Find by naming convention (model vs make, or vehicle_model vs vehicle_make)
            const makeName = makeSelect.getAttribute('name') || '';
            if (makeName === 'vehicle_make') {
                modelSelect = form.querySelector('[name="vehicle_model"]');
            } else if (makeName === 'make') {
                modelSelect = form.querySelector('[name="model"]');
            }
        }

        // Custom make & model input fields
        const makeCustomInput = form.querySelector('[name="' + (makeSelect.name === 'vehicle_make' ? 'vehicle_make_custom' : 'make_custom') + '"]')
            || makeSelect.parentElement.querySelector('.vehicle-make-custom');

        const modelCustomInput = modelSelect
            ? (form.querySelector('[name="' + (modelSelect.name === 'vehicle_model' ? 'vehicle_model_custom' : 'model_custom') + '"]') || modelSelect.parentElement.querySelector('.vehicle-model-custom'))
            : null;

        const initialMakeValue = (makeSelect.getAttribute('data-initial-value') || makeSelect.value || '').trim();
        const initialModelValue = modelSelect ? (modelSelect.getAttribute('data-initial-value') || modelSelect.value || '').trim() : '';

        // Populate Make Options
        populateMakeDropdown(makeSelect, initialMakeValue);

        function handleMakeChange() {
            const selectedMake = makeSelect.value;

            if (selectedMake === 'Other') {
                if (makeCustomInput) {
                    makeCustomInput.style.display = 'block';
                    makeCustomInput.required = true;
                }
                if (modelSelect) {
                    modelSelect.innerHTML = '<option value="" selected disabled>-- Select Model --</option><option value="Other">Other Model...</option>';
                    modelSelect.disabled = false;
                    modelSelect.value = 'Other';
                }
                if (modelCustomInput) {
                    modelCustomInput.style.display = 'block';
                    modelCustomInput.required = true;
                }
            } else if (selectedMake && VEHICLE_MAKE_MODEL_CATALOG[selectedMake]) {
                if (makeCustomInput) {
                    makeCustomInput.style.display = 'none';
                    makeCustomInput.required = false;
                    makeCustomInput.value = '';
                }
                if (modelSelect) {
                    populateModelDropdown(modelSelect, selectedMake, initialModelValue);
                }
            } else {
                if (makeCustomInput) {
                    makeCustomInput.style.display = 'none';
                    makeCustomInput.required = false;
                }
                if (modelSelect) {
                    modelSelect.innerHTML = '<option value="" selected disabled>-- Select Brand First --</option>';
                    modelSelect.disabled = true;
                }
                if (modelCustomInput) {
                    modelCustomInput.style.display = 'none';
                    modelCustomInput.required = false;
                }
            }
        }

        makeSelect.addEventListener('change', handleMakeChange);

        if (modelSelect) {
            modelSelect.addEventListener('change', function () {
                if (modelSelect.value === 'Other') {
                    if (modelCustomInput) {
                        modelCustomInput.style.display = 'block';
                        modelCustomInput.required = true;
                        modelCustomInput.focus();
                    }
                } else {
                    if (modelCustomInput) {
                        modelCustomInput.style.display = 'none';
                        modelCustomInput.required = false;
                        modelCustomInput.value = '';
                    }
                }
            });
        }

        // Initialize state
        if (initialMakeValue) {
            handleMakeChange();
        }
    }

    function populateMakeDropdown(selectElement, initialValue) {
        const currentVal = (initialValue || selectElement.value || '').trim();
        const makes = Object.keys(VEHICLE_MAKE_MODEL_CATALOG).sort();

        let html = '<option value="" disabled ' + (!currentVal ? 'selected' : '') + '>-- Select Car Brand / Make --</option>';

        let found = false;
        makes.forEach(function (make) {
            const isSelected = currentVal && make.toLowerCase() === currentVal.toLowerCase();
            if (isSelected) found = true;
            html += '<option value="' + make + '" ' + (isSelected ? 'selected' : '') + '>' + make + '</option>';
        });

        html += '<option value="Other" ' + (currentVal && !found ? 'selected' : '') + '>Other / Custom Brand...</option>';

        selectElement.innerHTML = html;

        if (currentVal && !found) {
            selectElement.value = 'Other';
        }
    }

    function populateModelDropdown(selectElement, makeName, initialValue) {
        const models = VEHICLE_MAKE_MODEL_CATALOG[makeName] || [];
        const currentVal = (initialValue || selectElement.value || '').trim();

        let html = '<option value="" disabled ' + (!currentVal ? 'selected' : '') + '>-- Select ' + makeName + ' Model --</option>';

        let found = false;
        models.forEach(function (model) {
            const isSelected = currentVal && model.toLowerCase() === currentVal.toLowerCase();
            if (isSelected) found = true;
            html += '<option value="' + model + '" ' + (isSelected ? 'selected' : '') + '>' + model + '</option>';
        });

        html += '<option value="Other" ' + (currentVal && !found ? 'selected' : '') + '>Other ' + makeName + ' Model...</option>';

        selectElement.innerHTML = html;
        selectElement.disabled = false;

        if (currentVal) {
            if (found) {
                selectElement.value = currentVal;
            } else {
                selectElement.value = 'Other';
            }
        }
    }

    function scanAndInitAllSelectors() {
        document.querySelectorAll('select.vehicle-make-select').forEach(initVehicleMakeModelSelector);
    }

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scanAndInitAllSelectors);
    } else {
        scanAndInitAllSelectors();
    }

    // Support dynamic modals opening
    document.addEventListener('shown.bs.modal', scanAndInitAllSelectors);

    // Export globally
    window.VehicleMakeModel = {
        catalog: VEHICLE_MAKE_MODEL_CATALOG,
        init: initVehicleMakeModelSelector,
        scan: scanAndInitAllSelectors
    };
})();
