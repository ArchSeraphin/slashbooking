import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	fetchGoogleStatus,
	startGoogleOAuth,
	disconnectGoogle,
	fetchGoogleSettings,
	saveGoogleSettings,
} from './api';

export default function GooglePage() {
	const [ status, setStatus ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ secret, setSecret ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const reload = async () => {
		setLoading( true );
		setError( null );
		try {
			const [ st, sg ] = await Promise.all( [
				fetchGoogleStatus(),
				fetchGoogleSettings(),
			] );
			setStatus( st );
			setSettings( sg );
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

	const saveSettings = async () => {
		try {
			await saveGoogleSettings( {
				clientId: settings.client_id,
				clientSecret: secret,
			} );
			setSecret( '' );
			await reload();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ settings && (
				<Card>
					<CardHeader>
						<h2>
							{ __( 'Configuration OAuth', 'trinity-booking' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							<strong>
								{ __(
									'URI de redirection à saisir dans Google Cloud Console :',
									'trinity-booking'
								) }
							</strong>
							<br />
							<code>{ settings.redirect_uri }</code>
						</p>
						<TextControl
							label="Client ID"
							value={ settings.client_id }
							onChange={ ( v ) =>
								setSettings( { ...settings, client_id: v } )
							}
						/>
						<TextControl
							label={
								settings.has_client_secret
									? __(
											'Client Secret (déjà défini — saisir pour remplacer)',
											'trinity-booking'
									  )
									: 'Client Secret'
							}
							type="password"
							value={ secret }
							onChange={ setSecret }
						/>
						<Button variant="primary" onClick={ saveSettings }>
							{ __( 'Enregistrer', 'trinity-booking' ) }
						</Button>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Google Calendar', 'trinity-booking' ) }</h2>
				</CardHeader>
				<CardBody>
					{ status?.connected ? (
						<>
							<p>
								<strong>
									{ __( 'Connecté', 'trinity-booking' ) } ✓
								</strong>
								<br />
								{ __(
									'Calendrier :',
									'trinity-booking'
								) }{ ' ' }
								<code>{ status.calendar_id }</code>
								<br />
								{ __(
									'Token expire :',
									'trinity-booking'
								) }{ ' ' }
								{ new Date(
									status.expires_at
								).toLocaleString() }
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
		</div>
	);
}
