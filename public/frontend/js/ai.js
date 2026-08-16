import { callAPI, postAPI } from "./api.js";

// ── Text-to-Speech (ElevenLabs via backend) ──────────────────

// Précharge l'audio TTS sans le jouer. Retourne {audio, url} ou null en cas d'échec.
let ttsWarningShown = false;

// ── État global pour la pause IA ──
window.aiSpeech = { audio: null, paused: false };

window.aiSpeechPause = function () {
    const a = window.aiSpeech.audio;
    if (a && !a.paused) { a.pause(); window.aiSpeech.paused = true; }
};
window.aiSpeechResume = function () {
    const a = window.aiSpeech.audio;
    if (a && a.paused) { a.play(); window.aiSpeech.paused = false; }
};
window.aiSpeechToggle = function () {
    if (window.aiSpeech.paused) window.aiSpeechResume();
    else window.aiSpeechPause();
};

export async function fetchAudio(text) {
    try {
        const response = await fetch(`${window.API_BASE}/tts/speak`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ text })
        });

        if (!response.ok) {
            const err = await response.json().catch(() => null);
            throw new Error(err?.error || `Erreur HTTP ${response.status}`);
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const audio = new Audio(url);
        return { audio, url };
    } catch (e) {
        console.warn('[TTS] ElevenLabs échoué:', e.message);
        if (!ttsWarningShown && typeof showToast === 'function') {
            showToast('Voix ElevenLabs indisponible — voix de secours utilisée.', 'warning');
            ttsWarningShown = true;
        }
        return null;
    }
}

// Joue un audio pré-chargé et nettoie après lecture.
function playAudio(audioObj) {
    return new Promise(resolve => {
        const { audio, url } = audioObj;
        window.aiSpeech.audio = audio;
        window.aiSpeech.paused = false;
        audio.onended = () => {
            URL.revokeObjectURL(url);
            window.aiSpeech.audio = null;
            window.aiSpeech.paused = false;
            resolve();
        };
        audio.onerror = () => {
            URL.revokeObjectURL(url);
            window.aiSpeech.audio = null;
            window.aiSpeech.paused = false;
            resolve();
        };
        audio.play().catch(() => {
            URL.revokeObjectURL(url);
            window.aiSpeech.audio = null;
            window.aiSpeech.paused = false;
            resolve();
        });
    });
}

// Joue un texte via TTS (fetch + play). Utilisé comme fallback si pas de pré-chargement.
async function speakText(text) {
    const fetched = await fetchAudio(text);
    if (fetched) {
        return playAudio(fetched);
    }
    return speakTextFallback(text);
}

function speakTextFallback(text) {
    return new Promise(resolve => {
        if (!window.speechSynthesis) { resolve(); return; }
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'fr-FR';
        utterance.rate = 0.95;
        utterance.pitch = 1;
        const voices = window.speechSynthesis.getVoices();
        const frVoice = voices.find(v => v.lang && v.lang.startsWith('fr'));
        if (frVoice) utterance.voice = frVoice;
        // Expose l'état pour la pause
        window.aiSpeech.audio = { paused: false, pause: () => { window.speechSynthesis.pause(); window.aiSpeech.audio.paused = true; }, play: () => { window.speechSynthesis.resume(); window.aiSpeech.audio.paused = false; } };
        utterance.onend = () => { window.aiSpeech.audio = null; window.aiSpeech.paused = false; resolve(); };
        utterance.onerror = () => { window.aiSpeech.audio = null; window.aiSpeech.paused = false; resolve(); };
        window.speechSynthesis.speak(utterance);
    });
}

if (window.speechSynthesis) {
    window.speechSynthesis.onvoiceschanged = () => window.speechSynthesis.getVoices();
}

// ── Avatar state (thinking / speaking) ───────────────────────
function setAvatarState(state) {
    const avatar = document.querySelector('.ai-avatar');
    if (!avatar) return;
    avatar.classList.remove('thinking', 'speaking');
    if (state) avatar.classList.add(state);
    // Grand cercle equalizer au centre du chat
    if (typeof window.setSpeakingVisualizer === 'function') {
        window.setSpeakingVisualizer(state === 'speaking' || state === 'thinking', state);
    }
}

// ── Entretien : get question ─────────────────────────────────
export async function getQuestion(preloadedAudio = null, onMessage = null) {
    setAvatarState('thinking');
    const data = await callAPI("entretien/question");
    if (data && data.question) {
        const ttsText = "... " + data.question;
        const fetched = preloadedAudio || await fetchAudio(ttsText);
        setAvatarState('speaking');
        if (onMessage) onMessage(data.question, 'ai');
        if (fetched) {
            await playAudio(fetched);
        } else {
            await speakTextFallback(ttsText);
        }
        setAvatarState(null);
    } else {
        setAvatarState(null);
    }
    return data;
}

export async function sendAnswer(answer, onMessage = null) {
    setAvatarState('thinking');
    const data = await postAPI("entretien/analyze", { answer });
    if (data && data.success) {
        const texts = [data.feedback, data.conseil, data.next_question].filter(Boolean);
        const fetched = await Promise.all(texts.map(t => fetchAudio("... " + t)));

        setAvatarState('speaking');
        for (let i = 0; i < texts.length; i++) {
            if (onMessage) onMessage(texts[i], 'ai');
            if (fetched[i]) {
                await playAudio(fetched[i]);
            } else {
                await speakTextFallback("... " + texts[i]);
            }
        }
        setAvatarState(null);
    } else {
        setAvatarState(null);
        showToast("Erreur d'analyse de l'IA.", "error");
    }
    return data;
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

// ── Import de fichier (PDF / DOCX / TXT) ─────────────────────
async function importFile(file) {
    let text = '';

    if (file.type === 'text/plain' || file.name.match(/\.txt$/i)) {
        text = await file.text();

    } else if (file.name.match(/\.docx?$/i)) {
        const buf = await file.arrayBuffer();
        if (typeof mammoth !== 'undefined') {
            const result = await mammoth.extractRawText({ arrayBuffer: buf });
            text = result.value;
        } else {
            throw new Error('Pour les fichiers Word, convertissez en PDF d\'abord.');
        }

    } else if (file.type === 'application/pdf' || file.name.match(/\.pdf$/i)) {
        const buf = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: buf }).promise;

        const pages = [];
        for (let i = 1; i <= pdf.numPages; i++) {
            const page = await pdf.getPage(i);
            const content = await page.getTextContent();
            pages.push(content.items.map(it => it.str).join(' '));
        }
        text = pages.join('\n');

        // PDF scanné → OCR Tesseract
        if (!text.trim() && typeof Tesseract !== 'undefined') {
            const worker = await Tesseract.createWorker('fra+eng');
            const ocrPages = [];
            for (let i = 1; i <= pdf.numPages; i++) {
                const page = await pdf.getPage(i);
                const viewport = page.getViewport({ scale: 2 });
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                const { data: { text: ocrText } } = await worker.recognize(canvas);
                ocrPages.push(ocrText);
            }
            await worker.terminate();
            text = ocrPages.join('\n');
        }
    } else {
        throw new Error('Format non supporté. Utilisez PDF, DOCX ou TXT.');
    }

    text = text.replace(/\s+/g, ' ').trim();
    if (!text) throw new Error('Impossible d\'extraire le texte de ce fichier.');
    return text;
}

// Expose for classic script usage
window.importFile = importFile;

