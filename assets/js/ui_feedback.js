(function () {
    const defaultToastDelay = 3500;

    function ensureToastContainer() {
        let container = document.getElementById('ui-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'ui-toast-container';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1100';
            document.body.appendChild(container);
        }
        return container;
    }

    function createToastElement(message, options = {}) {
        const { title = '', variant = 'primary', delay = defaultToastDelay } = options;
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-bg-${variant} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');

        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${title ? `<strong class="d-block">${title}</strong>` : ''}
                    <span>${message}</span>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        return { toastEl, delay };
    }

    function showToast(message, options = {}) {
        const container = ensureToastContainer();
        const { toastEl, delay } = createToastElement(message, options);
        container.appendChild(toastEl);

        const toast = new bootstrap.Toast(toastEl, {
            delay,
            autohide: true
        });

        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });

        toast.show();
        return toast;
    }

    function ensureModal(id, contentBuilder) {
        let modalEl = document.getElementById(id);
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = id;
            modalEl.className = 'modal fade';
            modalEl.tabIndex = -1;
            modalEl.innerHTML = contentBuilder();
            document.body.appendChild(modalEl);
        }
        return modalEl;
    }

    function getConfirmationModal() {
        return ensureModal('ui-confirmation-modal', () => `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="ui-confirmation-message" class="mb-0"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="ui-confirmation-confirm-btn">Confirm</button>
                    </div>
                </div>
            </div>
        `);
    }

    function showConfirmation(options = {}) {
        const {
            title = 'Confirm',
            message = 'Are you sure?',
            confirmText = 'Confirm',
            cancelText = 'Cancel',
            confirmVariant = 'primary'
        } = options;

        return new Promise((resolve) => {
            const modalEl = getConfirmationModal();
            const modal = new bootstrap.Modal(modalEl);
            const titleEl = modalEl.querySelector('.modal-title');
            const messageEl = modalEl.querySelector('#ui-confirmation-message');
            const confirmBtn = modalEl.querySelector('#ui-confirmation-confirm-btn');
            const cancelBtn = modalEl.querySelector('.modal-footer .btn-secondary');

            let resolved = false;

            titleEl.textContent = title;
            messageEl.textContent = message;
            confirmBtn.textContent = confirmText;
            confirmBtn.className = `btn btn-${confirmVariant}`;
            cancelBtn.textContent = cancelText;

            const cleanup = () => {
                confirmBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            };

            const onConfirm = () => {
                resolved = true;
                modal.hide();
                resolve(true);
            };

            const onHidden = () => {
                if (!resolved) {
                    resolve(false);
                }
                cleanup();
            };

            confirmBtn.addEventListener('click', onConfirm, { once: true });
            modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });

            modal.show();
        });
    }

    function getPromptModal() {
        return ensureModal('ui-prompt-modal', () => `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Input Required</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="ui-prompt-message"></p>
                        <input type="text" id="ui-prompt-input" class="form-control">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="ui-prompt-confirm-btn">Submit</button>
                    </div>
                </div>
            </div>
        `);
    }

    function showPrompt(options = {}) {
        const {
            title = 'Input Required',
            message = 'Please provide a value.',
            confirmText = 'Submit',
            cancelText = 'Cancel',
            confirmVariant = 'primary',
            placeholder = '',
            defaultValue = ''
        } = options;

        return new Promise((resolve) => {
            const modalEl = getPromptModal();
            const modal = new bootstrap.Modal(modalEl);
            const titleEl = modalEl.querySelector('.modal-title');
            const messageEl = modalEl.querySelector('#ui-prompt-message');
            const inputEl = modalEl.querySelector('#ui-prompt-input');
            const confirmBtn = modalEl.querySelector('#ui-prompt-confirm-btn');
            const cancelBtn = modalEl.querySelector('.modal-footer .btn-secondary');

            let resolved = false;

            titleEl.textContent = title;
            messageEl.textContent = message;
            inputEl.placeholder = placeholder;
            inputEl.value = defaultValue;
            confirmBtn.textContent = confirmText;
            confirmBtn.className = `btn btn-${confirmVariant}`;
            cancelBtn.textContent = cancelText;

            const cleanup = () => {
                confirmBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                inputEl.removeEventListener('keydown', onKeyDown);
            };

            const onConfirm = () => {
                resolved = true;
                modal.hide();
                resolve(inputEl.value);
            };

            const onHidden = () => {
                if (!resolved) {
                    resolve(null);
                }
                cleanup();
            };

            const onKeyDown = (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    onConfirm();
                }
            };

            confirmBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
            inputEl.addEventListener('keydown', onKeyDown);

            modal.show();
            setTimeout(() => inputEl.focus(), 200);
        });
    }

    window.showToast = showToast;
    window.showConfirmation = showConfirmation;
    window.showPrompt = showPrompt;

    // --- Scoring Modal Logic ---
    function getScoringModal() {
        return ensureModal('ui-scoring-modal', () => `
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-bottom">
                <div class="modal-content border-0">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="fw-bold mb-0">Live Battle Scoreboard</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="card bg-light border-0 py-4 mb-4">
                            <div class="row align-items-center">
                                <div class="col-5 text-center">
                                    <div id="score-p1-name" class="small fw-bold text-muted text-uppercase mb-1 text-truncate px-2">Player 1</div>
                                    <div id="score-p1-value" class="display-1 fw-black text-primary lh-1">0</div>
                                    <div id="p1-history" class="d-flex justify-content-center flex-wrap gap-1 mt-2" style="min-height: 24px;"></div>
                                </div>
                                <div class="col-2 text-center">
                                    <div class="d-flex flex-column align-items-center">
                                        <div class="badge bg-dark rounded-pill mb-2">VS</div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle" id="switch-players-btn" title="Switch Players">
                                            <i class="bi bi-arrow-left-right"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-5 text-center">
                                    <div id="score-p2-name" class="small fw-bold text-muted text-uppercase mb-1 text-truncate px-2">Player 2</div>
                                    <div id="score-p2-value" class="display-1 fw-black text-danger lh-1">0</div>
                                    <div id="p2-history" class="d-flex justify-content-center flex-wrap gap-1 mt-2" style="min-height: 24px;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Scoring Controls -->
                        <div class="row g-3">
                            <!-- Player 1 -->
                            <div class="col-6">
                                <div class="vstack gap-2" id="p1-controls">
                                    <button class="btn btn-outline-warning py-3 finish-btn" data-player="p1" data-points="1" data-type="Spin Finish">
                                        <div class="fw-bold">Spin</div>
                                        <div class="small opacity-75">+1</div>
                                    </button>
                                    <button class="btn btn-outline-danger py-3 finish-btn" data-player="p1" data-points="2" data-type="Burst Finish">
                                        <div class="fw-bold">Burst</div>
                                        <div class="small opacity-75">+2</div>
                                    </button>
                                    <button class="btn btn-outline-success py-3 finish-btn" data-player="p1" data-points="2" data-type="Over Finish">
                                        <div class="fw-bold">Over</div>
                                        <div class="small opacity-75">+2</div>
                                    </button>
                                    <button class="btn btn-outline-primary py-3 finish-btn" data-player="p1" data-points="3" data-type="Xtreme Finish">
                                        <div class="fw-bold">Xtreme</div>
                                        <div class="small opacity-75">+3</div>
                                    </button>
                                    <button class="btn btn-outline-white py-3 finish-btn" data-player="p1" data-points="1" data-type="No Contact Pocket">
                                        <div class="fw-bold">No Contact Pocket</div>
                                        <div class="small opacity-75">+1</div>
                                    </button>
                                </div>
                            </div>
                            <!-- Player 2 -->
                            <div class="col-6">
                                <div class="vstack gap-2" id="p2-controls">
                                    <button class="btn btn-outline-warning py-3 finish-btn" data-player="p2" data-points="1" data-type="Spin Finish">
                                        <div class="fw-bold">Spin</div>
                                        <div class="small opacity-75">+1</div>
                                    </button>
                                    <button class="btn btn-outline-danger py-3 finish-btn" data-player="p2" data-points="2" data-type="Burst Finish">
                                        <div class="fw-bold">Burst</div>
                                        <div class="small opacity-75">+2</div>
                                    </button>
                                    <button class="btn btn-outline-success py-3 finish-btn" data-player="p2" data-points="2" data-type="Over Finish">
                                        <div class="fw-bold">Over</div>
                                        <div class="small opacity-75">+2</div>
                                    </button>
                                    <button class="btn btn-outline-primary py-3 finish-btn" data-player="p2" data-points="3" data-type="Xtreme Finish">
                                        <div class="fw-bold">Xtreme</div>
                                        <div class="small opacity-75">+3</div>
                                    </button>
                                    <button class="btn btn-outline-white py-3 finish-btn" data-player="p2" data-points="1" data-type="No Contact Pocket">
                                        <div class="fw-bold">No Contact Pocket</div>
                                        <div class="small opacity-75">+1</div>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Match Log -->
                        <div class="mt-4 text-center">
                            <div id="score-log" class="small fw-semibold text-primary opacity-75 py-2 px-3 bg-primary bg-opacity-10 rounded-pill d-inline-block">
                                Match ready...
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <div class="row w-100 g-2">
                            <div class="col-4">
                                <button type="button" class="btn btn-light w-100 py-3 fw-bold" id="score-undo-btn">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </div>
                            <div class="col-8">
                                <button type="button" class="btn btn-dark w-100 py-3 fw-bold shadow-sm" id="score-submit-btn">
                                    Confirm Result
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function showScoringModal(p1Name, p2Name, existingData = null) {
        return new Promise((resolve) => {
            const modalEl = getScoringModal();
            const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });

            // Elements
            const p1NameEl = modalEl.querySelector('#score-p1-name');
            const p2NameEl = modalEl.querySelector('#score-p2-name');
            const p1ValEl = modalEl.querySelector('#score-p1-value');
            const p2ValEl = modalEl.querySelector('#score-p2-value');
            const p1HistoryEl = modalEl.querySelector('#p1-history');
            const p2HistoryEl = modalEl.querySelector('#p2-history');
            const logEl = modalEl.querySelector('#score-log');
            const undoBtn = modalEl.querySelector('#score-undo-btn');
            const submitBtn = modalEl.querySelector('#score-submit-btn');
            const switchBtn = modalEl.querySelector('#switch-players-btn');
            const scoreBtns = modalEl.querySelectorAll('.finish-btn');
            const closeBtn = modalEl.querySelector('.btn-close');

            // State
            let p1Score = 0;
            let p2Score = 0;
            let history = []; // Stack of snapshots for undo
            let moves = [];   // Linear list of actual scoring events

            // Init
            p1NameEl.textContent = p1Name;
            p2NameEl.textContent = p2Name;

            // CLEAR HISTORY UI
            p1HistoryEl.innerHTML = '';
            p2HistoryEl.innerHTML = '';

            // Preload existing data if provided (Edit Mode)
            if (existingData) {
                p1Score = existingData.p1Score || 0;
                p2Score = existingData.p2Score || 0;
                moves = existingData.finishes || [];
                logEl.textContent = 'Editing match result...';
            } else {
                // New match - start fresh
                p1Score = 0;
                p2Score = 0;
                moves = [];
                logEl.textContent = 'Swipe or tap finishes below...';
            }

            p1ValEl.textContent = p1Score;
            p2ValEl.textContent = p2Score;
            undoBtn.disabled = history.length === 0;

            const updateDisplay = () => {
                p1ValEl.textContent = p1Score;
                p2ValEl.textContent = p2Score;

                undoBtn.disabled = history.length === 0;

                // Win condition visual cue (First to 4)
                if (p1Score >= 4 || p2Score >= 4) {
                    submitBtn.textContent = 'Finish Match';
                    submitBtn.classList.remove('btn-dark');
                    submitBtn.classList.add('btn-primary');
                } else {
                    submitBtn.classList.remove('btn-primary');
                    submitBtn.classList.add('btn-dark');
                    submitBtn.textContent = 'Confirm Result';
                }

                renderHistory();
            };

            const renderHistory = () => {
                // Clear current
                p1HistoryEl.innerHTML = '';
                p2HistoryEl.innerHTML = '';

                moves.forEach(move => {
                    const badge = document.createElement('span');
                    // Styling based on type
                    let bgClass = 'bg-secondary';
                    let label = 'Spin';

                    if (move.type.includes('Burst')) {
                        bgClass = 'bg-danger text-white';
                        label = 'Burst';
                    } else if (move.type.includes('Over')) {
                        bgClass = 'bg-success text-white';
                        label = 'Over';
                    } else if (move.type.includes('Xtreme')) {
                        bgClass = 'bg-primary text-white';
                        label = 'Xtreme';
                    } else if (move.type.includes('Contact Pocket') || move.type.includes('NCP')) {
                        bgClass = 'bg-secondary text-white';
                        label = 'NCP';
                    } else {
                        // Spin default - solid yellow with white text
                        bgClass = 'bg-warning text-white';
                    }

                    badge.className = `badge ${bgClass} rounded-pill px-3`;
                    badge.textContent = label;
                    badge.title = `${move.type} (+${move.points})`; // Tooltip keeps points info

                    if (move.player === 'p1') {
                        p1HistoryEl.appendChild(badge);
                    } else {
                        p2HistoryEl.appendChild(badge);
                    }
                });
            };

            const switchPlayers = () => {
                // Swap names
                const tempName = p1NameEl.textContent;
                p1NameEl.textContent = p2NameEl.textContent;
                p2NameEl.textContent = tempName;

                // Swap scores
                const tempScore = p1Score;
                p1Score = p2Score;
                p2Score = tempScore;

                // Swap moves (update player references)
                moves = moves.map(move => ({
                    ...move,
                    player: move.player === 'p1' ? 'p2' : 'p1'
                }));

                // Update history logs to reflect swapped names
                history = history.map(state => ({
                    ...state,
                    log: state.log.replace(p1NameEl.textContent, 'TEMP_NAME').replace(p2NameEl.textContent, p1NameEl.textContent).replace('TEMP_NAME', p2NameEl.textContent)
                }));

                updateDisplay();
                logEl.textContent = 'Players switched';
            };

            const addPoints = (player, points, type) => {
                history.push({ p1: p1Score, p2: p2Score, log: logEl.textContent });
                moves.push({ player, points, type });

                if (player === 'p1') {
                    p1Score += points;
                    logEl.textContent = `${p1Name}: ${points} pt (${type})`;
                } else {
                    p2Score += points;
                    logEl.textContent = `${p2Name}: ${points} pt (${type})`;
                }
                updateDisplay();
            };

            const undo = () => {
                if (history.length === 0) return;
                const lastState = history.pop();
                p1Score = lastState.p1;
                p2Score = lastState.p2;
                logEl.textContent = lastState.log;
                moves.pop();
                updateDisplay();
            };

            const submit = () => {
                // Client-side validation: Check min score
                if (p1Score < 4 && p2Score < 4) {
                    showToast('Match not finished. First to 4 points wins.', { variant: 'warning' });
                    // Do NOT close the modal
                    return;
                }

                modal.hide();
                resolve({ p1: p1Score, p2: p2Score, finishes: moves });
            };

            const cleanup = () => {
                scoreBtns.forEach(btn => btn.removeEventListener('click', onScoreClick));
                undoBtn.removeEventListener('click', undo);
                submitBtn.removeEventListener('click', submit);
                switchBtn.removeEventListener('click', switchPlayers);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            };

            const onScoreClick = (e) => {
                const btn = e.currentTarget;
                const player = btn.getAttribute('data-player');
                const points = parseInt(btn.getAttribute('data-points'));
                const type = btn.getAttribute('data-type');
                addPoints(player, points, type);
            };

            const onHidden = () => {
                cleanup();
            };

            // Event Listeners
            scoreBtns.forEach(btn => btn.addEventListener('click', onScoreClick));
            undoBtn.addEventListener('click', undo);
            submitBtn.addEventListener('click', submit);
            switchBtn.addEventListener('click', switchPlayers);

            // Handle dismissal via backdrop/X
            modalEl.addEventListener('hidden.bs.modal', () => {
                // If not resolved yet (i.e. cancelled)
                // We can't easily check promise state here, but we can assume null if not submitted
            });

            modal.show();
        });
    }

    window.showScoringModal = showScoringModal;
})();
