/**
 * CTA « Espace adhérent » + modale de connexion (site public).
 *
 * Le formulaire de la modale se soumet NATIVEMENT vers wp-login.php (endpoint
 * standard WordPress) : wp-login pose le cookie de session et redirige (302) vers
 * le tableau de bord. On NE pose PAS le cookie depuis le front-office (page
 * d'accueil / REST / admin-ajax) car le plugin de consentement cookies filtre les
 * cookies posés sur le front tant que le consentement n'est pas donné → la session
 * n'était pas reconnue. wp-login.php est épargné par ces plugins.
 */
(function () {
	var C = window.TCM_LOGIN || {};

	// Ne rien faire dans le back-office (shell).
	if (document.body.classList.contains('tcm-crm-shell')) { return; }

	var dash = C.dashboard || '/tableau-de-bord/';
	// wp_localize_script sérialise en chaîne : "0" (déconnecté) est truthy en JS.
	var isLogged = (C.loggedIn === true || C.loggedIn === 1 || C.loggedIn === '1');

	var modal = document.getElementById('tcm-login-modal');

	function openModal() {
		if (!modal) { window.location.href = dash; return; }
		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
		var f = modal.querySelector('[name="log"]');
		if (f) { f.focus(); }
	}
	function closeModal() {
		if (modal) { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); }
	}

	// Clic sur le CTA (ou tout lien vers le tableau de bord) : si déconnecté,
	// ouvrir la modale au lieu de naviguer.
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a[href*="/tableau-de-bord"]');
		if (!a || a.closest('.tcm-login-box')) { return; }
		if (isLogged) { return; } // connecté : navigation normale
		e.preventDefault();
		openModal();
	});

	if (!modal) { return; }

	// Fermeture (clic sur le fond ou la croix, touche Échap).
	modal.addEventListener('click', function (e) {
		if (e.target === modal || e.target.closest('.tcm-login-close')) { closeModal(); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });

	// wp-login.php attend normalement un cookie de test posé au chargement de sa
	// page. Comme la modale poste en direct, on le pose nous-mêmes juste avant.
	var form = modal.querySelector('form');
	if (form) {
		form.addEventListener('submit', function () {
			document.cookie = 'wordpress_test_cookie=WP Cookie check; path=/';
		});
	}
})();
