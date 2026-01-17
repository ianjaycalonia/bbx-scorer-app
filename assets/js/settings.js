// Settings JavaScript - Database-backed implementation
class SettingsManager {
    constructor() {
        this.currentData = {};
        this.originalData = {};
        this.apiBase = 'api/users/profile.php';
    }

    // Initialize settings page
    async init() {
        try {
            // Load current profile data
            await this.loadProfile();
            
            // Setup event listeners
            this.setupEventListeners();
            
            // Setup editable fields
            this.setupEditableFields();
            
            console.log('Settings initialized successfully');
        } catch (error) {
            console.error('Settings initialization failed:', error);
            this.showError('Failed to load settings');
        }
    }

    // Load profile data from database
    async loadProfile() {
        try {
            const response = await fetch(this.apiBase, {
                method: 'GET',
                credentials: 'include'
            });
            const result = await response.json();
            
            if (result.success) {
                this.currentData = result.profile;
                this.originalData = JSON.parse(JSON.stringify(result.profile)); // Deep copy
                this.populateFields();
            } else {
                throw new Error(result.message || 'Failed to load profile');
            }
        } catch (error) {
            console.error('Error loading profile:', error);
            throw error;
        }
    }

    // Populate form fields with database data
    populateFields() {
        // Users table fields
        this.setFieldText('bladerName', this.currentData.blader_name || '');
        this.setFieldText('displayName', this.currentData.display_name || '');
        this.setFieldText('email', this.currentData.email || '');
        
        // User profiles table fields
        this.setFieldText('location', this.currentData.location || '');
        this.setFieldText('preferredType', this.currentData.preferred_beyblade_type || '');
        this.setFieldText('bio', this.currentData.bio || '');
        
        // Team field
        this.setTeamField(this.currentData.team_name || '');
        
        // Store preference fields for proper data binding (even though not in UI)
        this.currentData.email_notifications = this.currentData.email_notifications || 1;
        this.currentData.public_profile = this.currentData.public_profile || 1;
        this.currentData.show_tournament_results = this.currentData.show_tournament_results || 1;
    }

    // Helper methods for field manipulation
    setFieldText(fieldId, value) {
        const element = document.getElementById(fieldId);
        if (element) {
            element.textContent = value;
        }
    }

    setTeamField(teamName) {
        const teamInput = document.getElementById('teamInput');
        const teamDisplay = document.getElementById('teamDisplay');
        
        if (teamInput && teamDisplay) {
            teamInput.value = teamName;
            teamDisplay.textContent = teamName;
        }
    }

    setCheckbox(fieldId, checked) {
        const element = document.getElementById(fieldId);
        if (element) {
            element.checked = checked;
        }
    }

    // Setup editable fields
    setupEditableFields() {
        const editableFields = [
            { id: 'realNameField', dataKey: 'blader_name', inputId: 'bladerNameInput' },
            { id: 'displayNameField', dataKey: 'display_name', inputId: 'displayNameInput' },
            { id: 'locationField', dataKey: 'location', inputId: 'locationInput' },
            { id: 'preferredTypeField', dataKey: 'preferred_beyblade_type', inputId: 'preferredTypeInput' },
            { id: 'bioField', dataKey: 'bio', inputId: 'bioInput' }
        ];

        editableFields.forEach(field => {
            const fieldElement = document.getElementById(field.id);
            if (fieldElement) {
                fieldElement.addEventListener('click', (e) => {
                    // Only trigger if clicking on the field itself or edit icon, not on input
                    if (e.target.classList.contains('edit-icon') || e.target.classList.contains('editable-field')) {
                        e.preventDefault();
                        this.makeEditable(field);
                    }
                });
            }
        });
    }

    // Make a field editable
    makeEditable(field) {
        const fieldElement = document.getElementById(field.id);
        // Fix the ID mapping issue
        const textElementId = field.dataKey === 'blader_name' ? 'bladerName' : 
                              field.dataKey === 'display_name' ? 'displayName' :
                              field.dataKey === 'location' ? 'location' :
                              field.dataKey === 'preferred_beyblade_type' ? 'preferredType' :
                              field.dataKey === 'bio' ? 'bio' : null;
        const textElement = document.getElementById(textElementId);
        
        if (!textElement || fieldElement.classList.contains('editing')) {
            return;
        }

        const currentValue = this.currentData[field.dataKey] || '';
        const inputType = field.dataKey === 'bio' ? 'textarea' : 'input';
        const inputTypeAttr = field.dataKey === 'bio' ? '' : 'type="text"';
        
        const inputHtml = inputType === 'textarea' 
            ? `<textarea class="form-control border-0 bg-transparent p-0" id="${field.inputId}" rows="3">${currentValue}</textarea>`
            : `<input class="form-control border-0 bg-transparent p-0" id="${field.inputId}" ${inputTypeAttr} value="${currentValue}">`;
        
        textElement.style.display = 'none';
        fieldElement.insertAdjacentHTML('beforeend', inputHtml);
        fieldElement.classList.add('editing');
        
        const input = document.getElementById(field.inputId);
        input.focus();
        input.select();
        
        // Save on blur or Enter
        const saveValue = () => {
            const newValue = input.value.trim();
            this.currentData[field.dataKey] = newValue || null;
            this.restoreField(field);
        };
        
        input.addEventListener('blur', saveValue);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                saveValue();
            } else if (e.key === 'Escape') {
                this.restoreField(field);
            }
        });
    }

    // Restore field to display mode
    restoreField(field) {
        const fieldElement = document.getElementById(field.id);
        // Fix the ID mapping issue
        const textElementId = field.dataKey === 'blader_name' ? 'bladerName' : 
                              field.dataKey === 'display_name' ? 'displayName' :
                              field.dataKey === 'location' ? 'location' :
                              field.dataKey === 'preferred_beyblade_type' ? 'preferredType' :
                              field.dataKey === 'bio' ? 'bio' : null;
        const textElement = document.getElementById(textElementId);
        const input = document.getElementById(field.inputId);
        
        if (input) {
            input.remove();
        }
        
        if (textElement) {
            textElement.style.display = '';
            textElement.textContent = this.currentData[field.dataKey] || '';
        }
        
        fieldElement.classList.remove('editing');
    }

    // Setup event listeners
    setupEventListeners() {
        // Logout button
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', () => this.handleLogout());
        }

        // Team field autocomplete
        this.setupTeamAutocomplete();
    }

    // Setup team autocomplete functionality
    setupTeamAutocomplete() {
        const teamInput = document.getElementById('teamInput');
        const teamSuggestions = document.getElementById('teamSuggestions');
        const teamEditIcon = document.getElementById('teamEditIcon');
        
        if (!teamInput || !teamSuggestions) return;

        let debounceTimer;
        
        // Input event for autocomplete
        teamInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            clearTimeout(debounceTimer);
            
            if (query.length < 2) {
                teamSuggestions.style.display = 'none';
                return;
            }
            
            debounceTimer = setTimeout(() => {
                this.searchTeams(query);
            }, 300);
        });
        
        // Click outside to close suggestions
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.team-field')) {
                teamSuggestions.style.display = 'none';
            }
        });
        
        // Update team data when input changes
        teamInput.addEventListener('change', (e) => {
            this.currentData.team_name = e.target.value.trim() || null;
        });
    }

    // Search teams for autocomplete
    async searchTeams(query) {
        try {
            const response = await fetch(`api/teams/teams.php?action=search&q=${encodeURIComponent(query)}`, {
                credentials: 'include'
            });
            
            const result = await response.json();
            
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
                const teamInput = document.getElementById('teamInput');
                teamInput.value = teamName;
                this.currentData.team_name = teamName;
                teamSuggestions.style.display = 'none';
            });
        });
    }

    // Toggle password change form
    togglePasswordChange() {
        const form = document.getElementById('passwordChangeForm');
        form.classList.toggle('d-none');
        if (!form.classList.contains('d-none')) {
            document.getElementById('currentPassword').focus();
        }
        // Clear previous messages
        document.getElementById('passwordError').textContent = '';
        document.getElementById('passwordSuccess').textContent = '';
    }

    // Cancel password change
    cancelPasswordChange() {
        const form = document.getElementById('passwordChangeForm');
        form.classList.add('d-none');
        // Clear fields
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
        // Clear messages
        document.getElementById('passwordError').textContent = '';
        document.getElementById('passwordSuccess').textContent = '';
    }

    // Change password
    async changePassword() {
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const errorElement = document.getElementById('passwordError');
        const successElement = document.getElementById('passwordSuccess');
        
        // Clear previous messages
        errorElement.textContent = '';
        successElement.textContent = '';
        
        // Client-side validation
        if (!currentPassword || !newPassword || !confirmPassword) {
            errorElement.textContent = 'All password fields are required';
            return;
        }
        
        if (newPassword.length < 6) {
            errorElement.textContent = 'New password must be at least 6 characters long';
            return;
        }
        
        if (newPassword !== confirmPassword) {
            errorElement.textContent = 'New passwords do not match';
            return;
        }
        
        try {
            const response = await fetch('api/auth/change_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'change_password',
                    current_password: currentPassword,
                    new_password: newPassword
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                successElement.textContent = result.message;
                // Clear form after successful change
                setTimeout(() => {
                    this.cancelPasswordChange();
                }, 2000);
            } else {
                errorElement.textContent = result.message;
            }
        } catch (error) {
            console.error('Password change error:', error);
            errorElement.textContent = 'Failed to change password. Please try again.';
        }
    }


    // Save all changes to database
    async saveProfile() {
        try {
            // Check if there are any changes
            const hasChanges = JSON.stringify(this.currentData) !== JSON.stringify(this.originalData);
            
            if (!hasChanges) {
                this.showSuccess('No changes to save');
                return;
            }

            // Prepare data in format expected by API
            const userData = {
                blader_name: this.currentData.blader_name,
                display_name: this.currentData.display_name,
                avatar_url: this.currentData.avatar_url
            };
            
            const profileData = {
                location: this.currentData.location,
                preferred_beyblade_type: this.currentData.preferred_beyblade_type,
                bio: this.currentData.bio,
                email_notifications: this.currentData.email_notifications || 1,
                public_profile: this.currentData.public_profile || 1,
                show_tournament_results: this.currentData.show_tournament_results || 1,
                team_name: this.currentData.team_name
            };

            const requestData = {
                ...userData,
                ...profileData
            };

            console.log('DEBUG: Sending data to API:', requestData);
            console.log('DEBUG: Extra Info fields being sent:', {
                location: requestData.location,
                preferred_beyblade_type: requestData.preferred_beyblade_type,
                bio: requestData.bio,
                team_name: requestData.team_name
            });

            // Send update to API
            const response = await fetch(this.apiBase, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify(requestData)
            });
            
            const result = await response.json();
            console.log('DEBUG: API response:', result);
            
            if (result.success) {
                this.originalData = JSON.parse(JSON.stringify(this.currentData)); // Update original
                this.showSuccess('Profile updated successfully!');
            } else {
                throw new Error(result.message || 'Failed to update profile');
            }
        } catch (error) {
            console.error('Error saving profile:', error);
            this.showError('Failed to save changes: ' + error.message);
        }
    }

    // Reset form to original values
    resetForm() {
        this.currentData = JSON.parse(JSON.stringify(this.originalData));
        this.populateFields();
        this.showSuccess('Form reset to original values');
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

    // UI feedback methods
    showSuccess(message) {
        const indicator = document.getElementById('saveIndicator');
        indicator.innerHTML = `<i class="bi bi-check-circle-fill me-1 text-success"></i> ${message}`;
        indicator.classList.remove('d-none');
        setTimeout(() => indicator.classList.add('d-none'), 3000);
    }

    showError(message) {
        const indicator = document.getElementById('saveIndicator');
        indicator.innerHTML = `<i class="bi bi-exclamation-circle-fill me-1 text-danger"></i> ${message}`;
        indicator.classList.remove('d-none');
        setTimeout(() => indicator.classList.add('d-none'), 5000);
    }
}

// Global functions for button onclick handlers
let settingsManager;

window.saveProfile = async function() {
    if (settingsManager) {
        await settingsManager.saveProfile();
    }
};

window.resetForm = function() {
    if (settingsManager) {
        settingsManager.resetForm();
    }
};

window.togglePasswordChange = function() {
    if (settingsManager) {
        settingsManager.togglePasswordChange();
    }
};

window.cancelPasswordChange = function() {
    if (settingsManager) {
        settingsManager.cancelPasswordChange();
    }
};

window.changePassword = async function() {
    if (settingsManager) {
        await settingsManager.changePassword();
    }
};

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', async function() {
    console.log('DOM loaded, initializing settings page');
    settingsManager = new SettingsManager();
    await settingsManager.init();
    console.log('Settings page initialization complete');
});
