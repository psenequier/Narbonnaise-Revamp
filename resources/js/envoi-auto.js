// Une liste marquée data-envoi-auto envoie son formulaire dès qu'on change de choix
// (Statistiques : plus de bouton « Afficher », décision du 7 nov. 2026).
document.addEventListener('change', (e) => {
    const liste = e.target;
    if (liste instanceof HTMLSelectElement && liste.hasAttribute('data-envoi-auto') && liste.form) {
        liste.form.requestSubmit();
    }
});
