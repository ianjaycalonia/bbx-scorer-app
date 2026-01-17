// API Service for Blader App
class ApiService {
    constructor() {
        this.baseURL = 'api';
    }

    // Authentication
    async register(userData) {
        const response = await fetch(`${this.baseURL}/auth/register.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'register',
                ...userData
            })
        });
        return await response.json();
    }

    async login(email, password) {
        try {
            // Test simple endpoint first
            const response = await fetch(`${this.baseURL}/auth/login.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'login',
                    email,
                    password
                })
            });
            
            const data = await response.json();
            return data;
        } catch (error) {
            throw error;
        }
    }

    // User Profile
    async getProfile() {
        const response = await fetch(`${this.baseURL}/users/profile.php`, {
            method: 'GET',
            credentials: 'include'
        });
        return await response.json();
    }

    async updateProfile(profileData) {
        const response = await fetch(`${this.baseURL}/users/profile.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify(profileData)
        });
        return await response.json();
    }

    // Tournaments
    async getTournaments(status = null) {
        const url = status ? 
            `${this.baseURL}/tournaments/list.php?status=${status}` : 
            `${this.baseURL}/tournaments/list.php`;
        
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'include'
        });
        return await response.json();
    }

    async createTournament(tournamentData) {
        const response = await fetch(`${this.baseURL}/tournaments/list.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                action: 'create',
                ...tournamentData
            })
        });
        return await response.json();
    }

    async joinTournament(tournamentId) {
        const response = await fetch(`${this.baseURL}/tournaments/list.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                action: 'join',
                tournament_id: tournamentId
            })
        });
        return await response.json();
    }
}

// Global API instance
const api = new ApiService();
