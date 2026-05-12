// admin/members/members.js

// Sidebar Toggle Functionality
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar-wrapper');
const headerLogo = document.getElementById('headerLogo');

// Check if sidebar state is saved in localStorage
const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

// Apply saved state on page load
if (sidebarCollapsed) {
    sidebar.classList.add('collapsed');
}

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
    
    const modal = new bootstrap.Modal(modalEl);
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
                        <h4>Documents Attached:</h4>
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

// Also handle the multiple delete
window.confirmMultipleDelete = function() {
    const selectedCount = $('.member-checkbox:checked').length;
    if (selectedCount === 0) {
        alert('Please select at least one member to delete.');
        return false;
    }
    return confirm('⚠️ Are you sure you want to delete ' + selectedCount + ' selected member(s)?\n\nThey will be moved to deleted history and can be recovered within 50 days.');
};