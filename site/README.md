# site/ — Pages statiques pour validation Google OAuth

Contenu du sous-site **`slashbox.fr/slashbooking/`**. Trois pages HTML statiques sans dépendance externe, conçues pour satisfaire la review « OAuth consent screen » de Google (homepage + privacy policy URLs).

## Structure

```
site/
├── index.html        — Page d'accueil de présentation + bouton téléchargement
├── privacy.html      — Politique de confidentialité (compliant Google API Services User Data Policy / Limited Use)
├── terms.html        — Conditions d'utilisation
├── assets/
│   ├── style.css     — CSS partagé (autonome, pas de webfonts)
│   ├── logo.png      — Logo PNG 256×256
│   └── logo.svg      — Logo SVG vectoriel
└── README.md         — Ce fichier (à exclure du déploiement)
```

## Déploiement sur slashbox.fr/slashbooking/

Le sous-chemin est gérable de plusieurs façons selon ton infra :

### Option 1 — Plesk / cPanel : un dossier physique

1. Sur le serveur, créer le dossier `/<docroot>/slashbooking/`
2. Uploader le contenu de `site/` (sans `README.md`) à l'intérieur
3. Vérifier que `slashbox.fr/slashbooking/` répond avec la home
4. URLs finales :
   - `https://slashbox.fr/slashbooking/`
   - `https://slashbox.fr/slashbooking/privacy.html`
   - `https://slashbox.fr/slashbooking/terms.html`

### Option 2 — Nginx : un location bloc dédié

```nginx
location /slashbooking/ {
    alias /var/www/slashbox-slashbooking/;
    index index.html;
    try_files $uri $uri/ =404;
}
```

### Option 3 — URLs propres (sans `.html`)

Si tu veux des URLs plus jolies (`/slashbooking/privacy` au lieu de `/slashbooking/privacy.html`), ajoute des règles de rewrite :

```nginx
location /slashbooking/privacy { try_files /slashbooking/privacy.html =404; }
location /slashbooking/terms   { try_files /slashbooking/terms.html =404; }
```

ou un `.htaccess` Apache :

```apache
RewriteEngine On
RewriteRule ^slashbooking/privacy$ /slashbooking/privacy.html [L]
RewriteRule ^slashbooking/terms$   /slashbooking/terms.html   [L]
```

Dans ce cas, pense à mettre à jour les liens `href` dans les 3 HTML (chercher `privacy.html` et `terms.html`).

## Téléchargement du ZIP

La page d'accueil pointe vers `download/slashbooking-latest.zip` (relatif). Tu as deux options :

- **Lien direct** : créer un dossier `site/download/` et y copier `build/slashbooking-1.0.9.zip` renommé en `slashbooking-latest.zip`. Le mettre à jour à chaque release.
- **Redirection** : ajouter un rewrite `/slashbooking/download/slashbooking-latest.zip → URL GitHub Releases` quand tu publieras sur GitHub.

## Configuration Google OAuth Consent Screen

Une fois les pages déployées, dans **Google Cloud Console → APIs & Services → OAuth consent screen** :

| Champ | Valeur |
|---|---|
| **App name** | `SlashBooking` |
| **User support email** | ton e-mail |
| **App logo** | uploader `site/assets/logo.png` (256×256, ≤ 1 MB ✓) |
| **Application home page** | `https://slashbox.fr/slashbooking/` |
| **Application privacy policy link** | `https://slashbox.fr/slashbooking/privacy.html` |
| **Application terms of service link** | `https://slashbox.fr/slashbooking/terms.html` |
| **Authorized domains** | `slashbox.fr` |
| **Scopes** | `auth/calendar.events` + `auth/calendar.readonly` |

Pour la review en mode Production avec les scopes Calendar sensibles, Google demande typiquement :

- Une vidéo YouTube (publique ou unlisted) montrant le flow de connexion OAuth dans l'app et l'usage concret des données.
- Une justification écrite de l'usage des scopes (= section 4 de `privacy.html`).
- La vérification que la page d'accueil et la politique de confidentialité sont accessibles, en français/anglais.

## Vérifier le rendu localement

```bash
cd site
python3 -m http.server 8000
# Ouvrir http://localhost:8000/
```

Les liens relatifs fonctionnent sans modification.

## Maintenance

- La date « Dernière mise à jour » figure en haut de `privacy.html` et `terms.html` — penser à la mettre à jour si tu modifies le contenu juridique.
- Le contact e-mail (`contact@slashbox.fr`) apparaît 4× au total — search/replace si tu changes d'adresse.
- La version mentionnée nulle part dans les pages → pas besoin de toucher quoi que ce soit à chaque release du plugin.
