import apiFetch from '@wordpress/api-fetch';

export function setupApi() {
	if ( window.TrinityBooking?.nonce ) {
		apiFetch.use(
			apiFetch.createNonceMiddleware( window.TrinityBooking.nonce )
		);
	}
	if ( window.TrinityBooking?.restUrl ) {
		apiFetch.use(
			apiFetch.createRootURLMiddleware(
				window.TrinityBooking.restUrl + '/'
			)
		);
	}
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
