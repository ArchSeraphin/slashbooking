# templ/ — Templates HTML adaptés par client

Ce dossier contient les **templates e-mail spécifiques à un client en production**, adaptés depuis des designs existants. **Ces fichiers ne sont PAS embarqués dans le ZIP de release** (`bin/build-release.sh` ne copie que `src/`, `vendor/`, `assets/`, `languages/`).

## Pourquoi pas dans le code ?

Les templates par défaut de SlashBooking sont dans `src/Notifications/DefaultTemplates.php` — ils restent **génériques et neutres** pour être valables sur n'importe quelle installation.

Les designs par client (logo A2C, palette ambre, footer signé "A2C Énergies", etc.) ne doivent **jamais** être hardcodés dans le code source du plugin. Ils sont stockés dans la table `wp_sb_mail_templates` de l'install concernée, via l'éditeur CodeMirror de l'admin SPA.

## Workflow

1. **Recevoir un design HTML d'un client** (ex : maquette créée par leur agence, ou template d'un autre plugin).
2. **Adapter les placeholders** vers la syntaxe SlashBooking (voir mapping ci-dessous), enregistrer le résultat ici sous `<event-key>.html`.
3. **Sur le site du client** : ouvrir **WP Admin → SlashBooking → Templates → [l'événement concerné] → Modifier**.
4. Coller le HTML adapté dans l'éditeur CodeMirror, ajuster le sujet, **Enregistrer**.
5. Optionnel : **Envoyer un test** depuis l'éditeur pour valider le rendu.

## Tags SlashBooking disponibles

Cf. `src/Notifications/TagRegistry.php` (32 lignes, source de vérité).

| Catégorie | Tag | Description |
|---|---|---|
| Client | `{customer_name}` | Nom du client |
| Client | `{customer_email}` | E-mail du client |
| Client | `{customer_phone}` | Téléphone |
| Client | `{customer_address}` | Adresse |
| RDV | `{service_name}` | Nom du service (ex: "Photovoltaïque") |
| RDV | `{service_duration}` | Durée (ex: "1h30") |
| RDV | `{appointment_date}` | Date locale longue |
| RDV | `{appointment_time}` | Heure de début (HH:mm) |
| RDV | `{appointment_end}` | Heure de fin (HH:mm) |
| RDV | `{timezone}` | Fuseau |
| RDV | `{notes}` | Notes du client |
| Actions | `{confirm_url}` | URL HMAC 72h de confirmation (admin) |
| Actions | `{reject_url}` | URL HMAC 72h de refus (admin) |
| Actions | `{cancel_url}` | URL HMAC d'annulation (client) |
| Actions | `{ics_url}` | URL téléchargement .ics |
| Site | `{site_name}`, `{site_url}` | Identité site |
| Site | `{admin_email}` | E-mail admin |
| Site | `{company_logo}` | Balise `<img>` du logo (option `sb_company_logo`) |
| Site | `{company_phone}` | Téléphone société (option `sb_company_phone`) |

## Mapping depuis le syntaxe d'autres plugins

| Placeholder externe | SlashBooking |
|---|---|
| `[bookingtype]` | `{service_name}` |
| `[dates]` | `{appointment_date} de {appointment_time} à {appointment_end}` |
| `[content]` | bloc HTML composé de `{customer_name}` + `{customer_email}` + `{customer_phone}` + `{customer_address}` + `{notes}` |
| `[moderatelink]` | deux CTA distincts : `{confirm_url}` et `{reject_url}` (validation 1-clic HMAC) |
| `[siteurl]` | `{site_url}` |
| `[firstname]` / `[lastname]` | inclus dans `{customer_name}` (pas de séparation prénom/nom en V1) |

## Fichiers présents

| Fichier | Événement | Statut |
|---|---|---|
| `booking.pending.admin.html` | `booking.pending.admin` | ✅ adapté pour A2C Énergies |
| `New Booking (admin).html` | — | source originale, non-adaptée |

## TODO — autres événements à adapter au fur et à mesure

- [ ] `booking.pending.client.html` — accusé de réception client
- [ ] `booking.confirmed.client.html` — confirmation post-validation (avec .ics joint)
- [ ] `booking.rejected.client.html` — refus
- [ ] `booking.cancelled.client.html` — annulation
- [ ] `booking.reminder.client.html` — rappel J-1
