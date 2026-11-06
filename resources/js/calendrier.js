// Vues mois et saison (PRD « Sélection dans le calendrier », ADR 017) :
// infobulle au survol, sélection d'un créneau ou d'une période par clics, pop-up de réservation.
// Créneaux = éléments [data-rang] (rang croissant dans le temps), data-dispo = libre | non.
// Ce n'est qu'une aide : ServiceReservation revérifie tout côté serveur.

const RAISONS = {
    ouverte: 'Ce créneau est déjà réservé (réservation ouverte).',
    exclusive: 'Ce créneau est déjà réservé (exclusif).',
    attribue: 'Ce créneau fait partie de vacances attribuées à une lignée.',
    attente: "Ce créneau fait partie de vacances en attente d'attribution.",
    ferme: "L'appartement est fermé ce jour-là.",
    'pas-ouvert': "Cette année n'est pas encore ouverte à la réservation.",
};

function demarrer() {
    const popup = document.getElementById('popup-reservation');
    const creneaux = () => Array.from(document.querySelectorAll('[data-rang]'));
    if (!popup || creneaux().length === 0) {
        return;
    }
    const rang = (el) => Number(el.dataset.rang);
    let debut = null;
    let barre = null;

    const message = (texte) => {
        const m = document.createElement('div');
        m.className = 'message-flottant';
        m.setAttribute('role', 'alert');
        m.textContent = texte;
        document.body.appendChild(m);
        setTimeout(() => m.remove(), 3000);
    };
    const marquer = (els) => {
        creneaux().forEach((e) => e.classList.remove('selection'));
        els.forEach((e) => e.classList.add('selection'));
    };
    const fermerBarre = () => {
        barre?.remove();
        barre = null;
    };
    const toutEffacer = () => {
        debut = null;
        marquer([]);
        fermerBarre();
    };
    const entre = (a, b) => {
        const bas = Math.min(a, b);
        const haut = Math.max(a, b);
        return creneaux().filter((e) => rang(e) >= bas && rang(e) <= haut).sort((x, y) => rang(x) - rang(y));
    };

    const ouvrirBarre = (el) => {
        fermerBarre();
        barre = document.createElement('div');
        barre.className = 'barre-selection';
        barre.setAttribute('role', 'status');
        const texte = document.createElement('span');
        texte.textContent = `1 créneau : ${el.dataset.label} · cliquez sur un autre créneau pour choisir une période`;
        const reserver = document.createElement('button');
        reserver.type = 'button';
        reserver.className = 'h-[38px] rounded-lg bg-white px-3.5 font-bold text-encre';
        reserver.textContent = 'Réserver ce créneau';
        reserver.onclick = () => ouvrirPopup([el]);
        const annuler = document.createElement('button');
        annuler.type = 'button';
        annuler.className = 'h-[38px] rounded-lg px-3.5 font-bold text-bord';
        annuler.textContent = 'Annuler';
        annuler.onclick = toutEffacer;
        barre.append(texte, reserver, annuler);
        document.body.appendChild(barre);
    };

    const form = popup.querySelector('form');
    const actionCreation = form.getAttribute('action');

    // edition = le créneau cliqué d'une de mes réservations (attributs data-mienne…), ou null pour créer.
    const ouvrirPopup = (els, edition = null) => {
        fermerBarre();
        const cles = els.map((e) => e.dataset.cle);
        const journees = cles.filter((c) => c.endsWith('-J')).length;
        const nuits = cles.length - journees;
        form.elements.debut.value = cles[0].slice(0, 10);
        form.elements.fin.value = cles[cles.length - 1].slice(0, 10);
        const boite = form.querySelector('[data-creneaux]');
        boite.replaceChildren(...cles.map((c) => Object.assign(document.createElement('input'), { type: 'hidden', name: 'creneaux[]', value: c })));

        const premier = els[0].dataset.label;
        const dernier = els[els.length - 1].dataset.label;
        form.querySelector('[data-periode]').textContent = els.length === 1 ? premier : `Du ${premier} au ${dernier}`;
        form.querySelector('[data-compte]').textContent = `${journees} journée${journees > 1 ? 's' : ''} · ${nuits} nuit${nuits > 1 ? 's' : ''}`;

        const methode = form.querySelector('[data-methode]');
        const lien = form.querySelector('[data-lien-dates]');
        methode.disabled = edition === null;
        form.setAttribute('action', edition ? edition.dataset.action : actionCreation);
        form.querySelector('[data-titre]').textContent = edition ? 'Modifier ma réservation' : 'Nouvelle réservation';
        popup.querySelector('[data-valider]').textContent = edition ? 'Enregistrer' : 'Réserver';
        lien.hidden = edition === null;
        const zoneAnnulation = popup.querySelector('[data-zone-annulation]');
        zoneAnnulation.hidden = edition === null;
        zoneAnnulation.querySelector('details').open = false;
        if (edition) {
            zoneAnnulation.querySelector('[data-form-annulation]').setAttribute('action', edition.dataset.action);
        }
        if (edition) {
            lien.href = edition.dataset.pageModifier;
            form.querySelector(`input[name=mode][value=${edition.dataset.mode === 'exclusive' ? 'exclusive' : 'ouverte'}]`).checked = true;
            form.elements.nombre_personnes.value = edition.dataset.nb || 2;
            form.elements.commentaire.value = edition.dataset.commentaire ?? '';
            form.elements.invites.value = edition.dataset.invites ?? '';
        }

        popup.showModal();
        form.elements.nombre_personnes.focus();
    };

    const fermerPopup = () => {
        if (popup.open) {
            popup.close();
        }
    };
    popup.addEventListener('close', toutEffacer);
    popup.querySelector('[data-fermer]').addEventListener('click', fermerPopup);
    // Clic hors de la pop-up (sur le fond) : on ferme.
    popup.addEventListener('click', (e) => {
        if (e.target === popup) {
            fermerPopup();
        }
    });

    // Un créneau de ma réservation : la pop-up s'ouvre sur toute la réservation, en édition.
    const editer = (el) => {
        toutEffacer();
        const cles = (el.dataset.creneaux || el.dataset.cle).split(',');
        const parCle = new Map(creneaux().map((e) => [e.dataset.cle, e]));
        const els = cles.map((c) => parCle.get(c) ?? { dataset: { cle: c, label: c } });
        marquer(els.filter((e) => e instanceof Element));
        ouvrirPopup(els, el);
    };

    const choisir = (el) => {
        if (el.dataset.dispo === 'mienne') {
            if (debut === null) {
                editer(el);
            } else {
                message('Ce créneau fait déjà partie de votre réservation. Choisissez une fin libre.');
            }
            return;
        }
        if (el.dataset.dispo === 'non') {
            message(RAISONS[el.dataset.etat] ?? 'Ce créneau ne peut plus être réservé (date passée).');
            return;
        }
        const k = rang(el);
        if (debut === null) {
            debut = k;
            marquer([el]);
            ouvrirBarre(el);
            return;
        }
        if (k === debut) {
            toutEffacer();
            return;
        }
        const periode = entre(debut, k);
        if (periode.some((e) => e.dataset.dispo !== 'libre')) {
            message('La période contient un créneau déjà réservé ou indisponible. Choisissez une autre fin.');
            return;
        }
        marquer(periode);
        debut = null;
        ouvrirPopup(periode);
    };

    document.addEventListener('click', (e) => {
        const el = e.target.closest?.('[data-rang]');
        if (el && !popup.open) {
            choisir(el);
        }
    });
    document.addEventListener('keydown', (e) => {
        const el = e.target.closest?.('[data-rang]');
        if (el && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            choisir(el);
        } else if (e.key === 'Escape' && !popup.open) {
            toutEffacer();
        }
    });

    // Infobulle : première ligne en gras, commentaires (« … ») en italique.
    let bulle = null;
    const cacher = () => {
        bulle?.remove();
        bulle = null;
    };
    const montrer = (el) => {
        cacher();
        bulle = document.createElement('div');
        bulle.className = 'infobulle';
        bulle.setAttribute('role', 'tooltip');
        el.dataset.infobulle.split('¶').forEach((ligne, i) => {
            const l = document.createElement(i === 0 ? 'b' : ligne.startsWith('«') ? 'i' : 'div');
            l.textContent = ligne;
            bulle.appendChild(l);
        });
        document.body.appendChild(bulle);
        const r = el.getBoundingClientRect();
        const x = Math.min(Math.max(8, r.left + r.width / 2 - bulle.offsetWidth / 2), innerWidth - bulle.offsetWidth - 8);
        const y = r.bottom + 8 + bulle.offsetHeight > innerHeight ? r.top - bulle.offsetHeight - 8 : r.bottom + 8;
        bulle.style.left = `${x}px`;
        bulle.style.top = `${y}px`;
    };
    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest?.('[data-infobulle]');
        if (el) {
            montrer(el);
        } else {
            cacher();
        }
    });
    document.addEventListener('focusin', (e) => {
        const el = e.target.closest?.('[data-infobulle]');
        if (el) {
            montrer(el);
        }
    });
    document.addEventListener('focusout', cacher);
    addEventListener('scroll', cacher, { passive: true });

    // Refus du serveur : on resélectionne la période envoyée et on rouvre la pop-up avec la raison.
    if (popup.dataset.anciens) {
        const anciens = popup.dataset.anciens.split(',');
        const els = creneaux().filter((e) => anciens.includes(e.dataset.cle)).sort((x, y) => rang(x) - rang(y));
        if (els.length) {
            const mode = popup.querySelector('input[name=mode]:checked')?.value;
            marquer(els);
            ouvrirPopup(els);
            if (mode === 'exclusive' && !popup.querySelector('input[name=mode][value=exclusive]').disabled) {
                popup.querySelector('input[name=mode][value=exclusive]').checked = true;
            }
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer);
} else {
    demarrer();
}
