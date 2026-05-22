=== SlashBooking ===
Contributors: ArchSeraphin
Tags: booking, appointment, calendar, google-calendar, calendly
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.21
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prise de rendez-vous en ligne style Calendly, synchronisée avec Google Calendar. Pensée pour les commerciaux PV et IRVE, utilisable partout.

== Description ==

**SlashBooking** transforme n'importe quelle page WordPress en tunnel de prise de rendez-vous self-service. Le visiteur choisit un service, voit les créneaux libres en temps réel, et confirme son RDV en moins d'une minute. De l'autre côté, le commercial reçoit l'alerte par e-mail et valide en un clic — sans ouvrir WordPress, sans logiciel tiers, sans abonnement SaaS.

= Pourquoi SlashBooking =

À l'origine conçu pour les métiers de la transition énergétique (photovoltaïque, bornes de recharge), SlashBooking sert aujourd'hui tout artisan, indépendant ou TPE qui cale des RDV de qualification, de devis ou de chantier. C'est un Calendly auto-hébergé, sans frais récurrents, qui vit dans ton WordPress.

= Fonctionnalités principales =

* **Formulaire public via shortcode** — Un simple `[slashbooking]` dans une page suffit. Multi-services, sélecteur de date, créneaux temps-réel, formulaire client, le tout responsive.
* **Synchro bidirectionnelle Google Calendar** — Les RDV pris sur le site apparaissent instantanément dans ton agenda Google. À l'inverse, les événements ajoutés manuellement dans Google bloquent les créneaux côté formulaire. Zéro double-saisie.
* **Validation 1-clic par e-mail** — Chaque demande arrive avec deux boutons *Confirmer* / *Refuser*. Pas besoin de te connecter à WordPress. Les liens sont signés HMAC-SHA256, impossibles à forger.
* **Buffer intelligent autour des RDV** — Configure 30 min de battement (route, prépa) entre les RDV. Appliqué automatiquement, y compris autour des événements externes de ton Google Calendar.
* **Plages horaires multi-créneaux** — Lundi 9h-12h + 14h-18h, mardi matin seulement, mercredi off… Chaque jour et chaque service se règle indépendamment.
* **E-mails brandés et personnalisables** — 5 templates HTML éditables : confirmation client, notif commercial, rappel J-1, confirmation, refus. Pièce jointe `.ics` automatique pour ajout au calendrier en 1 clic.
* **Conformité RGPD intégrée** — Consentement explicite avant soumission, exporters/erasers WP_Privacy, rétention configurable, anonymisation automatique.
* **Mises à jour 1-clic** — Le plugin se connecte à ses propres releases GitHub. WordPress affiche "Mise à jour disponible" comme pour un plugin officiel.

= Sécurité =

* Cloudflare Turnstile optionnel pour bloquer les bots
* Honeypot anti-spam intégré
* Liens de décision signés HMAC-SHA256
* Token Google OAuth chiffré au repos via libsodium
* Rate-limiting par IP sur les endpoints publics

= Pour qui =

* Entreprises PV / IRVE qui veulent éviter le ping-pong téléphonique
* Commerciaux indépendants en quête d'un Calendly auto-hébergé
* Agences WordPress qui packagent un site + tunnel de RDV
* Tout métier où "réserver un créneau" ouvre le parcours client

== Installation ==

1. Téléverse le ZIP via **Extensions → Ajouter → Téléverser une extension** (ou décompresse-le dans `wp-content/plugins/`).
2. Active **SlashBooking** dans la liste des extensions.
3. Configure le plugin depuis le menu **SlashBooking** ajouté à l'admin :
   * **Services** — durée des RDV, buffer avant/après, jours et plages horaires, couleur d'affichage
   * **Google** — assistant guidé de connexion OAuth pour la synchro Calendar
   * **Modèles d'e-mail** — personnalise les 5 templates HTML avec tags dynamiques
   * **Réglages** — Cloudflare Turnstile, couleurs GCal, rétention RGPD
4. Colle `[slashbooking]` dans n'importe quelle page publique pour afficher le formulaire.

Pour la synchro Google Calendar, suis l'assistant de l'onglet **Google** : il te guide pour créer le projet GCP, l'OAuth consent screen, et copier les identifiants. Compte 10 minutes la première fois.

== Frequently Asked Questions ==

= Faut-il un compte Google obligatoirement ? =

Non. Sans connexion Google, le plugin fonctionne en mode autonome : les RDV restent dans WordPress, les e-mails partent normalement. Tu perds la double-direction (les events ajoutés manuellement dans Google ne bloquent plus les créneaux), mais le formulaire et la validation par e-mail fonctionnent à 100%.

= Combien de commerciaux puis-je gérer ? =

La V1 est mono-commercial — un seul Google Calendar connecté à la fois. Le multi-commerciaux et le load-balancing sont sur la roadmap.

= Les e-mails partent comment ? =

Par défaut via `wp_mail()`. Si tu as installé un plugin SMTP (WP Mail SMTP, FluentSMTP, etc.), SlashBooking l'utilise automatiquement — aucune config supplémentaire.

= Comment fonctionnent les mises à jour ? =

À partir de la v1.0.17, le plugin sonde les releases GitHub toutes les 12h. Quand une nouvelle version est publiée, WordPress affiche la notif standard dans **Extensions** avec le bouton **Mettre à jour**. Aucun token, aucune licence, aucun compte à saisir côté client.

= Si Google tombe, le formulaire continue ? =

Oui. Le formulaire enregistre les RDV directement dans WordPress même si l'API Google est indisponible. La synchro reprend automatiquement dès que Google répond, et un cron de réconciliation quotidien rattrape les RDV manqués pour les pousser dans le Calendar.

= Compatible avec WPML ou Polylang ? =

Les chaînes du plugin sont localisables (text domain `slashbooking`). Le frontend du formulaire utilise la locale WP. Aucun problème connu avec WPML/Polylang, mais pas explicitement testé. Remontez les bugs sur le repo si vous en croisez.

= Le plugin survit-il à une mise à jour de WordPress ? =

Oui. Les schémas de tables sont versionnés et migrés automatiquement, les options sont préservées, et le plugin teste sur les 3 versions de PHP actives (8.1, 8.2, 8.3) à chaque release.

== Changelog ==

= 1.0.21 =
*Sorti le 2026-05-22.* **Widget Dashboard** : ajoute un encart dans le tableau de bord WP qui affiche les **réservations en attente** (à valider) et les **RDV confirmés à venir sous 7 jours**. Pour chaque ligne : date+heure, nom client, service. Compteur badge sur chaque section. Lien direct vers la liste complète. Visible par admin + éditeur.

= 1.0.20 =
*Sorti le 2026-05-22.* Le rôle WP **Éditeur** a maintenant accès au menu SlashBooking et à toutes les opérations (gestion des réservations, services, Google, modèles d'e-mail, réglages). Utile quand un commercial ou un assistant gère les RDV sans avoir les droits d'admin technique. Migration automatique pour les installations existantes — pas besoin de désactiver/réactiver.

= 1.0.19 =
*Sorti le 2026-05-22.* Fix défensif sur le parsing de la version : ajout d'un `trim()` via filtre PUC pour les contextes où `_cleanup_header_comment` n'est pas disponible (cron, builds WP exotiques). Le `is_admin()` guard sur le bootstrap est retiré : PUC tourne maintenant dans tous les contextes qui peuvent rafraîchir le transient `update_plugins`.

= 1.0.18 =
*Sorti le 2026-05-22.* Ajout du `readme.txt` au format wp.org pour la page "Afficher les détails" dans wp-admin.

= 1.0.17 =
*Sorti le 2026-05-22.* Fix critique : système de mise à jour PUC opérationnel (le `classmap-authoritative` du build composer bypassait l'init du registre PUC).

= 1.0.16 =
*Sorti le 2026-05-22.* Fix : patcher PHP-Scoper qui préserve les clés string du registre PUC après scoping.

= 1.0.15 =
*Sorti le 2026-05-22.* Mises à jour 1-clic depuis wp-admin via Plugin Update Checker + GitHub Releases. Workflow GitHub Actions qui build et publie une release sur push de tag.

= 1.0.14 =
*Sorti le 2026-05-22.* Buffer symétrique de 30 min autour des événements Google Calendar. Le dernier créneau peut maintenant démarrer à l'heure de fin de plage.

= Versions antérieures =
Voir le [CHANGELOG complet](https://github.com/ArchSeraphin/slashbooking/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 1.0.21 =
Nouveau widget Dashboard pour voir les réservations en attente et à venir en un coup d'œil.

= 1.0.20 =
Le rôle Éditeur a maintenant accès au plugin. Migration automatique des permissions, aucune action requise.

= 1.0.19 =
Fix critique du parsing de version dans le système de mise à jour. Si tu es sur 1.0.17 ou 1.0.18, installe celle-ci manuellement (les mises à jour 1-clic ne détectaient pas la nouvelle version à cause d'un whitespace).

= 1.0.18 =
Ajoute la page de description riche dans wp-admin. Pas de breaking change.

= 1.0.17 =
Fix critique du système de mise à jour. Si tu es sur 1.0.15 ou 1.0.16, installe celle-ci pour débloquer les futures mises à jour 1-clic.
