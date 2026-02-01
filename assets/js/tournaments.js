// Tournaments JavaScript
// Use the same API system as the dashboard
(function () {
    'use strict';

    const api = new ApiService();

    // Current user data
    var currentUser = null;
    let userProfile = null;
    let tournamentsList = [];

    // Initialize tournaments page
    document.addEventListener('DOMContentLoaded', function () {
        setupEventListeners();
        loadTournaments();
    });

    // Check if user is authenticated
    async function checkAuthStatus() {
        try {
            // Load user profile using API
            const response = await api.getProfile();

            if (response.success) {
                currentUser = response.profile;
                userProfile = response.profile;
                updateUI();
                await loadTournaments();
            } else {
                // Check if it's because user is not logged in
                if (response.message === 'Not authenticated') {
                    window.location.href = 'index.html';
                } else {
                    window.location.href = 'index.html';
                }
            }
        } catch (err) {
            window.location.href = 'index.html';
        }
    }

    // Update UI with user data
    function updateUI() {
        if (!currentUser) return;

        // Update user info
        const displayName = userProfile.display_name || userProfile.blader_name || currentUser.email;

        document.getElementById('userName').textContent = displayName;
        document.getElementById('userEmail').textContent = currentUser.email;

        // Update avatar if available
        const userAvatarDiv = document.getElementById('userAvatar');

        if (userProfile.avatar_url && userProfile.avatar_url !== '') {
            // Update user avatar with image
            userAvatarDiv.innerHTML = `<img src="${userProfile.avatar_url}" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
        } else {
            // Show default avatar icon
            userAvatarDiv.innerHTML = '<i class="bi bi-person-fill"></i>';
        }
    }

    // Setup event listeners
    function setupEventListeners() {
        // Filter listeners
        document.getElementById('statusFilter')?.addEventListener('change', filterTournaments);
        document.getElementById('typeFilter')?.addEventListener('change', filterTournaments);
        document.getElementById('searchFilter')?.addEventListener('input', filterTournaments);

        // Logout button listener
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', handleLogout);
        }
    }

    // Load tournaments from database
    async function loadTournaments() {
        try {

            // Temporarily bypass API for testing
            tournamentsList = [];
            displayTournaments(tournamentsList);

            /*
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
            */

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
            <div class="text-center text-muted py-5">
                <i class="bi bi-trophy fs-1 mb-3"></i>
                <h5>No tournaments yet</h5>
                <p>Create your first tournament to get started!</p>
            </div>
        `;
            return;
        }

        tournamentsDiv.innerHTML = tournaments.map(tournament => `
        <div class="tournament-card card ${tournament.offline_mode ? 'border-warning' : ''} mb-3">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <h5 class="card-title mb-0">${tournament.name}</h5>
                            <span class="badge badge-status ${getStatusClass(tournament.status)}">
                                ${getStatusLabel(tournament.status)}
                            </span>
                            ${tournament.offline_mode ? '<span class="badge bg-warning">Offline</span>' : ''}
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="badge badge-status ${getStatusClass(tournament.status)}">
                                ${getStatusLabel(tournament.status)}
                            </span>
                            <span class="badge bg-info">${getTournamentTypeLabel(tournament.tournament_type)}</span>
                            ${tournament.date ?
                `<small><i class="bi bi-calendar me-1"></i>${formatDate(tournament.date)}</small>` :
                `<small><i class="bi bi-calendar-x me-1"></i>No date set</small>`
            }
                            ${tournament.max_players ?
                `<small><i class="bi bi-people me-1"></i>${tournament.max_players} Players</small>` :
                `<small><i class="bi bi-infinity me-1"></i>Unlimited Players</small>`
            }
                            ${tournament.offline_mode ?
                `<small><i class="bi bi-database-exclude me-1"></i>Local Only</small>` :
                `<small><i class="bi bi-cloud me-1"></i>Cloud Synced</small>`
            }
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-sm btn-outline-primary me-2" onclick="viewTournament('${tournament.id}')">
                            <i class="bi bi-eye me-1"></i>View
                        </button>
                        <button class="btn btn-sm btn-outline-secondary me-2" onclick="editTournament('${tournament.id}')">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                        ${!tournament.offline_mode ? `
                            <button class="btn btn-sm btn-outline-info" onclick="manageTournament('${tournament.id}')">
                                <i class="bi bi-gear me-1"></i>Manage
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
    `).join('');
    }

    // Filter tournaments
    function filterTournaments() {
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        const typeFilter = document.getElementById('typeFilter')?.value || '';
        const searchFilter = document.getElementById('searchFilter')?.value.toLowerCase() || '';

        const filtered = tournamentsList.filter(tournament => {
            const matchesStatus = !statusFilter || tournament.status === statusFilter;
            const matchesType = !typeFilter || tournament.tournament_type === typeFilter;
            const matchesSearch = !searchFilter ||
                tournament.name.toLowerCase().includes(searchFilter) ||
                (tournament.description && tournament.description.toLowerCase().includes(searchFilter));

            return matchesStatus && matchesType && matchesSearch;
        });

        displayTournaments(filtered);
    }

    // Get tournament type label
    function getTournamentTypeLabel(type) {
        const labels = {
            'single_elimination': 'Single Elim',
            'double_elimination': 'Double Elim',
            'swiss': 'Swiss',
            'round_robin': 'Round Robin'
        };
        return labels[type] || type;
    }

    // Get status label
    function getStatusLabel(status) {
        const labels = {
            'upcoming': 'Upcoming',
            'registration': 'Registration',
            'in_progress': 'In Progress',
            'completed': 'Completed',
            'cancelled': 'Cancelled'
        };
        return labels[status] || status;
    }

    // Get status badge class
    function getStatusClass(status) {
        switch (status) {
            case 'upcoming': return 'status-upcoming';
            case 'registration': return 'status-registration';
            case 'in_progress': return 'status-in-progress';
            case 'completed': return 'status-completed';
            case 'cancelled': return 'status-cancelled';
            default: return '';
        }
    }

    // Format date
    function formatDate(dateString) {
        if (!dateString) return 'No date set';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }

    // Action handlers
    function createTournament() {
        // Show the tournament creation modal
        const modal = new bootstrap.Modal(document.getElementById('tournamentModal'));
        modal.show();
    }

    // Save tournament
    async function saveTournament() {
        try {
            const name = document.getElementById('tournamentName').value.trim();
            const date = document.getElementById('tournamentDate').value;
            const type = document.getElementById('tournamentType').value;
            const stadiums = document.getElementById('tournamentStadiums').value || 1;
            const rules = document.getElementById('tournamentRules').value.trim();

            // Validation
            if (!name) {
                alert('Please enter a tournament name');
                return;
            }

            if (!type) {
                alert('Please select a tournament type');
                return;
            }

            if (!date) {
                alert('Please select a tournament date');
                return;
            }

            // Create tournament object
            const tournamentData = {
                name: name,
                date: date,
                tournament_type: type,
                number_of_stadiums: parseInt(stadiums),
                max_participants: 50, // Default max participants
                rules: rules || null
            };

            // Save to API
            const response = await fetch('api/tournaments/create.php?action=create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'include',
                body: new URLSearchParams({
                    ...tournamentData
                })
            });

            const result = await response.json();

            if (result.success) {
                // Success - redirect to tournament detail
                window.location.href = `tournament-detail.html?id=${result.tournament.id}`;
                return;
            } else {
                alert('Failed to create tournament: ' + result.message);
                return;
            }


        } catch (err) {
            alert('Failed to create tournament. Please try again.');
        }
    }

    function viewTournament(tournamentId) {
        window.location.href = `tournament-detail.html?id=${tournamentId}`;
    }

    function editTournament(tournamentId) {
        alert('Tournament editing coming soon!');
    }

    function manageTournament(tournamentId) {
        window.location.href = `tournament-detail.html?id=${tournamentId}`;
    }

    // Navigation handlers
    function viewMyCombos() {
        window.location.href = 'dashboard.html';
    }

    function viewLeaderboard() {
        window.location.href = 'dashboard.html';
    }

    function viewAchievements() {
        window.location.href = 'dashboard.html';
    }

    // Logout handler
    async function handleLogout() {
        try {

            // Call API logout
            const response = await fetch('api/auth/logout.php', {
                method: 'POST',
                credentials: 'include'
            });

            const result = await response.json();

            if (result.success) {
            } else {
            }

            // Clear local state
            currentUser = null;
            userProfile = null;

            // Redirect to login
            setTimeout(() => {
                window.location.href = 'index.html';
            }, 100);

        } catch (err) {
            // Still redirect even on error
            setTimeout(() => {
                window.location.href = 'index.html';
            }, 100);
        }
    }

    // Make functions globally accessible
    window.handleLogout = handleLogout;
    window.createTournament = createTournament;
    window.saveTournament = saveTournament;
    window.viewTournament = viewTournament;
    window.editTournament = editTournament;
    window.manageTournament = manageTournament;
    window.viewMyCombos = viewMyCombos;
    window.viewLeaderboard = viewLeaderboard;
    window.viewAchievements = viewAchievements;

})();
