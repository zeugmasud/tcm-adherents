/**
 * CTA « Espace adhérent » + modale de connexion (site public).
 * Injecte le CTA dans le menu principal ; si non connecté, ouvre la modale de
 * connexion (login AJAX) au lieu d'aller sur wp-login.php.
 */
(function () {
	var C = window.TCM_LOGIN || {};

	// Ne rien faire dans le back-office (shell).
	if (document.body.classList.contains('tcm-crm-shell')) { return; }

	var dash = C.dashboard || '/tableau-de-bord/';
	// wp_localize_script sérialise en chaîne : "0" (déconnecté) est truthy en JS.
	var isLogged = (C.loggedIn === true || C.loggedIn === 1 || C.loggedIn === '1');

	// Le bouton « Connexion » est géré directement dans le menu du site.
	// Ici on se contente d'intercepter les clics vers le tableau de bord pour
	// ouvrir la modale de connexion quand le visiteur n'est pas connecté.

	// Modale
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

	// 2. Clic sur le CTA (ou tout lien vers le tableau de bord).
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a[href*="/tableau-de-bord"]');
		if (!a || a.closest('.tcm-login-box')) { return; }
		if (isLogged) { return; } // connecté : navigation normale vers le dashboard
		e.preventDefault();
		openModal();
	});

	// 3. Modale : fermeture + soumission AJAX.
	if (modal) {
		modal.addEventListener('click', function (e) {
			if (e.target === modal || e.target.closest('.tcm-login-close')) { closeModal(); }
		});
		var form = modal.querySelector('form');
		if (form) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var err = modal.querySelector('.tcm-login-err');
				err.textContent = '';
				var btn = form.querySelector('button[type="submit"]');
				btn.disabled = true;
				var data = new URLSearchParams();
				data.set('action', 'tcm_login');
				data.set('nonce', C.nonce || '');
				data.set('log', form.log.value);
				data.set('pwd', form.pwd.value);
				if (form.remember && form.remember.checked) { data.set('remember', '1'); }
				fetch(C.ajax, {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: data.toString()
				})
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j && j.success) {
							window.location.href = (j.data && j.data.redirect) || dash;
						} else {
							err.textContent = (j && j.data && j.data.message) || 'Connexion impossible.';
							btn.disabled = false;
						}
					})
					.catch(function () { err.textContent = 'Erreur réseau, réessayez.'; btn.disabled = false; });
			});
		}
	}

	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });
})();
