(function () {
  'use strict';

  // SVG icons (inline so no external requests).
  var ICON_SHIELD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
  var ICON_CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
  var ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 11 12 14 17 8"/></svg>';
  var ICON_ALERT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

  function init(root) {
    var service = root.dataset.tbService;
    var rest = root.dataset.tbRest;
    var nonce = window.TrinityBooking && window.TrinityBooking.nonce;
    var locale = (window.TrinityBooking && window.TrinityBooking.locale) || 'fr-FR';

    var state = { date: null, start: null, submitting: false };

    var stepsEl, dateStep, slotsStep, formStep, feedbackEl;

    render();

    function render() {
      root.innerHTML = '';
      root.append(
        header(),
        steps(),
        stepDate(),
        stepSlots(),
        stepForm(),
        feedback()
      );
      updateSteps();
    }

    function header() {
      var h = el('div', 'tb-widget__header');
      h.append(
        trustItem(ICON_SHIELD, 'Données sécurisées'),
        trustItem(ICON_CLOCK,  'Réponse sous 24 h')
      );
      return h;
    }

    function trustItem(icon, label) {
      var w = el('span', 'tb-widget__trust');
      var i = el('span'); i.innerHTML = icon;
      w.append(i.firstChild, document.createTextNode(label));
      return w;
    }

    function steps() {
      stepsEl = el('div', 'tb-steps');
      stepsEl.setAttribute('role', 'progressbar');
      stepsEl.setAttribute('aria-valuemin', '1');
      stepsEl.setAttribute('aria-valuemax', '3');
      ['Date', 'Heure', 'Coordonnées'].forEach(function (lbl, i) {
        var item = el('div', 'tb-steps__item');
        var dot = el('span', 'tb-steps__dot');
        dot.textContent = String(i + 1);
        var l = el('span', 'tb-steps__label');
        l.textContent = lbl;
        item.append(dot, l);
        stepsEl.append(item);
        if (i < 2) stepsEl.append(el('span', 'tb-steps__line'));
      });
      return stepsEl;
    }

    function currentStepIndex() {
      if (!state.date) return 0;
      if (!state.start) return 1;
      return 2;
    }

    function updateSteps() {
      if (!stepsEl) return;
      var items = stepsEl.querySelectorAll('.tb-steps__item');
      var curr = currentStepIndex();
      items.forEach(function (item, i) {
        item.classList.remove('tb-steps__item--active', 'tb-steps__item--done');
        if (i < curr) item.classList.add('tb-steps__item--done');
        if (i === curr) item.classList.add('tb-steps__item--active');
      });
      stepsEl.setAttribute('aria-valuenow', String(curr + 1));
    }

    function stepDate() {
      dateStep = el('div', 'tb-step');
      dateStep.append(h3('Choisissez une date'));
      dateStep.append(hint('Sélectionnez le jour souhaité pour votre rendez-vous.'));
      var input = el('input', 'tb-date');
      input.type = 'date';
      input.min = todayISO();
      input.setAttribute('aria-label', 'Date du rendez-vous');
      input.addEventListener('change', function () {
        state.date = input.value;
        state.start = null;
        updateSteps();
        loadSlots();
      });
      dateStep.append(input);
      return dateStep;
    }

    function stepSlots() {
      slotsStep = el('div', 'tb-step');
      slotsStep.style.display = 'none';
      slotsStep.append(h3('Choisissez un créneau'));
      slotsStep.append(hint('Les horaires affichés sont en heure locale.'));
      var list = el('div', 'tb-slot-list');
      list.id = 'tb-slot-list';
      list.setAttribute('role', 'list');
      slotsStep.append(list);
      return slotsStep;
    }

    function stepForm() {
      formStep = el('div', 'tb-step');
      formStep.style.display = 'none';
      formStep.append(h3('Vos coordonnées'));
      formStep.append(hint('Nous vous contacterons pour confirmer le rendez-vous.'));

      formStep.append(
        field('customer_name',    'Nom complet',           'text',     true,  'Jean Dupont'),
        field('customer_email',   'E-mail',                'email',    true,  'jean.dupont@exemple.fr'),
        field('customer_phone',   'Téléphone',             'tel',      true,  '06 12 34 56 78'),
        field('customer_address', 'Adresse du rendez-vous','textarea', true,  '15 rue de la République, 75011 Paris'),
        field('notes',            'Notes (optionnel)',     'textarea', false, 'Précisions sur votre projet…')
      );

      // Honeypot — hidden but in form
      var hp = field('website', 'Website', 'text', false, '');
      hp.classList.add('tb-honeypot');
      hp.setAttribute('aria-hidden', 'true');
      hp.querySelector('input').setAttribute('tabindex', '-1');
      hp.querySelector('input').setAttribute('autocomplete', 'off');
      formStep.append(hp);

      // Consent
      var consentWrap = el('div', 'tb-field tb-field--consent');
      var consent = el('input');
      consent.type = 'checkbox';
      consent.id = 'tb-consent';
      consent.name = 'consent';
      consent.required = true;
      var consentLabel = el('label');
      consentLabel.htmlFor = 'tb-consent';
      consentLabel.append(document.createTextNode(
        "J'accepte que mes données soient utilisées pour me recontacter dans le cadre de ma demande de rendez-vous."
      ));

      var legalUrl = (window.TrinityBooking && window.TrinityBooking.legalUrl) || '';
      if (legalUrl) {
        var link = document.createElement('a');
        link.href = legalUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Mentions légales';
        link.className = 'tb-legal-link';
        consentLabel.append(document.createTextNode(' — '));
        consentLabel.append(link);
      }
      consentWrap.append(consent, consentLabel);
      formStep.append(consentWrap);

      var btn = el('button', 'tb-button');
      btn.type = 'button';
      btn.id = 'tb-submit';
      btn.textContent = 'Confirmer la demande';
      btn.addEventListener('click', submit);
      formStep.append(btn);

      return formStep;
    }

    function feedback() {
      feedbackEl = el('div', 'tb-feedback');
      feedbackEl.id = 'tb-feedback';
      feedbackEl.setAttribute('role', 'status');
      feedbackEl.setAttribute('aria-live', 'polite');
      return feedbackEl;
    }

    function loadSlots() {
      slotsStep.style.display = '';
      formStep.style.display = 'none';
      var list = root.querySelector('#tb-slot-list');
      list.textContent = '';
      var loading = el('div', 'tb-slot-empty');
      loading.textContent = 'Chargement des créneaux…';
      list.append(loading);

      var from = state.date;
      var to = addDays(from, 1);
      root.classList.add('tb-loading');
      fetch(rest + 'availability?service=' + encodeURIComponent(service) + '&from=' + from + '&to=' + to, {
        headers: { 'X-WP-Nonce': nonce || '' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          root.classList.remove('tb-loading');
          list.textContent = '';
          if (!data.slots || data.slots.length === 0) {
            var empty = el('div', 'tb-slot-empty');
            empty.textContent = 'Aucun créneau disponible ce jour-là. Essayez une autre date.';
            list.append(empty);
            return;
          }
          data.slots.forEach(function (slot) {
            var b = el('button', 'tb-slot');
            b.type = 'button';
            b.textContent = formatTime(slot.start);
            b.dataset.start = slot.start;
            b.addEventListener('click', function () {
              Array.from(list.children).forEach(function (c) { c.classList.remove('is-selected'); });
              b.classList.add('is-selected');
              state.start = slot.start;
              formStep.style.display = '';
              updateSteps();
              // Smooth-scroll the form into view on mobile.
              setTimeout(function () {
                formStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
              }, 50);
            });
            list.append(b);
          });
        })
        .catch(function () {
          root.classList.remove('tb-loading');
          list.textContent = '';
          showError('Erreur de chargement des créneaux. Réessayez dans un instant.');
        });
    }

    function submit() {
      if (state.submitting) return;
      if (!state.start) { showError('Choisissez un créneau.'); return; }
      var btn = root.querySelector('#tb-submit');
      var data = {
        service: service,
        start: state.start,
        customer_name: formStep.querySelector('[name=customer_name]').value.trim(),
        customer_email: formStep.querySelector('[name=customer_email]').value.trim(),
        customer_phone: formStep.querySelector('[name=customer_phone]').value.trim(),
        customer_address: formStep.querySelector('[name=customer_address]').value.trim(),
        notes: formStep.querySelector('[name=notes]').value.trim(),
        consent: formStep.querySelector('[name=consent]').checked,
        website: formStep.querySelector('[name=website]').value,
      };
      state.submitting = true;
      btn.disabled = true;
      btn.innerHTML = '';
      btn.append(spinner(), document.createTextNode('Envoi en cours…'));
      root.classList.add('tb-loading');

      fetch(rest + 'bookings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce || '',
        },
        body: JSON.stringify(data),
      })
        .then(function (r) {
          return r.json().then(function (body) { return { status: r.status, body: body }; });
        })
        .then(function (res) {
          state.submitting = false;
          root.classList.remove('tb-loading');
          if (res.status >= 200 && res.status < 300) {
            renderSuccess();
            return;
          }
          resetSubmitBtn(btn);
          if (res.status === 422) {
            var errs = (res.body && res.body.data && res.body.data.errors) || {};
            showError(Object.values(errs).join(' ') || 'Veuillez vérifier les champs du formulaire.');
            return;
          }
          if (res.status === 409) {
            showError('Désolé, ce créneau vient d\'être pris. Choisissez-en un autre.');
            loadSlots();
            return;
          }
          showError(res.body && res.body.message ? res.body.message : 'Erreur inattendue. Réessayez.');
        })
        .catch(function () {
          state.submitting = false;
          root.classList.remove('tb-loading');
          resetSubmitBtn(btn);
          showError('Impossible d\'envoyer la demande. Vérifiez votre connexion.');
        });
    }

    function resetSubmitBtn(btn) {
      btn.disabled = false;
      btn.textContent = 'Confirmer la demande';
    }

    function renderSuccess() {
      root.innerHTML = '';
      root.append(header());

      var ok = el('div', 'tb-success');
      var iconWrap = el('span');
      iconWrap.innerHTML = ICON_CHECK;
      var msgWrap = el('div');
      var title = el('strong');
      title.textContent = 'Demande envoyée !';
      var body = el('div');
      body.textContent = 'Merci, nous reviendrons vers vous très vite pour confirmer le rendez-vous. Un e-mail récapitulatif vous a été envoyé.';
      msgWrap.append(title, body);
      ok.append(iconWrap.firstChild, msgWrap);
      root.append(ok);
    }

    function spinner() {
      return el('span', 'tb-button__spinner');
    }

    function showError(msg) {
      feedbackEl.innerHTML = '';
      var e = el('div', 'tb-error');
      var iconWrap = el('span');
      iconWrap.innerHTML = ICON_ALERT;
      var msgEl = el('span');
      msgEl.textContent = msg;
      e.append(iconWrap.firstChild, msgEl);
      feedbackEl.append(e);
      // Move focus to the error for screen readers.
      feedbackEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function field(name, label, type, required, placeholder) {
      var wrap = el('div', 'tb-field');
      var lbl = el('label');
      lbl.htmlFor = 'tb-input-' + name;
      lbl.textContent = label;
      if (required) {
        var star = el('span', 'tb-required');
        star.textContent = '*';
        lbl.append(star);
      }
      var input = type === 'textarea' ? el('textarea') : el('input');
      if (type !== 'textarea') input.type = type;
      input.id = 'tb-input-' + name;
      input.name = name;
      if (required) input.required = true;
      if (placeholder) input.placeholder = placeholder;

      // Semantic input types for mobile keyboards + autocomplete hints.
      if (name === 'customer_email') input.autocomplete = 'email';
      if (name === 'customer_phone') input.autocomplete = 'tel';
      if (name === 'customer_name')  input.autocomplete = 'name';
      if (name === 'customer_address') input.autocomplete = 'street-address';

      wrap.append(lbl, input);
      return wrap;
    }

    function hint(t) {
      var p = el('p', 'tb-step__hint');
      p.textContent = t;
      return p;
    }

    function el(tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; }
    function h3(t) { var n = el('h3'); n.textContent = t; return n; }
    function todayISO() { var d = new Date(); return d.toISOString().slice(0, 10); }
    function addDays(iso, n) { var d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
    function formatTime(iso) {
      try { return new Date(iso).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }); }
      catch (e) { return iso; }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tb-widget').forEach(init);
  });
})();
