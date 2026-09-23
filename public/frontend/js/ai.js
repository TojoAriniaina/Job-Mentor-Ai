import { callAPI, postAPI } from "./api.js";

// ── Lazy-load des bibliothèques OCR (pdf.js + tesseract.js) ──
let ocrLoaded = false;
async function ensureOcrLibs() {
    if (ocrLoaded) return;
    const LIBS = (window.FRONTEND_BASE || '') + '/libs';
    const PDF_JS_URL = LIBS + '/pdfjs/pdf.min.js';
    const TESSERACT_URL = LIBS + '/tesseract/tesseract.min.js';
    const PDF_WORKER_URL = LIBS + '/pdfjs/pdf.worker.min.js';

    await Promise.all([
        loadScript(PDF_JS_URL),
        loadScript(TESSERACT_URL)
    ]);

    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = PDF_WORKER_URL;
    }
    ocrLoaded = true;
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) { resolve(); return; }
        const s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = () => reject(new Error('Échec de chargement: ' + src));
        document.body.appendChild(s);
    });
}

// ── Text-to-Speech (ElevenLabs via backend) ──────────────────

// Précharge l'audio TTS sans le jouer. Retourne {audio, url} ou null en cas d'échec.
let ttsWarningShown = false;

// ── État global pour la pause IA ──
window.aiSpeech = { audio: null, paused: false, _stopped: false };

// ── Compteur de génération pour arrêt atomique ──
let _speechGen = 0;

window.aiSpeechStop = function () {
    _speechGen++;
    window.aiSpeech._stopped = true;
    const a = window.aiSpeech.audio;
    if (a && typeof a.pause === 'function') {
        try { a.pause(); } catch (e) {}
    }
    window.aiSpeech.audio = null;
    window.aiSpeech.paused = false;
    if (window.speechSynthesis) {
        try { window.speechSynthesis.cancel(); } catch (e) {}
    }
};

window.aiSpeechStart = function () {
    window.aiSpeech._stopped = false;
};

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

function _dbg(msg) {
    if (!window.DEBUG_ENTRETIEN) return;
    console.log(`%c[IA ${performance.now().toFixed(0)}ms] ${msg}`, 'color:#8b5cf6;font-weight:bold');
}

export async function fetchAudio(text) {
    const _t = performance.now();
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
        _dbg(`fetchAudio TTS: ${(performance.now() - _t).toFixed(0)}ms (${text.substring(0, 40)}...)`);
        return { audio, url };
    } catch (e) {
        _dbg(`fetchAudio TTS FAILED: ${(performance.now() - _t).toFixed(0)}ms — ${e.message}`);
        console.warn('[TTS] ElevenLabs echoue:', e.message);
        if (!ttsWarningShown && typeof showToast === 'function') {
            showToast(e.message || 'Voix ElevenLabs indisponible — voix de secours utilisée.', 'warning');
            ttsWarningShown = true;
        }
        return null;
    }
}

// Joue un audio pré-chargé et nettoie après lecture.
function playAudio(audioObj) {
    return new Promise(resolve => {
        const gen = _speechGen;
        const { audio, url } = audioObj;

        const cleanup = () => {
            URL.revokeObjectURL(url);
            window.aiSpeech.audio = null;
            window.aiSpeech.paused = false;
            resolve();
        };

        // Si déjà arrêté avant même de commencer → résout immédiatement
        if (gen !== _speechGen || window.aiSpeech._stopped) { cleanup(); return; }

        window.aiSpeech.audio = audio;
        window.aiSpeech.paused = false;

        let resolved = false;
        const done = () => {
            if (resolved) return;
            resolved = true;
            cleanup();
        };

        audio.onended = done;
        audio.onerror = done;
        audio.onpause = () => { if (gen !== _speechGen) done(); };
        audio.play().catch(done);
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
        const gen = _speechGen;
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'fr-FR';
        utterance.rate = 0.95;
        utterance.pitch = 1;
        const voices = window.speechSynthesis.getVoices();
        const frVoice = voices.find(v => v.lang && v.lang.startsWith('fr'));
        if (frVoice) utterance.voice = frVoice;
        window.aiSpeech.audio = { paused: false, pause: () => { window.speechSynthesis.pause(); window.aiSpeech.audio.paused = true; }, play: () => { window.speechSynthesis.resume(); window.aiSpeech.audio.paused = false; } };
        let resolved = false;
        let timerId = null;
        const done = () => {
            if (resolved) return;
            resolved = true;
            if (timerId) { clearInterval(timerId); timerId = null; }
            window.aiSpeech.audio = null;
            window.aiSpeech.paused = false;
            resolve();
        };
        utterance.onend = done;
        utterance.onerror = done;
        timerId = setInterval(() => {
            if (gen !== _speechGen) done();
        }, 100);
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
    const _t = performance.now();
    _dbg('getQuestion: appel API /entretien/question...');
    setAvatarState('thinking');
    const data = await callAPI("entretien/question");
    _dbg(`getQuestion API: ${(performance.now() - _t).toFixed(0)}ms`);
    if (data && data.question) {
        const ttsText = "... " + data.question;
        const _tTts = performance.now();
        const fetched = preloadedAudio || await fetchAudio(ttsText);
        if (!preloadedAudio) _dbg(`getQuestion TTS: ${(performance.now() - _tTts).toFixed(0)}ms`);
        else _dbg(`getQuestion TTS: preloaded (0ms)`);
        setAvatarState('speaking');
        if (!window.aiSpeech._stopped) {
            if (onMessage) onMessage(data.question, 'ai');
            const _tPlay = performance.now();
            if (fetched) {
                await playAudio(fetched);
            } else {
                await speakTextFallback(ttsText);
            }
            _dbg(`getQuestion playback: ${(performance.now() - _tPlay).toFixed(0)}ms`);
        }
        _dbg(`getQuestion TOTAL: ${(performance.now() - _t).toFixed(0)}ms`);
        setAvatarState(null);
    } else {
        setAvatarState(null);
    }
    return data;
}

export async function sendAnswer(answer, onMessage = null) {
    const _t = performance.now();
    _dbg('sendAnswer: envoi reponse a l\'IA...');
    setAvatarState('thinking');
    const _tApi = performance.now();
    const data = await postAPI("entretien/analyze", { answer });
    _dbg(`sendAnswer API (LLM): ${(performance.now() - _tApi).toFixed(0)}ms`);
    if (data && data.success) {
        const texts = [data.feedback, data.conseil, data.next_question].filter(Boolean);
        const _tTts = performance.now();
        const fetched = await Promise.all(texts.map(t => fetchAudio("... " + t)));
        _dbg(`sendAnswer TTS (${texts.length} textes): ${(performance.now() - _tTts).toFixed(0)}ms`);

        setAvatarState('speaking');
        const _tPlay = performance.now();
        for (let i = 0; i < texts.length; i++) {
            if (window.aiSpeech._stopped) break;
            if (onMessage) onMessage(texts[i], 'ai');
            if (fetched[i]) {
                await playAudio(fetched[i]);
            } else {
                await speakTextFallback("... " + texts[i]);
            }
        }
        _dbg(`sendAnswer playback (${texts.length} clips): ${(performance.now() - _tPlay).toFixed(0)}ms`);
        _dbg(`sendAnswer TOTAL: ${(performance.now() - _t).toFixed(0)}ms`);
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
window.AI.generateCoverLetter = async function ({ cv, offre, ton, civilite, nom, poste, email, adresse, telephone, ville, entreprise, entreprise_adresse }) {
    const data = await postAPI("lettre/generate", { cv, offre, ton, civilite, nom, poste, email, adresse, telephone, ville, entreprise, entreprise_adresse });
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
        throw new Error(data ? data.error : "Erreur lors de l'amélioration de la lettre");
    }
};

window.AI.analyzeLetter = async function (text) {
    const data = await postAPI("lettre/analyze", { text });
    if (data && data.success) {
        return data.data;
    } else {
        throw new Error(data ? data.error : "Erreur lors de l'analyse de la lettre");
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
        await ensureOcrLibs();
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
            const TESS = (window.FRONTEND_BASE || '') + '/libs/tesseract';
            const worker = await Tesseract.createWorker('fra+eng', 1, {
                workerPath: TESS + '/worker.min.js',
                corePath:   TESS + '/core',
                langPath:   TESS + '/lang',
                gzip: true,
            });
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

// ── Extraction de la photo intégrée à un CV PDF (portrait) ─────
function pdfImageToDataUrl(img) {
    try {
        if (!img || !img.width || !img.height) return null;
        const src = document.createElement('canvas');
        src.width = img.width;
        src.height = img.height;
        const sctx = src.getContext('2d');
        if (img.bitmap) sctx.drawImage(img.bitmap, 0, 0);
        else if (img.canvas) sctx.drawImage(img.canvas, 0, 0);
        else if (img.data && img.data.length === img.width * img.height * 4) {
            sctx.putImageData(new ImageData(img.data, img.width, img.height), 0, 0);
        } else return null;
        const max = 720;
        const scale = Math.min(1, max / Math.max(src.width, src.height));
        const out = document.createElement('canvas');
        out.width = Math.max(1, Math.round(src.width * scale));
        out.height = Math.max(1, Math.round(src.height * scale));
        out.getContext('2d').drawImage(src, 0, 0, out.width, out.height);
        return out.toDataURL('image/jpeg', 0.93);
    } catch (e) { return null; }
}

async function extractPhotoFromPdf(file) {
    try {
        await ensureOcrLibs();
        const buf = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
        let best = null;
        const maxPages = Math.min(pdf.numPages, 2);
        for (let i = 1; i <= maxPages; i++) {
            const page = await pdf.getPage(i);
            const opList = await page.getOperatorList();
            const OPS = pdfjsLib.OPS;
            for (let k = 0; k < opList.fnArray.length; k++) {
                if (opList.fnArray[k] !== OPS.paintImageXObject) continue;
                const key = opList.argsArray[k][0];
                // pdf.js 3.11 n'a pas objs.getAsync : l'image est déjà résolue après getOperatorList
                let img = page.objs.get(key);
                for (let tryN = 0; !img && tryN < 5; tryN++) {
                    await new Promise(r => setTimeout(r, 100));
                    img = page.objs.get(key);
                }
                if (!img) continue;
                const w = img.width, h = img.height;
                if (!w || !h || w < 90 || h < 90) continue;
                const ratio = h / w;
                const portrait = ratio >= 0.85 && ratio <= 2.2;
                const area = w * h;
                if ((!best) || (portrait && (!best.portrait || best.area < area)) ||
                    (!portrait && best.portrait === false && best.area < area)) {
                    const dataUrl = pdfImageToDataUrl(img);
                    if (dataUrl) best = { dataUrl, area, portrait };
                }
            }
        }
        pdf.destroy();
        return (best && best.portrait) ? best.dataUrl : null;
    } catch (e) { return null; }
}

window.AI.extractPhotoFromPdf = extractPhotoFromPdf;

