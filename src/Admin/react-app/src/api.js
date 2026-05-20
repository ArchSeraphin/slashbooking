import apiFetch from '@wordpress/api-fetch';

const NAMESPACE = 'trinity-booking/v1/';

export function setupApi() {
	if ( window.TrinityBooking?.nonce ) {
		apiFetch.use(
			apiFetch.createNonceMiddleware( window.TrinityBooking.nonce )
		);
	}
	// Prefix every path with our REST namespace so WordPress's default
	// rootURL middleware builds wp-json/trinity-booking/v1/<path> correctly.
	// We do NOT override the rootURL itself — last-added middleware would
	// run first, then WP's own rootURL middleware would overwrite the URL
	// using the bare path, producing wp-json/<path> and a 404.
	apiFetch.use( ( options, next ) => {
		if ( typeof options.path === 'string' ) {
			const clean = options.path.replace( /^\//, '' );
			if ( ! clean.startsWith( NAMESPACE ) ) {
				return next( { ...options, path: NAMESPACE + clean } );
			}
		}
		return next( options );
	} );
}

export async function listBookings( params = {} ) {
	const qs = new URLSearchParams( params ).toString();
	return apiFetch( { path: 'admin/bookings' + ( qs ? '?' + qs : '' ) } );
}

export async function actBooking( id, action ) {
	return apiFetch( {
		path: `admin/bookings/${ id }/${ action }`,
		method: 'POST',
	} );
}

export async function fetchGoogleStatus() {
	return apiFetch( { path: 'admin/google/status' } );
}

export async function startGoogleOAuth() {
	return apiFetch( {
		path: 'admin/google/oauth/start',
		method: 'POST',
	} );
}

export async function disconnectGoogle() {
	return apiFetch( {
		path: 'admin/google/disconnect',
		method: 'POST',
	} );
}

export async function fetchGoogleSettings() {
	return apiFetch( { path: 'admin/google/settings' } );
}

export async function saveGoogleSettings( { clientId, clientSecret } ) {
	return apiFetch( {
		path: 'admin/google/settings',
		method: 'POST',
		data: { client_id: clientId, client_secret: clientSecret },
	} );
}

export async function fetchSyncLog( {
	page = 1,
	perPage = 50,
	level,
	status,
} = {} ) {
	const params = new URLSearchParams( { page, per_page: perPage } );
	if ( level ) {
		params.set( 'level', level );
	}
	if ( status ) {
		params.set( 'status', status );
	}
	return apiFetch( { path: `admin/sync-log?${ params }` } );
}

export async function fetchGoogleDiagnostics() {
	return apiFetch( { path: 'admin/google/diagnostics' } );
}

export async function startWatch() {
	return apiFetch( {
		path: 'admin/google/watch/start',
		method: 'POST',
	} );
}

export async function stopWatch() {
	return apiFetch( {
		path: 'admin/google/watch/stop',
		method: 'POST',
	} );
}

export async function forcePullNow() {
	return apiFetch( {
		path: 'admin/google/pull/now',
		method: 'POST',
	} );
}

// --- Plan 5 : templates editor ---

export async function listMailTemplates() {
	return apiFetch( { path: 'admin/mail-templates' } );
}

export async function fetchMailTemplate( eventKey ) {
	return apiFetch( { path: `admin/mail-templates/${ eventKey }` } );
}

export async function saveMailTemplate( eventKey, { subject, htmlBody, textBody, enabled } ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }`,
		method: 'POST',
		data: {
			subject,
			html_body: htmlBody,
			text_body: textBody,
			enabled,
		},
	} );
}

export async function restoreMailTemplate( eventKey ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }`,
		method: 'DELETE',
	} );
}

export async function previewMailTemplate( eventKey, { subject, htmlBody } ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }/preview`,
		method: 'POST',
		data: { subject, html_body: htmlBody },
	} );
}

export async function sendTestMailTemplate( eventKey, { subject, htmlBody } ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }/test`,
		method: 'POST',
		data: { subject, html_body: htmlBody },
	} );
}

export async function listTags() {
	return apiFetch( { path: 'admin/tags' } );
}

// --- Plan 5 : settings (legal page, retention) ---

export async function fetchSettings() {
	return apiFetch( { path: 'admin/settings' } );
}

export async function saveSettings( { legalPageId, bookingRetentionDays } ) {
	return apiFetch( {
		path: 'admin/settings',
		method: 'POST',
		data: {
			legal_page_id: legalPageId,
			booking_retention_days: bookingRetentionDays,
		},
	} );
}

// --- Plan 5+ : services CRUD ---

export async function listServices() {
	return apiFetch( { path: 'admin/services' } );
}

export async function fetchService( slug ) {
	return apiFetch( { path: `admin/services/${ slug }` } );
}

export async function saveService( slug, data ) {
	return apiFetch( {
		path: `admin/services/${ slug }`,
		method: 'POST',
		data,
	} );
}
