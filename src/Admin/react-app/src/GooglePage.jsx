import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchGoogleStatus, startGoogleOAuth, disconnectGoogle } from './api';

export default function GooglePage() {
	const [ status, setStatus ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const reload = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await fetchGoogleStatus();
			setStatus( data );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		reload();
	}, [] );

	const connect = async () => {
		try {
			const { auth_url: authUrl } = await startGoogleOAuth();
			window.location.href = authUrl;
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	const disconnect = async () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__( 'Vraiment déconnecter ce compte ?', 'trinity-booking' )
			)
		) {
			return;
		}
		try {
			await disconnectGoogle();
			await reload();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Google Calendar', 'trinity-booking' ) }</h2>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ status?.connected ? (
					<>
						<p>
							<strong>
								{ __( 'Connecté', 'trinity-booking' ) } ✓
							</strong>
							<br />
							{ __( 'Calendrier :', 'trinity-booking' ) }{ ' ' }
							<code>{ status.calendar_id }</code>
							<br />
							{ __( 'Token expire :', 'trinity-booking' ) }{ ' ' }
							{ new Date( status.expires_at ).toLocaleString() }
						</p>
						<Button
							variant="secondary"
							isDestructive
							onClick={ disconnect }
						>
							{ __( 'Déconnecter', 'trinity-booking' ) }
						</Button>
					</>
				) : (
					<>
						<p>
							{ __(
								'Aucun calendrier Google connecté.',
								'trinity-booking'
							) }
						</p>
						<Button variant="primary" onClick={ connect }>
							{ __(
								'Connecter mon Google Calendar',
								'trinity-booking'
							) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}
