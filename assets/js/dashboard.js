// Dashboard JavaScript
// Use the same API system as other pages
(function () {
    'use strict';

    const api = new ApiService();

    // Current user data
    var currentUser = null;
    let userProfile = null;

    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function () {
        checkAuthStatus();
        setupEventListeners();
    });

    // Setup event listeners
    function setupEventListeners() {
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', handleLogout);
        }
    }

    // Check if user is authenticated
    async function checkAuthStatus() {
        try {
            // Load user profile using API
            const response = await api.getProfile();

            if (response.success) {
                currentUser = response.profile;
                userProfile = response.profile;
                // Ensure session storage is in sync
                sessionStorage.setItem('user', JSON.stringify(currentUser));
                updateUI();
                loadTournaments();
            } else {
                console.error('Authentication failed:', response.message);
                window.location.href = 'index.html';
            }
        } catch (err) {
            console.error('Authentication error:', err);
            window.location.href = 'index.html';
        }
    }

    // Load tournaments from API
    async function loadTournaments() {
        try {
            const response = await api.getTournaments();

            if (response.success) {
                displayTournaments(response.tournaments || []);
            } else {
                console.error('Failed to load tournaments:', response.message);
                displayTournaments([]);
            }
        } catch (err) {
            console.error('Error loading tournaments:', err);
            displayTournaments([]);
        }
    }

    // Display tournaments categorized by status
    function displayTournaments(tournaments) {
        const ongoingList = document.getElementById('ongoingTournaments');
        const upcomingList = document.getElementById('upcomingTournaments');
        const recentList = document.getElementById('recentTournaments');

        const ongoingBadge = document.getElementById('ongoingCount');
        const upcomingBadge = document.getElementById('upcomingCount');

        if (!recentList) return;

        // Group tournaments
        const ongoing = tournaments.filter(t => t.status === 'in_progress' || t.status === 'registration');
        const upcoming = tournaments.filter(t => t.status === 'upcoming');
        const recent = tournaments.filter(t => t.status === 'completed');

        // Update counts and badges
        if (ongoingBadge) {
            ongoingBadge.textContent = ongoing.length;
            ongoingBadge.classList.toggle('d-none', ongoing.length === 0);
        }
        if (upcomingBadge) {
            upcomingBadge.textContent = upcoming.length;
            upcomingBadge.classList.toggle('d-none', upcoming.length === 0);
        }

        // Render sections
        renderTournamentList(ongoingList, ongoing, 'No ongoing tournaments');
        renderTournamentList(upcomingList, upcoming, 'No upcoming tournaments');
        renderTournamentList(recentList, recent, 'No recent tournaments');
    }

    // Helper to render a list of tournaments
    function renderTournamentList(container, tournaments, emptyMessage) {
        if (!container) return;

        if (tournaments.length === 0) {
            container.innerHTML = `<div class="text-center py-4 text-muted small">${emptyMessage}</div>`;
            return;
        }

        container.innerHTML = tournaments.map(t => `
            <a href="tournament-detail.html?id=${t.id}" class="list-group-item list-group-item-action border-0 rounded-3 mb-2 shadow-sm p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 fw-bold">${t.name}</h6>
                        <div class="d-flex gap-3 text-muted x-small">
                            <span><i class="bi bi-calendar3 me-1"></i>${formatDate(t.date)}</span>
                            <span><i class="bi bi-geo-alt me-1"></i>${t.location || 'TBD'}</span>
                            <span><i class="bi bi-people me-1"></i>${t.participant_count || 0}</span>
                        </div>
                    </div>
                    <i class="bi bi-chevron-right text-muted"></i>
                </div>
            </a>
        `).join('');
    }

    // Format date helper
    function formatDate(dateString) {
        if (!dateString) return 'TBD';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    // Update UI with user data
    function updateUI() {
        if (!currentUser) return;

        // Update welcome message
        const displayName = currentUser.display_name || currentUser.blader_name || 'Blader';
        document.getElementById('displayName').textContent = displayName;

        // Update stats with default values
        document.getElementById('championCount').textContent = '0';
        document.getElementById('firstRunnerUpCount').textContent = '0';
        document.getElementById('secondRunnerUpCount').textContent = '0';
        document.getElementById('lifetimeWinRate').textContent = '0%';
        document.getElementById('avgPlacement').textContent = '-';
        document.getElementById('bestFinish').textContent = '-';

        document.getElementById('recentPlacement').textContent = '-';
        document.getElementById('recentWinRate').textContent = '0%';
        document.getElementById('recentScore').textContent = '0.0';
        document.getElementById('recentBestFinish').textContent = '-';

        // Update current date in welcome card
        const currentDateElement = document.getElementById('currentDate');
        if (currentDateElement) {
            const today = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            currentDateElement.textContent = today.toLocaleDateString('en-US', options);
        }
    }

    // Logout handler
    async function handleLogout() {
        try {
            console.log('Logging out...');

            // Call API logout
            const response = await fetch('api/auth/logout.php', {
                method: 'POST',
                credentials: 'include'
            });

            const result = await response.json();

            if (result.success) {
                console.log('Logout successful');
            } else {
                console.log('Logout completed (API response:', result.message + ')');
            }

            // Clear local state
            currentUser = null;
            userProfile = null;

            // Redirect to login
            setTimeout(() => {
                window.location.href = 'index.html';
            }, 100);

        } catch (err) {
            console.error('Logout failed:', err);
            // Still redirect even on error
            setTimeout(() => {
                window.location.href = 'index.html';
            }, 100);
        }
    }
})();
