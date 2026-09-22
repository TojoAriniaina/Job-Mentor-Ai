// stepper.js — gère les formulaires par étapes (CV / Lettre)
// N'affecte aucune logique métier : ne fait que montrer/masquer des blocs
// et ne touche jamais aux valeurs des champs (mêmes id, mêmes données).
function jmInitStepper(stepperId, panelId, totalSteps) {
  const stepperEl = document.getElementById(stepperId);
  const panelEl = document.getElementById(panelId);
  if (!stepperEl || !panelEl) return;

  let current = 1;
  let maxVisited = 1;

  function render() {
    // Affiche uniquement l'étape courante
    panelEl.querySelectorAll(".form-step").forEach((el) => {
      el.style.display = parseInt(el.dataset.step, 10) === current ? "" : "none";
    });

    // Met à jour les cercles
    stepperEl.querySelectorAll(".stepper-item").forEach((el) => {
      const s = parseInt(el.dataset.step, 10);
      el.classList.toggle("active", s === current);
      el.classList.toggle("completed", s < current);
      el.classList.toggle("is-clickable", s <= maxVisited);
    });

    // Met à jour les lignes de progression
    stepperEl.querySelectorAll(".stepper-line").forEach((el, idx) => {
      el.classList.toggle("completed", idx + 1 < current);
    });

    // Bouton "Précédent" masqué sur la 1ère étape
    panelEl.querySelectorAll('.form-step[data-step="' + current + '"] .btn-step-prev').forEach((btn) => {
      btn.style.visibility = current === 1 ? "hidden" : "visible";
    });
  }

  function goTo(step) {
    if (step < 1 || step > totalSteps) return;
    if (step > maxVisited + 1) return; // pas de saut vers une étape jamais visitée
    current = step;
    if (step > maxVisited) maxVisited = step;
    render();
    panelEl.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  stepperEl.querySelectorAll(".stepper-item").forEach((el) => {
    el.addEventListener("click", () => {
      const s = parseInt(el.dataset.step, 10);
      if (s <= maxVisited) goTo(s);
    });
  });

  panelEl.querySelectorAll(".btn-step-next").forEach((btn) => {
    btn.addEventListener("click", () => goTo(current + 1));
  });
  panelEl.querySelectorAll(".btn-step-prev").forEach((btn) => {
    btn.addEventListener("click", () => goTo(current - 1));
  });

  render();
}
