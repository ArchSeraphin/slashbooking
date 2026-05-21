# SlashBooking — Admin SPA

React app for the WordPress admin "SlashBooking" page.

Built with `@wordpress/scripts` (webpack + Babel + ESLint preset).

## Setup

```bash
npm install
```

## Build

```bash
npm run build     # production bundle to assets/dist/
npm run start     # watch mode
npm run lint:js   # ESLint via wp-scripts
```

The PHP enqueue (`src/Admin/Assets.php`) reads `assets/dist/admin.asset.php` for
script dependencies + content-hash version. The bundle is loaded only on the
`SlashBooking` admin page (`toplevel_page_slashbooking`).

## API

A global `window.SlashBooking` is populated by `wp_localize_script` with:

- `restUrl` — base REST URL (`<site>/wp-json/slashbooking/v1`)
- `nonce`   — `wp_create_nonce( 'wp_rest' )`

`src/api.js` wires `@wordpress/api-fetch` to use both via middleware.
