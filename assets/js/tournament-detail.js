// Tournament Detail JavaScript

let currentTournament = null;
var currentUser = null;

function refreshTopCutDisplay(topCutValue) {
    const topCutDisplay = document.getElementById('topCutDisplay');
    const topCutMeta = document.getElementById('topCutMeta');

    if (!topCutDisplay || !topCutMeta) return;

    const parsed = parseInt(topCutValue, 10);
    const hasTopCut = Number.isInteger(parsed) && parsed > 0;

    topCutDisplay.textContent = hasTopCut ? `Top Cut: Top ${parsed}` : 'Top Cut: Not set';
    topCutMeta.classList.toggle('text-muted', !hasTopCut);
    topCutMeta.style.display = '';
}

function formatOrdinal(value) {
    const n = parseInt(value, 10);
    if (!Number.isInteger(n)) return `${value}`;
    const remainder100 = n % 100;
    if (remainder100 >= 11 && remainder100 <= 13) {
        return `${n}th`;
    }
    const remainder10 = n % 10;
    switch (remainder10) {
        case 1: return `${n}st`;
        case 2: return `${n}nd`;
        case 3: return `${n}rd`;
        default: return `${n}th`;
    }
}

function isTournamentEditingLocked() {
    return currentTournament && !['upcoming', 'registration'].includes(currentTournament.status);
}

function ensureTournamentEditable() {
    if (isTournamentEditingLocked()) {
        showToast('Tournament is locked once it has started. No further changes are allowed.', { variant: 'warning' });
        return false;
    }
    return true;
}

function showElement(element, displayValue = '') {
    if (!element) return;
    element.classList.remove('is-hidden');
    if (displayValue) {
        element.style.display = displayValue;
    } else {
        element.style.removeProperty('display');
    }
}

function hideElement(element) {
    if (!element) return;
    element.classList.add('is-hidden');
    element.style.display = 'none';
}

// Initialize page
document.addEventListener('DOMContentLoaded', function () {

    // Get tournament ID from URL
    const urlParams = new URLSearchParams(window.location.search);
    const tournamentId = urlParams.get('id');

    if (!tournamentId) {
        showError('No tournament ID provided');
        return;
    }

    // Load tournament details
    loadTournamentDetails(tournamentId);

    // Setup logout
    document.getElementById('logoutBtn').addEventListener('click', async function () {
        const confirmed = await showConfirmation({
            title: 'Logout',
            message: 'Are you sure you want to logout?',
            confirmText: 'Logout',
            confirmVariant: 'danger'
        });

        if (confirmed) {
            window.location.href = 'index.html';
        }
    });

    // Event listener for manual top cut changes
    const topCutInput = document.getElementById('topCutInput');
    if (topCutInput) {
        topCutInput.addEventListener('input', function () {
            // Mark as manually changed
            this.dataset.autoCalculated = 'false';
        });
    }

});

// Load tournament details
async function loadTournamentDetails(tournamentId) {
    try {

        // Fetch fresh data from API
        const response = await fetch(`api/tournaments/create.php?action=getDetails&tournament_id=${tournamentId}`);
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid response from server');
        }

        if (result.success) {
            currentTournament = result.tournament;

            // Check permissions before displaying anything
            // Fetch current user to verify ownership
            try {
                const profileResponse = await fetch('api/users/profile.php');
                const profileResult = await profileResponse.json();

                if (profileResult.success) {
                    currentUser = profileResult.profile;

                    // Strict Access Control: Only Creator/Organizer can view this management page
                    const isCreator = currentUser.id === currentTournament.created_by;
                    // Also check if user has 'organizer' role explicitly in result.people
                    const hasOrganizerRole = result.people.some(p => p.user_id === currentUser.id && p.role === 'organizer');

                    if (!isCreator && !hasOrganizerRole) {
                        window.location.href = `tournament-bracket.html?id=${currentTournament.id}`;
                        return;
                    }
                } else {
                    // Not logged in
                    window.location.href = `tournament-bracket.html?id=${currentTournament.id}`;
                    return;
                }
            } catch (authError) {
                window.location.href = `tournament-bracket.html?id=${currentTournament.id}`;
                return;
            }

            displayTournamentDetails(result);

            // Fetch and display people with roles
            const peopleResponse = await fetch(`api/tournaments/roles.php?action=getPeople&tournament_id=${tournamentId}`);
            const peopleResult = await peopleResponse.json();

            if (peopleResult.success) {
                displayPeople(peopleResult.people || []);
            } else {
                displayPeople([]);
            }

            // Load check-in data if applicable
            await loadCheckInData();
        } else {
            showError(result.message || 'Failed to load tournament details');
        }

    } catch (error) {
        showError('Failed to load tournament details');
    }
}

// Link to dedicated bracket page
function viewBracket() {
    window.location.href = `tournament-bracket.html?id=${currentTournament.id}`;
}


async function startTournament() {
    const confirmed = await showConfirmation({
        title: 'Start Tournament',
        message: 'This will generate pairings for all rounds and initialize the match engine. Continue?',
        confirmText: 'Start'
    });

    if (!confirmed) return;

    // Get top cut and rank to values
    const topCutInput = document.getElementById('topCutInput');
    const rankToInput = document.getElementById('rankToInput');
    const topCutValue = topCutInput ? parseInt(topCutInput.value) || 0 : 0;
    const rankToValue = rankToInput ? parseInt(rankToInput.value) || 3 : 3;

    // First update status to ongoing (backend handles this now)
    try {
        const params = new URLSearchParams({
            tournament_id: currentTournament.id,
            top_cut: topCutValue,
            rank_to: rankToValue
        });

        const response = await fetch('api/tournaments/rounds.php?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        });
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid response from server');
        }
        if (result.success) {
            showToast(result.message, { variant: 'success' });
            // Redirect to bracket page after start
            setTimeout(() => {
                const url = `tournament-bracket.html?id=${currentTournament.id}`;
                window.location.href = url;
            }, 500);
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        console.error('Start tournament error:', error);
        showToast('Failed to start tournament.', { variant: 'danger' });
    }
}

window.startTournament = startTournament;
window.viewBracket = viewBracket;

async function generateNextRound() {
    try {
        const response = await fetch('api/tournaments/rounds.php?action=generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ tournament_id: currentTournament.id })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, { variant: 'success' });
            loadTournamentDetails(currentTournament.id);
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        showToast('Failed to generate round.', { variant: 'danger' });
    }
}

async function advanceToTopCut() {
    const confirmed = await showConfirmation({
        title: 'Advance to Top Cut',
        message: 'This will generate a single elimination bracket for the top players based on current standings. Make sure all Swiss rounds are complete. Continue?',
        confirmText: 'Generate Bracket',
        confirmVariant: 'primary'
    });

    if (!confirmed) return;

    try {
        const response = await fetch('api/tournaments/rounds.php?action=advanceToTopCut', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ tournament_id: currentTournament.id })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, { variant: 'success' });
            // Redirect to bracket page to view the new topcut bracket
            setTimeout(() => {
                window.location.href = `tournament-bracket.html?id=${currentTournament.id}`;
            }, 500);
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        console.error('Advance to top cut error:', error);
        showToast('Failed to generate top cut bracket.', { variant: 'danger' });
    }
}

window.advanceToTopCut = advanceToTopCut;


// Display tournament details
function displayTournamentDetails(data) {
    const tournament = data.tournament;

    // Update basic info
    document.getElementById('tournamentName').textContent = tournament.name || 'Unknown Tournament';
    document.getElementById('tournamentDate').textContent = tournament.date ? formatDate(tournament.date) : 'No date set';
    document.getElementById('tournamentLocation').textContent = tournament.location || 'TBD';
    document.getElementById('createdBy').textContent = tournament.creator_name || 'Unknown';

    // Update type and status badges
    const typeBadge = document.getElementById('tournamentTypeBadge');
    const typeLabel = getTournamentTypeLabel(tournament.tournament_type);
    const typeIcon = getTournamentIcon(tournament.tournament_type);

    typeBadge.innerHTML = `
        <i class="bi ${typeIcon}"></i>
        <span>${typeLabel}</span>
    `;

    refreshTopCutDisplay(tournament.top_cut);

    // Update status badge
    const statusBadge = document.getElementById('tournamentStatusBadge');
    statusBadge.textContent = getStatusLabel(tournament.status || 'upcoming');
    statusBadge.className = `tournament-status-badge ${tournament.status || 'upcoming'}`;

    // Update info cards
    document.getElementById('participantCount').textContent = data.participant_count || 0;
    document.getElementById('numberOfStadiums').textContent = tournament.number_of_stadiums || 0;
    document.getElementById('judgeCount').textContent = data.judge_count || 0;

    const topCutInput = document.getElementById('topCutInput');
    if (topCutInput) {
        const topCutVal = parseInt(tournament.top_cut, 10);
        if (Number.isInteger(topCutVal) && topCutVal > 0) {
            topCutInput.value = topCutVal;
            topCutInput.dataset.autoCalculated = 'false';
        } else {
            topCutInput.value = '';
            topCutInput.dataset.autoCalculated = 'true';
        }
    }

    // Show rules if available
    if (tournament.rules && tournament.rules.trim()) {
        const rulesSection = document.getElementById('rulesSection');
        const rulesContent = document.getElementById('tournamentRules');
        rulesSection.style.display = 'block';
        rulesContent.textContent = tournament.rules;
    }

    // Show management section if user is creator (for now, always show for testing)
    const managementSection = document.getElementById('managementSection');
    showElement(managementSection);

    // Manage Tournament Control Buttons
    const startBtn = document.getElementById('startTournamentBtn');
    const finishBtn = document.getElementById('finishTournamentBtn');
    const viewBtn = document.getElementById('viewBracketBtn');
    const standingsBtn = document.getElementById('viewStandingsBtn');
    const rankToSection = document.getElementById('rankToSection');
    const topCutSection = document.getElementById('topCutSection');

    // Hide Top Cut Size and Podium fields for non-Swiss tournaments
    const isSwiss = tournament.tournament_type === 'swiss';
    if (!isSwiss) {
        hideElement(topCutSection);
        hideElement(rankToSection);
    } else {
        showElement(topCutSection);
    }

    if (tournament.status === 'upcoming' || tournament.status === 'registration') {
        if (startBtn) showElement(startBtn, 'block');
        if (finishBtn) hideElement(finishBtn);
        if (viewBtn) hideElement(viewBtn);
        if (standingsBtn) hideElement(standingsBtn);
        if (rankToSection && isSwiss) showElement(rankToSection);
    } else if (tournament.status === 'ongoing' || tournament.status === 'completed') {
        if (startBtn) hideElement(startBtn);
        if (finishBtn) {
            if (tournament.status === 'ongoing') {
                showElement(finishBtn, 'block');
            } else {
                hideElement(finishBtn);
            }
        }
        if (viewBtn) {
            showElement(viewBtn, 'block');
            viewBtn.href = `tournament-bracket.html?id=${tournament.id}`;
        }
        if (standingsBtn) {
            showElement(standingsBtn, 'block');
            standingsBtn.href = `tournament-bracket.html?id=${tournament.id}#standings`;
        }
        if (rankToSection) hideElement(rankToSection);
    }

    // Handle Podium
    if (tournament.status === 'completed') {
        fetchPodium(tournament.id);
    } else {
        const podiumSection = document.getElementById('podiumSection');
        if (podiumSection) hideElement(podiumSection);
    }

    // Show add people button when tournament is still open (temporarily always show for testing)
    const addPeopleBtn = document.getElementById('addPeopleBtn');
    const shuffleSeedsBtn = document.getElementById('shuffleSeedsBtn');
    const topCutHelperText = document.getElementById('topCutHelperText');

    const isEditable = ['upcoming', 'registration'].includes(tournament.status);

    // Toggle Add/Shuffle buttons in both roster views
    const commonButtons = [
        { id: 'addPeopleBtn', show: isEditable },
        { id: 'addPeopleBtnSimple', show: isEditable },
        { id: 'shuffleSeedsBtn', show: isEditable },
        { id: 'shuffleSeedsBtnSimple', show: isEditable },
        { id: 'topCutHelperText', show: isEditable, display: 'block' }
    ];

    commonButtons.forEach(btn => {
        const el = document.getElementById(btn.id);
        if (el) {
            if (btn.show) showElement(el, btn.display || '');
            else hideElement(el);
        }
    });

    if (topCutInput) {
        topCutInput.disabled = !isEditable;
    }
}

// Display judges
function displayJudges(judges) {
    const judgesList = document.getElementById('judgesList');

    if (judges.length === 0) {
        judgesList.innerHTML = `
            <div class="empty-judges">
                <i class="bi bi-gavel"></i>
                <h4>No judges assigned</h4>
                <p>Add judges to help manage this tournament.</p>
            </div>
        `;
        return;
    }

    judgesList.innerHTML = judges.map(judge => `
        <div class="judge-card" data-judge-id="${judge.user_id}">
            <div class="judge-avatar">
                ${judge.display_name ? judge.display_name.charAt(0).toUpperCase() : 'J'}
            </div>
            <div class="participant-info">
                <div class="participant-name">${judge.display_name || 'Unknown Judge'}</div>
                <div class="participant-date">Assigned ${formatDate(judge.assigned_at)}</div>
            </div>
            <div class="participant-actions">
                <button class="btn btn-sm btn-outline-danger" onclick="removeJudge('${judge.user_id}', '${judge.display_name || 'Unknown Judge'}')" title="Remove Judge">
                    <i class="bi bi-person-dash"></i>
                </button>
            </div>
        </div>
    `).join('');
}

// Calculate recommended top cut size based on player count
function calculateTopCut(playerCount) {
    if (playerCount < 4) return 2; // Minimum top cut

    // Formula: players/2, then round to nearest power of 2
    const halfPlayers = playerCount / 2;
    const nearestPowerOf2 = Math.pow(2, Math.round(Math.log2(halfPlayers)));

    return nearestPowerOf2;
}

// Update top cut field
function updateTopCutField(playerCount) {
    const topCutInput = document.getElementById('topCutInput');
    const topCutSection = document.getElementById('topCutSection');

    if (!topCutInput || !topCutSection) return;

    const recommendedTopCut = calculateTopCut(playerCount);

    // Only auto-update if field is empty or hasn't been manually changed
    if (!topCutInput.value || topCutInput.dataset.autoCalculated === 'true') {
        topCutInput.value = recommendedTopCut;
        topCutInput.dataset.autoCalculated = 'true';
    }

    if (currentTournament && ['upcoming', 'registration'].includes(currentTournament.status) && currentTournament.tournament_type === 'swiss') {
        showElement(topCutSection);
    }
}

// Display people with roles
function displayPeople(people) {
    const tabPeopleList = document.getElementById('tabPeopleList');
    const simplePeopleList = document.getElementById('simplePeopleList');
    const tabShuffleIndicator = document.getElementById('seedShuffleIndicator');
    const simpleShuffleIndicator = document.getElementById('seedShuffleIndicatorSimple');

    if (people.length === 0) {
        hideElement(document.getElementById('tabPlayerCountSummary'));
        hideElement(document.getElementById('simplePlayerCountSummary'));

        const emptyHtml = `
            <div class="col-12">
                <div class="empty-people text-center py-5">
                    <i class="bi bi-people display-4 text-muted mb-3"></i>
                    <h4 class="text-muted">No people added yet</h4>
                    <p class="text-muted">Add players and judges to kick off this tournament.</p>
                </div>
            </div>
        `;

        if (tabPeopleList) tabPeopleList.innerHTML = emptyHtml;
        if (simplePeopleList) simplePeopleList.innerHTML = emptyHtml;
        return;
    }

    // Update player count summary
    const totalPlayers = people.filter(p => {
        const roles = p.role.split(',').map(r => r.trim());
        return roles.includes('player') || roles.includes('both');
    }).length;

    const checkedInPlayers = people.filter(p => {
        const roles = p.role.split(',').map(r => r.trim());
        return (roles.includes('player') || roles.includes('both')) && p.registration_status === 'CHECKED_IN';
    }).length;

    // Update Tab Summary
    const tabSummary = document.getElementById('tabPlayerCountSummary');
    const tabCount = document.getElementById('tabTotalPlayersCount');
    if (tabSummary && tabCount) {
        tabCount.textContent = `${checkedInPlayers} / ${totalPlayers} Checked In`;
        showElement(tabSummary, 'flex');
    }

    // Update Simple Summary
    const simpleSummary = document.getElementById('simplePlayerCountSummary');
    const simpleCount = document.getElementById('simpleTotalPlayersCount');
    if (simpleSummary && simpleCount) {
        simpleCount.textContent = `${checkedInPlayers} / ${totalPlayers} Checked In`;
        showElement(simpleSummary, 'flex');
    }

    // Reset shuffle indicators
    [tabShuffleIndicator, simpleShuffleIndicator].forEach(indicator => {
        if (indicator) {
            indicator.style.display = 'none';
            indicator.classList.remove('is-visible');
            indicator.classList.remove('recent');
            indicator.innerHTML = '';
        }
    });

    // Update top cut field based on player count
    updateTopCutField(totalPlayers);

    const locked = isTournamentEditingLocked();

    const participantsHtml = people.map(person => {
        const isCreator = currentTournament && person.user_id === currentTournament.created_by;
        const roles = person.role.split(',');
        const isPlayer = roles.includes('player') || roles.includes('both');
        const isJudge = roles.includes('judge') || roles.includes('both');
        const isBoth = (roles.includes('player') && roles.includes('judge')) || roles.includes('both');
        const isObserver = roles.includes('observer');

        const roleClass = isBoth ? 'both-roles' : (isPlayer ? 'player-only' : (isJudge ? 'judge-only' : (isObserver ? 'observer-only' : 'organizer-only')));
        const avatarClass = roleClass;
        const displayName = person.display_name || person.blader_name || 'Unknown';
        const seedNumber = parseInt(person.seed, 10) || 0;
        const hasSeed = isPlayer && seedNumber > 0;
        const seedBadge = isPlayer
            ? `<span class="seed-badge ${hasSeed ? '' : 'seed-badge-pending'}">${hasSeed ? `#${seedNumber}` : 'Unseeded'}</span>`
            : '';

        let statusBadge = '';
        if (isPlayer) {
            if (person.registration_status === 'CHECKED_IN') {
                statusBadge = '<span class="badge bg-success rounded-pill ms-1" style="font-size: 0.65rem;"><i class="bi bi-check-circle me-1"></i>Checked In</span>';
            } else if (person.registration_status === 'REGISTERED') {
                statusBadge = '<span class="badge bg-warning text-dark rounded-pill ms-1" style="font-size: 0.65rem;"><i class="bi bi-clock me-1"></i>Wait</span>';
            }
        }

        let roleBadges = '';
        if (isCreator) {
            roleBadges += '<span class="role-badge organizer">Organizer</span>';
        }
        if (isPlayer) {
            roleBadges += '<span class="role-badge player">Player</span>';
        }
        if (isJudge) {
            roleBadges += '<span class="role-badge judge">Judge</span>';
        }
        if (isObserver) {
            roleBadges += '<span class="role-badge observer">Observer</span>';
        }

        const actionButtons = locked ? '' : `
                        <div class="participant-actions d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary" onclick="editPersonRole('${person.user_id}')" title="Edit Role">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="removePerson('${person.user_id}', '${displayName}')" title="Remove Person">
                                <i class="bi bi-person-dash"></i>
                            </button>
                        </div>
        `;

        return `
            <div class="col-md-4 mb-3">
                <div class="person-card ${roleClass} h-100" data-person-id="${person.user_id}" data-seed="${hasSeed ? seedNumber : ''}">
                    <div class="person-avatar ${avatarClass}">
                        ${displayName.charAt(0).toUpperCase()}
                    </div>
                    <div class="person-info">
                        <div class="person-name-row">
                            <div class="person-name text-truncate" title="${displayName}">${displayName}</div>
                            ${seedBadge}
                            ${statusBadge}
                        </div>
                        <div class="person-date">Added ${formatDate(person.assigned_at)}</div>
                        <div class="person-roles">
                            ${roleBadges}
                        </div>
                    </div>
                    ${actionButtons}
                </div>
            </div>
        `;
    }).join('');

    if (tabPeopleList) tabPeopleList.innerHTML = participantsHtml;
    if (simplePeopleList) simplePeopleList.innerHTML = participantsHtml;
}
// Display participants (deprecated - use displayPeople instead)
function displayParticipants(participants) {
    // ...
    const participantsList = document.getElementById('participantsList');

    if (participants.length === 0) {
        participantsList.innerHTML = `
            <div class="empty-participants">
                <i class="bi bi-person-x"></i>
                <h4>No players yet</h4>
                <p>Add players to kick off this tournament.</p>
            </div>
        `;
        return;
    }

    participantsList.innerHTML = participants.map(participant => `
        <div class="participant-card" data-player-id="${participant.user_id}">
            <div class="participant-avatar">
                ${participant.display_name ? participant.display_name.charAt(0).toUpperCase() : 'U'}
            </div>
            <div class="participant-info">
                <div class="participant-name">${participant.display_name || 'Unknown Player'}</div>
                <div class="participant-date">Joined ${formatDate(participant.registered_at)}</div>
            </div>
            <div class="participant-actions">
                <button class="btn btn-sm btn-outline-danger" onclick="removePlayer('${participant.user_id}', '${participant.display_name || 'Unknown Player'}')" title="Remove Player">
                    <i class="bi bi-person-dash"></i>
                </button>
            </div>
        </div>
    `).join('');
}

// Add people to tournament (unified roles system)
async function addPeople() {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    try {
        // Fetch available users
        const response = await fetch(`api/tournaments/roles.php?action=getAvailableUsers&tournament_id=${currentTournament.id}`);
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid response from server');
        }

        if (!result.success) {
            showError('Failed to load users');
            return;
        }

        const availableUsers = result.users;
        if (availableUsers.length === 0) {
            showToast('No available users to add', { variant: 'info' });
            return;
        }

        // Step 1: Select people
        const selectedUsers = await showPeopleSelectionModal(availableUsers);

        if (!selectedUsers || selectedUsers.length === 0) {
            return;
        }

        // Step 2: Set roles
        const peopleWithRoles = await showRoleSelectionModal(selectedUsers);

        if (!peopleWithRoles || peopleWithRoles.length === 0) {
            return;
        }

        // Add people with roles
        const response2 = await fetch('api/tournaments/roles.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'addPeople',
                tournament_id: currentTournament.id,
                people: JSON.stringify(peopleWithRoles)
            })
        });

        const text2 = await response2.text();
        let result2;
        try {
            result2 = JSON.parse(text2);
        } catch (e) {
            throw new Error('Invalid response from server');
        }

        if (result2.success) {
            const successCount = result2.successes.length;
            const failureCount = result2.failures.length;

            if (successCount > 0) {
                showToast(`Successfully added ${successCount} person(s).`, { variant: 'success' });
            }

            if (failureCount > 0) {
                const failureMessages = result2.failures.join('\n');
                showToast(`Some operations failed:\n${failureMessages}`, { variant: 'warning' });
            }

            await loadTournamentDetails(currentTournament.id);
        } else {
            showToast(`Failed to add people: ${result2.message}`, { variant: 'danger' });
        }

    } catch (error) {
        showError('Failed to load users');
    }
}

// Show people selection modal (Step 1)
/**
 * Step 1: User Selection Logic
 * Isolated into a class for stability.
 */
class UserSelectionHandler {
    constructor(users, resolve) {
        this.users = users;
        this.resolve = resolve;
        this.modalId = 'people-selection-modal';
        this.modal = null;
        this.modalEl = null;
    }

    init() {
        this.cleanup();
        this.render();
        this.modal = new bootstrap.Modal(this.modalEl);
        this.attachEventListeners();
        this.modal.show();
    }

    cleanup() {
        const existing = document.getElementById(this.modalId);
        if (existing) existing.remove();
    }

    render() {
        this.modalEl = document.createElement('div');
        this.modalEl.id = this.modalId;
        this.modalEl.className = 'modal fade';
        this.modalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Select People to Add</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>${this.users.length}</strong> available person${this.users.length !== 1 ? 's' : ''} to add
                        </div>
                        <div class="mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn">Select All</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">Deselect All</button>
                                    <span class="badge bg-primary ms-2 align-middle">Selected: <span id="selectedPeopleCount">0</span></span>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control form-control-sm" id="peopleSearch" placeholder="Search people...">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="input-group">
                                <input type="text" class="form-control form-control-sm" id="unregisteredPlayerName" placeholder="Add unregistered player by name...">
                                <button class="btn btn-success btn-sm" type="button" id="addUnregisteredBtn">
                                    <i class="bi bi-plus-circle me-1"></i>Add
                                </button>
                            </div>
                            <small class="text-muted">Auto-registers player with temporary credentials (name@bbx.test / test123)</small>
                        </div>
                        <div class="people-list" style="max-height: 400px; overflow-y: auto;">
                            <div class="row">
                                <div class="col-md-6" id="peopleCol1"></div>
                                <div class="col-md-6" id="peopleCol2"></div>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirm-select-people">Next: Set Roles →</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Populate columns
        const col1 = this.modalEl.querySelector('#peopleCol1');
        const col2 = this.modalEl.querySelector('#peopleCol2');

        this.users.forEach((user, index) => {
            const displayName = escapeHtml(user.display_name || user.blader_name || user.email);
            const userJson = JSON.stringify(user).replace(/'/g, '&apos;');
            const html = `
                <div class="form-check person-item mb-2" data-search="${displayName.toLowerCase()} ${user.email.toLowerCase()}">
                    <input class="form-check-input person-checkbox" type="checkbox" id="user-${user.id}" data-user='${userJson}'>
                    <label class="form-check-label text-truncate w-100" for="user-${user.id}">
                        <strong>${displayName}</strong><br>
                        <small class="text-muted">${user.email}</small>
                    </label>
                </div>
            `;
            if (index % 2 === 0) col1.innerHTML += html;
            else col2.innerHTML += html;
        });

        document.body.appendChild(this.modalEl);
    }

    attachEventListeners() {
        this.modalEl.querySelector('#selectAllBtn').addEventListener('click', () => {
            this.modalEl.querySelectorAll('.person-checkbox:not(:disabled)').forEach(cb => cb.checked = true);
            this.updateSelectedCount();
        });

        this.modalEl.querySelector('#deselectAllBtn').addEventListener('click', () => {
            this.modalEl.querySelectorAll('.person-checkbox').forEach(cb => cb.checked = false);
            this.updateSelectedCount();
        });

        const searchInput = this.modalEl.querySelector('#peopleSearch');
        searchInput.addEventListener('keyup', () => {
            const term = searchInput.value.toLowerCase();
            this.modalEl.querySelectorAll('.person-item').forEach(item => {
                const searchValue = item.getAttribute('data-search');
                item.style.display = searchValue.includes(term) ? 'block' : 'none';
            });
        });

        this.modalEl.querySelectorAll('.person-checkbox').forEach(cb => {
            cb.addEventListener('change', () => this.updateSelectedCount());
        });

        // Initialize selected count on open (covers pre-checked states if reused)
        this.updateSelectedCount();

        // Add unregistered player button handler
        this.modalEl.querySelector('#addUnregisteredBtn').addEventListener('click', async () => {
            const nameInput = this.modalEl.querySelector('#unregisteredPlayerName');
            const playerName = nameInput.value.trim();

            if (!playerName) {
                showToast('Please enter a player name', { variant: 'warning' });
                return;
            }

            try {
                const response = await fetch('api/auth/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'register',
                        email: `${playerName.toLowerCase().replace(/\s+/g, '')}@bbx.test`,
                        password: 'test123',
                        blader_name: playerName,
                        display_name: playerName
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Add the newly created user to the list
                    const newUser = {
                        id: result.user_id,
                        email: `${playerName.toLowerCase().replace(/\s+/g, '')}@bbx.test`,
                        display_name: playerName,
                        blader_name: playerName
                    };
                    this.users.push(newUser);

                    // Add to the UI
                    const col1 = this.modalEl.querySelector('#peopleCol1');
                    const col2 = this.modalEl.querySelector('#peopleCol2');
                    const displayName = escapeHtml(playerName);
                    const userJson = JSON.stringify(newUser).replace(/'/g, '&apos;');
                    const html = `
                        <div class="form-check person-item mb-2" data-search="${displayName.toLowerCase()} ${newUser.email.toLowerCase()}">
                            <input class="form-check-input person-checkbox" type="checkbox" id="user-${newUser.id}" data-user='${userJson}' checked>
                            <label class="form-check-label text-truncate w-100" for="user-${newUser.id}">
                                <strong>${displayName}</strong><br>
                                <small class="text-muted">${newUser.email}</small>
                            </label>
                        </div>
                    `;
                    if (this.users.length % 2 === 0) {
                        col1.innerHTML += html;
                    } else {
                        col2.innerHTML += html;
                    }

                    nameInput.value = '';
                    this.updateSelectedCount();

                    showToast(`Player "${playerName}" registered successfully`, { variant: 'success' });
                } else {
                    showToast(`Failed to register player: ${result.message}`, { variant: 'danger' });
                }
            } catch (error) {
                console.error('Error registering player:', error);
                showToast('Failed to register player. Please try again.', { variant: 'danger' });
            }
        });

        this.modalEl.querySelector('#confirm-select-people').addEventListener('click', () => {
            const selectedCheckboxes = this.modalEl.querySelectorAll('.person-checkbox:checked');
            const selectedUsers = Array.from(selectedCheckboxes).map(cb => {
                try {
                    return JSON.parse(cb.getAttribute('data-user'));
                } catch (e) { return null; }
            }).filter(Boolean);

            this.modal.hide();
            this.resolve(selectedUsers);
        });

        this.modalEl.addEventListener('hidden.bs.modal', () => {
            this.modalEl.remove();
            this.resolve(null);
        });
    }

    updateSelectedCount() {
        const selected = this.modalEl.querySelectorAll('.person-checkbox:checked').length;
        const counter = this.modalEl.querySelector('#selectedPeopleCount');
        if (counter) counter.textContent = selected;
    }
}

function showPeopleSelectionModal(users) {
    return new Promise((resolve) => {
        const handler = new UserSelectionHandler(users, resolve);
        handler.init();
    });
}

// Show role selection modal (Step 2)
/**
 * Refactored Role Selection Logic
 * Isolated into a class to prevent global namespace pollution and regression during edits.
 */
class RoleSelectionHandler {
    constructor(selectedUsers, resolve) {
        this.selectedUsers = selectedUsers;
        this.resolve = resolve;
        this.modalId = 'role-selection-modal';
        this.modal = null;
        this.modalEl = null;
    }

    init() {
        this.cleanup();
        this.render();
        this.modal = new bootstrap.Modal(this.modalEl);
        this.attachEventListeners();
        this.modal.show();
        this.updateCounters();
    }

    cleanup() {
        const existing = document.getElementById(this.modalId);
        if (existing) existing.remove();
    }

    render() {
        const userRows = this.selectedUsers.map((user, index) => {
            const displayName = user.display_name || user.blader_name || user.email;
            const initialRole = user.initialRole || 'player';

            return `
                <div class="row mb-3 align-items-center role-item" data-search="${displayName.toLowerCase()}">
                    <div class="col-md-4">
                        <strong>${displayName}</strong>
                    </div>
                    <div class="col-md-8 text-md-end">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="role-${index}" id="player-${index}" value="player" ${initialRole === 'player' ? 'checked' : ''}>
                            <label class="btn btn-outline-primary" for="player-${index}">Player</label>
                            
                            <input type="radio" class="btn-check" name="role-${index}" id="judge-${index}" value="judge" ${initialRole === 'judge' ? 'checked' : ''}>
                            <label class="btn btn-outline-success" for="judge-${index}">Judge</label>
                            
                            <input type="radio" class="btn-check" name="role-${index}" id="both-${index}" value="both" ${initialRole === 'both' ? 'checked' : ''}>
                            <label class="btn btn-outline-warning" for="both-${index}">Both</label>

                            <input type="radio" class="btn-check" name="role-${index}" id="observer-${index}" value="observer" ${initialRole === 'observer' ? 'checked' : ''}>
                            <label class="btn btn-outline-info" for="observer-${index}">Observer</label>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        this.modalEl = document.createElement('div');
        this.modalEl.id = this.modalId;
        this.modalEl.className = 'modal fade';
        this.modalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Set Roles for ${this.selectedUsers.length} Selected People</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Set the role for each person. They can be a player, judge, both, or observer.
                                    <div class="mt-2">
                                        <span class="badge bg-primary me-2">Players: <span id="modalPlayerCount">0</span></span>
                                        <span class="badge bg-success me-2">Judges: <span id="modalJudgeCount">0</span></span>
                                        <span class="badge bg-info">Observers: <span id="modalObserverCount">0</span></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" class="form-control" id="roleSearch" placeholder="Search selected people...">
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="individual-roles" style="max-height: 400px; overflow-y: auto;">
                            ${userRows}
                        </div>
                        <div class="mt-3 text-end">
                            <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="confirm-add-people">Add People</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(this.modalEl);
    }

    attachEventListeners() {
        // Search
        const searchInput = this.modalEl.querySelector('#roleSearch');
        searchInput.addEventListener('keyup', () => this.filterRoles(searchInput.value));

        // Counters
        this.modalEl.querySelectorAll('.btn-check').forEach(radio => {
            radio.addEventListener('change', () => this.updateCounters());
        });

        // Confirm
        this.modalEl.querySelector('#confirm-add-people').addEventListener('click', () => this.confirm());

        // Cleanup on hidden
        this.modalEl.addEventListener('hidden.bs.modal', () => {
            this.modalEl.remove();
            this.resolve(null);
        });
    }

    filterRoles(term) {
        const searchTerm = term.toLowerCase();
        this.modalEl.querySelectorAll('.role-item').forEach(item => {
            const searchValue = item.getAttribute('data-search');
            item.style.display = searchValue.includes(searchTerm) ? 'flex' : 'none';
        });
    }

    updateCounters() {
        let playerCount = 0;
        let judgeCount = 0;
        let observerCount = 0;

        this.selectedUsers.forEach((user, index) => {
            const selectedRole = this.modalEl.querySelector(`input[name="role-${index}"]:checked`)?.value || 'player';
            if (selectedRole === 'player' || selectedRole === 'both') playerCount++;
            if (selectedRole === 'judge' || selectedRole === 'both') judgeCount++;
            if (selectedRole === 'observer') observerCount++;
        });

        const playerSpan = this.modalEl.querySelector('#modalPlayerCount');
        const judgeSpan = this.modalEl.querySelector('#modalJudgeCount');
        const observerSpan = this.modalEl.querySelector('#modalObserverCount');
        if (playerSpan) playerSpan.textContent = playerCount;
        if (judgeSpan) judgeSpan.textContent = judgeCount;
        if (observerSpan) observerSpan.textContent = observerCount;
    }

    confirm() {
        const peopleWithRoles = this.selectedUsers.map((user, index) => {
            const role = this.modalEl.querySelector(`input[name="role-${index}"]:checked`)?.value || 'player';
            return {
                user_id: user.id,
                role: role,
                display_name: user.display_name || user.blader_name || user.email
            };
        });

        this.modal.hide();
        // The hidden.bs.modal listener will handle the resolve(null) normally, 
        // but here we resolve the actual data.
        this.resolve(peopleWithRoles);
    }
}

function showRoleSelectionModal(selectedUsers) {
    return new Promise((resolve) => {
        const handler = new RoleSelectionHandler(selectedUsers, resolve);
        handler.init();
    });
}

// Edit person role (for existing participants)
async function editPersonRole(userId) {
    if (!currentTournament) return;

    if (!ensureTournamentEditable()) return;

    // Find the person in the current list
    try {
        const response = await fetch(`api/tournaments/roles.php?action=getPeople&tournament_id=${currentTournament.id}`);
        const result = await response.json();

        if (!result.success) {
            showToast('Failed to load person details', { variant: 'danger' });
            return;
        }

        const person = result.people.find(p => p.user_id === userId);
        if (!person) {
            showToast('Person not found', { variant: 'danger' });
            return;
        }

        // Map database role string to UI role value
        let initialRole = 'player';
        if (person.role.includes('player') && person.role.includes('judge')) {
            initialRole = 'both';
        } else if (person.role.includes('judge')) {
            initialRole = 'judge';
        } else if (person.role.includes('observer')) {
            initialRole = 'observer';
        }

        const personObj = {
            id: person.user_id,
            display_name: person.display_name || person.blader_name || person.email,
            initialRole: initialRole
        };

        const peopleWithRoles = await showRoleSelectionModal([personObj]);

        if (peopleWithRoles && peopleWithRoles.length > 0) {
            // Include display_name for better feedback
            peopleWithRoles[0].display_name = personObj.display_name;

            // Update the role
            const response2 = await fetch('api/tournaments/roles.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'addPeople', // This action handles updates too
                    tournament_id: currentTournament.id,
                    people: JSON.stringify(peopleWithRoles)
                })
            });

            const result2 = await response2.json();
            if (result2.success) {
                showToast('Role updated successfully', { variant: 'success' });
                await loadTournamentDetails(currentTournament.id);
            } else {
                showToast(`Failed to update role: ${result2.message}`, { variant: 'danger' });
            }
        }
    } catch (error) {
        console.error('Error in editPersonRole:', error);
        showToast('Failed to update role', { variant: 'danger' });
    }
}

window.editPersonRole = editPersonRole;

// Remove person from tournament
async function removePerson(userId, personName) {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    const confirmed = await showConfirmation({
        title: 'Remove Person',
        message: `Are you sure you want to remove "${personName}" from the tournament?`,
        confirmText: 'Remove',
        confirmVariant: 'danger'
    });

    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('api/tournaments/roles.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'removePerson',
                tournament_id: currentTournament.id,
                user_id: userId
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(`Successfully removed ${personName} from tournament.`, { variant: 'success' });
            await loadTournamentDetails(currentTournament.id);
        } else {
            showToast(`Failed to remove person: ${result.message}`, { variant: 'danger' });
        }
    } catch (error) {
        console.error('Error removing person:', error);
        showToast('Failed to remove person. Please try again.', { variant: 'danger' });
    }
}

// Show player selection modal
function showPlayerSelectionModal(modalContent, users) {
    return new Promise((resolve) => {
        const modalId = 'player-selection-modal';

        // Remove existing modal if present
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal
        const modalEl = document.createElement('div');
        modalEl.id = modalId;
        modalEl.className = 'modal fade';
        modalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Select Players to Add</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        ${modalContent}
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        const modal = new bootstrap.Modal(modalEl);

        // Add global helper functions
        window.selectAllPlayers = function () {
            document.querySelectorAll('.player-checkbox:not(:disabled)').forEach(cb => cb.checked = true);
        };

        window.deselectAllPlayers = function () {
            document.querySelectorAll('.player-checkbox').forEach(cb => cb.checked = false);
        };

        window.filterPlayers = function () {
            const searchTerm = document.getElementById('playerSearch').value.toLowerCase();
            const playerItems = document.querySelectorAll('.player-item');

            playerItems.forEach(item => {
                const searchValue = item.getAttribute('data-search');
                item.style.display = searchValue.includes(searchTerm) ? 'block' : 'none';
            });
        };

        window.closePlayerModal = function () {
            modal.hide();
        };

        window.confirmAddPlayers = function () {
            const selectedCheckboxes = document.querySelectorAll('.player-checkbox:checked');
            const selectedUsers = Array.from(selectedCheckboxes).map(cb => {
                const userEmail = cb.value;
                // Find the full user object from the original users array
                const user = users.find(u => u.email === userEmail);
                return user;
            }).filter(Boolean); // Remove any undefined entries

            modal.hide();
            resolve(selectedUsers);
        };

        // Handle modal close
        modalEl.addEventListener('hidden.bs.modal', () => {
            // Clean up global functions
            delete window.selectAllPlayers;
            delete window.deselectAllPlayers;
            delete window.filterPlayers;
            delete window.closePlayerModal;
            delete window.confirmAddPlayers;
            modalEl.remove();
            resolve(null);
        });

        // Show the modal after setting up all event listeners
        modal.show();
    });
}

// Create judge selection modal content
function createJudgeSelectionModal(users) {
    const checkboxes = users.map(user => {
        const displayName = user.display_name || user.blader_name || user.email;
        const email = user.email;
        return `
            <div class="form-check judge-item mb-2" data-search="${displayName.toLowerCase()} ${email.toLowerCase()}">
                <input class="form-check-input judge-checkbox" type="checkbox" value="${email}" id="judge-${email}">
                <label class="form-check-label" for="judge-${email}">
                    <strong>${displayName}</strong><br>
                    <small class="text-muted">${email}</small>
                </label>
            </div>
        `;
    }).join('');

    return `
        <div class="judge-selection-container">
            <div class="alert alert-info">
                <i class="bi bi-gavel me-2"></i>
                <strong>${users.length}</strong> available user${users.length !== 1 ? 's' : ''} to assign as judge
            </div>
            <div class="mb-3">
                <div class="row">
                    <div class="col-md-6">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllJudges()">Select All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllJudges()">Deselect All</button>
                    </div>
                    <div class="col-md-6">
                        <input type="text" class="form-control form-control-sm" id="judgeSearch" placeholder="Search users..." onkeyup="filterJudges()">
                    </div>
                </div>
            </div>
            <div class="judge-list" style="max-height: 400px; overflow-y: auto;">
                <div class="row">
                    <div class="col-md-6">
                        ${checkboxes.split('</div>').filter((_, i) => i % 2 === 0).map(cb => cb + '</div>').join('')}
                    </div>
                    <div class="col-md-6">
                        ${checkboxes.split('</div>').filter((_, i) => i % 2 === 1).map(cb => cb + '</div>').join('')}
                    </div>
                </div>
            </div>
            <div class="mt-3 text-end">
                <button type="button" class="btn btn-secondary me-2" onclick="closeJudgeModal()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="confirmAssignJudges()">Assign Selected Judges</button>
            </div>
        </div>
    `;
}

// Show judge selection modal
function showJudgeSelectionModal(modalContent, users) {
    return new Promise((resolve) => {
        const modalId = 'judge-selection-modal';

        // Remove existing modal if present
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal
        const modalEl = document.createElement('div');
        modalEl.id = modalId;
        modalEl.className = 'modal fade';
        modalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Select Judges to Assign</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        ${modalContent}
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        const modal = new bootstrap.Modal(modalEl);

        // Add global helper functions
        window.selectAllJudges = function () {
            document.querySelectorAll('.judge-checkbox:not(:disabled)').forEach(cb => cb.checked = true);
        };

        window.deselectAllJudges = function () {
            document.querySelectorAll('.judge-checkbox').forEach(cb => cb.checked = false);
        };

        window.filterJudges = function () {
            const searchTerm = document.getElementById('judgeSearch').value.toLowerCase();
            const judgeItems = document.querySelectorAll('.judge-item');

            judgeItems.forEach(item => {
                const searchValue = item.getAttribute('data-search');
                if (searchValue.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        };

        window.closeJudgeModal = function () {
            modal.hide();
        };

        window.confirmAssignJudges = function () {
            const selectedCheckboxes = document.querySelectorAll('.judge-checkbox:checked');
            const selectedUsers = Array.from(selectedCheckboxes).map(cb => {
                const userEmail = cb.value;
                // Find the full user object from the original users array
                const user = users.find(u => u.email === userEmail);
                return user;
            }).filter(Boolean); // Remove any undefined entries

            modal.hide();
            resolve(selectedUsers);
        };

        // Handle modal close
        modalEl.addEventListener('hidden.bs.modal', () => {
            // Clean up global functions
            delete window.selectAllJudges;
            delete window.deselectAllJudges;
            delete window.filterJudges;
            delete window.closeJudgeModal;
            delete window.confirmAssignJudges;
            modalEl.remove();
            resolve(null);
        });

        // Show the modal after setting up all event listeners
        modal.show();
    });
}

// Add players to tournament
async function addPlayers() {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    try {
        // Fetch all users from database
        const response = await fetch('api/users/list.php');
        const result = await response.json();

        if (!result.success) {
            showError('Failed to load users');
            return;
        }

        const allUsers = result.users;
        if (allUsers.length === 0) {
            showToast('No users found in database', { variant: 'info' });
            return;
        }

        // Get current tournament participants to filter them out
        const participantsResponse = await fetch(`api/tournaments/create.php?action=getDetails&tournament_id=${currentTournament.id}`);
        const participantsResult = await participantsResponse.json();

        let registeredUserIds = [];
        if (participantsResult.success && participantsResult.participants) {
            registeredUserIds = participantsResult.participants.map(p => p.user_id);
        }

        // Filter out already registered users
        const availableUsers = allUsers.filter(user => !registeredUserIds.includes(user.id));

        if (availableUsers.length === 0) {
            showToast('All available players are already registered for this tournament', { variant: 'info' });
            return;
        }

        // Create modal content with checkboxes
        const modalContent = createPlayerSelectionModal(availableUsers);

        // Show confirmation modal with player list
        const selectedUsers = await showPlayerSelectionModal(modalContent, availableUsers);

        if (!selectedUsers || selectedUsers.length === 0) {
            return;
        }

        // Register selected players
        const successes = [];
        const failures = [];

        for (const user of selectedUsers) {
            try {
                const response = await fetch('api/tournaments/create.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'register',
                        tournament_id: currentTournament.id,
                        player_name: user.display_name || user.blader_name || user.email
                    })
                });

                const result = await response.json();

                if (result.success) {
                    successes.push(user.display_name || user.blader_name || user.email);
                } else {
                    failures.push({
                        user: user.display_name || user.blader_name || user.email,
                        message: result.message || 'Failed to add player'
                    });
                }
            } catch (error) {
                failures.push({
                    user: user.display_name || user.blader_name || user.email,
                    message: 'Request failed'
                });
            }
        }

        if (successes.length > 0) {
            showToast(`Successfully added ${successes.length} player(s).`, { variant: 'success' });
            await loadTournamentDetails(currentTournament.id);
        }

        if (failures.length > 0) {
            const failureMessages = failures.map(f => `${f.user}: ${f.message}`).join('\n');
            showToast(`Some players could not be added:\n${failureMessages}`, { variant: 'warning' });
        }

    } catch (error) {
        showError('Failed to load users');
    }
}

// Add judge to tournament
async function addJudge() {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    try {
        // Fetch all users from database
        const response = await fetch('api/users/list.php');
        const result = await response.json();

        if (!result.success) {
            showError('Failed to load users');
            return;
        }

        const allUsers = result.users;
        if (allUsers.length === 0) {
            showToast('No users found in database', { variant: 'info' });
            return;
        }

        // Get current tournament judges to filter them out
        const judgesResponse = await fetch(`api/tournaments/create.php?action=getJudges&tournament_id=${currentTournament.id}`);
        const judgesResult = await judgesResponse.json();

        let registeredJudgeIds = [];
        if (judgesResult.success && judgesResult.judges) {
            registeredJudgeIds = judgesResult.judges.map(j => j.user_id);
        }

        // Get current tournament participants to filter them out as well
        const participantsResponse = await fetch(`api/tournaments/create.php?action=getDetails&tournament_id=${currentTournament.id}`);
        const participantsResult = await participantsResponse.json();

        let participantIds = [];
        if (participantsResult.success && participantsResult.participants) {
            participantIds = participantsResult.participants.map(p => p.user_id);
        }

        // Filter out already assigned judges AND tournament participants
        const availableUsers = allUsers.filter(user =>
            !registeredJudgeIds.includes(user.id) && !participantIds.includes(user.id)
        );

        if (availableUsers.length === 0) {
            showToast('No available users to assign as judges (all users are either participants or already assigned as judges)', { variant: 'info' });
            return;
        }

        // Create modal content with checkboxes
        const modalContent = createJudgeSelectionModal(availableUsers);

        // Show confirmation modal with user list
        const selectedUsers = await showJudgeSelectionModal(modalContent, availableUsers);

        if (!selectedUsers || selectedUsers.length === 0) {
            return;
        }

        // Assign selected users as judges
        const successes = [];
        const failures = [];

        for (const user of selectedUsers) {
            try {
                const response = await fetch('api/tournaments/create.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'assignJudge',
                        tournament_id: currentTournament.id,
                        user_id: user.id
                    })
                });

                const result = await response.json();

                if (result.success) {
                    successes.push(user.display_name || user.blader_name || user.email);
                } else {
                    failures.push({
                        user: user.display_name || user.blader_name || user.email,
                        message: result.message || 'Failed to assign judge'
                    });
                }
            } catch (error) {
                failures.push({
                    user: user.display_name || user.blader_name || user.email,
                    message: 'Request failed'
                });
            }
        }

        if (successes.length > 0) {
            showToast(`Successfully assigned ${successes.length} judge(s).`, { variant: 'success' });
            await loadTournamentDetails(currentTournament.id);
        }

        if (failures.length > 0) {
            const failureMessages = failures.map(f => `${f.user}: ${f.message}`).join('\n');
            showToast(`Some judges could not be assigned:\n${failureMessages}`, { variant: 'warning' });
        }

    } catch (error) {
        showError('Failed to load users');
    }
}

// Remove judge from tournament
async function removeJudge(userId, judgeName) {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    const confirmed = await showConfirmation({
        title: 'Remove Judge',
        message: `Are you sure you want to remove "${judgeName}" as a judge from the tournament?`,
        confirmText: 'Remove',
        confirmVariant: 'danger'
    });

    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('api/tournaments/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'removeJudge',
                tournament_id: currentTournament.id,
                user_id: userId
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(`Successfully removed ${judgeName} as judge.`, { variant: 'success' });
            await loadTournamentDetails(currentTournament.id);
        } else {
            showToast(`Failed to remove judge: ${result.message}`, { variant: 'danger' });
        }
    } catch (error) {
        showToast('Failed to remove judge. Please try again.', { variant: 'danger' });
    }
}

// Remove player from tournament
async function removePlayer(userId, playerName) {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    const confirmed = await showConfirmation({
        title: 'Remove Player',
        message: `Are you sure you want to remove "${playerName}" from the tournament?`,
        confirmText: 'Remove',
        confirmVariant: 'danger'
    });

    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('api/tournaments/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'removePlayer',
                tournament_id: currentTournament.id,
                user_id: userId
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(`Successfully removed ${playerName} from tournament.`, { variant: 'success' });
            await loadTournamentDetails(currentTournament.id);
        } else {
            showToast(`Failed to remove player: ${result.message}`, { variant: 'danger' });
        }
    } catch (error) {
        showToast('Failed to remove player. Please try again.', { variant: 'danger' });
    }
}

// Update tournament status
async function updateTournamentStatus(newStatus, reload = true) {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    if (!ensureTournamentEditable()) return;

    const confirmed = await showConfirmation({
        title: 'Update Status',
        message: `Are you sure you want to change tournament status to "${newStatus}"?`,
        confirmText: 'Update',
        confirmVariant: 'primary'
    });

    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('api/tournaments/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'updateStatus',
                tournament_id: currentTournament.id,
                status: newStatus
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast('Tournament status updated successfully!', { variant: 'success' });
            if (reload) {
                await loadTournamentDetails(currentTournament.id); // Reload to show new status
            }
        } else {
            showError(result.message || 'Failed to update tournament status');
        }
    } catch (error) {
        showError('Failed to update tournament status');
    }
}

// Edit tournament
function editTournament() {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    // Redirect to tournaments page with edit parameter
    window.location.href = `tournaments.html?edit=${currentTournament.id}`;
}

// Delete tournament
async function deleteTournament() {
    if (!currentTournament) {
        showError('Tournament not loaded');
        return;
    }

    const confirmed = await showConfirmation({
        title: 'Delete Tournament',
        message: `Are you sure you want to delete "${currentTournament.name}"? This action cannot be undone.`,
        confirmText: 'Delete',
        confirmVariant: 'danger'
    });

    if (!confirmed) {
        return;
    }

    try {
        const response = await fetch('api/tournaments/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                tournament_id: currentTournament.id
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast('Tournament deleted successfully!', { variant: 'success' });
            window.location.href = 'tournaments.html';
        } else {
            showError(result.message || 'Failed to delete tournament');
        }
    } catch (error) {
        showError('Failed to delete tournament');
    }
}

// Helper functions
function formatDate(dateString) {
    if (!dateString) return 'No date';

    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function getTournamentTypeLabel(type) {
    const labels = {
        'single_elimination': 'Single Elim',
        'double_elimination': 'Double Elim',
        'swiss': 'Swiss',
        'round_robin': 'Round Robin'
    };
    return labels[type] || 'Unknown';
}

function getTournamentIcon(type) {
    const icons = {
        'single_elimination': 'bi-diagram-3',
        'double_elimination': 'bi-diagram-3-fill',
        'swiss': 'bi-grid-3x3-gap',
        'round_robin': 'bi-arrow-repeat'
    };
    return icons[type] || 'bi-trophy';
}

function getStatusLabel(status) {
    const labels = {
        'upcoming': 'Upcoming',
        'registration': 'Registration',
        'ongoing': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    };
    return labels[status] || 'Unknown';
}

function showError(message) {
    showToast(message, { variant: 'danger' });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
async function shuffleSeeds() {
    if (!currentTournament) return;

    if (!ensureTournamentEditable()) return;

    const shuffleIndicator = document.getElementById('seedShuffleIndicator');
    const shuffleButton = document.getElementById('shuffleSeedsBtn');

    if (shuffleButton) {
        shuffleButton.disabled = true;
        shuffleButton.classList.add('is-loading');
    }

    try {
        const formData = new FormData();
        formData.append('action', 'shuffleSeeds');
        formData.append('tournament_id', currentTournament.id);

        const response = await fetch('api/tournaments/roles.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success) {
            if (shuffleIndicator) {
                const formattedTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                shuffleIndicator.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i>Seeds shuffled · ${formattedTime}`;
                shuffleIndicator.classList.add('is-visible');
                shuffleIndicator.classList.add('recent');
                if (shuffleIndicator._recentTimeout) {
                    clearTimeout(shuffleIndicator._recentTimeout);
                }
                shuffleIndicator._recentTimeout = setTimeout(() => {
                    shuffleIndicator.classList.remove('recent');
                }, 4000);
            }
            loadTournamentDetails(currentTournament.id);
        } else {
            showError(result.message || 'Failed to shuffle seeds');
        }
    } catch (error) {
        showError('An error occurred while shuffling seeds');
    } finally {
        if (shuffleButton) {
            shuffleButton.disabled = false;
            shuffleButton.classList.remove('is-loading');
        }
    }
}

window.shuffleSeeds = shuffleSeeds;
async function finishTournament() {
    const confirmed = await showConfirmation({
        title: 'Finish Tournament',
        message: 'Are you sure you want to end the tournament and announce the winners? This will lock all scores.',
        confirmText: 'Finish',
        confirmVariant: 'success'
    });

    if (!confirmed) return;

    try {
        const response = await fetch('api/tournaments/rounds.php?action=endTournament', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ tournament_id: currentTournament.id })
        });
        const result = await response.json();
        if (result.success) {
            showToast('Tournament completed!', { variant: 'success' });
            loadTournamentDetails(currentTournament.id);
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        console.error('Finish tournament error:', error);
        showToast('Failed to finish tournament.', { variant: 'danger' });
    }
}

async function fetchPodium(tournamentId) {
    try {
        const response = await fetch(`api/tournaments/rounds.php?action=getPodium&tournament_id=${tournamentId}`);
        const result = await response.json();
        if (result.success && result.podium) {
            displayPodium(result.podium, result.swissKing || null);
        }
    } catch (error) {
        console.error('Error fetching podium:', error);
    }
}

function displayPodium(podium, swissKing = null) {
    const section = document.getElementById('podiumSection');
    if (!section) return;

    showElement(section, 'block');

    const swissKingPodium = document.getElementById('swissKingPodium');
    const swissKingName = document.getElementById('swissKingName');

    if (swissKingPodium && swissKing) {
        showElement(swissKingPodium, 'block');
        if (swissKingName) swissKingName.textContent = swissKing.name || '---';
    } else if (swissKingPodium) {
        hideElement(swissKingPodium);
    }

    const first = podium[1] || { name: '---' };
    const second = podium[2] || { name: '---' };
    const third = podium[3] || { name: '---' };

    document.getElementById('podium1stName').textContent = first.name;
    document.getElementById('podium1stAvatar').textContent = first.name.charAt(0).toUpperCase();

    document.getElementById('podium2ndName').textContent = second.name;
    document.getElementById('podium2ndAvatar').textContent = second.name.charAt(0).toUpperCase();

    document.getElementById('podium3rdName').textContent = third.name;
    document.getElementById('podium3rdAvatar').textContent = third.name.charAt(0).toUpperCase();

    // Handle extended rankings (4th-10th)
    const extendedSection = document.getElementById('extendedRankings');
    const extendedList = document.getElementById('extendedRankingsList');

    if (extendedSection && extendedList) {
        let itemsHtml = '';
        const topCutSize = currentTournament ? parseInt(currentTournament.top_cut, 10) : 0;
        const extendedLimit = topCutSize > 0 ? Math.max(topCutSize, 4) : 8;
        const startPlace = Math.min(4, extendedLimit);
        for (let i = startPlace; i <= extendedLimit; i++) {
            if (podium[i]) {
                itemsHtml += `
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="p-2 bg-white rounded-3 border text-center shadow-sm h-100">
                            <div class="badge bg-light text-dark rounded-pill mb-1" style="font-size: 0.7rem;">${formatOrdinal(i)} Place</div>
                            <div class="fw-bold small text-truncate" title="${podium[i].name}">${podium[i].name}</div>
                        </div>
                    </div>
                `;
            }
        }

        if (itemsHtml) {
            extendedList.innerHTML = itemsHtml;
            showElement(extendedSection, 'block');
        } else {
            hideElement(extendedSection);
        }
    }
}

window.finishTournament = finishTournament;

// ===== CHECK-IN MANAGEMENT FUNCTIONS =====

// Load check-in data and show tabs if needed
async function loadCheckInData() {
    if (!currentTournament) return;

    // Only show check-in tab for organizers before tournament starts
    const isUpcoming = ['upcoming', 'registration'].includes(currentTournament.status);
    const tabsSection = document.getElementById('organizerTabsSection');
    const simplePeopleSection = document.getElementById('simplePeopleSection');

    if (isUpcoming && currentUser) {
        const isOrganizer = currentUser.id === currentTournament.created_by;

        if (isOrganizer) {
            if (tabsSection) tabsSection.classList.remove('is-hidden');
            if (simplePeopleSection) simplePeopleSection.classList.add('is-hidden');

            // Load registration data
            await loadPendingCheckIns();
            await loadCheckedInPlayers();
        } else {
            if (tabsSection) tabsSection.classList.add('is-hidden');
            if (simplePeopleSection) simplePeopleSection.classList.remove('is-hidden');
        }
    } else {
        if (tabsSection) tabsSection.classList.add('is-hidden');
        if (simplePeopleSection) simplePeopleSection.classList.remove('is-hidden');
    }
}

// Load players awaiting check-in
async function loadPendingCheckIns() {
    try {
        const response = await fetch(`api/tournaments/checkin.php?action=getRegistered&tournament_id=${currentTournament.id}`);
        const result = await response.json();

        const pendingList = document.getElementById('pendingCheckInList');
        const pendingBadge = document.getElementById('pendingCheckInBadge');
        const bulkBtn = document.getElementById('bulkCheckInBtn');

        if (result.success && result.players && result.players.length > 0) {
            if (pendingBadge) pendingBadge.textContent = result.players.length;
            if (bulkBtn) bulkBtn.disabled = false;

            if (pendingList) {
                pendingList.innerHTML = result.players.map(player => `
                    <div class="d-flex align-items-center justify-content-between p-3 border rounded bg-white">
                        <div class="d-flex align-items-center gap-3">
                            <div class="player-avatar bg-warning text-white" style="width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                ${(player.display_name || player.blader_name || '?').charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <div class="fw-bold">${player.display_name || player.blader_name || 'Unknown'}</div>
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i> Registered ${formatDate(player.registered_at || new Date())}
                                </small>
                            </div>
                        </div>
                        <button class="btn btn-success btn-sm" onclick="checkInPlayer('${player.user_id}', '${escapeHtml(player.display_name || player.blader_name || 'Unknown')}')">
                            <i class="bi bi-check-circle me-1"></i> Check In
                        </button>
                    </div>
                `).join('');
            }
        } else {
            if (pendingBadge) pendingBadge.textContent = '0';
            if (bulkBtn) bulkBtn.disabled = true;
            if (pendingList) {
                pendingList.innerHTML = `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox display-6 opacity-25"></i>
                        <p class="small mb-0 mt-2">No pending check-ins</p>
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Error loading pending check-ins:', error);
    }
}

// Load checked-in players
async function loadCheckedInPlayers() {
    try {
        const response = await fetch(`api/tournaments/checkin.php?action=getCheckedIn&tournament_id=${currentTournament.id}`);
        const result = await response.json();

        const checkedInList = document.getElementById('checkedInList');

        if (result.success && result.players && result.players.length > 0) {
            if (checkedInList) {
                checkedInList.innerHTML = result.players.map(player => `
                    <div class="d-flex align-items-center justify-content-between p-3 border rounded bg-light">
                        <div class="d-flex align-items-center gap-3">
                            <div class="player-avatar bg-success text-white" style="width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                ${(player.display_name || player.blader_name || '?').charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <div class="fw-bold">${player.display_name || player.blader_name || 'Unknown'}</div>
                                <small class="text-success">
                                    <i class="bi bi-check-circle-fill me-1"></i> Checked in
                                </small>
                            </div>
                        </div>
                        <span class="badge bg-success rounded-pill">Ready</span>
                    </div>
                `).join('');
            }
        } else {
            if (checkedInList) {
                checkedInList.innerHTML = `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-people display-6 opacity-25"></i>
                        <p class="small mb-0 mt-2">No players checked in yet</p>
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Error loading checked-in players:', error);
    }
}

// Check in a single player
window.checkInPlayer = async function (playerId, playerName) {
    try {
        const response = await fetch('api/tournaments/checkin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'checkIn',
                tournament_id: currentTournament.id,
                player_id: playerId
            })
        });

        const result = await response.json();

        if (result.success) {
            showToast(`${playerName} checked in successfully!`, { variant: 'success' });
            await loadPendingCheckIns();
            await loadCheckedInPlayers();

            // Reload people list to update participant count
            const peopleResponse = await fetch(`api/tournaments/roles.php?action=getPeople&tournament_id=${currentTournament.id}`);
            const peopleResult = await peopleResponse.json();
            if (peopleResult.success) {
                displayPeople(peopleResult.people || []);
            }
        } else {
            showToast(result.message || 'Failed to check in player', { variant: 'danger' });
        }
    } catch (error) {
        console.error('Error checking in player:', error);
        showToast('Failed to check in player', { variant: 'danger' });
    }
};

// Bulk check-in all pending players
window.bulkCheckIn = async function () {
    try {
        // Get all pending players first
        const response = await fetch(`api/tournaments/checkin.php?action=getRegistered&tournament_id=${currentTournament.id}`);
        const result = await response.json();

        if (!result.success || !result.players || result.players.length === 0) {
            showToast('No players to check in', { variant: 'info' });
            return;
        }

        const confirmed = await showConfirmation({
            title: 'Bulk Check-In',
            message: `Check in all ${result.players.length} registered player(s)?`,
            confirmText: 'Check In All',
            confirmVariant: 'success'
        });

        if (!confirmed) return;

        const playerIds = result.players.map(p => p.user_id);

        const checkInResponse = await fetch('api/tournaments/checkin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'bulkCheckIn',
                tournament_id: currentTournament.id,
                player_ids: JSON.stringify(playerIds)
            })
        });

        const checkInResult = await checkInResponse.json();

        if (checkInResult.success) {
            const successCount = checkInResult.checked_in || playerIds.length;
            showToast(`Successfully checked in ${successCount} player(s)!`, { variant: 'success' });
            await loadPendingCheckIns();
            await loadCheckedInPlayers();

            // Reload people list
            const peopleResponse = await fetch(`api/tournaments/roles.php?action=getPeople&tournament_id=${currentTournament.id}`);
            const peopleResult = await peopleResponse.json();
            if (peopleResult.success) {
                displayPeople(peopleResult.people || []);
            }
        } else {
            showToast(checkInResult.message || 'Failed to check in players', { variant: 'danger' });
        }
    } catch (error) {
        console.error('Error bulk checking in:', error);
        showToast('Failed to check in players', { variant: 'danger' });
    }
};

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
