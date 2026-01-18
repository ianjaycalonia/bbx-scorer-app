// Dashboard Logic
document.addEventListener('DOMContentLoaded', function () {
    loadRecentTournaments();
    loadLifetimeStats();
    loadRecentTournamentStats();
    updateWelcomeMessage();
});

async function updateWelcomeMessage() {
    const displayNameElement = document.getElementById('displayName');
    if (displayNameElement) {
        try {
            const response = await fetch('api/users/profile.php');
            
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) {
                    console.error('Authentication error loading profile');
                    window.location.href = 'index.html';
                    return;
                }
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const rawText = await response.text();
            console.log('DEBUG: Raw profile API response in dashboard:', rawText);
            
            if (!rawText.trim()) {
                throw new Error('Empty response from profile API');
            }
            
            let result;
            try {
                result = JSON.parse(rawText);
            } catch (parseError) {
                console.error('JSON parse error for profile API in dashboard:', parseError);
                console.error('Raw response that failed to parse:', rawText);
                throw new Error(`Invalid JSON response from profile API: ${parseError.message}`);
            }

            if (result.success && result.profile) {
                const name = result.profile.display_name || result.profile.blader_name || 'Blader';
                displayNameElement.textContent = name;
            }
        } catch (error) {
            console.error('Error fetching user profile:', error);
            const userStr = sessionStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    const name = user.display_name || user.blader_name || 'Blader';
                    displayNameElement.textContent = name;
                } catch (e) {
                    displayNameElement.textContent = 'Blader';
                }
            } else {
                displayNameElement.textContent = 'Blader';
            }
        }
    }
}

async function loadLifetimeStats() {
    try {
        const response = await fetch('api/tournaments/stats.php?action=lifetime');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        
        if (!text.trim()) {
            console.error('Empty response from stats API');
            return;
        }
        
        const result = JSON.parse(text);

        if (result.success && result.stats) {
            const stats = result.stats;

            // Update champion counts
            const championCount = document.getElementById('championCount');
            if (championCount) championCount.textContent = stats.championCount;

            const firstRunnerUpCount = document.getElementById('firstRunnerUpCount');
            if (firstRunnerUpCount) firstRunnerUpCount.textContent = stats.firstRunnerUpCount;

            const secondRunnerUpCount = document.getElementById('secondRunnerUpCount');
            if (secondRunnerUpCount) secondRunnerUpCount.textContent = stats.secondRunnerUpCount;

            // Update performance metrics
            const lifetimeWinRate = document.getElementById('lifetimeWinRate');
            if (lifetimeWinRate) lifetimeWinRate.textContent = stats.winRate + '%';

            const avgPlacement = document.getElementById('avgPlacement');
            if (avgPlacement) avgPlacement.textContent = stats.avgPlacement || '-';

            const bestFinish = document.getElementById('bestFinish');
            if (bestFinish) bestFinish.textContent = stats.bestFinish || '-';
        } else if (result.message) {
            console.error('Stats API error:', result.message);
        }
    } catch (error) {
        console.error('Error loading lifetime stats:', error);
    }
}

async function loadRecentTournamentStats() {
    try {
        const response = await fetch('api/tournaments/stats.php?action=recent');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        
        if (!text.trim()) {
            console.error('Empty response from stats API');
            return;
        }
        
        const result = JSON.parse(text);

        if (result.success && result.stats) {
            const stats = result.stats;

            // Update performance
            const recentPlacement = document.getElementById('recentPlacement');
            if (recentPlacement) recentPlacement.textContent = stats.placement || '-';

            const recentWinRate = document.getElementById('recentWinRate');
            if (recentWinRate) recentWinRate.textContent = stats.winRate + '%';

            const recentScore = document.getElementById('recentScore');
            if (recentScore) recentScore.textContent = stats.avgScore;

            // Update battle highlights
            const recentBestFinish = document.getElementById('recentBestFinish');
            if (recentBestFinish) recentBestFinish.textContent = stats.bestFinish || '-';
        } else if (result.message) {
            console.error('Stats API error:', result.message);
        }
    } catch (error) {
        console.error('Error loading recent tournament stats:', error);
    }
}

async function loadRecentTournaments() {
    const listContainer = document.getElementById('recentTournaments');
    if (!listContainer) return;

    try {
        const response = await fetch('api/tournaments/list.php');
        
        if (!response.ok) {
            if (response.status === 401 || response.status === 403) {
                console.error('Authentication error loading tournaments');
                window.location.href = 'index.html';
                return;
            }
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const rawText = await response.text();
        console.log('DEBUG: Raw tournaments list API response:', rawText);
        
        if (!rawText.trim()) {
            throw new Error('Empty response from tournaments list API');
        }
        
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (parseError) {
            console.error('JSON parse error for tournaments list API:', parseError);
            console.error('Raw response that failed to parse:', rawText);
            throw new Error(`Invalid JSON response from tournaments list API: ${parseError.message}`);
        }

        let currentUserId = null;
        try {
            const profileResponse = await fetch('api/users/profile.php');
            
            if (profileResponse.ok) {
                const profileRawText = await profileResponse.text();
                console.log('DEBUG: Raw profile API response in tournaments:', profileRawText);
                
                if (profileRawText.trim()) {
                    try {
                        const profileResult = JSON.parse(profileRawText);
                        if (profileResult.success && profileResult.profile) {
                            currentUserId = profileResult.profile.id;
                        }
                    } catch (parseError) {
                        console.error('JSON parse error for profile API in tournaments:', parseError);
                    }
                }
            }
        } catch (profileError) {
            console.error('Error loading profile for tournament creator identification:', profileError);
        }

        if (result.success && result.tournaments && result.tournaments.length > 0) {
            const recent = result.tournaments.slice(0, 5);

            listContainer.innerHTML = recent.map(t => {
                const date = new Date(t.date || t.created_at).toLocaleDateString();
                const statusBadges = {
                    'upcoming': 'bg-primary',
                    'ongoing': 'bg-warning text-dark',
                    'completed': 'bg-success'
                };
                const badgeClass = statusBadges[t.status] || 'bg-secondary';

                const isCreator = currentUserId && t.created_by === currentUserId;
                const targetPage = isCreator ? 'tournament-detail.html' : 'tournament-bracket.html';

                return `
                    <a href="${targetPage}?id=${t.id}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-3 border rounded-3 mb-2 shadow-sm">
                        <div>
                            <div class="d-flex align-items-center mb-1">
                                <h6 class="mb-0 fw-bold text-dark">${escapeHtml(t.name)}</h6>
                                <span class="badge ${badgeClass} ms-2 rounded-pill" style="font-size: 0.7rem;">${t.status}</span>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-calendar-event me-1"></i> ${date}
                                <span class="mx-1">•</span>
                                <i class="bi bi-geo-alt me-1"></i> ${escapeHtml(t.location || 'TBD')}
                            </small>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                `;
            }).join('');
        } else {
            listContainer.innerHTML = `
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-trophy display-6 mb-2 d-block opacity-50"></i>
                    <p class="mb-0">No tournaments found.</p>
                    <a href="tournaments.html" class="btn btn-sm btn-outline-primary mt-2">Create One</a>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading recent tournaments:', error);
        listContainer.innerHTML = `
            <div class="alert alert-danger m-0 py-2 small">
                <i class="bi bi-exclamation-circle me-1"></i> Failed to load tournaments.
            </div>
        `;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Re-implement logout since we overwrote the file
window.handleLogout = async function () {
    try {
        await fetch('api/auth/logout.php', { method: 'POST' });
        window.location.href = 'index.html';
    } catch (error) {
        console.error('Logout failed:', error);
        window.location.href = 'index.html';
    }
};

const logoutBtn = document.getElementById('logoutBtn');
if (logoutBtn) {
    logoutBtn.addEventListener('click', window.handleLogout);
}
