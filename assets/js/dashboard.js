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
            } else {
                console.error('Authentication failed:', response.message);
                window.location.href = 'index.html';
            }
        } catch (err) {
            console.error('Authentication error:', err);
            window.location.href = 'index.html';
        }
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
