/**
 * BloodLink Pulse - Lead Frontend Controller (app.js)
 * Coordinates AJAX CRUD operations, Smart Blood Compatibility Engine,
 * Modals, Urgency Triage UI, and cURL Mock SMS Gateway Inspector.
 */

(function () {
    'use strict';

    // Application State
    const AppState = {
        donors: [],
        requests: [],
        matches: [],
        selectedRequestIdForMatch: null,
        editingDonorId: null,
        editingRequestId: null,
        filters: {
            donorBlood: '',
            donorStatus: '',
            donorSearch: '',
            requestUrgency: '',
            requestBlood: '',
            requestStatus: '',
            requestSearch: ''
        }
    };

    // Blood Compatibility Matrix Definition
    const BLOOD_COMPATIBILITY = {
        'O-':  { canGiveTo: ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'], canReceiveFrom: ['O-'] },
        'O+':  { canGiveTo: ['O+', 'A+', 'B+', 'AB+'],                         canReceiveFrom: ['O+', 'O-'] },
        'A-':  { canGiveTo: ['A-', 'A+', 'AB-', 'AB+'],                         canReceiveFrom: ['A-', 'O-'] },
        'A+':  { canGiveTo: ['A+', 'AB+'],                                     canReceiveFrom: ['A+', 'A-', 'O+', 'O-'] },
        'B-':  { canGiveTo: ['B-', 'B+', 'AB-', 'AB+'],                         canReceiveFrom: ['B-', 'O-'] },
        'B+':  { canGiveTo: ['B+', 'AB+'],                                     canReceiveFrom: ['B+', 'B-', 'O+', 'O-'] },
        'AB-': { canGiveTo: ['AB-', 'AB+'],                                     canReceiveFrom: ['AB-', 'A-', 'B-', 'O-'] },
        'AB+': { canGiveTo: ['AB+'],                                           canReceiveFrom: ['AB+', 'AB-', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-'] }
    };

    // DOM Elements Cache
    const DOM = {};

    // Initialization
    document.addEventListener('DOMContentLoaded', () => {
        cacheDom();
        bindNavigation();
        initValidationForms();
        bindFilterEvents();
        bindActionButtons();
        bindMatrixCalculator();
        loadInitialData();
    });

    function cacheDom() {
        DOM.kpiTotalDonors = document.getElementById('kpi-total-donors');
        DOM.kpiEmergencyRequests = document.getElementById('kpi-emergency-requests');
        DOM.kpiCriticalTriage = document.getElementById('kpi-critical-triage');
        DOM.kpiDispatchedSms = document.getElementById('kpi-dispatched-sms');

        DOM.donorsTableBody = document.getElementById('donors-table-body');
        DOM.requestsTableBody = document.getElementById('requests-table-body');
        DOM.matchesTableBody = document.getElementById('matches-table-body');

        DOM.modalDonor = document.getElementById('modal-donor');
        DOM.modalRequest = document.getElementById('modal-request');
        DOM.modalMatch = document.getElementById('modal-match');
        DOM.modalSmsReceipt = document.getElementById('modal-sms-receipt');

        DOM.formDonor = document.getElementById('form-donor');
        DOM.formRequest = document.getElementById('form-request');
        DOM.toastContainer = document.getElementById('toast-container');
    }

    /**
     * Tab Navigation Switching
     */
    function bindNavigation() {
        const tabs = document.querySelectorAll('.nav-tab');
        const sections = document.querySelectorAll('.tab-content-panel');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const targetId = tab.getAttribute('data-tab');
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                sections.forEach(sec => {
                    if (sec.id === `tab-${targetId}`) {
                        sec.classList.remove('hidden');
                        sec.classList.add('animate-fadeIn');
                    } else {
                        sec.classList.add('hidden');
                    }
                });

                if (targetId === 'logs') {
                    fetchMatches();
                } else if (targetId === 'donors') {
                    fetchDonors();
                } else if (targetId === 'triage') {
                    fetchRequests();
                }
            });
        });
    }

    /**
     * Initialize Vanilla JS Validation Forms
     */
    function initValidationForms() {
        // Donor Form
        window.donorValidator = window.BloodLinkValidation.bindForm(DOM.formDonor, {
            allowCooldownAsWarning: true,
            onValidSubmit: (data) => submitDonorForm(data)
        });

        // Request Form
        window.requestValidator = window.BloodLinkValidation.bindForm(DOM.formRequest, {
            onValidSubmit: (data) => submitRequestForm(data)
        });
    }

    /**
     * Filter & Search Event Listeners
     */
    function bindFilterEvents() {
        // Donor Filters
        document.getElementById('filter-donor-blood')?.addEventListener('change', (e) => {
            AppState.filters.donorBlood = e.target.value;
            fetchDonors();
        });
        document.getElementById('filter-donor-status')?.addEventListener('change', (e) => {
            AppState.filters.donorStatus = e.target.value;
            fetchDonors();
        });
        document.getElementById('search-donor')?.addEventListener('input', debounce((e) => {
            AppState.filters.donorSearch = e.target.value;
            fetchDonors();
        }, 300));

        // Request Filters
        document.getElementById('filter-request-urgency')?.addEventListener('change', (e) => {
            AppState.filters.requestUrgency = e.target.value;
            fetchRequests();
        });
        document.getElementById('filter-request-blood')?.addEventListener('change', (e) => {
            AppState.filters.requestBlood = e.target.value;
            fetchRequests();
        });
        document.getElementById('filter-request-status')?.addEventListener('change', (e) => {
            AppState.filters.requestStatus = e.target.value;
            fetchRequests();
        });
        document.getElementById('search-request')?.addEventListener('input', debounce((e) => {
            AppState.filters.requestSearch = e.target.value;
            fetchRequests();
        }, 300));
    }

    /**
     * Global Action Button Listeners
     */
    function bindActionButtons() {
        // Open Add Donor Modal
        document.getElementById('btn-add-donor')?.addEventListener('click', () => {
            AppState.editingDonorId = null;
            document.getElementById('modal-donor-title').textContent = 'Register New Blood Donor';
            window.donorValidator.reset();
            openModal(DOM.modalDonor);
        });

        // Open Add Request Modal
        document.getElementById('btn-add-request')?.addEventListener('click', () => {
            AppState.editingRequestId = null;
            document.getElementById('modal-request-title').textContent = 'Submit Emergency Blood Request';
            window.requestValidator.reset();
            openModal(DOM.modalRequest);
        });

        // Test SMS Gateway directly
        document.getElementById('btn-test-sms')?.addEventListener('click', () => {
            testSmsGateway();
        });

        // Refresh buttons
        document.getElementById('btn-refresh-donors')?.addEventListener('click', () => fetchDonors());
        document.getElementById('btn-refresh-requests')?.addEventListener('click', () => fetchRequests());
        document.getElementById('btn-refresh-logs')?.addEventListener('click', () => fetchMatches());

        // Close Modal buttons
        document.querySelectorAll('.btn-close-modal').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.target.closest('.fixed');
                if (modal) closeModal(modal);
            });
        });

        // Close on backdrop click
        [DOM.modalDonor, DOM.modalRequest, DOM.modalMatch, DOM.modalSmsReceipt].forEach(modal => {
            modal?.addEventListener('click', (e) => {
                if (e.target === modal) closeModal(modal);
            });
        });

        // Close on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => closeModal(m));
            }
        });
    }

    /**
     * Interactive Blood Compatibility Matrix Interactive Explorer
     */
    function bindMatrixCalculator() {
        const select = document.getElementById('matrix-blood-select');
        if (!select) return;

        function updateMatrixView(type) {
            const data = BLOOD_COMPATIBILITY[type];
            if (!data) return;

            const giveEl = document.getElementById('matrix-can-give');
            const receiveEl = document.getElementById('matrix-can-receive');

            if (giveEl) {
                giveEl.innerHTML = data.canGiveTo.map(t => `
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold ${t === type ? 'bg-red-600 text-white' : 'bg-slate-800 text-slate-200 border border-slate-700'}">
                        ${t} ${t === 'AB+' ? '⭐ Recipient' : ''}
                    </span>
                `).join('');
            }

            if (receiveEl) {
                receiveEl.innerHTML = data.canReceiveFrom.map(t => `
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold ${t === type ? 'bg-red-600 text-white' : 'bg-slate-800 text-slate-200 border border-slate-700'}">
                        ${t} ${t === 'O-' ? '🌟 Universal' : ''}
                    </span>
                `).join('');
            }
        }

        select.addEventListener('change', (e) => updateMatrixView(e.target.value));
        updateMatrixView(select.value || 'O-');
    }

    /**
     * Fetch initial dataset on load
     */
    function loadInitialData() {
        fetchDonors();
        fetchRequests();
        fetchMatches();
    }

    /**
     * AJAX: Fetch Donors
     */
    async function fetchDonors() {
        try {
            let url = 'api/donors.php?';
            const params = new URLSearchParams();
            if (AppState.filters.donorBlood) params.append('blood_type', AppState.filters.donorBlood);
            if (AppState.filters.donorStatus) params.append('status', AppState.filters.donorStatus);
            if (AppState.filters.donorSearch) params.append('search', AppState.filters.donorSearch);

            const res = await fetch(url + params.toString());
            const json = await res.json();

            if (json.success) {
                AppState.donors = json.data;
                renderDonorsTable(json.data);
                updateKpis();
            } else {
                showToast(json.message || 'Error loading donors', 'error');
            }
        } catch (err) {
            console.error('fetchDonors error:', err);
            showToast('Unable to connect to donors API endpoint.', 'error');
        }
    }

    /**
     * AJAX: Fetch Emergency Requests (Triage Sorted)
     */
    async function fetchRequests() {
        try {
            let url = 'api/requests.php?';
            const params = new URLSearchParams();
            if (AppState.filters.requestUrgency) params.append('urgency', AppState.filters.requestUrgency);
            if (AppState.filters.requestBlood) params.append('blood_type', AppState.filters.requestBlood);
            if (AppState.filters.requestStatus) params.append('status', AppState.filters.requestStatus);
            if (AppState.filters.requestSearch) params.append('search', AppState.filters.requestSearch);

            const res = await fetch(url + params.toString());
            const json = await res.json();

            if (json.success) {
                AppState.requests = json.data;
                renderRequestsTable(json.data);
                updateKpis();
            } else {
                showToast(json.message || 'Error loading requests', 'error');
            }
        } catch (err) {
            console.error('fetchRequests error:', err);
            showToast('Unable to connect to requests API endpoint.', 'error');
        }
    }

    /**
     * AJAX: Fetch Match Logs & SMS Audit Records
     */
    async function fetchMatches() {
        try {
            const res = await fetch('api/matches.php');
            const json = await res.json();

            if (json.success) {
                AppState.matches = json.data;
                renderMatchesTable(json.data);
                updateKpis();
            }
        } catch (err) {
            console.error('fetchMatches error:', err);
        }
    }

    /**
     * Render Donors Table
     */
    function renderDonorsTable(donors) {
        if (!DOM.donorsTableBody) return;

        if (!donors || donors.length === 0) {
            DOM.donorsTableBody.innerHTML = `
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                        <svg class="w-12 h-12 mx-auto text-slate-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        <p class="text-base font-medium">No donors found matching criteria</p>
                        <p class="text-xs text-slate-500 mt-1">Try adjusting your filters or register a new donor.</p>
                    </td>
                </tr>
            `;
            return;
        }

        DOM.donorsTableBody.innerHTML = donors.map(d => {
            const isUniversal = d.blood_type === 'O-';
            const bloodBadgeClass = isUniversal ? 'blood-badge-universal' : 'blood-badge-standard';
            
            // Status styling
            let statusBadge = '';
            if (d.status === 'Available') {
                statusBadge = `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">● Available</span>`;
            } else if (d.status === 'Cooldown') {
                const daysNeeded = d.eligibility?.days_needed ?? '?';
                statusBadge = `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/30">⏳ Cooldown (${daysNeeded}d)</span>`;
            } else {
                statusBadge = `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-700 text-slate-400">Inactive</span>`;
            }

            const formattedPhone = d.phone.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
            const donationDateDisplay = d.last_donation_date 
                ? `${d.last_donation_date} <span class="text-xs text-slate-400">(${d.eligibility?.days_since ?? 0}d ago)</span>`
                : `<span class="text-xs text-slate-500 italic">Never donated</span>`;

            return `
                <tr class="interactive-row border-b border-slate-800 text-sm">
                    <td class="px-4 py-3 font-semibold text-slate-100 flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-xs text-red-400">
                            ${d.name.substring(0, 2).toUpperCase()}
                        </div>
                        <div>
                            <div>${escapeHtml(d.name)}</div>
                            <div class="text-xs text-slate-400">${escapeHtml(d.email)}</div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="blood-badge ${bloodBadgeClass} px-3 py-1 text-xs">
                            ${d.blood_type} ${isUniversal ? '★ Universal' : ''}
                        </span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-300">
                        📞 ${formattedPhone}
                    </td>
                    <td class="px-4 py-3 text-slate-300">
                        📍 ${escapeHtml(d.city)}
                    </td>
                    <td class="px-4 py-3 text-slate-300">
                        ${donationDateDisplay}
                    </td>
                    <td class="px-4 py-3">
                        ${statusBadge}
                    </td>
                    <td class="px-4 py-3 text-right space-x-1">
                        <button onclick="window.editDonor(${d.id})" class="px-2.5 py-1 text-xs font-medium text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded transition border border-slate-700" title="Edit Donor">
                            ✏️ Edit
                        </button>
                        <button onclick="window.deleteDonor(${d.id})" class="px-2.5 py-1 text-xs font-medium text-red-400 hover:text-red-300 bg-red-950/40 hover:bg-red-900/60 rounded transition border border-red-900/60" title="Delete Donor">
                            🗑️
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    /**
     * Render Requests Table (Urgency Triage Priority)
     */
    function renderRequestsTable(requests) {
        if (!DOM.requestsTableBody) return;

        if (!requests || requests.length === 0) {
            DOM.requestsTableBody.innerHTML = `
                <tr>
                    <td colspan="8" class="px-6 py-12 text-center text-slate-400">
                        <svg class="w-12 h-12 mx-auto text-slate-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <p class="text-base font-medium">No emergency requests active</p>
                        <p class="text-xs text-slate-500 mt-1">All hospital blood needs are currently matched or fulfilled.</p>
                    </td>
                </tr>
            `;
            return;
        }

        DOM.requestsTableBody.innerHTML = requests.map(r => {
            // Urgency Badge & Pulse
            let urgencyHtml = '';
            if (r.urgency === 'Critical') {
                urgencyHtml = `
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold urgency-critical">
                        <span class="w-2 h-2 rounded-full bg-red-500 radar-pulse-critical"></span>
                        CRITICAL
                    </div>
                `;
            } else if (r.urgency === 'High') {
                urgencyHtml = `
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold urgency-high">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        HIGH
                    </div>
                `;
            } else {
                urgencyHtml = `
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold urgency-medium">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        MEDIUM
                    </div>
                `;
            }

            // Status Badge
            let statusBadge = '';
            if (r.status === 'Pending') {
                statusBadge = `<span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/30">Pending Match</span>`;
            } else if (r.status === 'Matched') {
                statusBadge = `<span class="px-2 py-0.5 rounded text-xs font-semibold bg-sky-500/10 text-sky-400 border border-sky-500/30">Matched & Alerted</span>`;
            } else if (r.status === 'Fulfilled') {
                statusBadge = `<span class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">Fulfilled</span>`;
            } else {
                statusBadge = `<span class="px-2 py-0.5 rounded text-xs font-semibold bg-slate-800 text-slate-400">Cancelled</span>`;
            }

            const formattedPhone = r.contact_phone.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');

            return `
                <tr class="interactive-row border-b border-slate-800 text-sm">
                    <td class="px-4 py-3">
                        ${urgencyHtml}
                    </td>
                    <td class="px-4 py-3 font-semibold text-slate-100">
                        <div>${escapeHtml(r.patient_name)}</div>
                        <div class="text-xs text-slate-400">REQ-#${r.id} • ${escapeHtml(r.notes || '')}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="blood-badge blood-badge-standard px-3 py-1 text-xs font-black">
                            ${r.blood_type}
                        </span>
                        <div class="text-[10px] text-slate-400 mt-1">Accepts: ${r.compatible_donor_types.join(', ')}</div>
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-red-400">
                        ${r.units_needed} unit(s)
                    </td>
                    <td class="px-4 py-3 text-slate-300">
                        <div class="font-medium text-slate-200">🏥 ${escapeHtml(r.hospital_name)}</div>
                        <div class="text-xs text-slate-400">📍 ${escapeHtml(r.city)} • 📞 ${formattedPhone}</div>
                    </td>
                    <td class="px-4 py-3">
                        ${statusBadge}
                    </td>
                    <td class="px-4 py-3 text-right space-x-1.5 whitespace-nowrap">
                        <button onclick="window.openMatcherForRequest(${r.id})" class="px-3 py-1.5 text-xs font-bold text-white bg-red-600 hover:bg-red-500 rounded shadow-md transition flex-inline items-center gap-1">
                            ⚡ Find Donors
                        </button>
                        <button onclick="window.editRequest(${r.id})" class="px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 rounded transition border border-slate-700" title="Edit Request">
                            ✏️
                        </button>
                        <button onclick="window.deleteRequest(${r.id})" class="px-2.5 py-1.5 text-xs font-medium text-red-400 hover:text-red-300 bg-red-950/40 hover:bg-red-900/60 rounded transition border border-red-900/60" title="Delete Request">
                            🗑️
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    /**
     * Render Matches & SMS Audit Logs
     */
    function renderMatchesTable(matches) {
        if (!DOM.matchesTableBody) return;

        if (!matches || matches.length === 0) {
            DOM.matchesTableBody.innerHTML = `
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                        <p class="text-base font-medium">No SMS match dispatches recorded yet</p>
                        <p class="text-xs text-slate-500 mt-1">Execute a match from the Emergency Triage tab to dispatch an external SMS alert.</p>
                    </td>
                </tr>
            `;
            return;
        }

        DOM.matchesTableBody.innerHTML = matches.map(m => {
            const urgencyClass = m.urgency_level === 'Critical' ? 'urgency-critical' : (m.urgency_level === 'High' ? 'urgency-high' : 'urgency-medium');
            const parsed = m.sms_payload_parsed || {};

            return `
                <tr class="interactive-row border-b border-slate-800 text-sm">
                    <td class="px-4 py-3 font-mono text-xs text-slate-400">
                        #LOG-${m.match_id}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold ${urgencyClass}">
                            ${m.urgency_level}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-100">${escapeHtml(m.patient_name)} (${m.requested_blood_type})</div>
                        <div class="text-xs text-slate-400">🏥 ${escapeHtml(m.hospital_name)}</div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-emerald-400">${escapeHtml(m.donor_name)} (${m.donor_blood_type})</div>
                        <div class="text-xs text-slate-400 font-mono">📞 ${m.donor_phone}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                            ✓ ${m.sms_status}
                        </span>
                        <div class="text-[11px] font-mono text-slate-400 mt-0.5">Post ID: #${escapeHtml(m.sms_response_id || 'N/A')}</div>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400">
                        ${m.matched_at}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <button onclick="window.viewSmsReceipt(${m.match_id})" class="px-2.5 py-1 text-xs text-sky-400 hover:text-sky-300 bg-sky-950/40 hover:bg-sky-900/60 rounded border border-sky-900/60">
                            📄 Inspect Payload
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    /**
     * Smart Blood Compatibility Matcher: Open Match Drawer for Request
     */
    window.openMatcherForRequest = async function (requestId) {
        AppState.selectedRequestIdForMatch = requestId;
        const modal = DOM.modalMatch;
        const container = document.getElementById('matcher-results-container');
        const header = document.getElementById('matcher-request-header');

        if (!modal || !container) return;

        container.innerHTML = `
            <div class="py-16 text-center text-slate-400">
                <svg class="animate-spin h-8 w-8 text-red-500 mx-auto mb-3" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <p class="text-sm font-medium">Running Smart Blood Compatibility Algorithm...</p>
            </div>
        `;
        openModal(modal);

        try {
            const res = await fetch(`api/requests.php?id=${requestId}&action=compatible_donors`);
            const json = await res.json();

            if (!json.success) {
                container.innerHTML = `<div class="p-6 text-red-400 text-center">${json.message}</div>`;
                return;
            }

            const req = json.request;
            header.innerHTML = `
                <div class="flex flex-wrap items-center justify-between gap-4 p-4 rounded-xl bg-slate-800/80 border border-slate-700 mb-6">
                    <div>
                        <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Triage Patient Request</div>
                        <div class="text-lg font-bold text-white">${escapeHtml(req.patient_name)}</div>
                        <div class="text-xs text-slate-300">🏥 ${escapeHtml(req.hospital_name)} • 📍 ${escapeHtml(req.city)} • Needs: <span class="text-red-400 font-bold">${req.units_needed} Unit(s)</span></div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-400">Requested Blood Type</div>
                        <div class="blood-badge blood-badge-standard text-sm px-4 py-1.5 font-black text-red-400">
                            ${req.blood_type}
                        </div>
                        <div class="text-[11px] text-emerald-400 mt-1">Accepts: ${json.compatible_donor_types.join(', ')}</div>
                    </div>
                </div>
            `;

            if (!json.donors || json.donors.length === 0) {
                container.innerHTML = `
                    <div class="p-12 text-center text-slate-400 bg-slate-800/40 rounded-xl border border-slate-700/50">
                        <p class="text-base font-semibold text-slate-300">No compatible donors found in database</p>
                        <p class="text-xs text-slate-500 mt-1">Patient requires ${req.blood_type} (compatible with: ${json.compatible_donor_types.join(', ')}). Try registering new donors.</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = `
                <div class="text-sm font-semibold text-slate-300 mb-3 flex items-center justify-between">
                    <span>Ranked Medical Matches (${json.count} Candidate Donors)</span>
                    <span class="text-xs text-slate-400">Compatibility Triage Algorithm Active</span>
                </div>
                <div class="space-y-3 max-h-[480px] overflow-y-auto pr-1">
                    ${json.donors.map(d => {
                        const score = d.match_score;
                        const scoreColor = score >= 80 ? 'text-emerald-400 border-emerald-500/40 bg-emerald-500/10' : (score >= 60 ? 'text-amber-400 border-amber-500/40 bg-amber-500/10' : 'text-slate-400 border-slate-700 bg-slate-800');
                        const isAvailable = d.status === 'Available' && d.eligibility?.is_eligible;

                        return `
                            <div class="p-4 rounded-xl bg-slate-800/60 hover:bg-slate-800 border border-slate-700/80 transition flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <div class="blood-badge ${d.blood_type === 'O-' ? 'blood-badge-universal' : 'blood-badge-standard'} text-sm px-3.5 py-1.5">
                                        ${d.blood_type}
                                    </div>
                                    <div>
                                        <div class="font-bold text-white text-base flex items-center gap-2">
                                            ${escapeHtml(d.name)}
                                            ${d.is_local ? '<span class="text-[10px] bg-sky-950 text-sky-400 border border-sky-800 px-2 py-0.5 rounded-full font-bold">Local Proximity Match</span>' : ''}
                                        </div>
                                        <div class="text-xs text-slate-400 flex items-center gap-3 mt-0.5">
                                            <span>📍 ${escapeHtml(d.city)}</span>
                                            <span>📞 ${d.phone}</span>
                                            <span>Tier: <strong class="text-slate-300">${escapeHtml(d.compatibility_type)}</strong></span>
                                        </div>
                                        <div class="text-xs mt-1">
                                            ${isAvailable ? '<span class="text-emerald-400 font-semibold">● Eligible & Ready to Donate</span>' : `<span class="text-amber-400 font-semibold">⏳ Cooldown Active (${d.eligibility?.days_needed ?? '?'}d remaining)</span>`}
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-4">
                                    <div class="text-center px-3 py-1.5 rounded-lg border ${scoreColor}">
                                        <div class="text-xs uppercase font-bold text-slate-400">Match Score</div>
                                        <div class="text-lg font-black">${score}%</div>
                                    </div>
                                    <button onclick="window.dispatchMatchSms(${req.id}, ${d.id})" class="px-4 py-2.5 text-xs font-bold text-white bg-red-600 hover:bg-red-500 rounded-lg shadow-lg hover:shadow-red-600/30 transition flex items-center gap-1.5">
                                        <span>📲 Match & Dispatch SMS</span>
                                    </button>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        } catch (err) {
            console.error('Matcher error:', err);
            container.innerHTML = `<div class="p-6 text-red-400 text-center">Failed to execute matcher query.</div>`;
        }
    };

    /**
     * Dispatch Match & Simulated Carrier SMS via cURL
     */
    window.dispatchMatchSms = async function (requestId, donorId) {
        showToast('Connecting to Carrier SMS Gateway (cURL)...', 'info');

        try {
            const res = await fetch('api/matches.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ request_id: requestId, donor_id: donorId })
            });
            const json = await res.json();

            if (json.success) {
                closeModal(DOM.modalMatch);
                showToast('SMS Alert Dispatched via External Gateway!', 'success');
                displaySmsReceipt(json.sms_receipt, json.match_data);
                fetchRequests();
                fetchDonors();
                fetchMatches();
            } else {
                showToast(json.message || 'Match dispatch failed', 'error');
            }
        } catch (err) {
            console.error('dispatchMatchSms error:', err);
            showToast('Network error during SMS dispatch.', 'error');
        }
    };

    /**
     * Direct Test of Fake SMS Gateway cURL Call
     */
    async function testSmsGateway() {
        showToast('Testing cURL call to JSONPlaceholder SMS Gateway...', 'info');
        try {
            const payload = {
                donor_phone: '5552345678',
                donor_name: 'Test Emergency Donor',
                patient_name: 'Simulated Patient',
                hospital: 'Metro General Hospital',
                blood_type: 'O-',
                units_needed: 2,
                urgency: 'Critical',
                contact_phone: '5559110000'
            };

            const res = await fetch('api/fake_sms_gateway.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();

            if (json.success) {
                showToast('cURL SMS Gateway response verified (HTTP 201)!', 'success');
                displaySmsReceipt(json.data, {
                    patient_name: payload.patient_name,
                    hospital: payload.hospital,
                    donor_name: payload.donor_name,
                    donor_phone: payload.donor_phone,
                    urgency: payload.urgency
                });
            } else {
                showToast(json.message || 'Gateway test failed', 'error');
            }
        } catch (err) {
            console.error('testSmsGateway error:', err);
            showToast('Gateway test encountered a network error.', 'error');
        }
    }

    /**
     * Show SMS Carrier Receipt Modal
     */
    function displaySmsReceipt(receipt, meta) {
        const modal = DOM.modalSmsReceipt;
        const body = document.getElementById('sms-receipt-content');
        if (!modal || !body) return;

        body.innerHTML = `
            <div class="space-y-4">
                <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-between">
                    <div>
                        <div class="text-xs uppercase text-emerald-400 font-bold">Gateway Dispatch Status</div>
                        <div class="text-lg font-bold text-white flex items-center gap-2">
                            ✓ Message Transmitted Successfully
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-400">External Post ID</div>
                        <div class="text-lg font-mono font-bold text-emerald-400">#${receipt.external_post_id}</div>
                    </div>
                </div>

                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 font-mono text-xs">
                    <div class="text-slate-400 mb-1">// Outbound SMS Payload Transmitted via cURL</div>
                    <div class="text-amber-400 font-bold whitespace-pre-wrap">${escapeHtml(receipt.message_preview || '')}</div>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="p-3 bg-slate-800/60 rounded-lg border border-slate-700">
                        <div class="text-slate-400">External Gateway Endpoint:</div>
                        <div class="text-slate-200 font-mono mt-0.5 truncate">${receipt.gateway_endpoint || 'https://jsonplaceholder.typicode.com/posts'}</div>
                    </div>
                    <div class="p-3 bg-slate-800/60 rounded-lg border border-slate-700">
                        <div class="text-slate-400">Gateway Latency / HTTP Code:</div>
                        <div class="text-slate-200 font-mono mt-0.5">${receipt.latency_ms} ms (HTTP ${receipt.http_status_code})</div>
                    </div>
                </div>

                <div class="text-[11px] text-slate-400 border-t border-slate-800 pt-3">
                    Audit log record created with donor contact confirmation and timestamped delivery token.
                </div>
            </div>
        `;

        openModal(modal);
    }

    /**
     * Inspect Past SMS Log Payload
     */
    window.viewSmsReceipt = function (matchId) {
        const item = AppState.matches.find(m => m.match_id == matchId);
        if (!item) return;

        const payload = item.sms_payload_parsed || {};
        displaySmsReceipt({
            external_post_id: item.sms_response_id,
            message_preview: payload.message || 'Emergency Blood Alert',
            gateway_endpoint: payload.gateway || 'https://jsonplaceholder.typicode.com/posts',
            latency_ms: payload.latency_ms || '210.4',
            http_status_code: payload.http_code || 201
        }, {
            patient_name: item.patient_name,
            hospital: item.hospital_name,
            donor_name: item.donor_name,
            donor_phone: item.donor_phone,
            urgency: item.urgency_level
        });
    };

    /**
     * AJAX: Submit Donor Form (Create or Update)
     */
    async function submitDonorForm(data) {
        showToast('Saving donor information...', 'info');
        const isUpdate = Boolean(AppState.editingDonorId);
        const url = 'api/donors.php';
        const method = isUpdate ? 'PUT' : 'POST';

        if (isUpdate) {
            data.id = AppState.editingDonorId;
        }

        try {
            const res = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();

            if (json.success) {
                closeModal(DOM.modalDonor);
                showToast(json.message || 'Donor saved successfully!', 'success');
                window.donorValidator.reset();
                AppState.editingDonorId = null;
                fetchDonors();
            } else {
                showToast(json.message || 'Failed to save donor', 'error');
            }
        } catch (err) {
            console.error('submitDonorForm error:', err);
            showToast('Network error while saving donor.', 'error');
        }
    }

    /**
     * Edit Donor
     */
    window.editDonor = function (id) {
        const donor = AppState.donors.find(d => d.id == id);
        if (!donor) return;

        AppState.editingDonorId = id;
        document.getElementById('modal-donor-title').textContent = `Edit Donor Record (#${id})`;

        const form = DOM.formDonor;
        form.name.value = donor.name;
        form.blood_type.value = donor.blood_type;
        form.phone.value = donor.phone;
        form.email.value = donor.email;
        form.city.value = donor.city;
        form.last_donation_date.value = donor.last_donation_date || '';

        openModal(DOM.modalDonor);
    };

    /**
     * Delete Donor
     */
    window.deleteDonor = async function (id) {
        if (!confirm(`Are you sure you want to permanently delete Donor #${id}?`)) {
            return;
        }

        try {
            const res = await fetch(`api/donors.php?id=${id}`, { method: 'DELETE' });
            const json = await res.json();

            if (json.success) {
                showToast(json.message || 'Donor deleted', 'success');
                fetchDonors();
                fetchMatches();
            } else {
                showToast(json.message || 'Failed to delete donor', 'error');
            }
        } catch (err) {
            console.error('deleteDonor error:', err);
            showToast('Network error deleting donor.', 'error');
        }
    };

    /**
     * AJAX: Submit Emergency Request Form (Create or Update)
     */
    async function submitRequestForm(data) {
        showToast('Saving emergency blood request...', 'info');
        const isUpdate = Boolean(AppState.editingRequestId);
        const url = 'api/requests.php';
        const method = isUpdate ? 'PUT' : 'POST';

        if (isUpdate) {
            data.id = AppState.editingRequestId;
        }

        try {
            const res = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();

            if (json.success) {
                closeModal(DOM.modalRequest);
                showToast(json.message || 'Request submitted successfully!', 'success');
                window.requestValidator.reset();
                AppState.editingRequestId = null;
                fetchRequests();

                // If Critical and auto-dispatched, notify user with receipt
                if (json.auto_dispatch && json.auto_dispatch.success) {
                    showToast('🚨 Automatic SMS Alert dispatched to nearest compatible donor!', 'success');
                    displaySmsReceipt(json.auto_dispatch, {
                        patient_name: data.patient_name,
                        hospital: data.hospital_name,
                        donor_name: json.auto_dispatch.recipient.name,
                        donor_phone: json.auto_dispatch.recipient.phone,
                        urgency: 'Critical'
                    });
                }
            } else {
                showToast(json.message || 'Failed to save request', 'error');
            }
        } catch (err) {
            console.error('submitRequestForm error:', err);
            showToast('Network error saving request.', 'error');
        }
    }

    /**
     * Edit Request
     */
    window.editRequest = function (id) {
        const req = AppState.requests.find(r => r.id == id);
        if (!req) return;

        AppState.editingRequestId = id;
        document.getElementById('modal-request-title').textContent = `Edit Emergency Request (#${id})`;

        const form = DOM.formRequest;
        form.patient_name.value = req.patient_name;
        form.blood_type.value = req.blood_type;
        form.units_needed.value = req.units_needed;
        form.urgency.value = req.urgency;
        form.hospital_name.value = req.hospital_name;
        form.city.value = req.city;
        form.contact_phone.value = req.contact_phone;
        form.notes.value = req.notes || '';

        openModal(DOM.modalRequest);
    };

    /**
     * Delete Request
     */
    window.deleteRequest = async function (id) {
        if (!confirm(`Are you sure you want to delete Request #${id}?`)) {
            return;
        }

        try {
            const res = await fetch(`api/requests.php?id=${id}`, { method: 'DELETE' });
            const json = await res.json();

            if (json.success) {
                showToast(json.message || 'Request deleted', 'success');
                fetchRequests();
                fetchMatches();
            } else {
                showToast(json.message || 'Failed to delete request', 'error');
            }
        } catch (err) {
            console.error('deleteRequest error:', err);
            showToast('Network error deleting request.', 'error');
        }
    };

    /**
     * Update KPI Summary Cards
     */
    function updateKpis() {
        if (DOM.kpiTotalDonors) DOM.kpiTotalDonors.textContent = AppState.donors.length;
        if (DOM.kpiEmergencyRequests) DOM.kpiEmergencyRequests.textContent = AppState.requests.length;

        const criticalCount = AppState.requests.filter(r => r.urgency === 'Critical' && r.status === 'Pending').length;
        if (DOM.kpiCriticalTriage) DOM.kpiCriticalTriage.textContent = criticalCount;

        if (DOM.kpiDispatchedSms) DOM.kpiDispatchedSms.textContent = AppState.matches.length;
    }

    /**
     * Modal Helper Utilities
     */
    function openModal(modalEl) {
        if (!modalEl) return;
        modalEl.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(modalEl) {
        if (!modalEl) return;
        modalEl.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    /**
     * Toast Notification System
     */
    function showToast(message, type = 'info', duration = 4000) {
        if (!DOM.toastContainer) return;

        const toast = document.createElement('div');
        let bgStyle = 'bg-slate-800 text-white border-slate-700';
        let icon = 'ℹ️';

        if (type === 'success') {
            bgStyle = 'bg-emerald-950/90 text-emerald-200 border-emerald-800';
            icon = '✓';
        } else if (type === 'error') {
            bgStyle = 'bg-red-950/90 text-red-200 border-red-800';
            icon = '✕';
        }

        toast.className = `toast px-4 py-3 rounded-xl border shadow-xl flex items-center gap-3 text-sm font-medium ${bgStyle}`;
        toast.innerHTML = `
            <span class="w-5 h-5 rounded-full flex items-center justify-center font-bold text-xs bg-black/20">${icon}</span>
            <div class="flex-1">${escapeHtml(message)}</div>
        `;

        DOM.toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    // Debounce Utility
    function debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Escape HTML Helper
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})();
