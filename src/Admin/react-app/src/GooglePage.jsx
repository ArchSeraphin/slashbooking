import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
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
	fetchGoogleCalendars,
	setGoogleCalendar,
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
	const [ calendars, setCalendars ] = useState( null );
	const [ calendarsLoading, setCalendarsLoading ] = useState( false );
	const [ calendarChoice, setCalendarChoice ] = useState( '' );
	const [ calendarMsg, setCalendarMsg ] = useState( '' );

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
				__( 'Erreur diagnostics : ', 'slashbooking' ) +
					( e.message ?? String( e ) )
			);
		}
	};

	const loadCalendars = async () => {
		setCalendarsLoading( true );
		setCalendarMsg( '' );
		try {
			const r = await fetchGoogleCalendars();
			setCalendars( r.calendars ?? [] );
			setCalendarChoice( r.selected ?? '' );
		} catch ( e ) {
			setCalendarMsg(
				__( 'Erreur chargement calendriers : ', 'slashbooking' ) +
					( e.message ?? String( e ) )
			);
		} finally {
			setCalendarsLoading( false );
		}
	};

	const onSaveCalendar = async () => {
		if ( ! calendarChoice ) {
			return;
		}
		setBusy( true );
		setCalendarMsg( '' );
		try {
			await setGoogleCalendar( calendarChoice );
			setCalendarMsg(
				__(
					'Calendrier enregistré. Le watch a été réinitialisé : pense à le redémarrer.',
					'slashbooking'
				)
			);
			await Promise.all( [ reload(), refreshDiag() ] );
		} catch ( e ) {
			setCalendarMsg(
				__( 'Erreur : ', 'slashbooking' ) +
					( e.message ?? String( e ) )
			);
		} finally {
			setBusy( false );
		}
	};

	useEffect( () => {
		reload();
		refreshDiag();
	}, [] );

	useEffect( () => {
		if ( status?.connected && calendars === null ) {
			loadCalendars();
		}
	}, [ status?.connected ] );

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
				__( 'Vraiment déconnecter ce compte ?', 'slashbooking' )
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
				__( 'Watch activé. Channel : ', 'slashbooking' ) +
					r.channelId +
					' (' +
					__( 'expire', 'slashbooking' ) +
					' ' +
					r.expiresAt +
					')'
			);
			await refreshDiag();
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur : ', 'slashbooking' ) +
					( e.message ?? String( e ) )
			);
		} finally {
			setBusy( false );
		}
	};

	const onStopWatch = async () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm( __( 'Arrêter le watch ?', 'slashbooking' ) )
		) {
			return;
		}
		setBusy( true );
		setPanelMsg( '' );
		try {
			await stopWatch();
			setPanelMsg( __( 'Watch arrêté.', 'slashbooking' ) );
			await refreshDiag();
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur : ', 'slashbooking' ) +
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
					'slashbooking'
				)
			);
		} catch ( e ) {
			setPanelMsg(
				__( 'Erreur : ', 'slashbooking' ) +
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
							{ __( 'Configuration OAuth', 'slashbooking' ) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							<strong>
								{ __(
									'URI de redirection à saisir dans Google Cloud Console :',
									'slashbooking'
								) }
							</strong>
							<br />
							<code>{ settings.redirect_uri }</code>
						</p>
						<TextControl
							label={ __( 'Client ID', 'slashbooking' ) }
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
											'slashbooking'
									  )
									: __( 'Client Secret', 'slashbooking' )
							}
							type="password"
							value={ secret }
							onChange={ setSecret }
						/>
						<Button variant="primary" onClick={ saveSettings }>
							{ __( 'Enregistrer', 'slashbooking' ) }
						</Button>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Google Calendar', 'slashbooking' ) }</h2>
				</CardHeader>
				<CardBody>
					{ status?.connected ? (
						<>
							<p>
								<strong>
									{ __( 'Connecté', 'slashbooking' ) } ✓
								</strong>
								<br />
								{ __(
									'Token expire :',
									'slashbooking'
								) }{ ' ' }
								{ new Date(
									status.expires_at
								).toLocaleString() }
							</p>

							<hr style={ { margin: '12px 0' } } />

							<p>
								<strong>
									{ __(
										'Calendrier cible',
										'slashbooking'
									) }
								</strong>
								<br />
								<span style={ { color: '#6b7280' } }>
									{ __(
										'Le calendrier dans lequel les RDV sont créés et dont les événements bloquent les créneaux.',
										'slashbooking'
									) }
								</span>
							</p>

							{ calendarsLoading && <Spinner /> }
							{ ! calendarsLoading && calendars !== null && (
								<>
									<SelectControl
										label={ __(
											'Choisir un calendrier',
											'slashbooking'
										) }
										value={ calendarChoice }
										options={ [
											...calendars.map( ( c ) => ( {
												label:
													( c.primary ? '★ ' : '' ) +
													( c.summary || c.id ) +
													( c.accessRole &&
													c.accessRole !== 'owner'
														? ' (' +
														  c.accessRole +
														  ')'
														: '' ),
												value: c.id,
											} ) ),
										] }
										onChange={ setCalendarChoice }
										__nextHasNoMarginBottom
									/>
									<div
										style={ {
											display: 'flex',
											gap: '8px',
											marginTop: '8px',
											flexWrap: 'wrap',
										} }
									>
										<Button
											variant="primary"
											onClick={ onSaveCalendar }
											disabled={
												busy ||
												! calendarChoice ||
												calendarChoice ===
													status.calendar_id
											}
										>
											{ __(
												'Enregistrer le calendrier',
												'slashbooking'
											) }
										</Button>
										<Button
											variant="tertiary"
											onClick={ loadCalendars }
											disabled={ busy }
										>
											{ __(
												'Rafraîchir la liste',
												'slashbooking'
											) }
										</Button>
									</div>
									<p
										style={ {
											fontSize: '12px',
											color: '#6b7280',
											marginTop: '4px',
										} }
									>
										{ __( 'Actuel : ', 'slashbooking' ) }
										<code>{ status.calendar_id }</code>
									</p>
								</>
							) }
							{ calendarMsg && (
								<Notice
									status={
										calendarMsg.startsWith( 'Erreur' )
											? 'error'
											: 'success'
									}
									isDismissible={ false }
									style={ { marginTop: '12px' } }
								>
									{ calendarMsg }
								</Notice>
							) }

							<hr style={ { margin: '12px 0' } } />

							<Button
								variant="secondary"
								isDestructive
								onClick={ disconnect }
							>
								{ __( 'Déconnecter', 'slashbooking' ) }
							</Button>
						</>
					) : (
						<>
							<p>
								{ __(
									'Aucun calendrier Google connecté.',
									'slashbooking'
								) }
							</p>
							<Button variant="primary" onClick={ connect }>
								{ __(
									'Connecter mon Google Calendar',
									'slashbooking'
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
							'slashbooking'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					{ diag === null && <Spinner /> }
					{ diag !== null && diag.connected === false && (
						<p>
							{ __(
								"Connectez d'abord un compte Google ci-dessus.",
								'slashbooking'
							) }
						</p>
					) }
					{ diag !== null && diag.connected === true && (
						<>
							<p>
								<strong>
									{ __(
										'Watch channel :',
										'slashbooking'
									) }{ ' ' }
								</strong>
								{ diag.watch?.channelId
									? diag.watch.channelId +
									  ' (' +
									  __( 'expire', 'slashbooking' ) +
									  ' ' +
									  diag.watch.expiresAt +
									  ')'
									: __( 'aucun', 'slashbooking' ) }
							</p>
							<p>
								<strong>
									{ __(
										'Dernier full sync :',
										'slashbooking'
									) }{ ' ' }
								</strong>
								{ diag.lastFullSyncAt ??
									__( 'jamais', 'slashbooking' ) }
							</p>
							<p>
								<strong>
									{ __( 'Sync token :', 'slashbooking' ) }{ ' ' }
								</strong>
								{ diag.syncToken
									? __(
											'présent (sync incrémental actif)',
											'slashbooking'
									  )
									: __(
											'absent (prochain pull = full sync)',
											'slashbooking'
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
											'slashbooking'
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
											'slashbooking'
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
										'slashbooking'
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
