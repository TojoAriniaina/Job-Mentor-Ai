// Défini par config.js (chargé avant ce fichier) — fonctionne peu importe le sous-dossier de déploiement
export const API_BASE = window.API_BASE || "/api";

export async function callAPI(endpoint) {
    try {
        const response = await fetch(`${API_BASE}/${endpoint}`, {credentials: 'include'});
        return await response.json();
    } catch (error) {
        console.error("API Error:", error);
    }
}

export async function postAPI(endpoint, data) {
    try {
        const response = await fetch(`${API_BASE}/${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(data)
        });
        return await response.json();
    } catch (error) {
        console.error("API Error:", error);
        throw error;
    }
}
