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
	fetchGoogleDiagnostics,
	startWatch,
	stopWatch,
	forcePullNow,
} from './api';

export default function GooglePage() {
	const [ status, setStatus ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ secret, setSecret ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ diag, setDiag ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ panelMsg, setPanelMsg ] = useState( '' );

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

	const refreshDiag = async () => {
		try {
			setDiag( await fetchGoogleDiagnostics() );
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur diagnostics : ', 'trinity-booking' ) +
					( e.message ?? String( e ) )
			);
		}
	};

	useEffect( () => {
		reload();
		refreshDiag();
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

	const onStartWatch = async () => {
		setBusy( true );
		setPanelMsg( '' );
		try {
			const r = await startWatch();
			setPanelMsg(
				__( 'Watch activé. Channel : ', 'trinity-booking' ) +
					r.channelId +
					' (' +
					__( 'expire', 'trinity-booking' ) +
					' ' +
					r.expiresAt +
					')'
			);
			await refreshDiag();
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur : ', 'trinity-booking' ) +
					( e.message ?? String( e ) )
			);
		} finally {
			setBusy( false );
		}
	};

	const onStopWatch = async () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm( __( 'Arrêter le watch ?', 'trinity-booking' ) )
		) {
			return;
		}
		setBusy( true );
		setPanelMsg( '' );
		try {
			await stopWatch();
			setPanelMsg( __( 'Watch arrêté.', 'trinity-booking' ) );
			await refreshDiag();
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur : ', 'trinity-booking' ) +
					( e.message ?? String( e ) )
			);
		} finally {
			setBusy( false );
		}
	};

	const onPullNow = async () => {
		setBusy( true );
		setPanelMsg( '' );
		try {
			await forcePullNow();
			setPanelMsg(
				__(
					'Pull enfilé. Vérifie le Journal dans quelques secondes.',
					'trinity-booking'
				)
			);
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur : ', 'trinity-booking' ) +
					( e.message ?? String( e ) )
			);
		} finally {
			setBusy( false );
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

			<Card>
				<CardHeader>
					<h2>
						{ __(
							'Synchronisation entrante (Google → WP)',
							'trinity-booking'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					{ diag === null && <Spinner /> }
					{ diag !== null && diag.connected === false && (
						<p>
							{ __(
								"Connectez d'abord un compte Google ci-dessus.",
								'trinity-booking'
							) }
						</p>
					) }
					{ diag !== null && diag.connected === true && (
						<>
							<p>
								<strong>
									{ __(
										'Watch channel :',
										'trinity-booking'
									) }{ ' ' }
								</strong>
								{ diag.watch?.channelId
									? diag.watch.channelId +
									  ' (' +
									  __( 'expire', 'trinity-booking' ) +
									  ' ' +
									  diag.watch.expiresAt +
									  ')'
									: __( 'aucun', 'trinity-booking' ) }
							</p>
							<p>
								<strong>
									{ __(
										'Dernier full sync :',
										'trinity-booking'
									) }{ ' ' }
								</strong>
								{ diag.lastFullSyncAt ??
									__( 'jamais', 'trinity-booking' ) }
							</p>
							<p>
								<strong>
									{ __( 'Sync token :', 'trinity-booking' ) }{ ' ' }
								</strong>
								{ diag.syncToken
									? __(
											'présent (sync incrémental actif)',
											'trinity-booking'
									  )
									: __(
											'absent (prochain pull = full sync)',
											'trinity-booking'
									  ) }
							</p>
							<div
								style={ {
									display: 'flex',
									gap: '8px',
									flexWrap: 'wrap',
								} }
							>
								{ ! diag.watch?.channelId && (
									<Button
										variant="primary"
										onClick={ onStartWatch }
										disabled={ busy }
									>
										{ __(
											'Démarrer le watch',
											'trinity-booking'
										) }
									</Button>
								) }
								{ diag.watch?.channelId && (
									<Button
										variant="secondary"
										isDestructive
										onClick={ onStopWatch }
										disabled={ busy }
									>
										{ __(
											'Arrêter le watch',
											'trinity-booking'
										) }
									</Button>
								) }
								<Button
									variant="tertiary"
									onClick={ onPullNow }
									disabled={ busy }
								>
									{ __(
										'Forcer un pull maintenant',
										'trinity-booking'
									) }
								</Button>
							</div>
							{ panelMsg && (
								<Notice
									status="info"
									isDismissible={ false }
									style={ { marginTop: '12px' } }
								>
									{ panelMsg }
								</Notice>
							) }
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
