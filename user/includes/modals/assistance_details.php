<?php
// user/includes/modals/assistance_details.php
?>
<!-- Burial Assistance Details Modal -->
<div class="modal fade" id="burialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-heartbeat me-2"></i>Death & Burial Benefits</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6>Eligibility:</h6>
                <ul>
                    <li>Active member for at least 6 months</li>
                    <li>Up-to-date with monthly contributions</li>
                </ul>
                
                <h6 class="mt-3">Benefits:</h6>
                <ul>
                    <li>Burial assistance: ₱10,000</li>
                    <li>Financial support for family: ₱5,000</li>
                    <li>Condolence assistance: ₱2,000</li>
                </ul>
                
                <h6 class="mt-3">Required Documents:</h6>
                <ul>
                    <li>Death Certificate (original)</li>
                    <li>Member's Certificate of Membership</li>
                    <li>Valid ID of claimant</li>
                    <li>Funeral contract or receipt</li>
                </ul>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-clock me-2"></i>
                    Processing time: 24-48 hours for emergency claims
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#assistanceRequestModal">
                    Request Assistance
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Educational Support Details Modal -->
<div class="modal fade" id="educationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i>Educational Support</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6>Scholarship Program:</h6>
                <ul>
                    <li>For children of active members</li>
                    <li>Must have grade average of 90% or higher</li>
                    <li>Annual scholarship: ₱5,000</li>
                </ul>
                
                <h6 class="mt-3">Educational Assistance:</h6>
                <ul>
                    <li>For school supplies and materials</li>
                    <li>Up to ₱2,000 per school year</li>
                    <li>Available for elementary to college level</li>
                </ul>
                
                <h6 class="mt-3">Requirements:</h6>
                <ul>
                    <li>Certificate of Enrollment</li>
                    <li>Report Card or Grades</li>
                    <li>School ID of student</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#assistanceRequestModal">
                    Apply Now
                </button>
            </div>
        </div>
    </div>
</div>