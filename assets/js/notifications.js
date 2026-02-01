// Global Notification System
let notificationAudio = null;
let notificationAudioUnlocked = false;
var currentUser = null;
let currentTournamentId = null;
let activeTournaments = [];
let lastAssignmentKeys = new Set();
let notificationPollInterval = null;

const initializeNotificationAudio = () => {
    notificationAudio = new Audio('assets/sounds/notification.wav');
    notificationAudio.preload = 'auto';
    notificationAudio.volume = 0.5;

    const unlockHandler = () => {
        if (!notificationAudio) return;
        notificationAudio.play().then(() => {
            notificationAudio.pause();
            notificationAudio.currentTime = 0;
            notificationAudioUnlocked = true;
        }).catch(() => {
            // Ignore errors during unlock attempt
        }).finally(() => {
            document.removeEventListener('click', unlockHandler);
            document.removeEventListener('keydown', unlockHandler);
        });
    };

    document.addEventListener('click', unlockHandler, { once: true });
    document.addEventListener('keydown', unlockHandler, { once: true });
};

const playNotificationSound = () => {
    if (notificationAudio) {
        notificationAudio.currentTime = 0;
        const playPromise = notificationAudio.play();
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.catch(() => {
                if (!notificationAudioUnlocked) {
                    console.warn('Notification audio blocked until user interacts with the page.');
                } else {
                    playFallbackTone();
                }
            });
        }
    } else {
        playFallbackTone();
    }
};

const playFallbackTone = () => {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, audioCtx.currentTime); // A5
        oscillator.frequency.exponentialRampToValueAtTime(440, audioCtx.currentTime + 0.5); // A4

        gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.5);

        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);

        oscillator.start();
        oscillator.stop(audioCtx.currentTime + 0.5);
    } catch (e) {
        console.warn('Fallback audio notification failed:', e);
    }
};

const showMatchAssignmentModal = (match, opponentName) => {
    // Create modal if it doesn't exist
    let modal = document.getElementById('matchAssignmentModal');
    if (!modal) {
        const modalHTML = `
            <div class="modal fade" id="matchAssignmentModal" tabindex="-1" aria-labelledby="matchAssignmentModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-gradient border-0 shadow-lg" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="modal-header border-0">
                            <h5 class="modal-title text-white" id="matchAssignmentModalLabel">
                                <i class="bi bi-bell-fill me-2"></i>YOU ARE NOW PLAYING!
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-white">
                            <div class="text-center mb-3">
                                <div class="display-1 mb-3">⚔️</div>
                                <h4 class="fw-bold mb-3">PLEASE GO TO STADIUM <span id="stadiumNumber">#</span></h4>
                                <p class="mb-2">JUDGED BY: <span id="judgeNameDisplay" class="fw-bold">Loading...</span></p>
                                <div class="bg-white bg-opacity-20 rounded-3 p-3 mb-3">
                                    <div class="fw-bold fs-5 text-uppercase" id="opponentLabel">Opponent: <span id="opponentName">Loading...</span></div>
                                    <div class="small" id="matchDetails">Loading...</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Got it!</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        modal = document.getElementById('matchAssignmentModal');
    }

    const opponentEl = document.getElementById('opponentName');
    const matchDetailsEl = document.getElementById('matchDetails');
    const judgeNameDisplayEl = document.getElementById('judgeNameDisplay');
    const stadiumNumberEl = document.getElementById('stadiumNumber');

    if (modal && opponentEl && matchDetailsEl) {
        opponentEl.textContent = opponentName || 'Unknown';
        const roundLabel = Number.isFinite(parseInt(match.round_number, 10)) ? `Round ${match.round_number}` : 'Round --';
        const matchLabel = Number.isFinite(parseInt(match.match_number, 10)) ? `Match ${match.match_number}` : 'Match --';
        matchDetailsEl.textContent = `${roundLabel}, ${matchLabel}`;

        // Update the new display elements
        if (judgeNameDisplayEl) {
            judgeNameDisplayEl.textContent = match.judge_name || 'Assigned';
        }
        if (stadiumNumberEl) {
            // Extract stadium number from name or use the ID
            const stadiumNumRaw = match.stadium_name ? match.stadium_name.replace(/[^0-9]/g, '') : '';
            const stadiumNum = stadiumNumRaw || match.stadium_id || '';
            stadiumNumberEl.textContent = stadiumNum ? `#${stadiumNum}` : '#?';
        }

        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
};

const checkPlayerMatchAssignment = async () => {
    if (!currentUser) return;

    // Get tournament ID from localStorage
    if (!currentTournamentId) {
        currentTournamentId = localStorage.getItem('activeTournamentId');
    }

    // If still no tournament ID, try to get one from the URL
    if (!currentTournamentId) {
        const urlParams = new URLSearchParams(window.location.search);
        currentTournamentId = urlParams.get('id');
    }

    // If still no tournament ID, can't check for matches
    if (!currentTournamentId) return;

    try {
        const response = await fetch(`api/tournaments/rounds.php?action=getState&tournament_id=${currentTournamentId}`);
        const result = await response.json();
        
        if (!result.success) return;

        const allRounds = result.rounds || [];
        
        // Find matches where current user is assigned as player1 or player2
        const playerMatches = allRounds.flatMap(round => 
            round.matches.filter(match => 
                (match.player1_id === currentUser.id || match.player2_id === currentUser.id) &&
                match.judge_id && 
                match.stadium_id &&
                match.status !== 'completed'
            )
        );

        if (playerMatches.length > 0) {
            const match = playerMatches[0]; // Get first assigned match
            const opponent = match.player1_id === currentUser.id ? match.player2_name : match.player1_name;
            const matchKey = `${match.id}-${match.judge_id}-${match.stadium_id}`;
            
            // Only notify if we haven't notified about this specific assignment
            if (!lastAssignmentKeys.has(matchKey)) {
                showMatchAssignmentModal(match, opponent);
                playNotificationSound();
                lastAssignmentKeys.add(matchKey);
            }
        }
    } catch (error) {
        console.error('Error checking player match assignment:', error);
    }
};

const startNotificationPolling = () => {
    // Clear any existing interval
    if (notificationPollInterval) {
        clearInterval(notificationPollInterval);
    }
    
    // Check immediately
    checkPlayerMatchAssignment();
    
    // Poll every 5 seconds
    notificationPollInterval = setInterval(() => {
        checkPlayerMatchAssignment();
    }, 5000);
};

const stopNotificationPolling = () => {
    if (notificationPollInterval) {
        clearInterval(notificationPollInterval);
        notificationPollInterval = null;
    }
};

const initializeGlobalNotifications = async (tournamentId = null) => {
    currentTournamentId = tournamentId;
    
    // Store tournament ID in localStorage for use on other pages
    if (currentTournamentId) {
        localStorage.setItem('activeTournamentId', currentTournamentId);
    }
    
    // Fetch current user
    try {
        const userResponse = await fetch('api/users/profile.php');
        const userData = await userResponse.json();
        if (userData.success) {
            currentUser = userData.profile;
        }
    } catch (error) {
        console.error('Error loading user profile:', error);
    }

    // Initialize audio
    initializeNotificationAudio();

    // Always start polling - will use localStorage tournament ID if available
    startNotificationPolling();
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    // Always initialize notifications, regardless of page
    // Get tournament ID from URL if on tournament pages
    const urlParams = new URLSearchParams(window.location.search);
    const tournamentId = urlParams.get('id');
    
    initializeGlobalNotifications(tournamentId);
});

// Export for use in other files
window.GlobalNotifications = {
    initialize: initializeGlobalNotifications,
    startPolling: startNotificationPolling,
    stopPolling: stopNotificationPolling,
    showMatchModal: showMatchAssignmentModal,
    playSound: playNotificationSound
};
