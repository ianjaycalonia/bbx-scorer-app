// Tournaments JavaScript - Modal functionality only

let currentUser = null;

function toggleSwissRoundsField(isSwiss) {
    const swissRoundsField = document.getElementById('swissRoundsField');
    const placementCoverageField = document.getElementById('placementCoverageField');
    const roundsInput = document.getElementById('tournamentRounds');
    const placementCoverageSelect = document.getElementById('placementCoverage');

    if (!swissRoundsField || !placementCoverageField || !roundsInput || !placementCoverageSelect) return;

    const defaultRounds = parseInt(roundsInput.dataset.defaultRounds, 10);
    const fallbackRounds = Number.isNaN(defaultRounds) ? 5 : defaultRounds;

    if (isSwiss) {
        // Show Swiss rounds field, hide placement coverage
        swissRoundsField.classList.remove('d-none');
        placementCoverageField.classList.add('d-none');
        roundsInput.disabled = false;
        placementCoverageSelect.required = false;
        placementCoverageSelect.value = '';
        
        if (!roundsInput.value) {
            roundsInput.value = fallbackRounds;
        }
    } else {
        // Hide Swiss rounds field, show placement coverage
        swissRoundsField.classList.add('d-none');
        placementCoverageField.classList.remove('d-none');
        roundsInput.disabled = true;
        placementCoverageSelect.required = true;
        roundsInput.value = fallbackRounds;
        
        // Set default placement coverage if not already selected
        if (!placementCoverageSelect.value) {
            placementCoverageSelect.value = '4'; // Default to 4th place
        }
    }
}

// Initialize tournaments page
document.addEventListener('DOMContentLoaded', function () {

    // Load current user from session
    const userStr = sessionStorage.getItem('user');
    if (userStr) {
        try {
            currentUser = JSON.parse(userStr);
        } catch (e) {
        }
    }

    setupEventListeners();
    loadTournaments();
});

// Setup event listeners
function setupEventListeners() {
    // Set minimum date to today for tournament date input
    const tournamentDateInput = document.getElementById('tournamentDate');
    if (tournamentDateInput) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const todayString = `${yyyy}-${mm}-${dd}`;
        tournamentDateInput.setAttribute('min', todayString);
    }

    // Tournament type change listener
    const tournamentTypeSelect = document.getElementById('tournamentType');
    if (tournamentTypeSelect) {
        const isSwiss = tournamentTypeSelect.value === 'swiss';
        toggleSwissRoundsField(isSwiss);

        tournamentTypeSelect.addEventListener('change', function () {
            const isSwiss = this.value === 'swiss';
            toggleSwissRoundsField(isSwiss);
        });
    }

    // Logout button listener
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
}

// Load tournaments from database
async function loadTournaments() {
    try {

        // Load tournaments from API
        const response = await fetch('api/tournaments/list.php', {
            method: 'GET',
            credentials: 'include'
        });

        const result = await response.json();

        if (result.success) {
            tournamentsList = result.tournaments || [];
            displayTournaments(tournamentsList);
        } else {
            displayTournaments([]);
        }

    } catch (err) {
        displayTournaments([]);
    }
}

// Display tournaments
function displayTournaments(tournaments) {
    const tournamentsDiv = document.getElementById('tournamentsList');

    if (!tournamentsDiv) return;

    if (tournaments.length === 0) {
        tournamentsDiv.innerHTML = `
            <div class="empty-tournaments">
                <div class="empty-icon">
                    <i class="bi bi-trophy"></i>
                </div>
                <h3>No tournaments yet</h3>
                <p>Create your first tournament to get the competition started!</p>
            </div>
        `;
    } else {
        tournamentsDiv.innerHTML = tournaments.map(tournament => `
            <div class="tournament-card" onclick="viewTournament('${tournament.id}')">
                <div class="tournament-header">
                    <div>
                        <div class="tournament-type-badge">
                            <i class="bi ${getTournamentIcon(tournament.tournament_type)}"></i>
                            <span>${getTournamentTypeLabel(tournament.tournament_type)}</span>
                        </div>
                        <h3 class="tournament-title">${tournament.name}</h3>
                    </div>
                    <div class="tournament-status-badge ${tournament.status || 'upcoming'}">
                        ${getStatusLabel(tournament.status || 'upcoming')}
                    </div>
                </div>
                <div class="tournament-meta">
                    <div class="meta-item">
                        <i class="bi bi-calendar3"></i>
                        <span>${tournament.date ? formatDate(tournament.date) : 'No date set'}</span>
                    </div>
                    <div class="meta-item">
                        <i class="bi bi-geo-alt"></i>
                        <span>${tournament.location || 'TBD'}</span>
                    </div>
                    <div class="meta-item">
                        <i class="bi bi-people"></i>
                        <span>${tournament.participant_count || 0} participants</span>
                    </div>
                    <div class="meta-item">
                        <i class="bi bi-person"></i>
                        <span>${tournament.created_by_name || 'Unknown'}</span>
                    </div>
                </div>
                <div class="tournament-actions" onclick="event.stopPropagation()">
                    <button class="btn btn-primary" onclick="viewTournament('${tournament.id}')">
                        <i class="bi bi-eye"></i> View
                    </button>
                    ${currentUser && tournament.created_by === currentUser.id ? `
                        <button class="btn btn-outline-secondary" onclick="editTournament('${tournament.id}')">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn btn-outline-danger" onclick="deleteTournament('${tournament.id}')">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    ` : ''}
                </div>
            </div>
        `).join('');
    }
}

// Helper functions
function getTournamentTypeLabel(type) {
    const labels = {
        'single_elimination': 'Single Elim',
        'double_elimination': 'Double Elim',
        'swiss': 'Swiss'
    };
    return labels[type] || type;
}

function getTournamentIcon(type) {
    const icons = {
        'single_elimination': 'bi-lightning',
        'double_elimination': 'bi-diagram-3',
        'swiss': 'bi-grid-3x3'
    };
    return icons[type] || 'bi-trophy';
}

function getStatusLabel(status) {
    const labels = {
        'upcoming': 'Coming Soon',
        'registration': 'Open',
        'in_progress': 'Live',
        'completed': 'Finished',
        'cancelled': 'Cancelled'
    };
    return labels[status] || 'Upcoming';
}

function formatDate(dateString) {
    if (!dateString) return 'No date';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function viewTournament(tournamentId) {
}

function editTournament(tournamentId) {

    // Find tournament data
    const tournament = tournamentsList.find(t => t.id == tournamentId);
    if (!tournament) {
        showToast('Tournament not found', { variant: 'danger' });
        return;
    }

    // Populate the modal with tournament data
    document.getElementById('tournamentName').value = tournament.name || '';
    const typeSelect = document.getElementById('tournamentType');
    typeSelect.value = tournament.tournament_type || 'single_elimination';
    document.getElementById('tournamentDate').value = tournament.date || '';
    document.getElementById('tournamentLocation').value = tournament.location || '';
    document.getElementById('tournamentStadiums').value = tournament.number_of_stadiums || 1;
    const visibilitySelect = document.getElementById('tournamentVisibility');
    if (visibilitySelect) {
        visibilitySelect.value = tournament.visibility || 'team_only';
    }

    // Show rounds field if Swiss
    toggleSwissRoundsField(tournament.tournament_type === 'swiss');

    if (tournament.tournament_type === 'swiss') {
        const roundsInput = document.getElementById('tournamentRounds');
        const parsedRounds = parseInt(tournament.swiss_rounds, 10);
        if (!Number.isNaN(parsedRounds) && parsedRounds > 0) {
            roundsInput.value = parsedRounds;
        } else if (tournament.rules && tournament.rules.includes('Swiss tournament with')) {
            const roundsMatch = tournament.rules.match(/(\d+) rounds/);
            roundsInput.value = roundsMatch ? roundsMatch[1] : roundsInput.value;
        }
    } else if (tournament.tournament_type === 'single_elimination' || tournament.tournament_type === 'double_elimination') {
        // Set placement coverage for elimination tournaments
        const placementCoverageSelect = document.getElementById('placementCoverage');
        const rankTo = parseInt(tournament.rank_to ?? 0);
        if (placementCoverageSelect && rankTo > 0) {
            placementCoverageSelect.value = rankTo.toString();
        }
    }

    // Change modal title and button
    document.querySelector('#tournamentModal .modal-title').textContent = 'Edit Tournament';
    document.querySelector('button[onclick="saveTournament()"]').textContent = 'Update Tournament';

    // Store editing mode
    window.editingTournamentId = tournamentId;

    // Show the modal
    const modalElement = document.getElementById('tournamentModal');
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
}

// Save Tournament (handles both create and update)
async function saveTournament() {

    const name = document.getElementById('tournamentName').value;
    const type = document.getElementById('tournamentType').value;
    const date = document.getElementById('tournamentDate').value;
    const location = document.getElementById('tournamentLocation').value;
    const stadiums = document.getElementById('tournamentStadiums').value;
    const rounds = document.getElementById('tournamentRounds').value;
    const visibility = document.getElementById('tournamentVisibility')?.value || 'team_only';
    const placementCoverage = document.getElementById('placementCoverage')?.value;

    if (!name) {
        showToast('Please enter a tournament name', { variant: 'danger' });
        return;
    }

    if (!type) {
        showToast('Please select a tournament type', { variant: 'danger' });
        return;
    }

    // Validate tournament date
    if (!date || date.trim() === '') {
        showToast('Please select a tournament date', { variant: 'danger' });
        return;
    }

    // Validate that the date is a valid date value
    const parsedDate = new Date(date);
    if (isNaN(parsedDate.getTime())) {
        showToast('Please enter a valid tournament date', { variant: 'danger' });
        return;
    }

    // Validate that the date is not in the past
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    parsedDate.setHours(0, 0, 0, 0);
    if (parsedDate < today) {
        showToast('Tournament date cannot be in the past', { variant: 'danger' });
        return;
    }

    // Validate location
    if (!location || location.trim() === '') {
        showToast('Please enter a tournament location', { variant: 'danger' });
        return;
    }

    // Validate rounds for Swiss tournaments
    if (type === 'swiss') {
        if (!rounds || rounds < 3 || rounds > 15) {
            showToast('Please enter a valid number of rounds (3-15) for Swiss tournament', { variant: 'danger' });
            return;
        }
    }

    // Validate placement coverage for Single/Double Elimination
    if (type === 'single_elimination' || type === 'double_elimination') {
        if (!placementCoverage) {
            showToast('Please select placement cutoff for elimination tournament', { variant: 'danger' });
            return;
        }
    }

    const saveBtn = document.querySelector('button[onclick="saveTournament()"]');
    const originalText = saveBtn.textContent;

    try {
        // Show loading
        saveBtn.textContent = window.editingTournamentId ? 'Updating...' : 'Saving...';
        saveBtn.disabled = true;

        // Prepare tournament data
        const tournamentData = {
            name: name,
            tournament_type: type,
            date: date || null,
            location: location || null,
            number_of_stadiums: parseInt(stadiums) || 1,
            swiss_rounds: type === 'swiss' ? parseInt(rounds) : 5,
            top_cut: 0, // Default for now
            rank_to: (type === 'single_elimination' || type === 'double_elimination') ? parseInt(placementCoverage) : 5,
            rules: type === 'swiss' ? `Swiss tournament with ${rounds} rounds` : null,
            max_participants: 0,
            visibility: visibility
        };

        // Add tournament ID for editing
        if (window.editingTournamentId) {
            tournamentData.tournament_id = window.editingTournamentId;
        }

        
        // Save to database via API
        const response = await fetch('api/tournaments/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: window.editingTournamentId ? 'update' : 'create',
                ...tournamentData
            })
        });

        // Check HTTP status first
        if (!response.ok) {
            if (response.status === 500) {
                throw new Error(`Server error (HTTP ${response.status}) - Check server logs`);
            } else if (response.status === 400) {
                throw new Error(`Bad request (HTTP ${response.status}) - Invalid data sent to server`);
            } else if (response.status === 405) {
                throw new Error(`Method not allowed (HTTP ${response.status})`);
            } else {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
        }

        // Get response text first to debug
        const responseText = await response.text();

        // Check for empty response
        if (!responseText.trim()) {
            throw new Error('Empty response from server - backend may have crashed or failed to output JSON');
        }

        // Try to parse JSON
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (jsonError) {
            throw new Error(`Invalid JSON response from server. Raw response: "${responseText}"`);
        }

        // Validate response structure
        if (typeof result !== 'object' || result === null) {
            throw new Error('Invalid response format - expected JSON object');
        }

        if (result.success) {
            // Close modal
            const modalElement = document.getElementById('tournamentModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }

            // Reset form and modal state
            resetModalForm();

            // Reload tournaments list
            await loadTournaments();

            showToast(window.editingTournamentId ? 'Tournament updated successfully!' : 'Tournament created successfully!', { variant: 'success' });
        } else {
            const errorMessage = result.message || 'Unknown server error';
            showToast('Failed to ' + (window.editingTournamentId ? 'update' : 'create') + ' tournament: ' + errorMessage, { variant: 'danger' });
        }

    } catch (err) {
        showToast('Failed to ' + (window.editingTournamentId ? 'update' : 'create') + ' tournament: ' + err.message, { variant: 'danger' });
    } finally {
        // Restore button
        saveBtn.textContent = originalText;
        saveBtn.disabled = false;
    }
}

function resetModalForm() {
    // Reset form
    document.getElementById('tournamentForm').reset();
    toggleSwissRoundsField(false);
    const visibilitySelect = document.getElementById('tournamentVisibility');
    if (visibilitySelect) {
        visibilitySelect.value = 'team_only';
    }

    // Reset placement coverage field
    const placementCoverageSelect = document.getElementById('placementCoverage');
    if (placementCoverageSelect) {
        placementCoverageSelect.value = '';
    }

    // Reset modal title and button
    document.querySelector('#tournamentModal .modal-title').textContent = 'Create Tournament';
    document.querySelector('button[onclick="saveTournament()"]').textContent = 'Create Tournament';

    // Clear editing mode
    window.editingTournamentId = null;
}

// Logout handler
async function handleLogout() {

    const confirmed = await showConfirmation({
        title: 'Logout',
        message: 'Are you sure you want to logout?',
        confirmText: 'Logout',
        confirmVariant: 'danger'
    });

    if (!confirmed) {
        return;
    }

    window.location.href = 'index.html';
}

// Make functions globally accessible
window.createTournament = createTournament;
window.saveTournament = saveTournament;
window.viewTournament = viewTournament;
window.editTournament = editTournament;
window.deleteTournament = deleteTournament;
window.resetModalForm = resetModalForm;
window.handleLogout = handleLogout;
