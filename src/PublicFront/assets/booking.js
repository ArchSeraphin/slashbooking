(function () {
  'use strict';

  function init(root) {
    var service = root.dataset.tbService;
    var rest = root.dataset.tbRest;
    var nonce = window.TrinityBooking && window.TrinityBooking.nonce;
    var locale = (window.TrinityBooking && window.TrinityBooking.locale) || 'fr-FR';

    var state = { date: null, start: null };

    render();

    function render() {
      root.innerHTML = '';
      root.append(
        stepDate(),
        stepSlots(),
        stepForm(),
        feedback()
      );
    }

    function stepDate() {
      var s = el('div', 'tb-step');
      s.append(h3('1. Choisissez une date'));
      var input = el('input');
      input.type = 'date';
      input.min = todayISO();
      input.addEventListener('change', function () {
        state.date = input.value;
        state.start = null;
        loadSlots();
      });
      s.append(input);
      return s;
    }

    function stepSlots() {
      var s = el('div', 'tb-step');
      s.id = 'tb-slots';
      s.append(h3('2. Choisissez un créneau'));
      var list = el('div', 'tb-slot-list');
      list.id = 'tb-slot-list';
      s.append(list);
      return s;
    }

    function stepForm() {
      var s = el('div', 'tb-step');
      s.id = 'tb-form';
      s.style.display = 'none';
      s.append(h3('3. Vos informations'));
      ['customer_name', 'customer_email', 'customer_phone', 'customer_address'].forEach(function (name) {
        s.append(field(name, labelFor(name), name === 'customer_address' ? 'textarea' : (name === 'customer_email' ? 'email' : 'text')));
      });
      var hp = field('website', 'Website', 'text');
      hp.classList.add('tb-honeypot');
      hp.setAttribute('aria-hidden', 'true');
      s.append(hp);

      var consentWrap = el('label', 'tb-field');
      var consent = el('input');
      consent.type = 'checkbox';
      consent.name = 'consent';
      consent.required = true;
      consentWrap.append(consent, ' ', document.createTextNode('J’accepte que mes données soient utilisées pour me recontacter.'));
      s.append(consentWrap);

      var btn = el('button', 'tb-button');
      btn.type = 'button';
      btn.textContent = 'Réserver';
      btn.addEventListener('click', submit);
      s.append(btn);

      return s;
    }

    function feedback() {
      var f = el('div');
      f.id = 'tb-feedback';
      return f;
    }

    function loadSlots() {
      var list = root.querySelector('#tb-slot-list');
      list.textContent = '';
      var from = state.date;
      var to = addDays(from, 1);
      root.classList.add('tb-loading');
      fetch(rest + 'availability?service=' + encodeURIComponent(service) + '&from=' + from + '&to=' + to, {
        headers: { 'X-WP-Nonce': nonce || '' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          root.classList.remove('tb-loading');
          if (!data.slots || data.slots.length === 0) {
            list.append(text('Aucun créneau disponible ce jour-là.'));
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
              root.querySelector('#tb-form').style.display = '';
            });
            list.append(b);
          });
        })
        .catch(function () {
          root.classList.remove('tb-loading');
          showError('Erreur de chargement des créneaux.');
        });
    }

    function submit() {
      if (!state.start) { showError('Choisissez un créneau.'); return; }
      var form = root.querySelector('#tb-form');
      var data = {
        service: service,
        start: state.start,
        customer_name: form.querySelector('[name=customer_name]').value.trim(),
        customer_email: form.querySelector('[name=customer_email]').value.trim(),
        customer_phone: form.querySelector('[name=customer_phone]').value.trim(),
        customer_address: form.querySelector('[name=customer_address]').value.trim(),
        consent: form.querySelector('[name=consent]').checked,
        website: form.querySelector('[name=website]').value,
      };
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
          root.classList.remove('tb-loading');
          if (res.status >= 200 && res.status < 300) {
            root.innerHTML = '';
            var ok = el('div', 'tb-success');
            ok.textContent = 'Demande envoyée ! Nous reviendrons vers vous très vite.';
            root.append(ok);
            return;
          }
          if (res.status === 422) {
            var errs = (res.body && res.body.data && res.body.data.errors) || {};
            showError(Object.values(errs).join(' ') || 'Champs invalides.');
            return;
          }
          if (res.status === 409) {
            showError('Désolé, ce créneau vient d’être pris. Choisissez-en un autre.');
            loadSlots();
            return;
          }
          showError(res.body && res.body.message ? res.body.message : 'Erreur inconnue.');
        })
        .catch(function () {
          root.classList.remove('tb-loading');
          showError('Impossible d’envoyer la demande.');
        });
    }

    function showError(msg) {
      var fb = root.querySelector('#tb-feedback');
      fb.innerHTML = '';
      var e = el('div', 'tb-error');
      e.textContent = msg;
      fb.append(e);
    }

    function field(name, label, type) {
      var wrap = el('label', 'tb-field');
      var lbl = el('span');
      lbl.textContent = label;
      var input = type === 'textarea' ? el('textarea') : el('input');
      if (type !== 'textarea') input.type = type;
      input.name = name;
      input.required = name !== 'website';
      wrap.append(lbl, input);
      return wrap;
    }

    function labelFor(name) {
      return ({
        customer_name: 'Nom complet',
        customer_email: 'E-mail',
        customer_phone: 'Téléphone',
        customer_address: 'Adresse du rendez-vous',
      })[name] || name;
    }

    function el(tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; }
    function h3(t) { var n = el('h3'); n.textContent = t; return n; }
    function text(t) { return document.createTextNode(t); }
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
