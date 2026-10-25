// ph-address-selector.js
// Philippine Address Selector for Harana Registration Form
// Supports TWO independent address selectors: Present and Permanent

const DATA_PATH = 'json/ph-address.json';

// ─── State ───
let allData = null;

// ─── DOM refs for Present Address ───
const presentProvince = document.getElementById('present_province');
const presentCity = document.getElementById('present_city');
const presentBarangay = document.getElementById('present_barangay');

// ─── DOM refs for Permanent Address ───
const permanentProvince = document.getElementById('permanent_province');
const permanentCity = document.getElementById('permanent_city');
const permanentBarangay = document.getElementById('permanent_barangay');

// ─── DOM refs for Place of Birth ───
const birthProvince = document.getElementById('birth_province');
const birthCity = document.getElementById('birth_city');

// ─── Fetch data ───
async function loadAddressData() {
    try {
        console.log('🔍 Loading address data...');
        const response = await fetch(DATA_PATH);
        if (!response.ok) throw new Error('Failed to load address data: ' + response.status);
        allData = await response.json();
        console.log('✅ Address data loaded successfully!');
        populateProvinces();
    } catch (err) {
        console.error('❌ Error loading address data:', err);
        if (presentProvince) {
            presentProvince.innerHTML = '<option value="">⚠️ Error loading data</option>';
        }
    }
}

// ─── Populate Provinces for ALL dropdowns ───
function populateProvinces() {
    const provinces = allData.provinces || [];
    provinces.sort((a, b) => a.name.localeCompare(b.name));

    // Present Address
    if (presentProvince) {
        presentProvince.innerHTML = '<option value="">— Select Province —</option>';
        provinces.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.name;
            opt.textContent = p.name;
            presentProvince.appendChild(opt);
        });
    }

    // Permanent Address
    if (permanentProvince) {
        permanentProvince.innerHTML = '<option value="">— Select Province —</option>';
        provinces.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.name;
            opt.textContent = p.name;
            permanentProvince.appendChild(opt);
        });
    }

    // Place of Birth - Province
    if (birthProvince) {
        birthProvince.innerHTML = '<option value="">— Select Province —</option>';
        provinces.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.name;
            opt.textContent = p.name;
            birthProvince.appendChild(opt);
        });
    }

    console.log('✅ Provinces populated');
    preSelectSavedValues();
}

// ─── Populate Cities for a specific selector ───
function populateCities(provName, citySelect, barangaySelect) {
    if (!citySelect) return;
    
    citySelect.innerHTML = '<option value="">— Select City —</option>';
    if (barangaySelect) {
        barangaySelect.innerHTML = '<option value="">— Select Barangay —</option>';
        barangaySelect.disabled = true;
    }
    citySelect.disabled = true;

    if (!provName || !allData) return;

    // Find the province by name
    const province = allData.provinces?.find(p => p.name === provName);
    if (!province) return;

    const provPsgc = province.psgc;
    const provPrefix = provPsgc.substring(0, 5);
    
    const cities = allData.cities?.filter(c => c.psgc.startsWith(provPrefix)) || [];
    const municipalities = allData.municipalities?.filter(m => m.psgc.startsWith(provPrefix)) || [];
    const combined = [...cities, ...municipalities].sort((a, b) => a.name.localeCompare(b.name));

    if (combined.length === 0) return;

    combined.forEach(place => {
        const opt = document.createElement('option');
        opt.value = place.name;
        opt.textContent = place.name;
        citySelect.appendChild(opt);
    });
    citySelect.disabled = false;
}

// ─── Populate Barangays for a specific selector ───
function populateBarangays(cityName, barangaySelect) {
    if (!barangaySelect) return;
    
    barangaySelect.innerHTML = '<option value="">— Select Barangay —</option>';
    barangaySelect.disabled = true;

    if (!cityName || !allData) return;

    const city = allData.cities?.find(c => c.name === cityName);
    const municipality = allData.municipalities?.find(m => m.name === cityName);
    const place = city || municipality;
    
    if (!place) return;

    const cityPsgc = place.psgc;
    const cityPrefix = cityPsgc.substring(0, 7);
    
    const barangays = allData.barangays?.filter(b => b.psgc.startsWith(cityPrefix)) || [];
    barangays.sort((a, b) => a.name.localeCompare(b.name));

    if (barangays.length === 0) return;

    barangays.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.name;
        opt.textContent = b.name;
        barangaySelect.appendChild(opt);
    });
    barangaySelect.disabled = false;
}

// ─── Pre-select saved values on error ───
function preSelectSavedValues() {
    // Present Address
    const savedPresentProvince = document.getElementById('saved_present_province')?.value;
    const savedPresentCity = document.getElementById('saved_present_city')?.value;
    const savedPresentBarangay = document.getElementById('saved_present_barangay')?.value;

    if (savedPresentProvince && presentProvince) {
        presentProvince.value = savedPresentProvince;
        populateCities(savedPresentProvince, presentCity, presentBarangay);
        setTimeout(() => {
            if (savedPresentCity && presentCity) {
                presentCity.value = savedPresentCity;
                populateBarangays(savedPresentCity, presentBarangay);
                setTimeout(() => {
                    if (savedPresentBarangay && presentBarangay) {
                        presentBarangay.value = savedPresentBarangay;
                    }
                }, 100);
            }
        }, 100);
    }

    // Permanent Address
    const savedPermanentProvince = document.getElementById('saved_permanent_province')?.value;
    const savedPermanentCity = document.getElementById('saved_permanent_city')?.value;
    const savedPermanentBarangay = document.getElementById('saved_permanent_barangay')?.value;

    if (savedPermanentProvince && permanentProvince) {
        permanentProvince.value = savedPermanentProvince;
        populateCities(savedPermanentProvince, permanentCity, permanentBarangay);
        setTimeout(() => {
            if (savedPermanentCity && permanentCity) {
                permanentCity.value = savedPermanentCity;
                populateBarangays(savedPermanentCity, permanentBarangay);
                setTimeout(() => {
                    if (savedPermanentBarangay && permanentBarangay) {
                        permanentBarangay.value = savedPermanentBarangay;
                    }
                }, 100);
            }
        }, 100);
    }

    // Place of Birth
    const savedBirthProvince = document.getElementById('saved_birth_province')?.value;
    const savedBirthCity = document.getElementById('saved_birth_city')?.value;

    if (savedBirthProvince && birthProvince) {
        birthProvince.value = savedBirthProvince;
        populateCities(savedBirthProvince, birthCity, null);
        setTimeout(() => {
            if (savedBirthCity && birthCity) {
                birthCity.value = savedBirthCity;
            }
        }, 100);
    }
}

// ─── Event listeners ───

// Present Address
if (presentProvince) {
    presentProvince.addEventListener('change', function() {
        populateCities(this.value, presentCity, presentBarangay);
        presentCity.value = '';
        presentBarangay.value = '';
        if (presentBarangay) presentBarangay.disabled = true;
    });
}

if (presentCity) {
    presentCity.addEventListener('change', function() {
        populateBarangays(this.value, presentBarangay);
        presentBarangay.value = '';
    });
}

// Permanent Address
if (permanentProvince) {
    permanentProvince.addEventListener('change', function() {
        populateCities(this.value, permanentCity, permanentBarangay);
        permanentCity.value = '';
        permanentBarangay.value = '';
        if (permanentBarangay) permanentBarangay.disabled = true;
    });
}

if (permanentCity) {
    permanentCity.addEventListener('change', function() {
        populateBarangays(this.value, permanentBarangay);
        permanentBarangay.value = '';
    });
}

// Place of Birth
if (birthProvince) {
    birthProvince.addEventListener('change', function() {
        populateCities(this.value, birthCity, null);
        birthCity.value = '';
    });
}

// ─── "Same as Present" Button ───
const sameAsPresentBtn = document.getElementById('sameAsPresentBtn');
if (sameAsPresentBtn) {
    sameAsPresentBtn.addEventListener('click', function() {
        // Copy Present Address values to Permanent Address
        const presentProvVal = presentProvince?.value || '';
        const presentCityVal = presentCity?.value || '';
        const presentBarangayVal = presentBarangay?.value || '';
        const presentStreet = document.getElementById('present_street')?.value || '';
        
        // Set Permanent Address dropdowns
        if (permanentProvince && presentProvVal) {
            permanentProvince.value = presentProvVal;
            // Trigger change to populate cities
            permanentProvince.dispatchEvent(new Event('change'));
            
            setTimeout(() => {
                if (permanentCity && presentCityVal) {
                    permanentCity.value = presentCityVal;
                    permanentCity.dispatchEvent(new Event('change'));
                    
                    setTimeout(() => {
                        if (permanentBarangay && presentBarangayVal) {
                            permanentBarangay.value = presentBarangayVal;
                        }
                    }, 100);
                }
            }, 100);
        }
        
        // Copy street
        if (document.getElementById('permanent_street') && presentStreet) {
            document.getElementById('permanent_street').value = presentStreet;
        }
    });
}

// ─── Load data when page loads ───
document.addEventListener('DOMContentLoaded', function() {
    loadAddressData();
});