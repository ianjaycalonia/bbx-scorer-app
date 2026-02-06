// Tournament Bracket JavaScript
let currentTournamentId = null;
let currentTournament = null;
var currentUser = null;
let allRounds = [];
let byeData = null;
let currentTournamentParticipants = null;
let currentViewStage = null;
let stadiumBindings = [];
let lastAssignmentKeys = new Set(); // To track which assignments we've already notified about
let notificationAudio = null;
let notificationAudioUnlocked = false;

// Bracket connector state
let connectorResizeObserver = null;
let connectorDrawScheduled = false;
let connectorWindowResizeHandler = null;
let connectorScrollListenerAttached = false;
let connectorOverlayContainer = null;
let connectorOverlaySvg = null;
let connectorScrollHandler = null;
let connectorCurrentRounds = [];
let connectorCurrentContainer = null;
let connectorConnectorsEnabled = false;

const formatOrdinal = (value) => {
    const n = parseInt(value, 10);
    if (!Number.isInteger(n)) return `${value}`;
    const remainder100 = n % 100;
    if (remainder100 >= 11 && remainder100 <= 13) {
        return `${n}th`;
    }
    const remainder10 = n % 10;
    switch (remainder10) {
        case 1:
            return `${n}st`;
        case 2:
            return `${n}nd`;
        case 3:
            return `${n}rd`;
        default:
            return `${n}th`;
    }
};

const SVG_NS = 'http://www.w3.org/2000/svg';

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    currentTournamentId = urlParams.get('id');

    if (!currentTournamentId) {
        window.location.href = 'tournaments.html';
        return;
    }

    document.getElementById('backBtn').href = `tournament-detail.html?id=${currentTournamentId}`;

    // Setup collapsed view toggle
    const collapsedViewToggle = document.getElementById('collapsedViewToggle');
    if (collapsedViewToggle) {
        collapsedViewToggle.addEventListener('change', (e) => {
            const roundsContainer = document.getElementById('roundsContainer');
            if (e.target.checked) {
                roundsContainer.classList.add('collapsed');
            } else {
                roundsContainer.classList.remove('collapsed');
            }
            renderRounds(allRounds);
        });
    }

    initializeNotificationAudio();

    loadTournamentAndBracket().then(() => {
        // Check for hash to switch tab (only for Swiss tournaments)
        if (currentTournament?.tournament_type === 'swiss' && window.location.hash === '#standings') {
            const standingsTab = document.getElementById('standings-tab');
            if (standingsTab) {
                bootstrap.Tab.getOrCreateInstance(standingsTab).show();
            }
        }
    });

    // Attach Logout Listener
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }

    // Real-time Poll (every 5 seconds)
    setInterval(() => {
        refreshPageData(false);
    }, 5000);
});

const loadTournamentAndBracket = async () => {
    try {
        // Fetch fresh data from API
        const response = await fetch(`api/tournaments/create.php?action=getDetails&tournament_id=${currentTournamentId}`);
        const data = await response.json();

        if (!data.success) {
            showToast('Tournament not found.', { variant: 'danger' });
            return;
        }

        currentTournament = data.tournament;
        currentTournamentPeople = data.people ?? [];
        document.title = `${currentTournament.name} - Bracket`;
        document.getElementById('tournamentName').textContent = currentTournament.name;

        // Hide standings tab and button for non-Swiss tournaments
        const isSwiss = currentTournament.tournament_type === 'swiss';
        const standingsTab = document.getElementById('standings-tab');
        const standingsPane = document.getElementById('standings-pane');

        if (!isSwiss && standingsTab) {
            standingsTab.style.display = 'none';
        }
        if (!isSwiss && standingsPane) {
            standingsPane.style.display = 'none';
        }

        // Fetch current user for authorization
        const userResponse = await fetch('api/users/profile.php');
        const userData = await userResponse.json();
        if (userData.success) {
            currentUser = userData.profile;
        }

        refreshPageData();
        refreshResultsPanel();
    } catch (error) {
        console.error('Error loading tournament:', error);
    }
};

const refreshPageData = async (showNotification = false) => {
    const promises = [refreshBracket(false)];

    // Only refresh standings for Swiss tournaments
    if (currentTournament && currentTournament.tournament_type === 'swiss') {
        promises.push(refreshStandings(false));
    }

    await Promise.all(promises);

    // Check for player match assignments after data refresh
    checkPlayerMatchAssignment();

    await refreshResultsPanel();
    if (showNotification) {
        showToast('All data refreshed.', { variant: 'success' });
    }
};

// Load tournament participants for bye name resolution
const loadTournamentParticipants = async () => {
    try {
        const response = await fetch(`api/tournaments/roles.php?action=getPeople&tournament_id=${currentTournamentId}`);
        const result = await response.json();

        if (result.success) {
            currentTournamentParticipants = result.people ?? [];
        } else {
            currentTournamentParticipants = [];
            console.error('Failed to load participants:', result.message);
        }
    } catch (error) {
        currentTournamentParticipants = [];
        console.error('Error loading participants:', error);
    }
};

const refreshBracket = async (showNotification = false) => {
    try {
        const response = await fetch(`api/tournaments/rounds.php?action=getState&tournament_id=${currentTournamentId}`);
        const result = await response.json();

        if (!result.success) {
            console.error('Failed to load bracket state:', result.message);
            return;
        }

        allRounds = normalizeRoundsData(result.rounds ?? []);
        byeData = result.byes ?? null;
        stadiumBindings = result.stadium_bindings ?? [];

        // Check for Judge Rotations/New Assignments
        checkForJudgeRotations(allRounds, stadiumBindings);

        // Load tournament participants for bye name resolution
        await loadTournamentParticipants();

        // If viewing stage hasn't been set yet, set to tournament's current stage
        if (currentViewStage === null) {
            currentViewStage = currentTournament ? parseInt(currentTournament.current_stage) : 1;
        }

        // Sync the UI radio buttons
        const stage1Radio = document.getElementById('stage1Tab');
        const stage2Radio = document.getElementById('stage2Tab');
        if (stage1Radio && stage2Radio) {
            if (currentViewStage === 2) stage2Radio.checked = true;
            else stage1Radio.checked = true;
        }

        renderRounds(allRounds);

        // Check if tournament is eligible for Stage 2 transition
        checkStage2Eligibility(allRounds);

        // Check if tournament is eligible to be finished
        checkTournamentCompletion(allRounds);
    } catch (error) {
        console.error('Error fetching bracket state:', error);
    }
};

const normalizeRoundsData = (rounds = []) => {
    return rounds.map(round => {
        const normalizedMatches = (round.matches ?? []).map(match => {
            const player1 = match.player1 ?? {};
            const player2 = match.player2 ?? {};
            const judge = match.judge ?? null;
            const stadium = match.stadium ?? null;
            const roundNumber = match.round_number ?? round.round_number ?? null;
            const stageNumber = match.stage ?? round.stage ?? null;

            const player1Id = player1.id ?? match.player1_id ?? null;
            const player2Id = player2.id ?? match.player2_id ?? null;
            const judgeId = judge?.id ?? match.judge_id ?? null;
            const stadiumId = stadium?.id ?? match.stadium_id ?? null;

            return {
                ...match,
                player1,
                player2,
                judge,
                stadium,
                round_number: roundNumber,
                stage: stageNumber,
                player1_id: player1Id,
                player2_id: player2Id,
                player1_name: player1.name ?? match.player1_name ?? 'TBA',
                player2_name: player2.name ?? match.player2_name ?? 'TBA',
                judge_id: judgeId,
                judge_name: judge?.name ?? match.judge_name ?? 'Unassigned',
                stadium_id: stadiumId,
                stadium_name: stadium?.name ?? match.stadium_name ?? 'Unassigned'
            };
        });

        return {
            ...round,
            matches: normalizedMatches
        };
    });
};

const refreshStandings = async () => {
    try {
        const response = await fetch(`api/tournaments/rounds.php?action=getStandings&tournament_id=${currentTournamentId}`);
        const result = await response.json();

        if (result.success) {
            renderStandings(result.standings ?? []);
        } else {
            console.error('Failed to load standings:', result.message);
        }
    } catch (error) {
        console.error('Error fetching standings:', error);
    }
};

const renderStandings = (standings) => {
    const list = document.getElementById('standingsList');
    if (!list) return;

    if (standings.length === 0) {
        list.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">No results recorded yet. Standings will appear as matches complete.</td></tr>';
        return;
    }

    const topCut = currentTournament?.top_cut ? parseInt(currentTournament.top_cut, 10) : 0;
    const swissComplete = isSwissRoundsComplete(allRounds);
    const stageTwoActive = (currentTournament?.current_stage ?? 1) >= 2;
    const showAdvancedBadges = topCut > 0 && (swissComplete || stageTwoActive);

    list.innerHTML = standings.map((s, index) => {
        const rank = index + 1;
        const isAdvanced = showAdvancedBadges && rank <= topCut;
        const fqiValue = s.fqi ?? 0;
        const pointDiff = s.point_diff ?? ((s.pf ?? 0) - (s.pa ?? 0));
        const diffLabel = pointDiff > 0 ? `+${pointDiff}` : pointDiff;
        const diffClass = pointDiff > 0 ? 'text-success' : (pointDiff < 0 ? 'text-danger' : 'text-muted');

        return `
        <tr>
            <td class="rank-cell">#${rank}</td>
            <td>
                <div class="player-cell">
                    <div class="player-avatar">${s.name.charAt(0).toUpperCase()}</div>
                    <div class="player-name">
                        ${s.seed ? `<span class="seed-number">${s.seed}</span>` : ''}
                        ${s.name}${isAdvanced ? ' <span class="badge badge-advanced">Advanced</span>' : ''}
                    </div>
                </div>
            </td>
            <td class="text-center record-cell">
                ${s.wins}W - ${s.losses}L
            </td>
            <td class="text-center">
                <span class="fw-bold text-primary">${s.bey_points}</span>
            </td>
            <td class="text-center">
                <span class="fw-bold text-info">${fqiValue}</span>
            </td>
            <td class="text-center">
                <span class="fw-bold ${diffClass}">${diffLabel}</span>
            </td>
        </tr>
    `;
    }).join('');
};

const refreshResultsPanel = async () => {
    const loading = document.getElementById('resultsLoading');
    const empty = document.getElementById('resultsEmpty');
    const content = document.getElementById('resultsContent');
    const resultsTabItem = document.getElementById('resultsTabItem');
    const resultsTabBtn = document.getElementById('results-tab');
    const bracketTabBtn = document.getElementById('bracket-tab');
    if (!loading || !empty || !content || !resultsTabItem || !resultsTabBtn)
        return;

    const tournamentCompleted = currentTournament && currentTournament.status === 'completed';

    if (!tournamentCompleted) {
        // Hide tab and ensure users aren't stuck on a hidden pane
        if (!resultsTabItem.classList.contains('d-none')) {
            resultsTabItem.classList.add('d-none');
        }
        if (resultsTabBtn.classList.contains('active') && bracketTabBtn) {
            const bracketTab = bootstrap.Tab.getOrCreateInstance(bracketTabBtn);
            bracketTab.show();
        }

        loading.classList.add('d-none');
        empty.classList.remove('d-none');
        empty.textContent = 'Results will appear once the tournament is completed.';
        content.classList.add('d-none');
        return;
    }

    resultsTabItem.classList.remove('d-none');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    content.classList.add('d-none');

    try {
        const response = await fetch(`api/tournaments/rounds.php?action=getPodium&tournament_id=${currentTournamentId}`);
        const result = await response.json();
        loading.classList.add('d-none');

        if (result.success && result.podium) {
            content.classList.remove('d-none');
            empty.classList.add('d-none');
            renderResultsPodium(result.podium, result.swissKing || null);
        } else {
            empty.classList.remove('d-none');
            empty.textContent = 'Results not available yet. Please check back later.';
        }
    } catch (error) {
        console.error('Error fetching results:', error);
        loading.classList.add('d-none');
        empty.classList.remove('d-none');
        empty.textContent = 'Unable to load results right now. Please try again later.';
    }
};

const renderResultsPodium = (podium, swissKing = null) => {
    const first = podium?.[1] || null;
    const second = podium?.[2] || null;
    const third = podium?.[3] || null;

    updateResultsSlot(first, 'resultsPodium1stName', 'resultsPodium1stAvatar');
    updateResultsSlot(second, 'resultsPodium2ndName', 'resultsPodium2ndAvatar');
    updateResultsSlot(third, 'resultsPodium3rdName', 'resultsPodium3rdAvatar');

    const swissSection = document.getElementById('resultsSwissKingSection');
    const swissName = document.getElementById('resultsSwissKingName');
    if (swissSection && swissName) {
        if (swissKing && swissKing.name) {
            swissSection.classList.remove('d-none');
            swissName.textContent = swissKing.name;
        } else {
            swissSection.classList.add('d-none');
            swissName.textContent = '---';
        }
    }

    const extendedSection = document.getElementById('resultsExtendedRankings');
    const extendedList = document.getElementById('resultsExtendedRankingsList');
    if (!extendedSection || !extendedList)
        return;

    const extendedHtml = buildResultsPlacements(podium);
    if (extendedHtml) {
        extendedList.innerHTML = extendedHtml;
        extendedSection.classList.remove('d-none');
    } else {
        extendedSection.classList.add('d-none');
        extendedList.innerHTML = '';
    }
};

const updateResultsSlot = (player, nameId, avatarId) => {
    const nameEl = document.getElementById(nameId);
    const avatarEl = document.getElementById(avatarId);
    const displayName = player?.name?.trim() || '---';
    if (nameEl)
        nameEl.textContent = displayName;

    if (avatarEl) {
        const initial = displayName !== '---' ? displayName.charAt(0).toUpperCase() : '?';
        avatarEl.textContent = initial;
    }
};

const buildResultsPlacements = (podium) => {
    if (!podium)
        return '';

    const topCut = parseInt(currentTournament?.top_cut ?? 0, 10) || 0;
    const rankTo = parseInt(currentTournament?.rank_to ?? 0, 10) || 0;
    const maxKey = Object.keys(podium)
        .map((key) => parseInt(key, 10))
        .filter((n) => Number.isInteger(n))
        .reduce((acc, val) => Math.max(acc, val), 0);

    const limit = Math.max(4, topCut, rankTo, maxKey);
    let html = '';
    for (let place = 4; place <= limit; place++) {
        const player = podium[place];
        if (!player || !player.name)
            continue;
        const ordinal = formatOrdinal(place).toUpperCase();
        html += `
            <div class="col-6 col-md-4 col-lg-3">
                <div class="p-3 bg-white rounded-3 border text-center shadow-sm h-100">
                    <div class="badge bg-light text-dark rounded-pill mb-1" style="font-size: 0.7rem;">${ordinal} PLACE</div>
                    <div class="fw-bold small text-truncate" title="${player.name}">${player.name}</div>
                </div>
            </div>
        `;
    }

    return html.trim();
};

const renderRounds = (roundsData) => {
    const container = document.getElementById('roundsContainer');
    if (!container) return;

    teardownConnectorOverlay();

    // Dispatch based on Tournament Type
    if (currentTournament?.tournament_type === 'swiss') {
        renderSwissBracket(container, roundsData);
    } else {
        renderSingleEliminationBracket(container, roundsData);
    }

    // Common post-render actions
    scrollToJudgeAssignedMatch();
    updatePlayerMatchButton();
};

const renderSwissBracket = (container, roundsData) => {
    const stage = currentViewStage || 1;
    const filteredRounds = filterRoundsForCurrentStage(roundsData, stage);

    // Toggle Stage Tabs Visibility
    const stageTabsContainer = document.getElementById('stageTabsContainer');
    if (stageTabsContainer) {
        const hasStage2 = roundsData.some(r => r.stage === 2);
        if (hasStage2) {
            stageTabsContainer.classList.remove('stage-tabs-container-hidden');
            stageTabsContainer.classList.add('stage-tabs-container-visible');
        } else {
            stageTabsContainer.classList.add('stage-tabs-container-hidden');
            stageTabsContainer.classList.remove('stage-tabs-container-visible');
        }
    }

    if (stage === 1) {
        renderSwissStage1(container, filteredRounds, roundsData);
    } else {
        renderEliminationTree(container, filteredRounds);
    }
};

const renderSingleEliminationBracket = (container, roundsData) => {
    // Hide Stage Switcher & Stage 2 Button
    const stageTabsContainer = document.getElementById('stageTabsContainer');
    if (stageTabsContainer) {
        stageTabsContainer.classList.add('stage-tabs-container-hidden');
        stageTabsContainer.classList.remove('stage-tabs-container-visible');
    }
    const stage2ButtonContainer = document.getElementById('stage2ButtonContainer');
    if (stage2ButtonContainer) {
        stage2ButtonContainer.classList.add('stage2-button-container-hidden');
        stage2ButtonContainer.classList.add('single-elimination-hidden');
    }

    const filteredRounds = filterRoundsForCurrentStage(roundsData, 1);
    renderEliminationTree(container, filteredRounds);
};

const renderSwissStage1 = (container, rounds, allRoundsData) => {
    container.classList.remove('single-elimination-mode');
    container.style.minHeight = '';

    // Show Stage 2 Transition Button if eligible
    const stage2ButtonContainer = document.getElementById('stage2ButtonContainer');
    if (stage2ButtonContainer) {
        stage2ButtonContainer.classList.remove('single-elimination-hidden');
        checkStage2Eligibility(allRoundsData);
    }

    if (rounds.length === 0) {
        container.innerHTML = '<div class="alert alert-info text-center w-100">Swiss rounds will appear here once generated. Finish the current round to unlock the next.</div>';
        return;
    }

    container.innerHTML = rounds.map(round => renderRoundBlock(round, false, null)).join('');
};

const renderEliminationTree = (container, rounds) => {
    container.classList.add('single-elimination-mode');

    // Compute Layout
    const bracketLayout = computeBracketLayout(rounds);
    const maxMatches = Math.max(...rounds.map(r => r.matches.length), 1);
    container.style.minHeight = (maxMatches * 160 + 100) + 'px';

    if (rounds.length === 0) {
        container.innerHTML = '<div class="alert alert-light text-center w-100">No rounds found for this stage.</div>';
        return;
    }

    const totalRounds = rounds.length;
    container.innerHTML = rounds.map(round => renderRoundBlock(round, true, bracketLayout, totalRounds)).join('');

    setupBracketConnectorOverlay(container, rounds);
};

const renderRoundBlock = (round, isElimination, layout, totalRounds) => {
    // Separate bye matches from regular matches
    const regularMatches = [];
    const byeMatches = [];

    if (round.bye_players) {
        const byePlayerIds = round.bye_players.split(',').filter(id => id.trim());
        const uniqueByePlayerIds = [...new Set(byePlayerIds)];

        uniqueByePlayerIds.forEach(playerId => {
            const participant = currentTournamentParticipants?.find(p => p.user_id === playerId);
            const playerName = participant?.display_name || participant?.blader_name || `Player ID: ${playerId}`;
            byeMatches.push({
                is_bye: true,
                player1: {
                    id: playerId,
                    name: playerName,
                    seed: participant?.seed || 0
                },
                player2: null
            });
        });
    }

    (round.matches || []).forEach(match => {
        if (!isElimination && (match.is_bye || (match.player2?.id === null && match.player1?.id))) return;
        regularMatches.push(match);
    });

    const matchesForRound = normalizeMatchesForDisplay(regularMatches, isElimination);

    // Sort matches for layout if Elimination
    if (isElimination && layout && matchesForRound.length > 1) {
        matchesForRound.sort((a, b) => {
            const idA = getNumericMatchId(a?.id);
            const idB = getNumericMatchId(b?.id);
            const posA = Number.isFinite(idA) ? (layout.slotMap.get(idA) ?? Number.MAX_SAFE_INTEGER) : Number.MAX_SAFE_INTEGER;
            const posB = Number.isFinite(idB) ? (layout.slotMap.get(idB) ?? Number.MAX_SAFE_INTEGER) : Number.MAX_SAFE_INTEGER;
            if (posA === posB) {
                const isSpecialA = (a?.match_number ?? 0) >= 90;
                const isSpecialB = (b?.match_number ?? 0) >= 90;
                if (isSpecialA && isSpecialB) {
                    return (b?.match_number ?? 0) - (a?.match_number ?? 0);
                }
                return (a?.match_number ?? 0) - (b?.match_number ?? 0);
            }
            return posA - posB;
        });
    }

    let specialIndex = 0;

    return `
    <div class="bracket-round">
        <div class="round-header">
            <span class="round-title">${isElimination ? getRoundLabel(round.round_number, totalRounds) : `Round ${round.round_number}`}</span>
            <span class="badge-status ${round.status === 'active' ? 'active' : 'scheduled'}">${round.status}</span>
        </div>
        <div class="match-list">
            ${byeMatches.length > 0 ? `
                <div class="bye-entries">
                    ${byeMatches.map(match => {
        const playerName = match.player1?.name || 'Unknown';
        return `
                            <div class="bye-entry">
                                <span class="bye-label">Bye:</span>
                                <span class="badge bg-info">
                                    ${match.player1.seed ? `<span class="seed-number" style="background: rgba(255,255,255,0.2); margin-right: 4px; border-radius: 2px; padding: 0 4px;">${match.player1.seed}</span>` : ''}
                                    ${escapeHtml(playerName)}
                                </span>
                            </div>
                        `;
    }).join('')}
                </div>
            ` : ''}
            ${matchesForRound.map((match) => {
        const isSpecial = match.match_number >= 90;
        const matchCardHtml = renderMatchCard(match, isElimination);
        const numericId = getNumericMatchId(match.id);

        let gridStyleAttr = '';
        let bracketPosAttr = '';

        if (isElimination) {
            const layoutInfo = (layout && Number.isFinite(numericId)) ? layout.layoutMap.get(numericId) : null;
            bracketPosAttr = layoutInfo ? ` data-bracket-slot="${layoutInfo.slot}"` : '';

            if (layoutInfo && !isSpecial) {
                gridStyleAttr = ` style="grid-row: ${layoutInfo.rowStart} / span ${layoutInfo.rowSpan};"`;
            } else if (isSpecial) {
                let extraStyles = '';
                // For the finals round, pull special matches much closer to the center
                if (round.round_number === totalRounds && specialIndex === 0) {
                    const finalsMatch = round.matches.find(m => (m.match_number || 0) < 90);
                    const finalsLayout = finalsMatch ? layout.layoutMap.get(getNumericMatchId(finalsMatch.id)) : null;
                    if (finalsLayout) {
                        const isCollapsed = document.getElementById('roundsContainer')?.classList.contains('collapsed');
                        const rowHeight = isCollapsed ? 80 : 280;
                        const cardHeight = isCollapsed ? 65 : 190;

                        const rowSpan = finalsLayout.rowSpan;
                        // Calculation: RowHeight is current height. Center is RowSpan * (rowHeight/2).
                        // Card Bottom is center + (cardHeight/2).
                        const pullUp = Math.max(0, (rowSpan * (rowHeight / 2)) - cardHeight);
                        if (pullUp > 0) {
                            extraStyles = ` margin-top: -${pullUp}px;`;
                        }
                    }
                }
                gridStyleAttr = ` style="grid-row: auto;${extraStyles}"`;
                specialIndex++;
            }
        }

        return `
                    <div class="match-wrapper ${isSpecial ? 'special-match-wrapper' : ''}" 
                         data-match-id="${match.id}" 
                         data-round="${round.round_number}" 
                         data-stage="${round.stage}" 
                         data-match-number="${match.match_number}" 
                         data-special="${isSpecial ? '1' : '0'}"${bracketPosAttr}${gridStyleAttr}>
                        ${isSpecial ? `<div class="special-match-label">${getSpecialMatchLabel(match.match_number)}</div>` : ''}
                        ${matchCardHtml}
                    </div>
                `;
    }).join('')}
        </div>
    </div>
    `;
};

const filterRoundsForCurrentStage = (rounds, stage) => {
    if (!Array.isArray(rounds)) return [];

    if (currentTournament?.tournament_type === 'swiss' && stage === 1) {
        // For Swiss tournaments in Stage 1, only show Stage 1 rounds (exclude Stage 2/top cut)
        return rounds.filter(r => r.stage === 1);
    }

    return rounds.filter(r => r.stage === stage);
};

const normalizeMatchesForDisplay = (matches, isSingleElim) => {
    if (!Array.isArray(matches) || matches.length === 0) return [];

    if (isSingleElim) {
        return matches.map(match => {
            if (!match) return match;
            if (match.is_bye) {
                return {
                    ...match,
                    status: match.status ?? 'completed',
                    player2: {
                        id: null,
                        name: 'BYE',
                        score: match.player2?.score ?? 0
                    },
                    winner_id: match.winner_id ?? match.player1?.id ?? null
                };
            }
            return match;
        });
    }

    return matches;
};

const getNumericMatchId = (id) => {
    if (id === null || id === undefined) return NaN;
    const parsed = parseInt(id, 10);
    return Number.isNaN(parsed) ? NaN : parsed;
};

const computeBracketLayout = (rounds) => {
    const layoutMap = new Map();
    const slotMap = new Map();
    let maxRows = 0;
    let isElimination = false;

    if (!Array.isArray(rounds) || rounds.length === 0) {
        return { layoutMap, slotMap, maxRows, isElimination };
    }

    const relevantRounds = rounds
        .filter(round => round && Array.isArray(round.matches) && round.matches.some(match => match && match.match_number < 90));

    if (relevantRounds.length === 0) {
        return { layoutMap, slotMap, maxRows, isElimination };
    }

    isElimination = true;

    const sortedRounds = [...relevantRounds].sort((a, b) => Number(a.round_number) - Number(b.round_number));
    const baseRoundNumber = Number(sortedRounds[0].round_number) ?? 1;
    let maxRoundNumber = baseRoundNumber;

    const matchMap = new Map();
    const parentChildren = new Map();
    const childHasParent = new Map();

    sortedRounds.forEach(round => {
        const roundNumber = Number(round.round_number) ?? baseRoundNumber;
        if (roundNumber > maxRoundNumber) {
            maxRoundNumber = roundNumber;
        }

        (round.matches ?? []).forEach(match => {
            if (!match || !match.id || match.match_number >= 90) return;

            const matchId = Number(match.id);
            matchMap.set(matchId, { match, roundNumber });

            if (match.next_match_id) {
                const parentId = Number(match.next_match_id);
                const slot = Number(match.next_match_slot) === 2 ? 2 : 1;
                if (!parentChildren.has(parentId)) {
                    parentChildren.set(parentId, {});
                }
                parentChildren.get(parentId)[slot] = matchId;
                childHasParent.set(matchId, parentId);
            }
        });
    });

    if (matchMap.size === 0) {
        return { layoutMap, slotMap, maxRows, isElimination };
    }

    const totalRounds = Math.max(1, maxRoundNumber - baseRoundNumber + 1);
    maxRows = 2 ** Math.max(0, totalRounds - 1);

    const roots = Array.from(matchMap.values())
        .filter(entry => !childHasParent.has(Number(entry.match.id)))
        .sort((a, b) => {
            if (a.roundNumber !== b.roundNumber) {
                return b.roundNumber - a.roundNumber;
            }
            return (a.match.match_number ?? 0) - (b.match.match_number ?? 0);
        });

    const visited = new Set();
    let nextRootOffset = 0;

    const computeRowSpan = (roundNumber) => {
        const relativeIndex = roundNumber - baseRoundNumber;
        return Math.max(1, 2 ** Math.max(0, relativeIndex));
    };

    const computeRootSlotSpan = (roundNumber) => {
        const relativeIndex = roundNumber - baseRoundNumber;
        const levelsBelow = totalRounds - 1 - Math.max(0, relativeIndex);
        return Math.max(1, 2 ** Math.max(0, levelsBelow));
    };

    const assignMatch = (matchId, slot) => {
        if (!matchMap.has(matchId) || visited.has(matchId)) return;
        visited.add(matchId);

        const { match, roundNumber } = matchMap.get(matchId);
        const isSpecial = parseInt(match.match_number || 0) >= 90;
        const rowSpan = isSpecial ? 1 : computeRowSpan(roundNumber);
        const rowStart = slot * rowSpan + 1;

        layoutMap.set(matchId, { slot, rowSpan, rowStart, roundNumber });
        slotMap.set(matchId, slot);
        maxRows = Math.max(maxRows, rowStart + rowSpan - 1);

        const children = parentChildren.get(matchId);
        if (!children) return;

        const baseSlot = slot * 2;
        if (children[1]) assignMatch(children[1], baseSlot);
        if (children[2]) assignMatch(children[2], baseSlot + 1);
    };

    roots.forEach(entry => {
        const matchId = Number(entry.match.id);
        const slotSpan = computeRootSlotSpan(entry.roundNumber);
        const assignedSlot = nextRootOffset;
        assignMatch(matchId, assignedSlot);
        nextRootOffset += slotSpan;
    });

    matchMap.forEach((value, matchId) => {
        if (!visited.has(matchId)) {
            assignMatch(matchId, nextRootOffset);
            nextRootOffset += 1;
        }
    });

    return { layoutMap, slotMap, maxRows, treeMaxRows: maxRows, isElimination };
};

const setupBracketConnectorOverlay = (container, rounds) => {
    connectorCurrentContainer = container;
    connectorCurrentRounds = Array.isArray(rounds) ? rounds : [];

    if (!shouldRenderBracketConnectors(connectorCurrentRounds)) {
        connectorConnectorsEnabled = false;
        return;
    }

    connectorConnectorsEnabled = true;

    // Ensure container can anchor absolutely positioned overlay
    if (window.getComputedStyle(container).position === 'static') {
        container.style.position = 'relative';
    }

    if (!connectorOverlayContainer) {
        connectorOverlayContainer = document.createElement('div');
        connectorOverlayContainer.className = 'bracket-connector-overlay';
        connectorOverlayContainer.style.position = 'absolute';
        connectorOverlayContainer.style.top = '0';
        connectorOverlayContainer.style.left = '0';
        connectorOverlayContainer.style.pointerEvents = 'none';
        connectorOverlayContainer.style.zIndex = '1';

        connectorOverlaySvg = document.createElementNS(SVG_NS, 'svg');
        connectorOverlaySvg.classList.add('bracket-connector-svg');
        connectorOverlaySvg.setAttribute('xmlns', SVG_NS);
        connectorOverlaySvg.setAttribute('fill', 'none');
        connectorOverlayContainer.appendChild(connectorOverlaySvg);
    }

    if (!connectorOverlayContainer.parentElement) {
        container.appendChild(connectorOverlayContainer);
    }

    // Attach listeners
    if (!connectorWindowResizeHandler) {
        connectorWindowResizeHandler = () => scheduleConnectorRedraw();
        window.addEventListener('resize', connectorWindowResizeHandler);
    }

    if (connectorScrollListenerAttached && connectorScrollHandler && connectorCurrentContainer !== container) {
        connectorCurrentContainer.removeEventListener('scroll', connectorScrollHandler);
        connectorScrollListenerAttached = false;
    }

    if (!connectorScrollListenerAttached) {
        connectorScrollHandler = () => scheduleConnectorRedraw();
        container.addEventListener('scroll', connectorScrollHandler, { passive: true });
        connectorScrollListenerAttached = true;
    }

    if (connectorResizeObserver) {
        connectorResizeObserver.disconnect();
    }
    connectorResizeObserver = new ResizeObserver(() => scheduleConnectorRedraw());
    connectorResizeObserver.observe(container);
    container.querySelectorAll('.match-card').forEach(card => connectorResizeObserver.observe(card));

    scheduleConnectorRedraw();
}

const teardownConnectorOverlay = () => {
    if (connectorResizeObserver) {
        connectorResizeObserver.disconnect();
        connectorResizeObserver = null;
    }

    if (connectorScrollListenerAttached && connectorScrollHandler && connectorCurrentContainer) {
        connectorCurrentContainer.removeEventListener('scroll', connectorScrollHandler);
    }
    connectorScrollListenerAttached = false;
    connectorScrollHandler = null;

    if (connectorWindowResizeHandler) {
        window.removeEventListener('resize', connectorWindowResizeHandler);
        connectorWindowResizeHandler = null;
    }

    if (connectorOverlayContainer?.parentElement) {
        connectorOverlayContainer.parentElement.removeChild(connectorOverlayContainer);
    }
    connectorOverlayContainer = null;
    connectorOverlaySvg = null;

    connectorCurrentRounds = [];
    connectorCurrentContainer = null;
    connectorConnectorsEnabled = false;
};

const scheduleConnectorRedraw = () => {
    if (!connectorConnectorsEnabled || !connectorOverlaySvg) return;
    if (connectorDrawScheduled) return;
    connectorDrawScheduled = true;
    requestAnimationFrame(() => {
        connectorDrawScheduled = false;
        drawBracketConnectors();
    });
};

const drawBracketConnectors = () => {
    if (!connectorConnectorsEnabled || !connectorOverlaySvg || !connectorCurrentContainer) return;

    const container = connectorCurrentContainer;
    const svg = connectorOverlaySvg;

    const scrollWidth = container.scrollWidth;
    const scrollHeight = container.scrollHeight;
    connectorOverlayContainer.style.width = `${scrollWidth}px`;
    connectorOverlayContainer.style.height = `${scrollHeight}px`;

    svg.setAttribute('width', scrollWidth);
    svg.setAttribute('height', scrollHeight);
    svg.setAttribute('viewBox', `0 0 ${scrollWidth} ${scrollHeight}`);

    svg.replaceChildren();

    const containerRect = container.getBoundingClientRect();
    const scrollX = container.scrollLeft;
    const scrollY = container.scrollTop;

    const wrapperMap = new Map();
    container.querySelectorAll('.match-wrapper[data-match-id]').forEach(wrapper => {
        const id = Number(wrapper.dataset.matchId);
        if (Number.isFinite(id)) {
            wrapperMap.set(id, wrapper);
        }
    });

    const connections = collectBracketConnections(connectorCurrentRounds);

    connections.forEach(connection => {
        const sourceWrapper = wrapperMap.get(connection.from);
        const targetWrapper = wrapperMap.get(connection.to);
        if (!sourceWrapper || !targetWrapper) return;

        const sourceCard = sourceWrapper.querySelector('.match-card');
        const targetCard = targetWrapper.querySelector('.match-card');
        if (!sourceCard || !targetCard) return;

        const sourceRect = sourceCard.getBoundingClientRect();
        const targetRect = targetCard.getBoundingClientRect();

        const startX = sourceRect.right - containerRect.left + scrollX;
        const startY = sourceRect.top + (sourceRect.height / 2) - containerRect.top + scrollY;
        const endX = targetRect.left - containerRect.left + scrollX;
        const endY = targetRect.top + (targetRect.height / 2) - containerRect.top + scrollY;

        const midX = (startX + endX) / 2;

        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', `M ${startX} ${startY} H ${midX} V ${endY} H ${endX}`);
        path.setAttribute('class', `bracket-connector-path${connection.type === 'loser' ? ' loser' : ''}`);
        svg.appendChild(path);
    });
}

const collectBracketConnections = (rounds) => {
    const connections = [];
    if (!Array.isArray(rounds)) return connections;

    // Create a map of all matches for easy match_number lookup
    const matchLookup = new Map();
    rounds.forEach(round => {
        (round.matches || []).forEach(match => {
            const id = getNumericMatchId(match.id);
            if (Number.isFinite(id)) matchLookup.set(id, match);
        });
    });

    rounds.forEach(round => {
        if (!round || !Array.isArray(round.matches)) return;

        round.matches.forEach(match => {
            const numericId = getNumericMatchId(match?.id);
            if (!match || !match.id || !Number.isFinite(numericId)) return;

            const isSpecialMatch = (matchNumber) => parseInt(matchNumber || 0) >= 90;

            // Skip connections starting from special matches
            if (isSpecialMatch(match.match_number)) return;

            const addConnection = (targetId, type) => {
                if (!targetId || !Number.isFinite(targetId)) return;

                const targetMatch = matchLookup.get(targetId);
                // Skip connections going TO special matches
                if (targetMatch && isSpecialMatch(targetMatch.match_number)) return;

                connections.push({ from: numericId, to: targetId, type });
            };

            addConnection(getNumericMatchId(match.next_match_id), 'winner');
            addConnection(getNumericMatchId(match.loser_next_match_id), 'loser');
        });
    });

    return connections;
};

const shouldRenderBracketConnectors = (rounds) => {
    if (!currentTournament) return false;
    if (!Array.isArray(rounds) || rounds.length === 0) return false;

    const isSingleEliminationTournament = currentTournament.tournament_type === 'single_elimination';

    return rounds.some(round => {
        if (!round || !Array.isArray(round.matches) || round.matches.length === 0) return false;
        const stageNumber = Number(round.stage);
        const isEliminationStage = stageNumber === 2 || (isSingleEliminationTournament && stageNumber === 1);
        if (!isEliminationStage) return false;
        return round.matches.some(match => match && (match.next_match_id || match.loser_next_match_id));
    });
};

const renderMatchCard = (match, isSingleElimStage) => {
    const isCompleted = match.status === 'completed';
    const isP1Winner = isCompleted && match.winner_id === match.player1.id;
    const isP2Winner = isCompleted && match.winner_id === match.player2.id;
    const isBye = Boolean(match.is_bye || match.player2?.id === null);

    // Check if player has a bye in the current round (Swiss only)
    const roundNumber = match.round_number ?? 0;
    const p1HasBye = byeData?.byes_awarded?.[match.player1?.id] === roundNumber;
    const p2HasBye = byeData?.byes_awarded?.[match.player2?.id] === roundNumber;

    const player1Finishes = isCompleted && Array.isArray(match.finishes?.player1) ? match.finishes.player1 : [];
    const player2Finishes = isCompleted && Array.isArray(match.finishes?.player2) ? match.finishes.player2 : [];
    const player1BadgeMarkup = player1Finishes.length ? `<span class="finish-badge-list">${renderFinishBadges(player1Finishes)}</span>` : '';
    const player2BadgeMarkup = player2Finishes.length ? `<span class="finish-badge-list">${renderFinishBadges(player2Finishes)}</span>` : '';

    // Determine if clickable
    const isJudge = currentUser && match.judge && String(currentUser.id) === String(match.judge.id);
    const isPlayer = currentUser && (String(currentUser.id) === String(match.player1.id) || String(currentUser.id) === String(match.player2?.id));
    const isCreator = currentTournament && currentUser && String(currentTournament.created_by) === String(currentUser.id);
    const isOrganizer = currentUser && Array.isArray(currentTournamentPeople) && currentTournamentPeople.some(person => {
        if (!person || String(person.user_id) !== String(currentUser.id)) return false;
        if (!person.role) return false;
        return person.role.split(',').some(rolePart => rolePart.trim() === 'organizer');
    });
    // Allow scoring if assigned/in-progress OR if completed and user was the judge (Edit Mode)
    const canScore = !isBye && isJudge && (match.status === 'assigned' || match.status === 'in_progress');
    // Unified Manual Override: available for non-completed matches (creator/organizer) or completed matches (creator/organizer/judge)
    const canManualOverride = !isBye && (
        (!isCompleted && (isCreator || isOrganizer)) || // Admin intervention when no judge available
        (isCompleted && (isCreator || isOrganizer || isJudge)) // Edit completed matches
    );

    const headerLabel = getMatchHeaderLabel(match, isSingleElimStage);
    const player1Score = normalizeScoreDisplay(match.player1?.score, isBye && isCompleted);
    const player2Score = normalizeScoreDisplay(match.player2?.score, isBye && isCompleted);

    return `
        <div class="match-card status-${match.status} ${canScore ? 'clickable-card' : ''} ${isJudge && match.status !== 'completed' ? 'judge-assigned-highlight' : ''} ${isPlayer && match.status !== 'completed' ? 'player-assigned-highlight' : ''} ${isBye ? 'match-card-bye' : ''}"
             onclick="${canScore ? `promptMatchScore(${match.id}, '${escapeHtml(match.player1.name)}', '${escapeHtml(match.player2.name)}', ${match.player1.score}, ${match.player2.score})` : ''}">
            ${canManualOverride ? `
                <button type="button" class="match-ellipsis-btn" onclick="event.stopPropagation(); toggleManualOverride(${match.id})" title="${isCompleted ? 'Edit Match Result' : 'Manual Override'}">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
            ` : ''}
            <div class="match-header" style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #64748b; margin-bottom: 0.5rem;">
                <span>${headerLabel}</span>
                <span><small class="text-muted fw-normal">Match ${match.match_number}</small></span>
            </div>
            <div class="player-vs">
                <div class="player-row ${isP1Winner ? 'winner' : (isP2Winner ? 'loser' : '')}">
                    <div class="player-topline">
                        ${match.player1.seed ? `<span class="seed-number">${match.player1.seed}</span>` : ''}
                        <span class="player-name">${escapeHtml(match.player1.name || 'TBA')}</span>
                        <div class="player-meta">
                            <span class="match-score font-monospace fw-bold">${player1Score}</span>
                            ${isP1Winner ? '<i class="bi bi-trophy-fill text-warning match-trophy"></i>' : ''}
                            ${p1HasBye ? '<span class="badge bg-info ms-2">BYE</span>' : ''}
                        </div>
                    </div>
                    ${player1BadgeMarkup ? `<div class="player-finishes">${player1BadgeMarkup}</div>` : ''}
                </div>
                <div class="player-row ${isP2Winner ? 'winner' : (isP1Winner ? 'loser' : '')}">
                    <div class="player-topline">
                        ${match.player2.seed ? `<span class="seed-number">${match.player2.seed}</span>` : ''}
                        <span class="player-name">${escapeHtml(match.player2.name || 'TBA')}</span>
                        <div class="player-meta">
                            <span class="match-score font-monospace fw-bold">${player2Score}</span>
                            ${isP2Winner ? '<i class="bi bi-trophy-fill text-warning match-trophy"></i>' : ''}
                            ${p2HasBye ? '<span class="badge bg-info ms-2">BYE</span>' : ''}
                        </div>
                    </div>
                    ${player2BadgeMarkup ? `<div class="player-finishes">${player2BadgeMarkup}</div>` : ''}
                    ${isBye && match.player2.name !== 'TBD' ? '<span class="text-muted player-bye ms-2">(BYE)</span>' : ''}
                </div>
            </div>
            
            <!-- Match number for collapsed view -->
            <div class="collapsed-match-number" style="display: none; font-size: 0.7rem; color: #64748b; margin-top: 0.25rem;">
                Match ${match.match_number}
            </div>
            
            ${match.judge || match.stadium ? `
                <div class="assignment-info">
                    ${match.judge ? `<div><i class="bi bi-person-badge"></i> ${escapeHtml(match.judge.name)}</div>` : ''}
                    ${match.stadium ? `<div><i class="bi bi-geo-alt"></i> ${escapeHtml(match.stadium.name)}</div>` : ''}
                </div>
            ` : ''}

            ${match.blocked_reason ? `
                <div class="alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size: 0.75rem; border-radius: 8px;">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> ${escapeHtml(match.blocked_reason)}
                </div>
            ` : ''}

            ${match.status === 'completed' ? `
                <div class="text-center text-muted small mt-2">
                    <i class="bi bi-check-circle-fill text-success me-1"></i> Match Completed
                </div>
            ` : (canScore ? `
                <div class="text-center text-muted small mt-2">
                    <i class="bi bi-hand-index-thumb me-1"></i> Tap card to score
                </div>
            ` : (!isBye ? `
                <div class="text-center text-muted small mt-2">
                    <i class="bi bi-shield-lock me-1"></i> Judge Only
                </div>
            ` : ''))}

            ${canManualOverride ? `
                <div class="manual-override-container" id="manual-override-${match.id}">
                    <div class="text-center mt-2">
                        <button type="button" class="btn btn-sm btn-outline-warning" 
                            onclick="event.stopPropagation(); handleManualOverride(${match.id}, '${escapeHtml(match.player1.name)}', '${escapeHtml(match.player2.name)}');">
                            <i class="bi bi-exclamation-triangle me-1"></i> Manual Override
                        </button>
                    </div>
                </div>
            ` : ''}
        </div>
    `;
}

async function toggleManualOverride(matchId) {
    const match = allRounds.flatMap(r => r.matches || []).find(m => m.id === matchId);
    if (!match) return;

    if (match.status === 'completed') {
        // Show confirmation before editing completed match
        const confirmed = await showConfirmation({
            title: 'Edit Completed Match',
            message: `This match is already completed with a score of ${match.player1.score}-${match.player2.score}. Editing will modify the recorded results and this action will be logged. Continue?`,
            confirmText: 'Edit Match',
            confirmVariant: 'warning'
        });

        if (!confirmed) return;

        // Edit completed match result
        promptMatchScore(matchId, match.player1.name, match.player2.name, match.player1.score, match.player2.score);
    } else {
        // Manual override for non-completed match (no judge available scenario)
        handleManualOverride(matchId, match.player1.name, match.player2.name);
    }
}

const FINISH_BADGE_MAP = {
    'Spin Finish': {
        label: 'Spin',
        className: 'finish-badge-spin',
        icon: 'bi bi-wind',
        tooltip: 'Spin Finish'
    },
    'Burst Finish': {
        label: 'Burst',
        className: 'finish-badge-burst',
        icon: 'bi bi-lightning-charge-fill',
        tooltip: 'Burst Finish'
    },
    'Over Finish': {
        label: 'Over',
        className: 'finish-badge-over',
        icon: 'bi bi-arrow-up-right-circle-fill',
        tooltip: 'Over Finish'
    },
    'Xtreme Finish': {
        label: 'Xtreme',
        className: 'finish-badge-xtreme',
        icon: 'bi bi-stars',
        tooltip: 'Xtreme Finish'
    },
    'Fault': {
        label: 'Fault',
        className: 'finish-badge-fault',
        icon: 'bi bi-circle',
        tooltip: 'Fault'
    }
};

const renderFinishBadges = (finishes) => {
    if (!Array.isArray(finishes) || finishes.length === 0) {
        return '';
    }

    const aggregated = [];
    const map = new Map();

    finishes.forEach((finish, index) => {
        if (!finish || !finish.type) {
            return;
        }

        // Group by type only, not by points
        const key = finish.type;

        if (!map.has(key)) {
            const entry = {
                type: finish.type,
                count: 1,
                order: index
            };
            map.set(key, entry);
            aggregated.push(entry);
        } else {
            map.get(key).count += 1;
        }
    });


    aggregated.sort((a, b) => a.order - b.order);

    return aggregated.map(entry => {
        const config = FINISH_BADGE_MAP[entry.type] || {
            label: entry.type || 'Finish',
            className: 'finish-badge-default',
            icon: 'bi bi-flag',
            tooltip: entry.type || 'Finish'
        };

        const titleParts = [config.tooltip];
        if (entry.count > 1) {
            titleParts.push(`×${entry.count}`);
        }

        const title = escapeHtml(titleParts.join(' | '));
        const label = escapeHtml(config.label);
        const iconHtml = config.icon ? `<i class="${config.icon}"></i>` : '';
        const countHtml = entry.count > 1 ? `<span class="finish-count">×${entry.count}</span>` : '';

        return `
            <span class="badge finish-badge ${config.className}" title="${title}">
                ${iconHtml}<span class="finish-label">${label}</span>${countHtml}
            </span>
        `;
    }).join('');
}

const runMatchEngine = async () => {
    try {
        const response = await fetch('api/tournaments/match_engine.php?action=run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ tournament_id: currentTournamentId })
        });
        const result = await response.json();
        if (result.success) {
            const count = result.assignments?.length ?? 0;
            if (count > 0) {
                showToast(`Match engine assigned ${count} match(es) successfully.`, { variant: 'success' });
            } else {
                showToast('No new assignments possible with current staff/stadiums.', { variant: 'info' });
            }
            refreshPageData();
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        showToast('Failed to run match engine.', { variant: 'danger' });
    }
};

const promptMatchScore = async (matchId, p1Name, p2Name, currentP1 = 0, currentP2 = 0) => {
    // Check if this is an edit of an existing match
    const match = allRounds.flatMap(r => r.matches ?? []).find(m => m.id === matchId);
    let existingData = null;

    if (match && match.status === 'completed') {
        // Edit mode: preload existing scores and finishes
        existingData = {
            p1Score: match.player1.score ?? 0,
            p2Score: match.player2.score ?? 0,
            finishes: []
        };

        // Reconstruct finishes from match data
        if (match.finishes) {
            // Use actual Player IDs for finishes instead of generic slot names
            if (Array.isArray(match.finishes.player1)) {
                existingData.finishes.push(...match.finishes.player1.map(f => ({
                    player: match.player1.id,
                    type: f.type,
                    points: f.points
                })));
            }
            if (Array.isArray(match.finishes.player2)) {
                existingData.finishes.push(...match.finishes.player2.map(f => ({
                    player: match.player2.id,
                    type: f.type,
                    points: f.points
                })));
            }
        }
    }

    // Determine if this match requires 7 points (Finals, Semi-Finals, Battle for 3rd)
    let minPoints = 4;
    const round = allRounds.find(r => r.matches?.some(m => m.id === matchId));
    if (round && match) {
        let label = '';
        if (match.match_number >= 90) {
            label = getSpecialMatchLabel(match.match_number);
        } else {
            const stageRounds = allRounds.filter(r => r.stage === round.stage);
            const totalRounds = Math.max(...stageRounds.map(r => r.round_number), 1);
            label = getRoundLabel(round.round_number, totalRounds);
        }

        if (label === 'Finals' || label === 'Semi-Finals' || label === 'Battle for 3rd') {
            // Only apply 7-point rule for high-stakes elimination rounds (Finals, Semis, 3rd Place)
            // Note: Quarter-Finals and earlier always remain at 4 points as per user preference.
            const isSwissStage1 = currentTournament?.tournament_type === 'swiss' && round.stage === 1;
            if (!isSwissStage1) {
                minPoints = 7;
            }
        }
    }

    if (!existingData) existingData = {};
    existingData.config = { minPoints };

    let displayName1 = p1Name;
    let displayName2 = p2Name;
    if (match) {
        if (match.player1?.seed) displayName1 = `(#${match.player1.seed}) ${p1Name}`;
        if (match.player2?.seed) displayName2 = `(#${match.player2.seed}) ${p2Name}`;
    }

    const scores = await showScoringModal(displayName1, displayName2, match.player1.id, match.player2.id, existingData);
    if (!scores) return; // Cancelled

    recordMatchResult(matchId, scores.p1, scores.p2, scores.finishes, scores.p1Id, scores.p2Id);
}

const handleManualOverride = async (matchId, p1Name, p2Name) => {
    const confirmed = await showConfirmation({
        title: 'Manual Judge Override',
        message: 'This will allow you to score the match even if you are assigned as a player. Continue?',
        confirmText: 'Override & Score',
        confirmVariant: 'warning'
    });

    if (!confirmed) return;

    promptMatchScore(matchId, p1Name, p2Name);
};

const recordMatchResult = async (matchId, p1Score, p2Score, finishes = [], p1Id = null, p2Id = null) => {
    // Sanitize finishes but preserve all occurrences (no deduplication)
    const sanitizedFinishes = Array.isArray(finishes) ? finishes.filter(finish => {
        return finish && finish.player && finish.type;
    }).map(finish => ({
        player: finish.player,
        type: finish.type,
        points: Number.isFinite(Number(finish.points)) ? Number(finish.points) : null
    })) : [];

    const bodyParams = {
        tournament_id: currentTournamentId,
        match_id: matchId,
        p1_score: p1Score,
        p2_score: p2Score,
        finishes: JSON.stringify(sanitizedFinishes)
    };

    // Only add IDs if they are valid truthy values and not the string "null"
    if (p1Id && p1Id !== 'null') bodyParams.p1_id = p1Id;
    if (p2Id && p2Id !== 'null') bodyParams.p2_id = p2Id;

    try {
        const response = await fetch('api/tournaments/rounds.php?action=recordResult', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(bodyParams)
        });
        const result = await response.json();
        if (result.success) {
            showToast('Result recorded.', { variant: 'success' });
            refreshPageData();
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        showToast('Failed to record result.', { variant: 'danger' });
    }
};

const getRoundLabel = (roundNum, totalRounds) => {
    const remaining = totalRounds - roundNum;
    if (remaining === 0) return 'Finals';
    if (remaining === 1) return 'Semi-Finals';
    if (remaining === 2) return 'Quarter-Finals';
    return `Round ${roundNum}`;
};

const getSpecialMatchLabel = (matchNumber) => {
    const mNum = parseInt(matchNumber);
    if (mNum === 99) return 'Battle for 3rd';
    if (mNum === 98) return '5th Place Match';
    if (mNum === 97) return 'Battle for 7th';
    if (mNum === 96) return 'Battle for 9th';
    if (mNum === 95 || mNum === 94) return '5th Place Semifinal';
    return 'Consolation Match';
};

const escapeHtml = (text) => {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Check if tournament is eligible for Stage 2 transition
const userCanManageStage2 = () => {
    if (!currentTournament || !currentUser) return false;

    const isCreator = String(currentTournament.created_by) === String(currentUser.id);
    if (isCreator) return true;

    return currentTournamentPeople.some(person => {
        if (String(person.user_id) !== String(currentUser.id)) return false;
        return person.role.split(',').some(role => role.trim() === 'organizer');
    });
};

// Shared function to determine if Swiss rounds are complete (used by both button and standings)
const isSwissRoundsComplete = (rounds) => {
    if (!currentTournament) return false;

    // Only for Swiss tournaments
    if (currentTournament.tournament_type !== 'swiss') return false;

    // Check if already in stage 2
    if (currentTournament.current_stage === 2) return false;

    // Check if all rounds are complete
    if (!rounds || rounds.length === 0) return false;

    return rounds.every(round => {
        // Round must be completed
        if (round.status !== 'completed') return false;

        // All matches in round must be completed
        if (round.matches && round.matches.length > 0) {
            return round.matches.every(match => match.status === 'completed');
        }

        return true;
    });
};

const checkStage2Eligibility = (rounds) => {
    const stage2Container = document.getElementById('stage2ButtonContainer');
    if (!stage2Container) return;

    if (!userCanManageStage2()) {
        stage2Container.classList.add('stage2-button-container-hidden');
        stage2Container.classList.remove('stage2-button-container-visible');
        return;
    }

    if (!currentTournament) return;

    const topCutSelect = document.getElementById('topCutSelect');
    if (topCutSelect && currentTournament.top_cut > 0) {
        topCutSelect.value = currentTournament.top_cut;
    }

    // Use shared function to check if Swiss rounds are complete
    const swissComplete = isSwissRoundsComplete(rounds);

    // Show button if all conditions met
    if (swissComplete) {
        stage2Container.classList.remove('stage2-button-container-hidden');
        stage2Container.classList.add('stage2-button-container-visible');
    } else {
        stage2Container.classList.add('stage2-button-container-hidden');
        stage2Container.classList.remove('stage2-button-container-visible');
    }
};

// Advance to Top Cut (Stage 2)
const advanceToTopCut = async () => {
    const topCutSelect = document.getElementById('topCutSelect');
    const topCutValue = topCutSelect ? parseInt(topCutSelect.value) : (currentTournament.top_cut ?? 8);

    const confirmed = await showConfirmation({
        title: 'Advance to Top Cut',
        message: `This will generate a single elimination bracket for the top ${topCutValue} players based on current standings. All Swiss rounds must be complete. Continue?`,
        confirmText: 'Generate Bracket',
        confirmVariant: 'primary'
    });

    if (!confirmed) return;

    try {
        // First save the top cut to ensure it's in the database
        await fetch('api/tournaments/rounds.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'saveTopCut',
                tournament_id: currentTournamentId,
                top_cut: topCutValue
            })
        });

        const response = await fetch('api/tournaments/rounds.php?action=advanceToTopCut', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ tournament_id: currentTournamentId })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, { variant: 'success' });
            // Refresh to show new bracket
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        console.error('Advance to top cut error:', error);
        showToast('Failed to generate top cut bracket.', { variant: 'danger' });
    }
}

const checkTournamentCompletion = (rounds) => {
    const completionContainer = document.getElementById('tournamentCompletionContainer');
    if (!completionContainer) return;

    if (!userCanManageStage2()) {
        completionContainer.classList.add('d-none');
        return;
    }

    if (!currentTournament || currentTournament.status === 'completed') {
        completionContainer.classList.add('d-none');
        return;
    }

    // Determine the final stage
    const maxStage = Math.max(...rounds.map(r => r.stage), 1);

    // Switch logic based on tournament type
    // If Swiss and in Stage 1, we don't finish yet (wait for Top Cut)
    if (currentTournament.tournament_type === 'swiss' && maxStage === 1) {
        completionContainer.classList.add('d-none');
        return;
    }

    // Check if ALL matches in the final stage are completed
    const finalStageRounds = rounds.filter(r => r.stage === maxStage);
    if (finalStageRounds.length === 0) {
        completionContainer.classList.add('d-none');
        return;
    }

    const isFullyScored = finalStageRounds.every(round => {
        return round.matches && round.matches.length > 0 &&
            round.matches.every(match => match.status === 'completed' || match.is_bye);
    });

    if (isFullyScored) {
        completionContainer.classList.remove('d-none');
    } else {
        completionContainer.classList.add('d-none');
    }
};

const finishTournament = async () => {
    const confirmed = await showConfirmation({
        title: 'Finish Tournament',
        message: 'Are you sure you want to finish the tournament? This will finalize all rankings and winners. This action cannot be undone.',
        confirmText: 'Finish & Finalize',
        confirmVariant: 'success'
    });

    if (!confirmed) return;

    try {
        const response = await fetch('api/tournaments/rounds.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'endTournament',
                tournament_id: currentTournamentId
            })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, { variant: 'success' });
            // Redirect to tournament details page
            setTimeout(() => {
                window.location.href = `tournament-detail.html?id=${currentTournamentId}`;
            }, 1500);
        } else {
            showToast(result.message, { variant: 'danger' });
        }
    } catch (error) {
        console.error('Error finishing tournament:', error);
        showToast('Failed to finish tournament.', { variant: 'danger' });
    }
};

// Switch Stage View
const switchStage = (stage) => {
    currentViewStage = stage;
    renderRounds(allRounds);
};

// Scroll to judge's assigned match
const scrollToJudgeAssignedMatch = () => {
    if (!currentUser) return;

    // Find the judge's assigned match card
    const assignedMatch = document.querySelector('.match-card.judge-assigned-highlight');
    if (!assignedMatch) return;

    // Get the rounds container for scrolling
    const roundsContainer = document.getElementById('roundsContainer');
    if (!roundsContainer) return;

    // Use smooth scroll to bring the match into view
    assignedMatch.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'center'
    });
};

// Helper functions
const getMatchHeaderLabel = (match, isSingleElimStage) => {
    if (match.status === 'scheduled') return 'Scheduled';
    if (match.status === 'assigned') return 'Assigned';
    if (match.status === 'in_progress') return 'In Progress';
    if (match.status === 'completed') return 'Completed';
    if (match.status === 'blocked') return 'Blocked';
    return 'Unknown';
};

const normalizeScoreDisplay = (score, isByeAndCompleted) => {
    if (isByeAndCompleted) return 'BYE';
    if (score === null || score === undefined) return '-';
    return score;
};

const handleLogout = async (e) => {
    if (e) e.preventDefault();
    try {
        await fetch('api/auth/logout.php', { method: 'POST' });
        window.location.href = 'index.html';
    } catch (error) {
        console.error('Logout failed:', error);
        window.location.href = 'index.html';
    }
};
// Check for Judge Rotations and New Assignments
const checkForJudgeRotations = (rounds, bindings) => {
    if (!rounds || !bindings) return;

    let hasRotation = false;
    const currentAssignments = [];

    rounds.forEach(round => {
        (round.matches || []).forEach(match => {
            if (match.status === 'assigned' && match.judge && match.stadium) {
                const matchKey = `m${match.id}_j${match.judge.id}_s${match.stadium.id}`;
                currentAssignments.push(matchKey);

                // If this is a NEW assignment we haven't seen in this session
                if (!lastAssignmentKeys.has(matchKey)) {
                    const homeBinding = bindings.find(b => b.stadium_id == match.stadium.id);
                    const homeJudgeId = homeBinding ? homeBinding.judge_id : null;

                    // If it's a rotation (Sub replacing Home)
                    if (homeJudgeId && match.judge.id != homeJudgeId) {
                        const homeJudge = currentTournamentPeople?.find(p => p.user_id == homeJudgeId);
                        const homeName = homeJudge ? (homeJudge.display_name || homeJudge.blader_name) : 'Home Judge';

                        // Notify TOs AND involved judges (Incoming Judge, Outgoing Judge)
                        const isInvolved = currentUser && (
                            String(currentUser.id) === String(match.judge.id) || // Incoming Judge
                            String(currentUser.id) === String(homeJudgeId)       // Outgoing Judge
                        );

                        if (userCanManageStage2() || isInvolved) {
                            showToast(`🔁 <strong>Judge Rotation on ${escapeHtml(match.stadium.name)}</strong><br>${escapeHtml(match.judge.name)} is subbing for ${escapeHtml(homeName)}!`, { variant: 'info', delay: 10000 });
                            hasRotation = true;
                        }
                    }
                    // Optional: Notify of ANY new assignment if preferred, but user specifically asked for replacements.

                    lastAssignmentKeys.add(matchKey);
                }
            }
        });
    });

    if (hasRotation) {
        playNotificationSound();
    }
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

// Check for player match assignments and show notification
const checkPlayerMatchAssignment = () => {
    if (!currentUser || !currentTournamentId) return;

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
};

// Show match assignment modal
const showMatchAssignmentModal = (match, opponentName) => {
    const modal = document.getElementById('matchAssignmentModal');
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
const scrollToPlayerMatch = () => {
    if (!currentUser) return;

    // Find the player's assigned match card
    const assignedMatch = document.querySelector('.match-card.player-assigned-highlight');
    if (!assignedMatch) return;

    // Use smooth scroll to bring the match into view
    assignedMatch.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'center'
    });
};

const updatePlayerMatchButton = () => {
    let btn = document.getElementById('floatingPlayerMatchBtn');

    // Check if player has an active match visible
    const assignedMatch = document.querySelector('.match-card.player-assigned-highlight');

    if (assignedMatch) {
        if (!btn) {
            btn = document.createElement('button');
            btn.id = 'floatingPlayerMatchBtn';
            btn.className = 'floating-match-btn';
            btn.innerHTML = '<i class="bi bi-controller"></i> Go to My Match';
            btn.onclick = scrollToPlayerMatch;
            document.body.appendChild(btn);
        }

        // Show button with animation
        requestAnimationFrame(() => {
            btn.classList.add('visible');
        });
    } else {
        if (btn) {
            btn.classList.remove('visible');
        }
    }
};
