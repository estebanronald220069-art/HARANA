<?php
// user/includes/modals/assistance_request.php
?>
<!-- Assistance Request Modal -->
<div class="modal fade" id="assistanceRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Request Assistance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="request_assistance.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Type of Assistance</label>
                        <select class="form-select" name="assistance_type" required>
                            <option value="">Select type</option>
                            <option value="emergency">Emergency Financial Assistance</option>
                            <option value="medical">Medical/Hospitalization</option>
                            <option value="educational">Educational Support</option>
                            <option value="livelihood">Livelihood Assistance</option>
                            <option value="burial">Burial Assistance</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Amount Requested (₱)</label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purpose/Reason</label>
                        <textarea class="form-control" name="purpose" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Supporting Documents</label>
                        <input type="file" class="form-control" name="documents" multiple>
                        <small class="text-muted">Upload any supporting documents (medical certificate, estimate, etc.)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>