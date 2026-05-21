(function () {
	'use strict';

	// Inline SVG icons (no external requests).
	var ICON_SHIELD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
	var ICON_CLOCK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
	var ICON_CHECK  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 11 12 14 17 8"/></svg>';
	var ICON_ALERT  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
	var ICON_CHEV_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>';
	var ICON_CHEV_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

	var WEEKDAYS = ['Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa', 'Di'];
	var MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

	function init(root) {
		var rest = root.dataset.sbRest;
		var rawServiceAttr = (root.dataset.sbService || '').trim();
		var serviceWhitelist = rawServiceAttr === '' ? [] : rawServiceAttr.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
		var nonce = window.SlashBooking && window.SlashBooking.nonce;
		// WP returns "fr_FR" (underscore); Intl APIs want BCP-47 "fr-FR" (hyphen).
		// Without this conversion, toLocaleTimeString throws RangeError and we fall
		// back to printing the raw ISO string in slot buttons.
		var rawLocale = (window.SlashBooking && window.SlashBooking.locale) || 'fr_FR';
		var locale = rawLocale.replace('_', '-');

		var state = {
			services: [],         // [{slug, name, duration_min, color}, ...]
			service: null,        // selected slug
			date: null,           // ISO 'YYYY-MM-DD'
			start: null,          // ISO datetime
			month: monthStart(new Date()),  // first day of currently displayed month
			dayStates: {},        // { 'YYYY-MM-DD': {state, count} }
			submitting: false,
		};

		var els = {};

		// Decide if we need a project step. If exactly one whitelisted service,
		// preselect it and skip the step.
		bootstrap();

		function bootstrap() {
			if (serviceWhitelist.length === 1) {
				state.service = serviceWhitelist[0];
				// We need duration to display alongside slot lists later; fetch it lazily.
				fetchServices().then(function (list) {
					state.services = list.filter(function (s) { return s.slug === state.service; });
					render();
					loadMonth();
				});
				return;
			}
			fetchServices().then(function (list) {
				if (serviceWhitelist.length > 0) {
					list = list.filter(function (s) { return serviceWhitelist.indexOf(s.slug) !== -1; });
				}
				state.services = list;
				if (list.length === 1) {
					state.service = list[0].slug;
				}
				render();
				if (state.service) {
					loadMonth();
				}
			});
		}

		function fetchServices() {
			return fetch(rest + 'services', { headers: { 'X-WP-Nonce': nonce || '' } })
				.then(function (r) { return r.json(); })
				.catch(function () { return []; });
		}

		// ----- Render -------------------------------------------------------

		function render() {
			root.innerHTML = '';
			root.append(headerEl(), stepsEl());

			if (needsProjectStep()) root.append(projectStepEl());
			root.append(dateStepEl());
			root.append(slotsStepEl());
			root.append(formStepEl());
			root.append(feedbackEl());

			updateSteps();
			refreshCalendar();
		}

		function needsProjectStep() {
			return state.services.length > 1;
		}

		function headerEl() {
			var h = el('div', 'sb-widget__header');
			h.append(
				trustItem(ICON_SHIELD, 'Données sécurisées'),
				trustItem(ICON_CLOCK,  'Réponse sous 24 h')
			);
			return h;
		}

		function trustItem(icon, label) {
			var w = el('span', 'sb-widget__trust');
			var i = el('span'); i.innerHTML = icon;
			w.append(i.firstChild, document.createTextNode(label));
			return w;
		}

		function stepsEl() {
			els.steps = el('div', 'sb-steps');
			els.steps.setAttribute('role', 'progressbar');
			var labels = needsProjectStep()
				? ['Projet', 'Date', 'Heure', 'Coordonnées']
				: ['Date', 'Heure', 'Coordonnées'];
			els.steps.setAttribute('aria-valuemin', '1');
			els.steps.setAttribute('aria-valuemax', String(labels.length));
			labels.forEach(function (lbl, i) {
				var item = el('div', 'sb-steps__item');
				var dot = el('span', 'sb-steps__dot');
				dot.textContent = String(i + 1);
				var l = el('span', 'sb-steps__label');
				l.textContent = lbl;
				item.append(dot, l);
				els.steps.append(item);
				if (i < labels.length - 1) els.steps.append(el('span', 'sb-steps__line'));
			});
			return els.steps;
		}

		function currentStepIndex() {
			var offset = needsProjectStep() ? 1 : 0;
			if (needsProjectStep() && !state.service) return 0;
			if (!state.date)  return 0 + offset;
			if (!state.start) return 1 + offset;
			return 2 + offset;
		}

		function updateSteps() {
			if (!els.steps) return;
			var items = els.steps.querySelectorAll('.sb-steps__item');
			var curr = currentStepIndex();
			items.forEach(function (item, i) {
				item.classList.remove('sb-steps__item--active', 'sb-steps__item--done');
				if (i < curr) item.classList.add('sb-steps__item--done');
				if (i === curr) item.classList.add('sb-steps__item--active');
			});
			els.steps.setAttribute('aria-valuenow', String(curr + 1));
		}

		// ----- Step : Project (service picker) ------------------------------

		function projectStepEl() {
			els.projectStep = el('div', 'sb-step');
			els.projectStep.append(h3('Choix du projet'));
			els.projectStep.append(hint('Sélectionnez la nature du rendez-vous — la durée s\'adapte automatiquement.'));

			var grid = el('div', 'sb-services');
			state.services.forEach(function (svc) {
				var b = el('button', 'sb-service-pill');
				b.type = 'button';
				b.dataset.slug = svc.slug;
				if (state.service === svc.slug) b.classList.add('is-selected');

				var name = el('span', 'sb-service-pill__name');
				name.textContent = svc.name;
				var dur = el('span', 'sb-service-pill__duration');
				dur.textContent = '(' + formatDuration(svc.duration_min) + ')';
				b.append(name, dur);

				b.addEventListener('click', function () {
					Array.from(grid.children).forEach(function (c) { c.classList.remove('is-selected'); });
					b.classList.add('is-selected');
					state.service = svc.slug;
					state.date = null;
					state.start = null;
					state.dayStates = {};
					updateSteps();
					loadMonth();
					// Scroll to date step for context.
					setTimeout(function () {
						if (els.dateStep) els.dateStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}, 60);
				});
				grid.append(b);
			});
			els.projectStep.append(grid);
			return els.projectStep;
		}

		// ----- Step : Date (calendar) ---------------------------------------

		function dateStepEl() {
			els.dateStep = el('div', 'sb-step');
			els.dateStep.append(h3('Choisissez une date'));
			els.dateStep.append(hint('Cliquez sur un jour disponible (vert) pour voir les créneaux.'));

			els.cal = el('div', 'sb-cal');

			// Header : month label + nav
			var header = el('div', 'sb-cal__header');
			els.prevBtn = el('button', 'sb-cal__nav');
			els.prevBtn.type = 'button';
			els.prevBtn.innerHTML = ICON_CHEV_L;
			els.prevBtn.setAttribute('aria-label', 'Mois précédent');
			els.prevBtn.addEventListener('click', function () {
				state.month = addMonths(state.month, -1);
				renderCalendarGrid();
				loadMonth();
			});

			els.nextBtn = el('button', 'sb-cal__nav');
			els.nextBtn.type = 'button';
			els.nextBtn.innerHTML = ICON_CHEV_R;
			els.nextBtn.setAttribute('aria-label', 'Mois suivant');
			els.nextBtn.addEventListener('click', function () {
				state.month = addMonths(state.month, 1);
				renderCalendarGrid();
				loadMonth();
			});

			els.monthLabel = el('div', 'sb-cal__month');

			header.append(els.prevBtn, els.monthLabel, els.nextBtn);

			// Weekday row
			var dows = el('div', 'sb-cal__dows');
			WEEKDAYS.forEach(function (w) {
				var d = el('div', 'sb-cal__dow');
				d.textContent = w;
				dows.append(d);
			});

			// Grid
			els.grid = el('div', 'sb-cal__grid');

			// Legend
			var legend = el('div', 'sb-cal__legend');
			legend.append(
				legendItem('available',  'Disponible'),
				legendItem('partial',    'Partiel'),
				legendItem('full',       'Complet'),
				legendItem('closed',     'Fermé')
			);

			els.cal.append(header, dows, els.grid, legend);
			els.dateStep.append(els.cal);

			renderCalendarGrid();
			return els.dateStep;
		}

		function legendItem(state, label) {
			var w = el('span', 'sb-cal__legend-item');
			var sw = el('span', 'sb-cal__legend-swatch sb-cal-day--' + state);
			var t = el('span'); t.textContent = label;
			w.append(sw, t);
			return w;
		}

		function renderCalendarGrid() {
			if (!els.grid) return;
			els.monthLabel.textContent = MONTHS[state.month.getMonth()] + ' ' + state.month.getFullYear();
			els.grid.textContent = '';

			var firstDay = new Date(state.month);
			var lastDay = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 0);
			// JS weekday: 0 Sun .. 6 Sat. We want Mon=0 .. Sun=6.
			var offset = (firstDay.getDay() + 6) % 7;

			var todayIso = todayISO();
			var horizonIso = addDaysISO(todayIso, 60);
			var leadIso = addDaysISO(todayIso, 1); // min lead time 24h

			// Pad with previous-month blanks
			for (var i = 0; i < offset; i++) {
				els.grid.append(el('div', 'sb-cal-day sb-cal-day--blank'));
			}

			for (var d = 1; d <= lastDay.getDate(); d++) {
				var iso = state.month.getFullYear() + '-' + pad2(state.month.getMonth() + 1) + '-' + pad2(d);
				var btn = el('button', 'sb-cal-day');
				btn.type = 'button';
				btn.dataset.date = iso;
				btn.textContent = String(d);

				if (iso < leadIso || iso > horizonIso) {
					btn.classList.add('sb-cal-day--closed');
					btn.disabled = true;
				} else {
					btn.classList.add('sb-cal-day--loading');
				}
				if (state.date === iso) btn.classList.add('is-selected');

				btn.addEventListener('click', function (ev) {
					var cell = ev.currentTarget;
					var iso = cell.dataset.date;
					if (cell.disabled) return;
					if (cell.classList.contains('sb-cal-day--full') || cell.classList.contains('sb-cal-day--closed')) return;
					Array.from(els.grid.children).forEach(function (c) { c.classList.remove('is-selected'); });
					cell.classList.add('is-selected');
					state.date = iso;
					state.start = null;
					updateSteps();
					loadSlots();
				});

				els.grid.append(btn);
			}
		}

		function refreshCalendar() {
			if (!els.grid) return;
			Array.from(els.grid.querySelectorAll('.sb-cal-day')).forEach(function (cell) {
				if (cell.classList.contains('sb-cal-day--blank') || cell.disabled) return;
				var iso = cell.dataset.date;
				var info = state.dayStates[iso];
				cell.classList.remove('sb-cal-day--loading', 'sb-cal-day--available', 'sb-cal-day--partial', 'sb-cal-day--full');
				if (!info) {
					cell.classList.add('sb-cal-day--loading');
					return;
				}
				cell.classList.add('sb-cal-day--' + info.state);
				if (info.state === 'full' || info.state === 'closed') {
					cell.disabled = true;
				}
			});
		}

		function loadMonth() {
			if (!state.service) return;
			if (!els.grid) return;
			var from = toIso(state.month);
			var to = toIso(new Date(state.month.getFullYear(), state.month.getMonth() + 1, 1));
			state.dayStates = {};
			refreshCalendar();
			fetch(rest + 'availability?service=' + encodeURIComponent(state.service) + '&from=' + from + '&to=' + to, {
				headers: { 'X-WP-Nonce': nonce || '' },
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var byDay = {};
					(data.slots || []).forEach(function (slot) {
						var d = slot.start.slice(0, 10);
						byDay[d] = (byDay[d] || 0) + 1;
					});
					// We don't know the "max" per day, so use a simple heuristic.
					// >=3 slots → available, 1-2 → partial, 0 → full.
					var totals = Object.values(byDay);
					var p75 = percentile(totals, 75) || 1;
					Object.keys(byDay).forEach(function (d) {
						var c = byDay[d];
						var st = c >= Math.max(3, p75 * 0.6) ? 'available'
							:    c >= 1 ? 'partial' : 'full';
						state.dayStates[d] = { state: st, count: c };
					});
					// Mark days with no slots (in horizon) as full.
					Array.from(els.grid.querySelectorAll('.sb-cal-day')).forEach(function (cell) {
						if (cell.classList.contains('sb-cal-day--blank') || cell.disabled) return;
						var iso = cell.dataset.date;
						if (!state.dayStates[iso]) {
							state.dayStates[iso] = { state: 'full', count: 0 };
						}
					});
					refreshCalendar();
				})
				.catch(function () {
					// fallback : leave loading state
				});
		}

		// ----- Step : Slots -------------------------------------------------

		function slotsStepEl() {
			els.slotsStep = el('div', 'sb-step');
			els.slotsStep.style.display = 'none';
			els.slotsStep.append(h3('Choisissez un créneau'));
			els.slotsStep.append(hint('Les horaires sont affichés en heure locale.'));
			els.slotList = el('div', 'sb-slot-list');
			els.slotList.setAttribute('role', 'list');
			els.slotsStep.append(els.slotList);
			return els.slotsStep;
		}

		function loadSlots() {
			els.slotsStep.style.display = '';
			els.formStep.style.display = 'none';
			els.slotList.textContent = '';
			var loading = el('div', 'sb-slot-empty');
			loading.textContent = 'Chargement des créneaux…';
			els.slotList.append(loading);

			var from = state.date;
			var to = addDaysISO(from, 1);
			root.classList.add('sb-loading');
			fetch(rest + 'availability?service=' + encodeURIComponent(state.service) + '&from=' + from + '&to=' + to, {
				headers: { 'X-WP-Nonce': nonce || '' },
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					root.classList.remove('sb-loading');
					els.slotList.textContent = '';
					if (!data.slots || data.slots.length === 0) {
						var empty = el('div', 'sb-slot-empty');
						empty.textContent = 'Aucun créneau disponible ce jour-là. Essayez une autre date.';
						els.slotList.append(empty);
						return;
					}
					data.slots.forEach(function (slot) {
						var b = el('button', 'sb-slot');
						b.type = 'button';
						b.textContent = formatTime(slot.start);
						b.dataset.start = slot.start;
						b.addEventListener('click', function () {
							Array.from(els.slotList.children).forEach(function (c) { c.classList.remove('is-selected'); });
							b.classList.add('is-selected');
							state.start = slot.start;
							els.formStep.style.display = '';
							updateSteps();
							setTimeout(function () {
								els.formStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
							}, 50);
						});
						els.slotList.append(b);
					});
				})
				.catch(function () {
					root.classList.remove('sb-loading');
					els.slotList.textContent = '';
					showError('Erreur de chargement des créneaux. Réessayez dans un instant.');
				});
		}

		// ----- Step : Form --------------------------------------------------

		function formStepEl() {
			els.formStep = el('div', 'sb-step');
			els.formStep.style.display = 'none';
			els.formStep.append(h3('Vos coordonnées'));
			els.formStep.append(hint('Nous vous contacterons pour confirmer le rendez-vous.'));

			els.formStep.append(
				field('customer_name',    'Nom complet',           'text',     true,  'Jean Dupont'),
				field('customer_email',   'E-mail',                'email',    true,  'jean.dupont@exemple.fr'),
				field('customer_phone',   'Téléphone',             'tel',      true,  '06 12 34 56 78'),
				field('customer_address', 'Adresse du rendez-vous','textarea', true,  '15 rue de la République, 75011 Paris'),
				field('notes',            'Notes (optionnel)',     'textarea', false, 'Précisions sur votre projet…')
			);

			var hp = field('website', 'Website', 'text', false, '');
			hp.classList.add('sb-honeypot');
			hp.setAttribute('aria-hidden', 'true');
			hp.querySelector('input').setAttribute('tabindex', '-1');
			hp.querySelector('input').setAttribute('autocomplete', 'off');
			els.formStep.append(hp);

			var consentWrap = el('div', 'sb-field sb-field--consent');
			var consent = el('input');
			consent.type = 'checkbox';
			consent.id = 'sb-consent';
			consent.name = 'consent';
			consent.required = true;
			var consentLabel = el('label');
			consentLabel.htmlFor = 'sb-consent';
			consentLabel.append(document.createTextNode(
				"J'accepte que mes données soient utilisées pour me recontacter dans le cadre de ma demande de rendez-vous."
			));

			var legalUrl = (window.SlashBooking && window.SlashBooking.legalUrl) || '';
			if (legalUrl) {
				var link = document.createElement('a');
				link.href = legalUrl;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = 'Mentions légales';
				link.className = 'sb-legal-link';
				consentLabel.append(document.createTextNode(' — '));
				consentLabel.append(link);
			}
			consentWrap.append(consent, consentLabel);
			els.formStep.append(consentWrap);

			// Cloudflare Turnstile widget — rendered only if a site key is configured.
			var turnstileKey = (window.SlashBooking && window.SlashBooking.turnstileSiteKey) || '';
			if (turnstileKey) {
				els.turnstileWrap = el('div', 'sb-turnstile');
				els.formStep.append(els.turnstileWrap);
				renderTurnstile(turnstileKey);
			}

			// Disclaimer text — shown above the submit button, configurable in admin.
			var disclaimer = (window.SlashBooking && window.SlashBooking.disclaimer) || '';
			if (disclaimer) {
				els.disclaimer = el('p', 'sb-form-disclaimer');
				els.disclaimer.textContent = disclaimer;
				els.formStep.append(els.disclaimer);
			}

			els.submitBtn = el('button', 'sb-button');
			els.submitBtn.type = 'button';
			els.submitBtn.textContent = 'Confirmer la demande';
			els.submitBtn.addEventListener('click', submit);
			els.formStep.append(els.submitBtn);

			return els.formStep;
		}

		function renderTurnstile(sitekey) {
			// Poll for the Turnstile API to be ready (the api.js loads `?render=explicit`).
			var tries = 0;
			var maxTries = 50; // ~5s at 100ms
			var interval = setInterval(function () {
				tries++;
				if (window.turnstile && typeof window.turnstile.render === 'function') {
					clearInterval(interval);
					try {
						els.turnstileWidgetId = window.turnstile.render(els.turnstileWrap, {
							sitekey: sitekey,
							callback: function (token) { state.turnstileToken = token; },
							'expired-callback': function () { state.turnstileToken = ''; },
							'error-callback':   function () { state.turnstileToken = ''; },
						});
					} catch (e) {
						// Bad sitekey or DOM removed — silent; submit will fail server-side.
					}
				} else if (tries >= maxTries) {
					clearInterval(interval);
				}
			}, 100);
		}

		function feedbackEl() {
			els.feedback = el('div', 'sb-feedback');
			els.feedback.setAttribute('role', 'status');
			els.feedback.setAttribute('aria-live', 'polite');
			return els.feedback;
		}

		function submit() {
			if (state.submitting) return;
			if (!state.start) { showError('Choisissez un créneau.'); return; }

			var turnstileKey = (window.SlashBooking && window.SlashBooking.turnstileSiteKey) || '';
			if (turnstileKey && !state.turnstileToken) {
				showError('Veuillez compléter la vérification anti-robot.');
				return;
			}

			var data = {
				service: state.service,
				start: state.start,
				customer_name: els.formStep.querySelector('[name=customer_name]').value.trim(),
				customer_email: els.formStep.querySelector('[name=customer_email]').value.trim(),
				customer_phone: els.formStep.querySelector('[name=customer_phone]').value.trim(),
				customer_address: els.formStep.querySelector('[name=customer_address]').value.trim(),
				notes: els.formStep.querySelector('[name=notes]').value.trim(),
				consent: els.formStep.querySelector('[name=consent]').checked,
				website: els.formStep.querySelector('[name=website]').value,
				cf_turnstile_response: state.turnstileToken || '',
			};
			state.submitting = true;
			els.submitBtn.disabled = true;
			els.submitBtn.innerHTML = '';
			els.submitBtn.append(el('span', 'sb-button__spinner'), document.createTextNode('Envoi en cours…'));
			root.classList.add('sb-loading');

			fetch(rest + 'bookings', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce || '',
				},
				body: JSON.stringify(data),
			})
				.then(function (r) { return r.json().then(function (body) { return { status: r.status, body: body }; }); })
				.then(function (res) {
					state.submitting = false;
					root.classList.remove('sb-loading');
					if (res.status >= 200 && res.status < 300) {
						renderSuccess();
						return;
					}
					resetSubmit();
					// Turnstile tokens are single-use — reset the widget so user can re-validate.
					if (window.turnstile && els.turnstileWidgetId !== undefined) {
						try { window.turnstile.reset(els.turnstileWidgetId); } catch (e) { /* noop */ }
						state.turnstileToken = '';
					}
					if (res.status === 422) {
						var errs = (res.body && res.body.data && res.body.data.errors) || {};
						showError(Object.values(errs).join(' ') || 'Veuillez vérifier les champs du formulaire.');
						return;
					}
					if (res.status === 409) {
						showError("Désolé, ce créneau vient d'être pris. Choisissez-en un autre.");
						loadSlots();
						loadMonth();
						return;
					}
					if (res.body && res.body.code === 'sb_captcha_failed') {
						showError('Vérification anti-robot échouée. Merci de la refaire.');
						return;
					}
					showError(res.body && res.body.message ? res.body.message : 'Erreur inattendue. Réessayez.');
				})
				.catch(function () {
					state.submitting = false;
					root.classList.remove('sb-loading');
					resetSubmit();
					showError("Impossible d'envoyer la demande. Vérifiez votre connexion.");
				});
		}

		function resetSubmit() {
			els.submitBtn.disabled = false;
			els.submitBtn.textContent = 'Confirmer la demande';
		}

		function renderSuccess() {
			root.innerHTML = '';
			root.append(headerEl());
			var ok = el('div', 'sb-success');
			var iconWrap = el('span'); iconWrap.innerHTML = ICON_CHECK;
			var msgWrap = el('div');
			var title = el('strong'); title.textContent = 'Demande envoyée !';
			var body = el('div'); body.textContent = 'Merci, nous reviendrons vers vous très vite pour confirmer le rendez-vous. Un e-mail récapitulatif vous a été envoyé.';
			msgWrap.append(title, body);
			ok.append(iconWrap.firstChild, msgWrap);
			root.append(ok);
		}

		function showError(msg) {
			els.feedback.innerHTML = '';
			var e = el('div', 'sb-error');
			var iconWrap = el('span'); iconWrap.innerHTML = ICON_ALERT;
			var msgEl = el('span'); msgEl.textContent = msg;
			e.append(iconWrap.firstChild, msgEl);
			els.feedback.append(e);
			els.feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		// ----- Helpers ------------------------------------------------------

		function field(name, label, type, required, placeholder) {
			var wrap = el('div', 'sb-field');
			var lbl = el('label');
			lbl.htmlFor = 'sb-input-' + name;
			lbl.textContent = label;
			if (required) {
				var star = el('span', 'sb-required'); star.textContent = '*';
				lbl.append(star);
			}
			var input = type === 'textarea' ? el('textarea') : el('input');
			if (type !== 'textarea') input.type = type;
			input.id = 'sb-input-' + name;
			input.name = name;
			if (required) input.required = true;
			if (placeholder) input.placeholder = placeholder;

			if (name === 'customer_email')   input.autocomplete = 'email';
			if (name === 'customer_phone')   input.autocomplete = 'tel';
			if (name === 'customer_name')    input.autocomplete = 'name';
			if (name === 'customer_address') input.autocomplete = 'street-address';

			wrap.append(lbl, input);
			return wrap;
		}

		function hint(t) { var p = el('p', 'sb-step__hint'); p.textContent = t; return p; }
		function el(tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; }
		function h3(t) { var n = el('h3'); n.textContent = t; return n; }
		function pad2(n) { return n < 10 ? '0' + n : String(n); }
		function todayISO() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
		function addDaysISO(iso, n) { var p = iso.split('-'); var d = new Date(parseInt(p[0],10), parseInt(p[1],10) - 1, parseInt(p[2],10)); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
		function monthStart(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
		function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
		function toIso(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
		function formatTime(iso) {
			try { return new Date(iso).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }); }
			catch (e) { return iso; }
		}
		function formatDuration(min) {
			if (min < 60) return min + ' min';
			var h = Math.floor(min / 60); var m = min % 60;
			return m === 0 ? h + 'h' : h + 'h' + pad2(m);
		}
		function percentile(arr, p) {
			if (!arr.length) return 0;
			var sorted = arr.slice().sort(function (a, b) { return a - b; });
			var idx = Math.floor((p / 100) * sorted.length);
			return sorted[Math.min(idx, sorted.length - 1)];
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.sb-widget').forEach(init);
	});
})();
