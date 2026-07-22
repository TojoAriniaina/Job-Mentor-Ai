import { callAPI, postAPI } from "./api.js";

// ── Text-to-Speech ───────────────────────────────────────────
function speakText(text) {
    if (!window.speechSynthesis) return;

    // Annuler toute lecture en cours
    window.speechSynthesis.cancel();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'fr-FR';
    utterance.rate = 0.95;
    utterance.pitch = 1;

    // Choisir une voix française si disponible
    const voices = window.speechSynthesis.getVoices();
    const frVoice = voices.find(v => v.lang && v.lang.startsWith('fr'));
    if (frVoice) utterance.voice = frVoice;

    window.speechSynthesis.speak(utterance);
}

// Les voix se chargent de façon asynchrone — forcer le chargement dès le départ
if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = () => window.speechSynthesis.getVoices();
}

// ── Chat bubble ──────────────────────────────────────────────
function appendBubble(text, sender = 'ai') {
    const chatWindow = document.getElementById("chat-window");
    const initialMsg = document.getElementById('initial-msg');
    if (initialMsg) initialMsg.remove();

    const bubble = document.createElement("div");
    bubble.className = `bubble ${sender}-bubble`;
    const icon = sender === 'ai'
        ? '<i class="fa-solid fa-robot"></i> JobMentor IA'
        : '<i class="fa-solid fa-user"></i> Vous';
    bubble.innerHTML = `<div class="bubble-name">${icon}</div><div class="bubble-text"></div>`;
    chatWindow.appendChild(bubble);
    chatWindow.scrollTop = chatWindow.scrollHeight;
    return bubble.querySelector(".bubble-text");
}

// ── Typewriter effect ────────────────────────────────────────
function typeWriter(element, text, speed = 25) {
    return new Promise(resolve => {
        let i = 0;
        function type() {
            if (i < text.length) {
                element.textContent += text.charAt(i);
                i++;
                const chatWindow = document.getElementById("chat-window");
                if (chatWindow) chatWindow.scrollTop = chatWindow.scrollHeight;
                setTimeout(type, speed);
            } else {
                resolve();
            }
        }
        type();
    });
}

// ── Entretien : get question ─────────────────────────────────
export async function getQuestion() {
    const textNode = appendBubble("...");
    textNode.innerHTML = '<i><i class="fa-solid fa-spinner fa-spin"></i> L\'IA génère une question...</i>';

    const data = await callAPI("entretien/question");
    textNode.innerHTML = '';

    if (data && data.question) {
        await typeWriter(textNode, data.question);
        speakText(data.question);
    }

    return data;
}

export async function sendAnswer(answer) {
    // 1. On affiche la bulle de l'utilisateur
    appendBubble(answer, 'user');

    // 2. On affiche le chargement de l'IA
    const feedbackNode = appendBubble("...");
    feedbackNode.innerHTML = '<i><i class="fa-solid fa-spinner fa-spin"></i> L\'IA analyse votre réponse...</i>';

    // 3. Appel API
    const data = await postAPI("entretien/analyze", { answer });
    feedbackNode.innerHTML = '';

    if (data && data.success) {
        // Affichage du feedback
        await typeWriter(feedbackNode, data.feedback);

        // Ajout du conseil dans un petit bloc spécial
        const conseilDiv = document.createElement('div');
        conseilDiv.className = 'alert alert-info mt-2';
        conseilDiv.style.fontSize = '0.85rem';
        conseilDiv.innerHTML = `<strong>💡 Conseil :</strong> ${data.conseil}`;
        feedbackNode.appendChild(conseilDiv);

        // Si une question suivante est prévue, on l'affiche après un court délai
        if (data.next_question) {
            setTimeout(async () => {
                const nextQNode = appendBubble("...");
                nextQNode.innerHTML = '<i><i class="fa-solid fa-microphone"></i> Recruteur...</i>';

                await new Promise(r => setTimeout(r, 1000));
                nextQNode.innerHTML = '';

                await typeWriter(nextQNode, data.next_question);
                speakText(data.next_question);
            }, 1500);
        }
    } else {
        feedbackNode.innerHTML = '<span class="text-danger">Erreur d\'analyse de l\'IA.</span>';
    }

    return data;
}

export function simulateUserAnswer() {
    // On n'en a plus besoin si on utilise la vraie reco vocale ou texte,
    // mais on la garde pour compatibilité si oral.html l'utilise encore.
    const textNode = appendBubble("", 'user');
    typeWriter(textNode, "Ceci est une simulation de ma réponse interceptée par le micro...", 40);
}

// ── MODULE CV ────────────────────────────────────────────────
window.AI = window.AI || {};

window.AI.generateCV = async function (info) {
    const data = await postAPI("cv/generate", { info });
    if (data && data.success) {
        return data.data;
    } else {
        throw new Error(data ? data.error : "Erreur de génération du CV par l'IA");
    }
};

window.AI.improveCV = async function (cv, jobOffer) {
    const data = await postAPI("cv/improve", { cv, jobOffer });
    if (data && data.success) {
        return data.data;
    } else {
        throw new Error(data ? data.error : "Erreur lors de l'analyse du CV");
    }
};

// ── MODULE LETTRE ────────────────────────────────────────────
window.AI.generateCoverLetter = async function ({ cv, offre, ton, nom, adresse, telephone, ville, entreprise, entreprise_adresse }) {
    const data = await postAPI("lettre/generate", { cv, offre, ton, nom, adresse, telephone, ville, entreprise, entreprise_adresse });
    if (data && data.success) {
        return data.data;
    } else {
        throw new Error(data ? data.error : "Erreur de génération de la lettre");
    }
};

window.AI.correctLetter = async function (text) {
    const data = await postAPI("lettre/correct", { text });
    if (data && data.success) {
        return data.data;
    } else {
        throw new Error(data ? data.error : "Erreur lors de la correction de la lettre");
    }
};

// ── MODULE ORAL ──────────────────────────────────────────────
window.AI.analyzeOralResponse = async function ({ transcription, poste, langue }) {
    const data = await postAPI("oral/analyze", { transcription, poste, langue });
    if (data && data.success) {
        return data.data;
    } else {
        throw new Error(data ? data.error : "Erreur d'analyse de la réponse orale");
    }
};

