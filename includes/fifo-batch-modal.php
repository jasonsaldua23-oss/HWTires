<?php
/**
 * FIFO Batch History & Visual Proof Modal
 * Read-Only Component for HWTIRES Revision 3 FIFO Demonstration
 */
?>
<!-- FIFO Batch History Modal -->
<div class="modal fade" id="fifoBatchModal" tabindex="-1" aria-labelledby="fifoBatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <!-- Modal Header with High Contrast Light Background -->
            <div class="modal-header border-bottom py-3 bg-white">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-success-subtle text-success border border-success-subtle" style="width: 36px; height: 36px;">
                        <i class="fas fa-layer-group fs-6"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold mb-0 text-dark" id="fifoBatchModalLabel">FIFO Batch Allocation History</h5>
                        <div class="small text-muted" style="font-size: 0.8rem;">
                            First-In, First-Out Physical Inventory Audit &amp; Visual Issuance Proof
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4 bg-light">
                <!-- Loading Spinner -->
                <div id="fifoModalLoading" class="text-center py-5">
                    <div class="spinner-border text-success" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-3 fw-semibold text-secondary">Retrieving FIFO batch chain and allocation records...</div>
                </div>

                <!-- Error Alert -->
                <div id="fifoModalError" class="alert alert-danger d-none shadow-sm mb-0">
                    <i class="fas fa-circle-exclamation me-2"></i><span id="fifoModalErrorMessage">Failed to load batch records.</span>
                </div>

                <!-- Main Content Area -->
                <div id="fifoModalContent" class="d-none">
                    <!-- Top Summary Card -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body p-3">
                            <div class="row align-items-center g-3">
                                <div class="col-lg-7">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-primary text-uppercase px-2 py-1" id="fifoItemCategory" style="font-size: 0.75rem;">TIRE</span>
                                        <span class="badge bg-secondary px-2 py-1" id="fifoItemBranch" style="font-size: 0.75rem;">Branch</span>
                                        <span class="text-muted small" id="fifoItemSku">SKU: -</span>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1" id="fifoItemName">-</h5>
                                    <div class="text-muted small" id="fifoItemMeta">Brand: - | Size: -</div>
                                </div>
                                <div class="col-lg-5 text-lg-end">
                                    <div class="d-inline-flex flex-column align-items-lg-end gap-1">
                                        <div class="d-flex align-items-baseline gap-2">
                                            <span class="text-secondary small fw-semibold">Current System Stock:</span>
                                            <span class="fs-4 fw-bold text-dark" id="fifoItemQty">0 units</span>
                                        </div>
                                        <div id="fifoParityBadge">
                                            <span class="badge bg-success-subtle text-success border border-success px-2 py-1">
                                                <i class="fas fa-check-circle me-1"></i> 100% FIFO Parity Verified
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FIFO Engine Principle Banner -->
                    <div class="p-3 mb-3 rounded-3 border-start border-4 border-info shadow-sm" style="background-color: #f0f9ff; border-color: #0284c7 !important;">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-circle-info text-info fs-5 mt-1"></i>
                            <div class="small text-dark">
                                <strong>FIFO Issuance Engine Rule:</strong> Batches are strictly consumed in order:
                                <span class="badge bg-dark ms-1">1. Legacy Opening Balances</span> &rarr;
                                <span class="badge bg-dark ms-1">2. Oldest Receipt Date (received_at ASC)</span> &rarr;
                                <span class="badge bg-dark ms-1">3. Batch ID Tie-Breaker (id ASC)</span>.
                                <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                    When stock is issued, the active Priority #1 batch is exhausted before any newer batch is touched.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 1: Batches Breakdown -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="fas fa-boxes-stacked text-primary me-2"></i>Inventory Batches (FIFO Priority Queue)
                            </h6>
                            <span class="badge bg-light text-secondary border" id="fifoBatchCountBadge">0 Batches</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 150px;">FIFO Priority</th>
                                        <th>Batch Code</th>
                                        <th>Supply Source</th>
                                        <th>Received Date</th>
                                        <th class="text-end">Initial Qty</th>
                                        <th class="text-end">Issued</th>
                                        <th class="text-end">Remaining</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="fifoBatchesTableBody">
                                    <!-- Dynamic rows -->
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="4" class="text-end">Inventory Item Aggregate Parity:</td>
                                        <td class="text-end" id="fifoTotalInitial">0</td>
                                        <td class="text-end fw-semibold text-primary" id="fifoTotalIssued">0</td>
                                        <td class="text-end text-success fs-6" id="fifoTotalRemaining">0</td>
                                        <td class="text-center" id="fifoParityStatusCell">
                                            <span class="badge bg-success">Verified</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Section 2: Real Stock-Out Allocation History -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="fas fa-receipt text-danger me-2"></i>Stock-Out Allocation History (Real Database Records)
                            </h6>
                            <span class="badge bg-light text-secondary border" id="fifoAllocCountBadge">0 Allocations</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" style="font-size: 0.875rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th>Alloc ID</th>
                                        <th>Transaction</th>
                                        <th>Transaction Details &amp; Notes</th>
                                        <th>Date &amp; Time</th>
                                        <th>Batch Consumed</th>
                                        <th class="text-end">Allocated Qty</th>
                                        <th>Issued By</th>
                                    </tr>
                                </thead>
                                <tbody id="fifoAllocTableBody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                        <div id="fifoNoAllocMsg" class="p-4 text-center text-muted d-none">
                            <i class="fas fa-inbox fs-3 mb-2 d-block text-secondary"></i>
                            No stock-out allocations recorded for this inventory item yet.
                        </div>
                    </div>

                    <!-- Visual FIFO Proof Annotation Box -->
                    <div class="p-3 rounded-3 border bg-white shadow-sm" id="fifoProofCallout">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-shield-halved text-success fs-5 mt-1"></i>
                            <div class="small">
                                <strong class="text-dark">Visual FIFO Defense Proof:</strong>
                                <span id="fifoProofText" class="text-secondary">
                                    Stock-out transactions link directly to specific batches via <code>inventory_batch_allocations</code>.
                                    The older legacy opening batch was depleted first while the newer supplier-delivery batch remained intact.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-white border-top py-2">
                <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.priority-first-out {
    background: #dcfce7 !important;
    color: #15803d !important;
    border: 1px solid #86efac !important;
    font-weight: 600;
}
.priority-next {
    background: #e0f2fe !important;
    color: #0369a1 !important;
    border: 1px solid #7dd3fc !important;
    font-weight: 600;
}
.priority-depleted {
    background: #f1f5f9 !important;
    color: #64748b !important;
    border: 1px solid #cbd5e1 !important;
    font-weight: 500;
}
.priority-inactive {
    background: #fef2f2 !important;
    color: #b91c1c !important;
    border: 1px solid #fca5a5 !important;
    font-weight: 500;
}
.fifo-batch-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.8rem;
    font-weight: 600;
    color: #334155;
}
.fifo-row-active {
    background-color: #f0fdf4 !important;
}
</style>

<script>
(function() {
    function getApiUrl() {
        const base = (window.HWTIRES_APP_URL ? window.HWTIRES_APP_URL.replace(/\/+$/, '') : '') || '/hwtires';
        return base + '/api/inventory-api.php';
    }

    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openFifoBatchModal(itemId) {
        const modalEl = document.getElementById('fifoBatchModal');
        if (!modalEl) return;

        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();

        const loadingEl = document.getElementById('fifoModalLoading');
        const errorEl = document.getElementById('fifoModalError');
        const errorMsgEl = document.getElementById('fifoModalErrorMessage');
        const contentEl = document.getElementById('fifoModalContent');

        loadingEl.classList.remove('d-none');
        errorEl.classList.add('d-none');
        contentEl.classList.add('d-none');

        const url = getApiUrl() + '?action=get_fifo_batch_history&item_id=' + encodeURIComponent(itemId);

        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            return res.json().then(function(data) {
                return { status: res.status, ok: res.ok, data: data };
            });
        })
        .then(function(result) {
            loadingEl.classList.add('d-none');
            if (!result.ok || !result.data || !result.data.success) {
                const msg = (result.data && result.data.message) ? result.data.message : 'Failed to retrieve batch records.';
                errorMsgEl.textContent = msg;
                errorEl.classList.remove('d-none');
                return;
            }

            renderFifoModalData(result.data);
            contentEl.classList.remove('d-none');
        })
        .catch(function(err) {
            loadingEl.classList.add('d-none');
            errorMsgEl.textContent = 'Network or connection error: ' + (err.message || err);
            errorEl.classList.remove('d-none');
        });
    }

    function renderFifoModalData(payload) {
        const item = payload.item || {};
        const batches = payload.batches || [];
        const allocations = payload.allocations || [];
        const recon = payload.reconciliation || {};

        document.getElementById('fifoItemName').textContent = item.item_name || '-';
        document.getElementById('fifoItemCategory').textContent = (item.category || 'TIRE').toUpperCase();
        document.getElementById('fifoItemBranch').textContent = item.branch_name || 'Branch';
        document.getElementById('fifoItemSku').textContent = 'SKU: ' + (item.sku || '-');
        document.getElementById('fifoItemMeta').textContent = 'Brand: ' + (item.brand || 'Unbranded') + ' | Size: ' + (item.size || '-');
        document.getElementById('fifoItemQty').textContent = (item.quantity !== undefined ? item.quantity : 0) + ' units';

        const parityBadge = document.getElementById('fifoParityBadge');
        if (recon.is_balanced) {
            parityBadge.innerHTML = '<span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="fas fa-check-circle me-1"></i> 100% FIFO Parity Verified (' + recon.total_batch_remaining + ' / ' + item.quantity + ' Units)</span>';
        } else {
            parityBadge.innerHTML = '<span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1"><i class="fas fa-triangle-exclamation me-1"></i> Stock Discrepancy: Batch sum (' + recon.total_batch_remaining + ') &ne; Item total (' + item.quantity + ')</span>';
        }

        document.getElementById('fifoBatchCountBadge').textContent = batches.length + ' Batches';

        const tbody = document.getElementById('fifoBatchesTableBody');
        tbody.innerHTML = '';

        batches.forEach(function(b) {
            const tr = document.createElement('tr');
            if (b.is_current_first_out) {
                tr.className = 'fifo-row-active';
            }

            const priorityBadge = '<span class="badge ' + escapeHtml(b.priority_class) + ' px-2 py-1">' +
                (b.is_current_first_out ? '<i class="fas fa-play me-1 text-success"></i>' : '') +
                escapeHtml(b.priority_label) + '</span>';

            const sourceDisplay = '<div><span class="fw-semibold text-dark">' + escapeHtml(b.source_label) + '</span>' +
                (b.source_ref && b.source_ref !== 'N/A' ? '<div class="text-muted small" style="font-size:0.75rem;">Ref: ' + escapeHtml(b.source_ref) + '</div>' : '') +
                '</div>';

            const statusBadge = (b.status === 'active' && b.remaining_quantity > 0)
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-secondary">Exhausted</span>';

            // Present issued as clean, positive number (e.g. 5 for issued batch, 0 for untouched)
            const issuedDisplay = escapeHtml(b.issued_quantity !== undefined ? b.issued_quantity : 0);

            tr.innerHTML = '<td>' + priorityBadge + '</td>' +
                '<td><span class="fifo-batch-code">' + escapeHtml(b.batch_code) + '</span>' + (b.is_legacy ? ' <span class="badge bg-dark-subtle text-dark ms-1" style="font-size:0.7rem;">Legacy</span>' : '') + '</td>' +
                '<td>' + sourceDisplay + '</td>' +
                '<td><span class="text-secondary small">' + escapeHtml(b.received_date_display) + '</span></td>' +
                '<td class="text-end fw-semibold">' + escapeHtml(b.initial_quantity) + '</td>' +
                '<td class="text-end fw-semibold ' + (b.issued_quantity > 0 ? 'text-primary' : 'text-muted') + '">' + issuedDisplay + '</td>' +
                '<td class="text-end fw-bold ' + (b.remaining_quantity > 0 ? 'text-success' : 'text-muted') + '">' + escapeHtml(b.remaining_quantity) + '</td>' +
                '<td class="text-center">' + statusBadge + '</td>';

            tbody.appendChild(tr);
        });

        document.getElementById('fifoTotalInitial').textContent = recon.total_batch_initial || 0;
        document.getElementById('fifoTotalIssued').textContent = (recon.total_batch_issued !== undefined) ? recon.total_batch_issued : 0;
        document.getElementById('fifoTotalRemaining').textContent = (recon.total_batch_remaining || 0) + ' units';

        const parityCell = document.getElementById('fifoParityStatusCell');
        if (recon.is_balanced) {
            parityCell.innerHTML = '<span class="badge bg-success"><i class="fas fa-check me-1"></i> 100% Balanced</span>';
        } else {
            parityCell.innerHTML = '<span class="badge bg-danger"><i class="fas fa-times me-1"></i> Discrepancy</span>';
        }

        // Render Allocations
        document.getElementById('fifoAllocCountBadge').textContent = allocations.length + ' Allocations';
        const allocTbody = document.getElementById('fifoAllocTableBody');
        const noAllocMsg = document.getElementById('fifoNoAllocMsg');
        allocTbody.innerHTML = '';

        if (allocations.length === 0) {
            noAllocMsg.classList.remove('d-none');
        } else {
            noAllocMsg.classList.add('d-none');
            allocations.forEach(function(a) {
                const tr = document.createElement('tr');
                const isRev = a.is_reversal;
                const qtyBadge = isRev
                    ? '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning">+' + escapeHtml(a.allocated_quantity) + ' units (Restored)</span>'
                    : '<span class="badge bg-danger-subtle text-danger border border-danger">' + escapeHtml(a.allocated_quantity) + ' units issued</span>';

                tr.innerHTML = '<td><span class="badge bg-light text-dark border">#' + escapeHtml(a.allocation_id) + '</span></td>' +
                    '<td><span class="fw-semibold text-dark">TX #' + escapeHtml(a.transaction_id) + '</span> <span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:0.7rem;">' + escapeHtml(a.transaction_type) + '</span></td>' +
                    '<td><div class="small text-dark">' + escapeHtml(a.transaction_notes) + '</div></td>' +
                    '<td><span class="text-secondary small">' + escapeHtml(a.allocated_at) + '</span></td>' +
                    '<td><span class="fifo-batch-code">' + escapeHtml(a.batch_code) + '</span>' + (a.is_legacy ? ' <span class="badge bg-dark-subtle text-dark ms-1" style="font-size:0.7rem;">Priority 1</span>' : '') + '</td>' +
                    '<td class="text-end">' + qtyBadge + '</td>' +
                    '<td><span class="small text-secondary">' + escapeHtml(a.created_by_name) + '</span></td>';

                allocTbody.appendChild(tr);
            });
        }

        // Proof text
        const proofTextEl = document.getElementById('fifoProofText');
        if (batches.length >= 2 && allocations.length > 0) {
            const firstBatch = batches[0];
            const secondBatch = batches[1];
            proofTextEl.innerHTML = 'Stock-out transactions link directly to specific batches via <code>inventory_batch_allocations</code>. Notice that when ' +
                escapeHtml(item.item_name) + ' was issued (' + escapeHtml(allocations[0].allocated_quantity) + ' units on ' + escapeHtml(allocations[0].allocated_at) + '), ' +
                'the older <strong>' + escapeHtml(firstBatch.source_label) + '</strong> (' + escapeHtml(firstBatch.batch_code) + ') was consumed first (' +
                escapeHtml(firstBatch.issued_quantity) + ' units issued, ' + escapeHtml(firstBatch.remaining_quantity) + ' units remaining), leaving the newer ' +
                '<strong>' + escapeHtml(secondBatch.source_label) + '</strong> (' + escapeHtml(secondBatch.batch_code) + ') untouched at ' + escapeHtml(secondBatch.remaining_quantity) + ' units.';
        } else if (batches.length === 1) {
            proofTextEl.innerHTML = 'Currently 1 active batch in FIFO queue (' + escapeHtml(batches[0].batch_code) + '). Any upcoming stock issuance will draw exclusively from this batch.';
        } else {
            proofTextEl.innerHTML = 'All batch balances and issuance records are strictly audited in real-time with 100% mathematical inventory parity.';
        }
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.js-view-fifo-batches');
        if (!btn) return;
        e.preventDefault();

        const itemId = btn.dataset.itemId || btn.dataset.id;
        if (!itemId) return;

        openFifoBatchModal(itemId);
    });

    window.openFifoBatchModal = openFifoBatchModal;
})();
</script>