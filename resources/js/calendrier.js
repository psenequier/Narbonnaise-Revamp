// Vues mois et saison (PRD « Sélection dans le calendrier », ADR 017) :
// infobulle au survol, sélection d'un créneau ou d'une période par clics, pop-up de réservation.
// Créneaux = éléments [data-rang] (rang croissant dans le temps), data-dispo = libre | mienne | non,
// et pour un admin attribuable (en attente) | attribution (attribué) : on attribue comme on réserve (7 nov.).
// Ce n'est qu'une aide : ServiceReservation revérifie tout côté serveur.

const RAISONS = {
    ouverte: 'Ce créneau est déjà réservé (réservation ouverte).',
    exclusive: 'Ce créneau est déjà réservé (exclusif).',
    attribue: 'Ce créneau fait partie de vacances attribuées à une lignée.',
    attente: "Ce créneau fait partie de vacances en attente d'attribution.",
    ferme: "L'appartement est fermé ce jour-là.",
    'pas-ouvert': "Cette année n'est pas encore ouverte à la réservation.",
};

// Infobulle au survol de tout élément [data-infobulle] (calendrier, formulaire de réservation).
function demarrerInfobulle() {
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
    // Écran tactile : toucher ailleurs ferme la bulle.
    document.addEventListener('touchstart', (e) => {
        if (!e.target.closest?.('[data-infobulle]')) {
            cacher();
        }
    }, { passive: true });

    return { montrer, cacher };
}

function demarrer() {
    const infobulle = demarrerInfobulle();
    // Pas de vue saison sur téléphone (6 nov.) : on bascule sur la vue mois.
    if (document.querySelector('[data-vue-saison]') && matchMedia('(max-width: 599px)').matches) {
        location.replace('/calendrier');
        return;
    }
    const popup = document.getElementById('popup-reservation');
    const popupAttr = document.getElementById('popup-attribution');
    // Seuls les créneaux affichés comptent : la grille (ordinateur) et la liste (téléphone) coexistent dans la page.
    const creneaux = () => Array.from(document.querySelectorAll('[data-rang]')).filter((e) => e.offsetParent !== null);
    if ((!popup && !popupAttr) || creneaux().length === 0) {
        return;
    }
    const rang = (el) => Number(el.dataset.rang);
    // Même rang que le serveur (AffichageCreneau) : deux par jour depuis le 1er janvier 1970.
    const rangCle = (cle) => {
        const [a, m, j] = cle.slice(0, 10).split('-').map(Number);
        return (Date.UTC(a, m - 1, j) / 86400000) * 2 + (cle.endsWith('-N') ? 1 : 0);
    };
    const unePopupOuverte = () => Boolean(popup?.open || popupAttr?.open);
    let debut = null;
    // Sélection en cours : « libre » (réservation) ou « attribuable » (attribution, admins seulement).
    let sorte = 'libre';
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
        document.body.classList.remove('avec-barre');
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
        const court = matchMedia('(max-width: 639px)').matches;
        texte.textContent = court ? `${el.dataset.label.replace(' (journée)', ' J').replace(' (nuit)', ' N')} → touchez la fin` : `1 créneau : ${el.dataset.label} · cliquez sur un autre créneau pour choisir une période`;
        const reserver = document.createElement('button');
        reserver.type = 'button';
        reserver.className = 'bg-white text-encre';
        const attribuer = sorte === 'attribuable';
        reserver.textContent = attribuer ? (court ? 'Attribuer' : 'Attribuer ce créneau') : (court ? 'Réserver' : 'Réserver ce créneau');
        reserver.onclick = () => (attribuer ? ouvrirAttribution([el]) : ouvrirPopup([el]));
        const annuler = document.createElement('button');
        annuler.type = 'button';
        annuler.className = 'text-bord';
        annuler.textContent = 'Annuler';
        annuler.onclick = toutEffacer;
        barre.append(texte, reserver, annuler);
        document.body.appendChild(barre);
        document.body.classList.add('avec-barre');
    };

    const form = popup?.querySelector('form');
    const actionCreation = form?.getAttribute('action');
    const compte = (cles) => {
        const journees = cles.filter((c) => c.endsWith('-J')).length;
        const nuits = cles.length - journees;
        return `${journees} journée${journees > 1 ? 's' : ''} · ${nuits} nuit${nuits > 1 ? 's' : ''}`;
    };

    // edition = le créneau cliqué d'une de mes réservations (attributs data-mienne…), ou null pour créer.
    const ouvrirPopup = (els, edition = null) => {
        if (!popup) {
            return;
        }
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

    // Pop-up d'attribution (admins) : création depuis une sélection, ou édition de la plage d'un créneau attribué.
    const ouvrirAttribution = (els, edition = null) => {
        if (!popupAttr) {
            return;
        }
        fermerBarre();
        const f = popupAttr.querySelector('form');
        const premiere = edition ? edition.dataset.debutPlage : els[0].dataset.cle;
        const derniere = edition ? edition.dataset.finPlage : els[els.length - 1].dataset.cle;
        f.elements.debut.value = premiere.slice(0, 10);
        f.elements.debut_type.value = premiere.slice(-1);
        f.elements.fin.value = derniere.slice(0, 10);
        f.elements.fin_type.value = derniere.slice(-1);

        const parCle = new Map(creneaux().map((e) => [e.dataset.cle, e]));
        const libelle = (cle) => parCle.get(cle)?.dataset.label ?? cle;
        const cles = [];
        for (let k = rangCle(premiere); k <= rangCle(derniere); k++) {
            const jour = new Date(Math.floor(k / 2) * 86400000).toISOString().slice(0, 10);
            cles.push(`${jour}-${k % 2 ? 'N' : 'J'}`);
        }
        f.querySelector('[data-periode]').textContent = premiere === derniere ? libelle(premiere) : `Du ${libelle(premiere)} au ${libelle(derniere)}`;
        f.querySelector('[data-compte]').textContent = compte(cles);

        f.querySelector('[data-methode]').disabled = edition === null;
        f.setAttribute('action', edition ? edition.dataset.action : f.dataset.actionCreation);
        f.querySelector('[data-titre]').textContent = edition ? "Modifier l'attribution" : 'Nouvelle attribution';
        popupAttr.querySelector('[data-valider]').textContent = edition ? 'Enregistrer' : 'Attribuer';
        const dates = f.querySelector('[data-dates]');
        dates.hidden = edition === null;
        dates.open = false;
        const retrait = popupAttr.querySelector('[data-zone-retrait]');
        retrait.hidden = edition === null;
        retrait.open = false;
        if (edition) {
            retrait.querySelector('[data-form-retrait]').setAttribute('action', edition.dataset.action);
            const choix = f.querySelector(`input[name=lignee_id][value="${edition.dataset.lignee}"]`);
            if (choix) {
                choix.checked = true;
            }
            marquer(cles.map((c) => parCle.get(c)).filter(Boolean));
        }

        popupAttr.showModal();
        (f.querySelector('input[name=lignee_id]:checked') ?? f.querySelector('input[name=lignee_id]'))?.focus();
    };

    const fermerPopup = () => {
        [popup, popupAttr].forEach((p) => p?.open && p.close());
    };
    [popup, popupAttr].filter(Boolean).forEach((p) => {
        p.addEventListener('close', toutEffacer);
        p.querySelector('[data-fermer]').addEventListener('click', fermerPopup);
        // Clic hors de la pop-up (sur le fond) : on ferme.
        p.addEventListener('click', (e) => {
            if (e.target === p) {
                fermerPopup();
            }
        });
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
        if (el.dataset.dispo === 'attribution') {
            if (debut === null) {
                toutEffacer();
                ouvrirAttribution([el], el);
            } else {
                message('Ce créneau est déjà attribué. Choisissez une fin en attente d\'attribution.');
            }
            return;
        }
        if (el.dataset.dispo === 'mienne') {
            if (debut === null) {
                editer(el);
            } else {
                message('Ce créneau fait déjà partie de votre réservation. Choisissez une fin libre.');
            }
            return;
        }
        if (el.dataset.dispo !== 'non' && debut !== null && el.dataset.dispo !== sorte) {
            message(sorte === 'attribuable'
                ? "Ce créneau n'est pas en attente d'attribution. Choisissez une fin dans les mêmes vacances."
                : RAISONS[el.dataset.etat] ?? 'Choisissez une fin libre.');
            return;
        }
        if (el.dataset.dispo === 'non') {
            // Une réservation d'un autre membre : on montre son détail (utile au toucher, sans survol).
            if (el.dataset.qui) {
                infobulle.montrer(el);
            } else {
                message(RAISONS[el.dataset.etat] ?? 'Ce créneau ne peut plus être réservé (date passée).');
            }
            return;
        }
        const k = rang(el);
        if (debut === null) {
            debut = k;
            sorte = el.dataset.dispo;
            marquer([el]);
            ouvrirBarre(el);
            return;
        }
        if (k === debut) {
            toutEffacer();
            return;
        }
        const periode = entre(debut, k);
        if (periode.some((e) => e.dataset.dispo !== sorte)) {
            message(sorte === 'attribuable'
                ? "La période contient un créneau qui n'est pas en attente d'attribution. Choisissez une autre fin."
                : 'La période contient un créneau déjà réservé ou indisponible. Choisissez une autre fin.');
            return;
        }
        marquer(periode);
        debut = null;
        if (sorte === 'attribuable') {
            ouvrirAttribution(periode);
        } else {
            ouvrirPopup(periode);
        }
    };

    document.addEventListener('click', (e) => {
        const el = e.target.closest?.('[data-rang]');
        if (el && !unePopupOuverte()) {
            choisir(el);
        }
    });
    document.addEventListener('keydown', (e) => {
        const el = e.target.closest?.('[data-rang]');
        if (el && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            choisir(el);
        } else if (e.key === 'Escape' && !unePopupOuverte()) {
            toutEffacer();
        }
    });


    // Refus du serveur : on resélectionne la période envoyée et on rouvre la pop-up avec la raison.
    if (popup?.dataset.anciens) {
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
