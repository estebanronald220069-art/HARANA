// admin/members/members.js
// ========================================
// SIDEBAR TOGGLE + PERSISTENCE (no-flash)
// ========================================
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar-wrapper');
const headerLogo = document.getElementById('headerLogo');

// Apply the persisted state so subsequent logic reads correct value.
if (localStorage.getItem('sidebarCollapsed') === 'true') {
    sidebar.classList.add('collapsed');
}

// After first paint, swap from "pre-paint" CSS to normal transitions.
// Double rAF guarantees the browser painted once before transitions re-enable.
requestAnimationFrame(() => {
    requestAnimationFrame(() => {
        document.documentElement.classList.remove('sidebar-was-collapsed');
        document.documentElement.classList.add('sidebar-transitions-on');
    });
});

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });
}
// City data from PHP
const citiesByProvince = window.citiesData || {};

// Toast elements
const successToastEl = document.getElementById('successToast');
const errorToastEl = document.getElementById('errorToast');
const warningToastEl = document.getElementById('warningToast');

let successToast, errorToast, warningToast;

if (successToastEl) successToast = new bootstrap.Toast(successToastEl);
if (errorToastEl) errorToast = new bootstrap.Toast(errorToastEl);
if (warningToastEl) warningToast = new bootstrap.Toast(warningToastEl);

// Update cities based on selected province
function updateCities(prefix) {
    const province = document.getElementById(prefix + '_province');
    const citySelect = document.getElementById(prefix + '_city');
    
    if (!province || !citySelect) return;
    
    citySelect.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Select City';
    citySelect.appendChild(defaultOption);
    
    if (province.value && citiesByProvince[province.value]) {
        citiesByProvince[province.value].forEach(city => {
            const option = document.createElement('option');
            option.value = city;
            option.textContent = city;
            citySelect.appendChild(option);
        });
    }
}

// Calculate age from birth date
function calculateAge(prefix) {
    const birthDate = document.getElementById(prefix + '_birth_date');
    const ageField = document.getElementById(prefix + '_age');
    
    if (birthDate && birthDate.value && ageField) {
        const today = new Date();
        const birth = new Date(birthDate.value);
        let age = today.getFullYear() - birth.getFullYear();
        const monthDiff = today.getMonth() - birth.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
            age--;
        }
        
        ageField.value = age;
    }
}
// View member details
function viewMemberDetails(memberCode) {
    const modalEl = document.getElementById('viewMemberModal');
    if (!modalEl) return;
    
    // SAFE MODAL INIT: Check if instance already exists, otherwise create it
    let modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) {
        modal = new bootstrap.Modal(modalEl);
    }
    const contentEl = document.getElementById('memberDetailsContent');
    
    contentEl.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-info" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    
    modal.show();
    
    fetch(`members.php?view_code=${memberCode}`)
        .then(response => response.json())
        .then(data => {
            const birthDate = data.birth_date && data.birth_date !== '0000-00-00' ? new Date(data.birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const screeningDate = data.screening_date && data.screening_date !== '0000-00-00' ? new Date(data.screening_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const dateRegistered = data.date_registered && data.date_registered !== '0000-00-00' ? new Date(data.date_registered).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const dateJoined = data.date_joined && data.date_joined !== '0000-00-00' ? new Date(data.date_joined).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            
            const fatherName = [data.father_fname, data.father_mname, data.father_lname].filter(Boolean).join(' ');
            const motherName = [data.mother_fname, data.mother_mname, data.mother_lname].filter(Boolean).join(' ');
            const spouseName = [data.spouse_fname, data.spouse_mname, data.spouse_lname].filter(Boolean).join(' ');
            
            const child1Name = [data.child1_fname, data.child1_mname, data.child1_lname].filter(Boolean).join(' ');
            const child2Name = [data.child2_fname, data.child2_mname, data.child2_lname].filter(Boolean).join(' ');
            const child3Name = [data.child3_fname, data.child3_mname, data.child3_lname].filter(Boolean).join(' ');
            const child4Name = [data.child4_fname, data.child4_mname, data.child4_lname].filter(Boolean).join(' ');
            
            const fullAddress = [data.street, data.barangay ? 'Brgy. ' + data.barangay : '', data.city, data.province].filter(Boolean).join(', ');
            
            let content = `
                <div class="application-form">
                    <div class="logo-section">
                        <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; document.getElementById('view-logo-placeholder').style.display='flex';">
                        <div id="view-logo-placeholder" class="logo-placeholder" style="display: none;">
                            <i class="fas fa-hand-holding-heart fa-3x"></i>
                        </div>
                        <div class="text-content">
                            <h2>NAGKAISANG HIRANISTA</h2>
                            <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                            <p class="small">(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</p>
                            <p class="small">(Sec. REG No. CN 700172104)</p>
                            <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                        </div>
                    </div>

                    <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>

                    <div class="documents-section">
                        <div class="documents-section-header">
                            <h4 class="mb-0">Documents Attached:</h4>
                            <div class="doc-actions">
                                ${data.birth_certificate_file
                                    ? `<button type="button" class="btn btn-sm btn-outline-primary"
                                               onclick="openDocumentViewer('${memberCode}', 'birth_certificate_file', 'Birth Certificate');">
                                           <i class="fas fa-file-image me-1"></i>View Birth Certificate
                                       </button>`
                                    : ''}
                                ${data.medical_certificate_file
                                    ? `<button type="button" class="btn btn-sm btn-outline-primary"
                                               onclick="openDocumentViewer('${memberCode}', 'medical_certificate_file', 'Medical Certificate');">
                                           <i class="fas fa-file-medical me-1"></i>View Medical Certificate
                                       </button>`
                                    : ''}
                            </div>
                        </div>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" ${data.medical_certificate == 1 ? 'checked' : ''} disabled>
                                <label>Medical Certificate</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" ${data.birth_certificate == 1 ? 'checked' : ''} disabled>
                                <label>Birth Certificate</label>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">I. PERSONAL INFORMATION</div>
                    
                    <table class="info-table">
                        <tr>
                            <td class="label">Member Code:</td>
                            <td>${data.member_code || ''}</td>
                            <td class="label">Date Joined:</td>
                            <td>${dateJoined}</td>
                            <td class="label">Monthly Contribution:</td>
                            <td>₱${parseFloat(data.monthly_contribution || 0).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td class="label">Last Name:</td>
                            <td>${data.last_name || ''}</td>
                            <td class="label">First Name:</td>
                            <td>${data.first_name || ''}</td>
                            <td class="label">Middle Name:</td>
                            <td>${data.middle_name || ''}</td>
                        </tr>
                        <tr>
                            <td class="label">Date of Birth:</td>
                            <td>${birthDate}</td>
                            <td class="label">Place of Birth:</td>
                            <td>${data.place_of_birth || ''}</td>
                            <td class="label">Age:</td>
                            <td>${data.age || ''}</td>
                        </tr>
                        <tr>
                            <td class="label">Gender:</td>
                            <td>${data.gender || ''}</td>
                            <td class="label">Civil Status:</td>
                            <td>${data.civil_status || ''}</td>
                            <td class="label">Religion:</td>
                            <td>${data.religion || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">II. ADDRESS INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Present Address:</td>
                            <td colspan="5">${data.present_address || data.address || fullAddress}</td>
                        </tr>
                        <tr>
                            <td class="label">Permanent Address:</td>
                            <td colspan="5">${data.permanent_address || data.address || fullAddress}</td>
                        </tr>
                    </table>

                    <div class="section-title">III. CONTACT INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Contact Number:</td>
                            <td>${data.contact_number || ''}</td>
                            <td class="label">Alternate Number:</td>
                            <td colspan="3">${data.alternate_number || ''}</td>
                        </tr>
                        <tr>
                            <td class="label">Email Address:</td>
                            <td colspan="5">${data.email || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">IV. FAMILY BACKGROUND</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Father's Name:</td>
                            <td colspan="2">${fatherName}</td>
                            <td class="label">Mother's Name:</td>
                            <td colspan="2">${motherName}</td>
                        </tr>
                        <tr>
                            <td class="label">Spouse's Name:</td>
                            <td colspan="3">${spouseName}</td>
                            <td class="label">Age:</td>
                            <td>${data.spouse_age || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">V. CHILDREN</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td colspan="3">${child1Name}</td><td class="label">Age:</td><td>${data.child1_age || ''}</td></tr>
                        <tr><td class="label">2.</td><td colspan="3">${child2Name}</td><td class="label">Age:</td><td>${data.child2_age || ''}</td></tr>
                        <tr><td class="label">3.</td><td colspan="3">${child3Name}</td><td class="label">Age:</td><td>${data.child3_age || ''}</td></tr>
                        <tr><td class="label">4.</td><td colspan="3">${child4Name}</td><td class="label">Age:</td><td>${data.child4_age || ''}</td></tr>
                    </table>

                    <div class="section-title">VI. CHARACTER REFERENCES</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td>${data.ref1_name || ''}</td><td class="label">Contact:</td><td colspan="3">${data.ref1_contact || ''}</td></tr>
                        <tr><td class="label">2.</td><td>${data.ref2_name || ''}</td><td class="label">Contact:</td><td colspan="3">${data.ref2_contact || ''}</td></tr>
                    </table>

                    <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Name:</td><td colspan="5">${data.beneficiary_name || ''}</td></tr>
                        <tr><td class="label">Address:</td><td colspan="5">${data.beneficiary_address || ''}</td></tr>
                        <tr><td class="label">Relationship:</td><td>${data.beneficiary_relation || ''}</td><td class="label">Age:</td><td>${data.beneficiary_age || ''}</td><td class="label">Contact:</td><td>${data.beneficiary_contact || ''}</td></tr>
                    </table>

                    <div class="signature-section">
                        <div class="signature-box">
                            <div class="signature-line"></div>
                            <div>Signature of Applicant</div>
                        </div>
                        <div class="signature-box">
                            <div class="signature-line"></div>
                            <div>Signature of Beneficiary</div>
                        </div>
                    </div>

                    <div class="section-title">VIII. CHAPTER INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Chapter:</td><td>${data.chapter || ''}</td><td class="label">Group Name:</td><td colspan="3">${data.group_name || ''}</td></tr>
                        <tr><td class="label">Leader:</td><td>${data.leader || ''}</td><td class="label">Coordinator:</td><td colspan="3">${data.coordinator || ''}</td></tr>
                        <tr><td class="label">Chairman:</td><td>${data.chairman || ''}</td><td class="label">Screening Officer:</td><td colspan="3">${data.screening_officer || ''}</td></tr>
                        <tr><td class="label">Screening Date:</td><td>${screeningDate}</td><td class="label">Approved By:</td><td colspan="3">${data.approved_by || ''}</td></tr>
                        <tr><td class="label">Date Registered:</td><td>${dateRegistered}</td><td class="label">Status:</td><td colspan="3"><span class="badge bg-${data.status === 'active' ? 'success' : (data.status === 'inactive' ? 'warning' : 'secondary')}">${data.status || 'active'}</span></td></tr>
                    </table>

                    <div class="section-title">IX. ACCOUNT INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Username:</td><td colspan="5">${data.username || 'Not set'}</td></tr>
                    </table>
                </div>
            `;
            
            contentEl.innerHTML = content;
        })
        .catch(error => {
            contentEl.innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error loading member details. Please try again.
                </div>
            `;
            if (errorToast) errorToast.show();
        });
}

// Fill sample data for testing
function fillSampleData() {
    const randomCode = Math.floor(1000 + Math.random() * 9000);
    
    document.querySelector('#addMemberModal [name="member_code"]').value = 'M' + randomCode;
    document.querySelector('#addMemberModal [name="first_name"]').value = 'Juan';
    document.querySelector('#addMemberModal [name="last_name"]').value = 'Dela Cruz';
    document.querySelector('#addMemberModal [name="middle_name"]').value = 'Santos';
    
    const provinceSelect = document.querySelector('#addMemberModal [name="province"]');
    if (provinceSelect) provinceSelect.value = 'Nueva Ecija';
    updateCities('add');
    
    setTimeout(() => {
        const citySelect = document.querySelector('#addMemberModal [name="city"]');
        if (citySelect) citySelect.value = 'Palayan City';
    }, 100);
    
    document.querySelector('#addMemberModal [name="barangay"]').value = 'Singalat';
    document.querySelector('#addMemberModal [name="street"]').value = 'Purok 3';
    document.querySelector('#addMemberModal [name="contact_number"]').value = '09123456789';
    document.querySelector('#addMemberModal [name="alternate_number"]').value = '09987654321';
    document.querySelector('#addMemberModal [name="email"]').value = 'juan.delacruz@example.com';
    document.querySelector('#addMemberModal [name="birth_date"]').value = '1990-01-15';
    calculateAge('add');
    document.querySelector('#addMemberModal [name="place_of_birth"]').value = 'Manila';
    document.querySelector('#addMemberModal [name="gender"]').value = 'Male';
    document.querySelector('#addMemberModal [name="civil_status"]').value = 'Married';
    document.querySelector('#addMemberModal [name="religion"]').value = 'Roman Catholic';
    
    // Family
    document.querySelector('#addMemberModal [name="father_fname"]').value = 'Pedro';
    document.querySelector('#addMemberModal [name="father_mname"]').value = 'D';
    document.querySelector('#addMemberModal [name="father_lname"]').value = 'Dela Cruz';
    document.querySelector('#addMemberModal [name="mother_fname"]').value = 'Maria';
    document.querySelector('#addMemberModal [name="mother_mname"]').value = 'S';
    document.querySelector('#addMemberModal [name="mother_lname"]').value = 'Santos';
    document.querySelector('#addMemberModal [name="spouse_fname"]').value = 'Juana';
    document.querySelector('#addMemberModal [name="spouse_mname"]').value = 'R';
    document.querySelector('#addMemberModal [name="spouse_lname"]').value = 'Dela Cruz';
    document.querySelector('#addMemberModal [name="spouse_age"]').value = '30';
    
    // Children
    document.querySelector('#addMemberModal [name="child1_fname"]').value = 'Jose';
    document.querySelector('#addMemberModal [name="child1_mname"]').value = 'J';
    document.querySelector('#addMemberModal [name="child1_lname"]').value = 'Dela Cruz';
    document.querySelector('#addMemberModal [name="child1_age"]').value = '5';
    document.querySelector('#addMemberModal [name="child2_fname"]').value = 'Maria';
    document.querySelector('#addMemberModal [name="child2_mname"]').value = 'J';
    document.querySelector('#addMemberModal [name="child2_lname"]').value = 'Dela Cruz';
    document.querySelector('#addMemberModal [name="child2_age"]').value = '3';
    
    // References
    document.querySelector('#addMemberModal [name="ref1_name"]').value = 'Jose Rizal';
    document.querySelector('#addMemberModal [name="ref1_contact"]').value = '09221112233';
    document.querySelector('#addMemberModal [name="ref2_name"]').value = 'Andres Bonifacio';
    document.querySelector('#addMemberModal [name="ref2_contact"]').value = '09332223344';
    
    // Beneficiary
    document.querySelector('#addMemberModal [name="beneficiary_name"]').value = 'Juana Dela Cruz';
    document.querySelector('#addMemberModal [name="beneficiary_address"]').value = 'Palayan City';
    document.querySelector('#addMemberModal [name="beneficiary_relation"]').value = 'Spouse';
    document.querySelector('#addMemberModal [name="beneficiary_age"]').value = '30';
    document.querySelector('#addMemberModal [name="beneficiary_contact"]').value = '09123456789';
    
    // Chapter
    document.querySelector('#addMemberModal [name="chapter"]').value = 'GUIMBA';
    document.querySelector('#addMemberModal [name="group_name"]').value = 'Group A';
    document.querySelector('#addMemberModal [name="leader"]').value = 'John Doe';
    document.querySelector('#addMemberModal [name="coordinator"]').value = 'Jane Smith';
    document.querySelector('#addMemberModal [name="chairman"]').value = 'Mike Johnson';
    document.querySelector('#addMemberModal [name="screening_officer"]').value = 'Sarah Wilson';
    document.querySelector('#addMemberModal [name="screening_date"]').value = new Date().toISOString().split('T')[0];
    document.querySelector('#addMemberModal [name="approved_by"]').value = 'Admin';
    
    // Account Info
    document.querySelector('#addMemberModal [name="username"]').value = 'juan_' + randomCode;
    document.querySelector('#addMemberModal [name="password"]').value = 'password123';
    document.querySelector('#addMemberModal [name="confirm_password"]').value = 'password123';
    
    // Documents
    document.querySelector('#addMemberModal [name="medical_certificate"]').checked = true;
    document.querySelector('#addMemberModal [name="birth_certificate"]').checked = true;
    
    if (successToast) {
        document.getElementById('successMessage').textContent = 'Sample data filled!';
        successToast.show();
    }
}

// Show inactive modal
function showInactiveModal(element) {
    const code = $(element).data('code');
    const name = $(element).data('name');
    
    $('#inactive_member_code').val(code);
    $('#inactive_member_name').text(name);
    
    $('#inactive_reason').val('');
    $('#inactive_other_reason').val('');
    $('#inactive_notes').val('');
    $('#other_reason_container').hide();
    
    $('#inactive_submit_btn').text('Set Inactive').prop('disabled', false);
    $('#inactive_reason').prop('required', true).prop('disabled', false);
    $('#inactive_notes').prop('disabled', false);
}

// Show deceased modal
function showDeceasedModal(element) {
    const code = $(element).data('code');
    const name = $(element).data('name');
    
    $('#deceased_member_code').val(code);
    $('#deceased_member_name').text(name);
    
    $('#death_date').val('');
    $('#cause_of_death').val('');
    $('#death_certificate').val('');
    $('#place_of_death').val('');
    $('#burial_place').val('');
    $('#burial_date').val('');
    $('#death_notes').val('');
}

// Multiple delete functionality
let selectedCount = 0;

function updateSelectedCount() {
    selectedCount = $('.member-checkbox:checked').length;
    $('#selectedCount').text(selectedCount);
    
    if (selectedCount > 0) {
        $('#deleteSelectedDiv').show();
    } else {
        $('#deleteSelectedDiv').hide();
    }
    
    const totalCheckboxes = $('.member-checkbox').length;
    const checkedCheckboxes = $('.member-checkbox:checked').length;
    
    if (totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes) {
        $('#selectAllCheckbox').prop('checked', true);
    } else {
        $('#selectAllCheckbox').prop('checked', false);
    }
}

function confirmMultipleDelete() {
    const count = selectedCount;
    if (count === 0) return;
    
    if (confirm(`Are you sure you want to delete ${count} selected member(s)?\n\nThey will be moved to deleted history and can be recovered within 50 days.`)) {
        $('#multipleDeleteForm').submit();
    }
}

// Document Ready Event
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const success = urlParams.get('success');
    
    if (success === 'added' && successToast) {
        document.getElementById('successMessage').textContent = 'Member added successfully!';
        successToast.show();
    } else if (success === 'updated' && successToast) {
        document.getElementById('successMessage').textContent = 'Member updated successfully!';
        successToast.show();
    } else if (success === 'deleted' && warningToast) {
        document.getElementById('warningMessage').textContent = 'Member moved to deleted history.';
        warningToast.show();
    } else if (success === 'deleted_multiple' && warningToast) {
        document.getElementById('warningMessage').textContent = 'Selected members moved to deleted history.';
        warningToast.show();
    } else if (success === 'inactive' && warningToast) {
        document.getElementById('warningMessage').textContent = 'Member set as inactive successfully.';
        warningToast.show();
    } else if (success === 'reactivated' && successToast) {
        document.getElementById('successMessage').textContent = 'Member reactivated successfully!';
        successToast.show();
    } else if (success === 'recovered' && successToast) {
        document.getElementById('successMessage').textContent = 'Member recovered successfully!';
        successToast.show();
    } else if (success === 'reverted' && successToast) {
        document.getElementById('successMessage').textContent = 'Member data reverted successfully!';
        successToast.show();
    } else if (success === 'undeath' && successToast) {
        document.getElementById('successMessage').textContent = 'Death record undone. Member reactivated!';
        successToast.show();
    }
    
    if (urlParams.has('success')) {
        urlParams.delete('success');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, document.title, newUrl);
    }
    
    if (window.errorMessage && errorToast) {
        document.getElementById('errorMessage').textContent = window.errorMessage;
        errorToast.show();
    }
    
    updateCities('add');
    updateCities('edit');
    
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', function() {
            const memberCode = this.dataset.memberCode;
            if (memberCode) {
                viewMemberDetails(memberCode);
            }
        });
    });
    
    $('#selectAllCheckbox').on('change', function() {
        $('.member-checkbox').prop('checked', $(this).is(':checked'));
        updateSelectedCount();
    });
    
    $('.member-checkbox').on('change', function() {
        updateSelectedCount();
    });
    
    updateSelectedCount();
    $('.table-responsive').css('transition', 'all 0.3s ease');
});

// Other event handlers
$(document).on('change', '#inactive_reason', function() {
    if ($(this).val() === 'Other') {
        $('#other_reason_container').show();
        $('#inactive_other_reason').prop('required', true);
    } else {
        $('#other_reason_container').hide();
        $('#inactive_other_reason').prop('required', false);
    }
});

// Delete member handler (single)
$(document).on('click', '.delete-btn', function(event) {
    event.stopPropagation();
    $('#delete_member_code').val($(this).data('code'));
    $('#delete_member_name').text($(this).data('name'));
});

// Form validation for add member
document.getElementById('addMemberForm')?.addEventListener('submit', function(e) {
    const memberCode = this.querySelector('[name="member_code"]').value;
    const firstName = this.querySelector('[name="first_name"]').value;
    const lastName = this.querySelector('[name="last_name"]').value;
    const contact = this.querySelector('[name="contact_number"]').value;
    const username = this.querySelector('[name="username"]').value;
    const password = this.querySelector('[name="password"]').value;
    const confirmPassword = this.querySelector('[name="confirm_password"]').value;
    
    if (!memberCode || !firstName || !lastName || !contact) {
        e.preventDefault();
        document.getElementById('errorMessage').textContent = 'Please fill in all required fields (Member Code, Last Name, First Name, Contact Number).';
        if (errorToast) errorToast.show();
        return;
    }
    
    if (username && password && password !== confirmPassword) {
        e.preventDefault();
        document.getElementById('errorMessage').textContent = 'Passwords do not match.';
        if (errorToast) errorToast.show();
        return;
    }
    
    if (username && password && password.length < 6) {
        e.preventDefault();
        document.getElementById('errorMessage').textContent = 'Password must be at least 6 characters.';
        if (errorToast) errorToast.show();
        return;
    }
});

// Form validation for edit member
document.getElementById('editMemberForm')?.addEventListener('submit', function(e) {
    const firstName = this.querySelector('[name="first_name"]').value;
    const lastName = this.querySelector('[name="last_name"]').value;
    const contact = this.querySelector('[name="contact_number"]').value;
    
    if (!firstName || !lastName || !contact) {
        e.preventDefault();
        document.getElementById('errorMessage').textContent = 'Please fill in all required fields (Last Name, First Name, Contact Number).';
        if (errorToast) errorToast.show();
    }
});

// Deceased form submission
document.getElementById('deceasedMemberForm')?.addEventListener('submit', function(e) {
    const deathDate = this.querySelector('[name="death_date"]').value;
    if (!deathDate) {
        e.preventDefault();
        document.getElementById('errorMessage').textContent = 'Please enter the date of death.';
        if (errorToast) errorToast.show();
    }
});
// Edit member handler - AJAX VERSION (BEST PRACTICE)
$(document).on('click', '.edit-btn', function(event) {
    event.preventDefault();
    event.stopPropagation();
    
  let memberId = jQuery(this).data('id');
    
    if (!memberId) {
        console.error('No member ID found');
        return;
    }
    
    console.log('Fetching member data for ID:', memberId);
    
    // Show loading indicator (optional)
    $('#edit_first_name').val('Loading...');
    
    $.ajax({
        url: 'get_member.php',
        type: 'POST',
        data: { id: memberId },
        dataType: 'json',
        success: function(memberData) {
            console.log('Member data received:', memberData);
            
            if (memberData.error) {
                console.error('Error:', memberData.error);
                alert('Error loading member data: ' + memberData.error);
                return;
            }
            
            // Populate ALL form fields
            // Basic info
            $('#edit_member_code').val(memberData.member_code || '');
            $('#edit_member_code_display').val(memberData.member_code || '');
            $('#edit_first_name').val(memberData.first_name || '');
            $('#edit_last_name').val(memberData.last_name || '');
            $('#edit_middle_name').val(memberData.middle_name || '');
            
            // Address
            $('#edit_province').val(memberData.province || 'Occidental Mindoro');
            updateCities('edit');
            setTimeout(function() {
                $('#edit_city').val(memberData.city || '');
            }, 300);
            $('#edit_barangay').val(memberData.barangay || '');
            $('#edit_street').val(memberData.street || '');
            $('#edit_present_address').val(memberData.present_address || '');
            $('#edit_permanent_address').val(memberData.permanent_address || '');
            
            // Contact
            $('#edit_contact').val(memberData.contact_number || '');
            $('#edit_alternate').val(memberData.alternate_number || '');
            $('#edit_email').val(memberData.email || '');
            
            // Personal
            $('#edit_birth_date').val(memberData.birth_date || '');
            $('#edit_place_of_birth').val(memberData.place_of_birth || '');
            $('#edit_age').val(memberData.age || '');
            $('#edit_gender').val(memberData.gender || '');
            $('#edit_civil_status').val(memberData.civil_status || '');
            $('#edit_religion').val(memberData.religion || '');
            
            // Family - Father
            $('#edit_father_fname').val(memberData.father_fname || '');
            $('#edit_father_mname').val(memberData.father_mname || '');
            $('#edit_father_lname').val(memberData.father_lname || '');
            
            // Family - Mother
            $('#edit_mother_fname').val(memberData.mother_fname || '');
            $('#edit_mother_mname').val(memberData.mother_mname || '');
            $('#edit_mother_lname').val(memberData.mother_lname || '');
            
            // Spouse
            $('#edit_spouse_fname').val(memberData.spouse_fname || '');
            $('#edit_spouse_mname').val(memberData.spouse_mname || '');
            $('#edit_spouse_lname').val(memberData.spouse_lname || '');
            $('#edit_spouse_age').val(memberData.spouse_age || '');
            
            // Children
            $('#edit_child1_fname').val(memberData.child1_fname || '');
            $('#edit_child1_mname').val(memberData.child1_mname || '');
            $('#edit_child1_lname').val(memberData.child1_lname || '');
            $('#edit_child1_age').val(memberData.child1_age || '');
            
            $('#edit_child2_fname').val(memberData.child2_fname || '');
            $('#edit_child2_mname').val(memberData.child2_mname || '');
            $('#edit_child2_lname').val(memberData.child2_lname || '');
            $('#edit_child2_age').val(memberData.child2_age || '');
            
            $('#edit_child3_fname').val(memberData.child3_fname || '');
            $('#edit_child3_mname').val(memberData.child3_mname || '');
            $('#edit_child3_lname').val(memberData.child3_lname || '');
            $('#edit_child3_age').val(memberData.child3_age || '');
            
            $('#edit_child4_fname').val(memberData.child4_fname || '');
            $('#edit_child4_mname').val(memberData.child4_mname || '');
            $('#edit_child4_lname').val(memberData.child4_lname || '');
            $('#edit_child4_age').val(memberData.child4_age || '');
            
            // References
            $('#edit_ref1_name').val(memberData.ref1_name || '');
            $('#edit_ref1_contact').val(memberData.ref1_contact || '');
            $('#edit_ref2_name').val(memberData.ref2_name || '');
            $('#edit_ref2_contact').val(memberData.ref2_contact || '');
            
            // Chapter info
            $('#edit_chapter').val(memberData.chapter || '');
            $('#edit_group_name').val(memberData.group_name || '');
            $('#edit_leader').val(memberData.leader || '');
            $('#edit_coordinator').val(memberData.coordinator || '');
            $('#edit_chairman').val(memberData.chairman || '');
            $('#edit_screening_officer').val(memberData.screening_officer || '');
            $('#edit_screening_date').val(memberData.screening_date || '');
            $('#edit_approved_by').val(memberData.approved_by || '');
            $('#edit_date_joined').val(memberData.date_joined || '');
            $('#edit_date_registered').val(memberData.date_registered || '');
            $('#edit_monthly').val(memberData.monthly_contribution || '100.00');
            $('#edit_status').val(memberData.status || 'active');
            
            // Beneficiary
            $('#edit_beneficiary_name').val(memberData.beneficiary_name || '');
            $('#edit_beneficiary_address').val(memberData.beneficiary_address || '');
            $('#edit_beneficiary_relation').val(memberData.beneficiary_relation || '');
            $('#edit_beneficiary_age').val(memberData.beneficiary_age || '');
            $('#edit_beneficiary_contact').val(memberData.beneficiary_contact || '');
            
            // Account Info
            $('#edit_username').val(memberData.username || '');
            
            // Documents
            $('#edit_medical_certificate').prop('checked', memberData.medical_certificate == 1);
            $('#edit_birth_certificate').prop('checked', memberData.birth_certificate == 1);
            
            console.log('Fields populated, showing modal');
            
            // Show the modal
            $('#editMemberModal').modal('show');
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            alert('Error loading member data. Please try again.');
        }
    });
});
// ============================================
// DELETE FUNCTIONALITY - COMPLETE FIX
// ============================================

// Function to show delete modal
window.showDeleteModal = function(memberCode, memberName) {
    console.log('Delete function called for:', memberCode, memberName);
    
    if (!memberCode) {
        alert('Error: Invalid member code');
        return false;
    }
    
    try {
        // Set values in the modal
        document.getElementById('delete_member_code').value = memberCode;
        document.getElementById('delete_member_name').innerHTML = memberName;
        
        // Show the modal using Bootstrap 5
        const deleteModalElement = document.getElementById('deleteMemberModal');
        const deleteModal = new bootstrap.Modal(deleteModalElement);
        deleteModal.show();
    } catch (error) {
        console.error('Error showing modal:', error);
        alert('Error showing delete confirmation. Please try again.');
    }
    
    return false;
};

// Handle single delete form submission when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setupDeleteFormHandler();
    });
} else {
    setupDeleteFormHandler();
}

function setupDeleteFormHandler() {
    const deleteForm = document.getElementById('singleDeleteForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function(e) {
            const memberName = document.getElementById('delete_member_name').innerText;
            const confirmMsg = '⚠️ Are you ABSOLUTELY sure you want to delete "' + memberName + '"?\n\nThis action cannot be undone immediately.';
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
            return true;
        });
    }
}


// ============================================================
// DOCUMENT VIEWER (fallback path)
// ============================================================
// NOTE: The primary openDocumentViewer() lives in the inline
// <script> of members.php. This block provides the SAME function
// in the external file so both paths work if the inline script
// is ever removed. Guarded so we don't clobber the inline one.
// ============================================================
if (typeof window.openDocumentViewer !== 'function') {
    window.openDocumentViewer = function(memberCode, docType, title) {
        if (!memberCode || !docType) {
            alert('Missing document reference.');
            return;
        }

        const modalEl = document.getElementById('documentViewerModal');
        const titleEl = document.getElementById('docViewerTitle');
        const bodyEl  = document.getElementById('docViewerBody');
        const dlBtn   = document.getElementById('docViewerDownload');
        const tabBtn  = document.getElementById('docViewerOpenTab');

        if (!modalEl || !bodyEl) {
            alert('Document viewer not available. Please refresh the page.');
            return;
        }

        const url = 'view_document.php'
                  + '?member_code=' + encodeURIComponent(memberCode)
                  + '&doc_type='    + encodeURIComponent(docType);

        titleEl.textContent = title || 'Document';
        bodyEl.innerHTML = `
            <div class="text-center p-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-3 text-muted">Loading document...</p>
            </div>
        `;

        dlBtn.href  = url;
        dlBtn.setAttribute('download', (title || 'document'));
        tabBtn.href = url;

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        fetch(url, { method: 'GET', credentials: 'same-origin' })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(msg => {
                        throw new Error(msg || ('HTTP ' + response.status));
                    });
                }
                const ct = (response.headers.get('Content-Type') || '').toLowerCase();

                if (ct.indexOf('application/pdf') !== -1) {
                    bodyEl.innerHTML = `
                        <iframe src="${url}"
                                style="width:100%; height:78vh; border:0; display:block;"
                                title="${title}"></iframe>
                    `;
                } else if (ct.indexOf('image/') === 0) {
                    bodyEl.innerHTML = `
                        <div style="padding:20px;">
                            <img src="${url}" alt="${title}"
                                 style="max-width:100%; max-height:75vh; border-radius:8px;
                                        box-shadow:0 2px 12px rgba(0,0,0,0.1); background:#fff;">
                        </div>
                    `;
                } else {
                    bodyEl.innerHTML = `
                        <div class="alert alert-info m-4">
                            <i class="fas fa-info-circle me-2"></i>
                            This file type can't be previewed inline.
                            Use <strong>Download</strong> or <strong>Open in New Tab</strong> below.
                        </div>
                    `;
                }
            })
            .catch(err => {
                const msg = (err && err.message) ? err.message : 'Unable to load document.';
                bodyEl.innerHTML = `
                    <div class="alert alert-danger m-4 text-start">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Document unavailable.</strong><br>
                        <small class="text-muted">${msg}</small>
                    </div>
                `;
            });
    };
}

// ============================================================
// EDIT HISTORY — VIEW DETAILS WITH SELECTIVE REVERT
// ============================================================
function viewEditDetails(logId, memberCode) {
    const modalEl = document.getElementById('editDetailsModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    const content = document.getElementById('editDetailsContent');
    const title = document.getElementById('editDetailsTitle');
    const revertBtn = document.getElementById('revertFromDetailsBtn');

    content.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-info" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3">Loading edit details...</p>
        </div>
    `;
    revertBtn.style.display = 'none';
    title.textContent = 'Edit Details - ' + memberCode;

    modal.show();

    fetch('members.php?get_edit_details=1&log_id=' + logId)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `
                    <div class="alert alert-danger m-3">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading edit details: ${data.error || 'Unknown error'}
                    </div>
                `;
                return;
            }

            let html = '';
            const actionType = data.log.action_type || 'edit';
            const adminName = data.log.admin_name || 'System';
            const createdAt = new Date(data.log.created_at).toLocaleString();

            html += `
                <div class="edit-details-header mb-4">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Member:</strong> ${memberCode}
                            <br><span class="badge bg-secondary">${memberCode}</span>
                        </div>
                        <div class="col-md-4">
                            <strong>Action:</strong>
                            <span class="badge bg-${actionType == 'edit' ? 'info' : (actionType == 'revert' ? 'warning' : 'success')}">
                                ${actionType.toUpperCase()}
                            </span>
                            <br><strong>By:</strong> ${adminName}
                        </div>
                        <div class="col-md-4 text-end">
                            <strong>Date:</strong> ${createdAt}
                        </div>
                    </div>
                </div>
            `;

            const changes = data.changes || {};

            // ============================================================
            // SPECIAL CASE: Revert Receipt
            // ============================================================
            if (actionType === 'revert' && changes.reverted_fields) {
                const revertedFields = changes.reverted_fields;
                const fieldKeys = Object.keys(revertedFields);

                html += `
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-receipt me-2"></i>
                        <strong>REVERT RECEIPT</strong>
                        ${changes.partial_revert ? '<span class="badge bg-info text-dark ms-2">Partial</span>' : ''}
                        <hr class="my-2">
                        <div class="row small">
                            <div class="col-md-6">
                                <strong>Original Edit By:</strong> ${changes.original_admin || 'System'}<br>
                                <strong>Original Date:</strong> ${new Date(changes.original_date).toLocaleString()}<br>
                                <strong>Original Log ID:</strong> #${changes.reverted_log_id}
                            </div>
                            <div class="col-md-6">
                                <strong>Reverted By:</strong> ${changes.reverted_by}<br>
                                <strong>Reverted At:</strong> ${new Date(changes.reverted_at).toLocaleString()}
                            </div>
                        </div>
                    </div>
                `;

                if (fieldKeys.length === 0) {
                    html += `<div class="alert alert-info">No fields were reverted.</div>`;
                } else {
                    html += `
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Field</th>
                                        <th style="width: 35%;">Value Before Revert</th>
                                        <th style="width: 40%;">Value After Revert (Restored)</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                    fieldKeys.forEach(key => {
                        const fromVal = revertedFields[key].from || '';
                        const toVal = revertedFields[key].to || '';
                        const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                        html += `
                            <tr class="table-warning">
                                <td><strong>${displayKey}</strong></td>
                                <td class="text-danger">${fromVal || '<span class="text-muted">(empty)</span>'}</td>
                                <td class="text-success">${toVal || '<span class="text-muted">(empty)</span>'}</td>
                            </tr>
                        `;
                    });

                    html += `</tbody></table></div>`;
                }

                content.innerHTML = html;
                return;
            }

            // ============================================================
            // Regular edit/add log display WITH SELECTION CHECKBOXES
            // ============================================================
            const changeKeys = Object.keys(changes);

            if (changeKeys.length === 0) {
                html += `<div class="alert alert-info">No field changes recorded for this edit.</div>`;
            } else {
                html += `
                    <div class="alert alert-info mb-2 py-2">
                        <i class="fas fa-info-circle me-1"></i>
                        <small>Check specific fields you want to revert, then click <strong>Revert Selected Fields</strong>. If nothing is checked, all changed fields will be reverted.</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="editDetailsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 5%;" class="text-center">
                                        <input type="checkbox" id="selectAllChanges" title="Select all" checked>
                                    </th>
                                    <th style="width: 20%;">Field</th>
                                    <th style="width: 32%;">Old Value</th>
                                    <th style="width: 32%;">New Value</th>
                                    <th style="width: 11%;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                changeKeys.forEach(key => {
                    const oldValue = changes[key].old || '';
                    const newValue = changes[key].new || '';
                    const isChanged = oldValue !== newValue;
                    const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                    html += `
                        <tr class="${isChanged ? 'table-warning' : ''}">
                            <td class="text-center">
                                <input type="checkbox"
                                       class="change-checkbox"
                                       value="${key}"
                                       data-field="${key}"
                                       ${isChanged ? 'checked' : 'disabled'}>
                            </td>
                            <td><strong>${displayKey}</strong></td>
                            <td class="${isChanged ? 'text-danger' : ''}">
                                ${oldValue || '<span class="text-muted">(empty)</span>'}
                            </td>
                            <td class="${isChanged ? 'text-success' : ''}">
                                ${newValue || '<span class="text-muted">(empty)</span>'}
                            </td>
                            <td class="text-center">
                                ${isChanged ? '<span class="badge bg-warning text-dark">Changed</span>' : '<span class="badge bg-secondary">Same</span>'}
                            </td>
                        </tr>
                    `;
                });

                html += `
                            </tbody>
                        </table>
                    </div>
                `;
            }

            if (actionType === 'edit' && changeKeys.length > 0) {
                revertBtn.style.display = 'inline-block';
                revertBtn.innerHTML = '<i class="fas fa-undo me-1"></i> Revert Selected Fields';
                revertBtn.onclick = function() {
                    const checked = [];
                    document.querySelectorAll('#editDetailsContent .change-checkbox:checked').forEach(cb => {
                        checked.push(cb.value);
                    });
                    modal.hide();
                    setTimeout(() => {
                        confirmRevert(logId, memberCode, checked);
                    }, 300);
                };
            } else {
                revertBtn.style.display = 'none';
            }

            content.innerHTML = html;

            // Wire up select-all checkbox
            const selectAll = document.getElementById('selectAllChanges');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('#editDetailsContent .change-checkbox:not(:disabled)').forEach(cb => {
                        cb.checked = selectAll.checked;
                    });
                });
                document.querySelectorAll('#editDetailsContent .change-checkbox').forEach(cb => {
                    cb.addEventListener('change', function() {
                        const all = document.querySelectorAll('#editDetailsContent .change-checkbox:not(:disabled)');
                        const allChecked = Array.from(all).every(x => x.checked);
                        if (selectAll) selectAll.checked = allChecked;
                    });
                });
            }
        })
        .catch(error => {
            content.innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error loading edit details. Please try again.
                </div>
            `;
        });
}

// ============================================================
// CONFIRM REVERT — with selective fields
// ============================================================
function confirmRevert(logId, memberCode, selectedFields) {
    selectedFields = selectedFields || [];
    const isPartial = selectedFields.length > 0;

    const modalEl = document.getElementById('revertConfirmModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    const memberNameSpan = document.getElementById('revertMemberName');
    const changesList = document.getElementById('revertChangesList');
    const logIdInput = document.getElementById('revertLogId');
    const selectedContainer = document.getElementById('revertSelectedFieldsContainer');
    const banner = document.getElementById('revertBannerText');

    memberNameSpan.textContent = memberCode;
    logIdInput.value = logId;

    // Inject hidden inputs for selected fields
    if (selectedContainer) {
        selectedContainer.innerHTML = '';
        selectedFields.forEach(f => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'selected_fields[]';
            inp.value = f;
            selectedContainer.appendChild(inp);
        });
    }

    if (banner) {
        banner.innerHTML = '<i class="fas fa-info-circle me-1"></i> Loading...';
    }
    changesList.innerHTML = '<li class="text-muted">Loading changes...</li>';

    modal.show();

    fetch('members.php?get_edit_details=1&log_id=' + logId)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                changesList.innerHTML = `<li class="text-danger">Error loading changes: ${data.error || 'Unknown error'}</li>`;
                return;
            }

            const changes = data.changes || {};
            const changeKeys = Object.keys(changes);

            const fieldsToShow = isPartial
                ? changeKeys.filter(k => selectedFields.includes(k))
                : changeKeys;

            if (banner) {
                banner.innerHTML = isPartial
                    ? `<i class="fas fa-info-circle me-1"></i> <strong>Partial revert:</strong> Only the ${fieldsToShow.length} selected field(s) will be reverted. Other changes will remain.`
                    : `<i class="fas fa-info-circle me-1"></i> <strong>Full revert:</strong> All ${fieldsToShow.length} changed field(s) will be reverted.`;
            }

            if (fieldsToShow.length === 0) {
                changesList.innerHTML = '<li class="text-muted">No changes to revert</li>';
            } else {
                changesList.innerHTML = '';
                fieldsToShow.forEach(key => {
                    const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    const oldValue = changes[key].old || '(empty)';
                    const newValue = changes[key].new || '(empty)';

                    const li = document.createElement('li');
                    li.className = 'revert-change-item';
                    li.innerHTML = `
                        <span class="badge bg-light text-dark me-2">${displayKey}</span>
                        <span class="text-danger text-decoration-line-through me-2">${oldValue}</span>
                        <i class="fas fa-arrow-right text-muted me-2"></i>
                        <span class="text-success">${newValue}</span>
                    `;
                    changesList.appendChild(li);
                });
            }
        })
        .catch(error => {
            changesList.innerHTML = `<li class="text-danger">Error loading changes: ${error.message}</li>`;
        });
}