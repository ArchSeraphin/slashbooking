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

## ⚠️ Syntaxe des tags : **DOUBLE accolades**

SlashBooking utilise `{{tag_name}}` (double accolades) — pas `{tag_name}`. Le `TemplateRenderer` (`src/Notifications/TemplateRenderer.php`) ignore les tags à simple accolade.

```html
✅ Bonjour {{customer_name}} !
❌ Bonjour {customer_name} !   <!-- ne sera PAS remplacé -->
```

## Tags SlashBooking disponibles

Cf. `src/Notifications/TagRegistry.php` (source de vérité).

| Catégorie | Tag | Description |
|---|---|---|
| Client | `{{customer_name}}` | Nom du client |
| Client | `{{customer_email}}` | E-mail du client |
| Client | `{{customer_phone}}` | Téléphone |
| Client | `{{customer_address}}` | Adresse |
| RDV | `{{service_name}}` | Nom du service (ex: "Photovoltaïque") |
| RDV | `{{service_duration}}` | Durée (ex: "1h30") |
| RDV | `{{appointment_date}}` | Date locale longue |
| RDV | `{{appointment_time}}` | Heure de début (HH:mm) |
| RDV | `{{appointment_end}}` | Heure de fin (HH:mm) |
| RDV | `{{timezone}}` | Fuseau |
| RDV | `{{notes}}` | Notes du client |
| Actions | `{{confirm_url}}` | URL HMAC 72h de confirmation (admin) |
| Actions | `{{reject_url}}` | URL HMAC 72h de refus (admin) |
| Actions | `{{cancel_url}}` | URL HMAC d'annulation (client) |
| Actions | `{{ics_url}}` | URL téléchargement .ics |
| Site | `{{site_name}}`, `{{site_url}}` | Identité site |
| Site | `{{admin_email}}` | E-mail admin |
| Site | `{{company_logo}}` | Balise `<img>` du logo (option `sb_company_logo`) |
| Site | `{{company_phone}}` | Téléphone société (option `sb_company_phone`) |

## Mapping depuis la syntaxe d'autres plugins

| Placeholder externe | SlashBooking (double accolades !) |
|---|---|
| `[bookingtype]` | `{{service_name}}` |
| `[dates]` | `{{appointment_date}} de {{appointment_time}} à {{appointment_end}}` |
| `[content]` | bloc HTML composé de `{{customer_name}}` + `{{customer_email}}` + `{{customer_phone}}` + `{{customer_address}}` + `{{notes}}` |
| `[moderatelink]` | deux CTA distincts : `{{confirm_url}}` et `{{reject_url}}` (validation 1-clic HMAC) |
| `[siteurl]` | `{{site_url}}` |
| `[firstname]` / `[lastname]` | inclus dans `{{customer_name}}` (pas de séparation prénom/nom en V1) |

## Fichiers présents — kit complet A2C Énergies

| Fichier | Événement | Sujet suggéré |
|---|---|---|
| `booking.pending.client.html`   | `booking.pending.client`   | Nous avons bien reçu votre demande — A2C Énergies |
| `booking.pending.admin.html`    | `booking.pending.admin`    | Nouvelle demande de RDV — `{{service_name}}` |
| `booking.confirmed.client.html` | `booking.confirmed.client` | RDV confirmé — `{{appointment_date}}` à `{{appointment_time}}` — A2C Énergies |
| `booking.rejected.client.html`  | `booking.rejected.client`  | Votre demande de RDV n'a pas pu être confirmée — A2C Énergies |
| `booking.cancelled.client.html` | `booking.cancelled.client` | Annulation de votre RDV enregistrée — A2C Énergies |
| `booking.reminder.client.html`  | `booking.reminder.client`  | Rappel : RDV demain à `{{appointment_time}}` — A2C Énergies |
| `New Booking (admin).html`      | — | source originale (non-adaptée), conservée pour référence |

## Cohérence visuelle (design tokens A2C)

| Élément | Valeur |
|---|---|
| Bg page | `#f5f3ee` (beige clair) |
| Bg card | `#ffffff` |
| Bg header | `#1a1a14` (presque noir) |
| Accent principal | `#fcc300` (ambre) |
| Accent succès | `#15803d` (vert) — uniquement `confirmed` |
| Accent neutre | `#6b6a5a` (gris taupe) — `rejected` + `cancelled` |
| Texte titre | Georgia serif, `#1a1a0a`, 26px |
| Texte body | sans-serif système, `#3a3a2a` / `#6b6a5a` |
| Logo | `https://a2cenergies.fr/wp-content/uploads/2026/05/a2c-logo-blanc-couelurs.png` (à remplacer pour un autre client) |
