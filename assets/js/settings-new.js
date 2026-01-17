class SettingsManager {
    constructor() {
        this.apiBase = 'api/users/profile.php';
        this.teamsApiBase = 'api/teams/teams.php';
        this.currentData = {};
        this.originalData = {};
        this.editingSection = null;
        this.teamDebounceTimer = null;
    }

    async init() {
        try {
            await this.loadProfile();
            this.setupEventListeners();
            this.populateViewMode();
        } catch (error) {
            this.showToast('Error loading profile: ' + error.message, 'error');
        }
    }

    // Load profile from database
    async loadProfile() {
        try {
            const response = await fetch(this.apiBase, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) {
                    console.error('Authentication error loading profile');
                    window.location.href = 'index.html';
                    throw new Error('Not authenticated');
                }
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const rawText = await response.text();
            console.log('DEBUG: Raw profile API response:', rawText);
            
            if (!rawText.trim()) {
                throw new Error('Empty response from profile API');
            }
            
            let result;
            try {
                result = JSON.parse(rawText);
            } catch (parseError) {
                console.error('JSON parse error for profile API:', parseError);
                console.error('Raw response that failed to parse:', rawText);
                throw new Error(`Invalid JSON response from profile API: ${parseError.message}`);
            }
            
            if (result.success) {
                this.currentData = result.profile;
                this.originalData = JSON.parse(JSON.stringify(result.profile));
                console.log('Profile loaded successfully:', this.currentData);
            } else {
                console.error('Backend returned failure for profile load:', {
                    message: result.message,
                    success: result.success,
                    fullResponse: result
                });
                throw new Error(result.message || 'Failed to load profile');
            }
        } catch (error) {
            console.error('Error loading profile:', error);
            throw error;
        }
    }

    // Populate view mode with database data
    populateViewMode() {
        // Identity section
        this.setViewText('viewBladerName', this.currentData.blader_name || '');
        this.setViewText('viewDisplayName', this.currentData.display_name || '');
        this.setViewText('viewEmail', this.currentData.email || '');
        
        // Extra Info section
        this.setViewText('viewLocation', this.currentData.location || '');
        this.setViewText('viewPreferredType', this.currentData.preferred_beyblade_type || '');
        this.setViewText('viewTeam', this.currentData.team_name || '');
        this.setViewText('viewBio', this.currentData.bio || '');
        
        // Security section (password is never shown)
    }

    // Helper method to set view text
    setViewText(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = value || 'Not set';
        }
    }

    // Setup event listeners
    setupEventListeners() {
        // Identity section
        document.getElementById('editIdentityBtn').addEventListener('click', () => this.enterEditMode('identity'));
        document.getElementById('saveIdentityBtn').addEventListener('click', () => this.saveSection('identity'));
        document.getElementById('cancelIdentityBtn').addEventListener('click', () => this.cancelEdit('identity'));
        
        // Extra Info section
        document.getElementById('editExtraInfoBtn').addEventListener('click', () => this.enterEditMode('extraInfo'));
        document.getElementById('saveExtraInfoBtn').addEventListener('click', () => this.saveSection('extraInfo'));
        document.getElementById('cancelExtraInfoBtn').addEventListener('click', () => this.cancelEdit('extraInfo'));
        
        // Security section
        document.getElementById('editSecurityBtn').addEventListener('click', () => this.enterEditMode('security'));
        document.getElementById('saveSecurityBtn').addEventListener('click', () => this.saveSection('security'));
        document.getElementById('cancelSecurityBtn').addEventListener('click', () => this.cancelEdit('security'));
        
        // Team autocomplete
        this.setupTeamAutocomplete();
        
        // Logout
        document.getElementById('logoutBtn').addEventListener('click', () => this.handleLogout());
    }

    // Enter edit mode for a section
    enterEditMode(section) {
        if (this.editingSection && this.editingSection !== section) {
            // Don't allow editing multiple sections at once
            this.showToast('Please save or cancel current changes first', 'error');
            return;
        }
        
        this.editingSection = section;
        
        // Hide view mode, show edit mode
        document.getElementById(`${section}ViewMode`).classList.add('d-none');
        document.getElementById(`${section}EditMode`).classList.remove('d-none');
        
        // Hide edit button, show controls
        document.getElementById(`${section}Controls`).classList.add('d-none');
        
        // Populate edit fields with current data
        this.populateEditMode(section);
    }

    // Populate edit mode with current data
    populateEditMode(section) {
        switch (section) {
            case 'identity':
                document.getElementById('editBladerName').value = this.currentData.blader_name || '';
                document.getElementById('editDisplayName').value = this.currentData.display_name || '';
                document.getElementById('editEmail').textContent = this.currentData.email || '';
                break;
                
            case 'extraInfo':
                document.getElementById('editLocation').value = this.currentData.location || '';
                document.getElementById('editPreferredType').value = this.currentData.preferred_beyblade_type || '';
                document.getElementById('editTeam').value = this.currentData.team_name || '';
                document.getElementById('editBio').value = this.currentData.bio || '';
                break;
                
            case 'security':
                // Clear password fields
                document.getElementById('currentPassword').value = '';
                document.getElementById('newPassword').value = '';
                document.getElementById('confirmPassword').value = '';
                break;
        }
    }

    // Cancel editing for a section
    cancelEdit(section) {
        // Hide edit mode, show view mode
        document.getElementById(`${section}EditMode`).classList.add('d-none');
        document.getElementById(`${section}ViewMode`).classList.remove('d-none');
        
        // Show edit button
        document.getElementById(`${section}Controls`).classList.remove('d-none');
        
        this.editingSection = null;
    }

    // Save a section
    async saveSection(section) {
        try {
            let data = {};
            let endpoint = this.apiBase;
            let successMessage = '';
            
            switch (section) {
                case 'identity':
                    data = {
                        blader_name: document.getElementById('editBladerName').value.trim(),
                        display_name: document.getElementById('editDisplayName').value.trim(),
                        avatar_url: this.currentData.avatar_url
                    };
                    successMessage = 'Identity updated successfully';
                    break;
                    
                case 'extraInfo':
                    data = {
                        location: document.getElementById('editLocation').value.trim(),
                        preferred_beyblade_type: document.getElementById('editPreferredType').value.trim(),
                        bio: document.getElementById('editBio').value.trim(),
                        team_name: document.getElementById('editTeam').value.trim(),
                        email_notifications: this.currentData.email_notifications || 1,
                        public_profile: this.currentData.public_profile || 1,
                        show_tournament_results: this.currentData.show_tournament_results || 1
                    };
                    successMessage = 'Extra info updated successfully';
                    break;
                    
                case 'security':
                    const currentPassword = document.getElementById('currentPassword').value;
                    const newPassword = document.getElementById('newPassword').value;
                    const confirmPassword = document.getElementById('confirmPassword').value;
                    
                    // Validate password fields
                    if (!currentPassword || !newPassword || !confirmPassword) {
                        this.showToast('All password fields are required', 'error');
                        return;
                    }
                    
                    if (newPassword.length < 6) {
                        this.showToast('New password must be at least 6 characters long', 'error');
                        return;
                    }
                    
                    if (newPassword !== confirmPassword) {
                        this.showToast('New passwords do not match', 'error');
                        return;
                    }
                    
                    // Use password change endpoint
                    endpoint = 'api/auth/change_password.php';
                    data = {
                        action: 'change_password',
                        current_password: currentPassword,
                        new_password: newPassword
                    };
                    successMessage = 'Password updated successfully';
                    break;
            }
            
            console.log(`DEBUG: Saving ${section} section with data:`, data);
            
            // Send update to API
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(data)
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Log raw response text before parsing
            const rawText = await response.text();
            console.log(`DEBUG: Raw ${section} API response:`, rawText);
            
            if (!rawText.trim()) {
                throw new Error(`Empty response from ${section} API`);
            }
            
            let result;
            try {
                result = JSON.parse(rawText);
            } catch (parseError) {
                console.error(`JSON parse error for ${section} API:`, parseError);
                console.error('Raw response that failed to parse:', rawText);
                throw new Error(`Invalid JSON response from ${section} API: ${parseError.message}`);
            }
            console.log(`DEBUG: ${section} section API response:`, result);
            
            if (result.success) {
                // Re-load profile from database to get fresh data
                await this.loadProfile();
                this.populateViewMode();
                this.cancelEdit(section);
                this.showToast(successMessage, 'success');
            } else {
                throw new Error(result.message || `Failed to save ${section}`);
            }
            
        } catch (error) {
            console.error(`Error saving ${section}:`, error);
            this.showToast(`Failed to save ${section}: ` + error.message, 'error');
            // Keep user in edit mode on error
        }
    }

    // Setup team autocomplete
    setupTeamAutocomplete() {
        const teamInput = document.getElementById('editTeam');
        const teamSuggestions = document.getElementById('teamSuggestions');
        
        if (!teamInput || !teamSuggestions) return;
        
        // Input event for autocomplete
        teamInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            clearTimeout(this.teamDebounceTimer);
            
            if (query.length < 2) {
                teamSuggestions.style.display = 'none';
                return;
            }
            
            this.teamDebounceTimer = setTimeout(() => {
                this.searchTeams(query);
            }, 300);
        });
        
        // Click outside to close suggestions
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#editTeam') && !e.target.closest('#teamSuggestions')) {
                teamSuggestions.style.display = 'none';
            }
        });
    }

    // Search teams for autocomplete
    async searchTeams(query) {
        try {
            const response = await fetch(`${this.teamsApiBase}?action=search&q=${encodeURIComponent(query)}`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            // Log raw response text before parsing
            const rawText = await response.text();
            console.log('DEBUG: Raw teams API response:', rawText);
            
            if (!rawText.trim()) {
                console.error('Empty response from teams API');
                return;
            }
            
            let result;
            try {
                result = JSON.parse(rawText);
            } catch (parseError) {
                console.error('JSON parse error for teams API:', parseError);
                console.error('Raw response that failed to parse:', rawText);
                return;
            }
            
            if (result.success) {
                this.showTeamSuggestions(result.teams);
            }
        } catch (error) {
            console.error('Team search error:', error);
        }
    }

    // Show team suggestions
    showTeamSuggestions(teams) {
        const teamSuggestions = document.getElementById('teamSuggestions');
        
        if (!teamSuggestions || teams.length === 0) {
            teamSuggestions.style.display = 'none';
            return;
        }
        
        const suggestionsHtml = teams.map(team => 
            `<div class="team-suggestion px-3 py-2" data-team-id="${team.id}" data-team-name="${team.name}">
                ${team.name}
            </div>`
        ).join('');
        
        teamSuggestions.innerHTML = suggestionsHtml;
        teamSuggestions.style.display = 'block';
        
        // Add click handlers to suggestions
        teamSuggestions.querySelectorAll('.team-suggestion').forEach(suggestion => {
            suggestion.addEventListener('click', () => {
                const teamName = suggestion.dataset.teamName;
                const teamInput = document.getElementById('editTeam');
                teamInput.value = teamName;
                teamSuggestions.style.display = 'none';
            });
        });
    }

    // Show toast notification
    showToast(message, type = 'success') {
        const toastId = type === 'success' ? 'successToast' : 'errorToast';
        const toastBodyId = type === 'success' ? 'successToastBody' : 'errorToastBody';
        
        const toastElement = document.getElementById(toastId);
        const toastBody = document.getElementById(toastBodyId);
        
        toastBody.textContent = message;
        
        const toast = new bootstrap.Toast(toastElement);
        toast.show();
    }

    // Logout handler
    async handleLogout() {
        try {
            const response = await fetch('api/auth/logout.php', {
                method: 'POST',
                credentials: 'include'
            });
            
            const result = await response.json();
            console.log('Logout result:', result);
            
            // Redirect to login regardless of API response
            window.location.href = 'index.html';
        } catch (error) {
            console.error('Logout error:', error);
            // Still redirect even on error
            window.location.href = 'index.html';
        }
    }
}

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', async function() {
    console.log('DOM loaded, initializing new settings page');
    window.settingsManager = new SettingsManager();
    await window.settingsManager.init();
    console.log('New settings page initialization complete');
});
