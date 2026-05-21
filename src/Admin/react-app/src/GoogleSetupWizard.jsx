import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Friendly guided walkthrough of the Google Cloud Console setup.
 * Shown above the OAuth config card when the account is not yet connected.
 *
 * Each step links directly to the right Google Cloud Console page (no
 * "find the settings buried in 4 menus" friction).
 */
export default function GoogleSetupWizard( { redirectUri } ) {
	// Open by default on step 1; users can fold/unfold as they progress.
	const [ open, setOpen ] = useState( { 1: true, 2: false, 3: false, 4: false } );
	const [ copied, setCopied ] = useState( false );

	const toggle = ( n ) => setOpen( ( o ) => ( { ...o, [ n ]: ! o[ n ] } ) );

	const copyRedirect = async () => {
		try {
			await navigator.clipboard.writeText( redirectUri );
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} catch ( e ) {
			// Silent fail — user can copy manually from the code block.
		}
	};

	return (
		<Card className="sb-setup-wizard">
			<CardHeader>
				<h2>
					{ __( 'Configurer Google Calendar — guide pas à pas', 'slashbooking' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p style={ { color: '#475569', marginTop: 0 } }>
					{ __(
						"Tu as besoin d'un projet Google Cloud (gratuit, 5 min). Les 4 boutons ci-dessous ouvrent directement la bonne page Google.",
						'slashbooking'
					) }
				</p>

				<Step
					n={ 1 }
					title={ __( 'Activer Google Calendar API', 'slashbooking' ) }
					open={ open[ 1 ] }
					onToggle={ () => toggle( 1 ) }
				>
					<p>
						{ __(
							"Crée un nouveau projet (ex : « slashbooking ») ou réutilise-en un, puis active l'API Google Calendar.",
							'slashbooking'
						) }
					</p>
					<ExternalLink href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com">
						{ __( '→ Ouvrir Google Cloud Console (page Calendar API)', 'slashbooking' ) }
					</ExternalLink>
					<p className="sb-step-hint">
						{ __( "Clique « Enable ». Si le projet n'existe pas, Google te le propose en haut.", 'slashbooking' ) }
					</p>
				</Step>

				<Step
					n={ 2 }
					title={ __( 'Configurer l\'écran de consentement', 'slashbooking' ) }
					open={ open[ 2 ] }
					onToggle={ () => toggle( 2 ) }
				>
					<p>
						<strong>{ __( 'User Type :', 'slashbooking' ) }</strong>{ ' ' }
						<code>External</code>
					</p>
					<p>
						<strong>{ __( 'Scopes à ajouter :', 'slashbooking' ) }</strong>
					</p>
					<ul>
						<li><code>auth/calendar.events</code></li>
						<li><code>auth/calendar.readonly</code></li>
					</ul>
					<p>
						<strong>{ __( 'Test users :', 'slashbooking' ) }</strong>{ ' ' }
						{ __( "ajoute l'adresse e-mail qui se connectera ci-dessous (limite 100).", 'slashbooking' ) }
					</p>
					<ExternalLink href="https://console.cloud.google.com/apis/credentials/consent">
						{ __( '→ Ouvrir OAuth consent screen', 'slashbooking' ) }
					</ExternalLink>
				</Step>

				<Step
					n={ 3 }
					title={ __( 'Créer un OAuth Client ID', 'slashbooking' ) }
					open={ open[ 3 ] }
					onToggle={ () => toggle( 3 ) }
				>
					<p>
						<strong>{ __( 'Application type :', 'slashbooking' ) }</strong>{ ' ' }
						<code>Web application</code>
					</p>
					<p>
						<strong>{ __( "Authorized redirect URIs : colle exactement ceci", 'slashbooking' ) } 👇</strong>
					</p>
					<div className="sb-redirect-uri-box">
						<code>{ redirectUri }</code>
						<Button
							variant="secondary"
							size="small"
							onClick={ copyRedirect }
						>
							{ copied
								? __( '✓ Copié', 'slashbooking' )
								: __( '📋 Copier', 'slashbooking' ) }
						</Button>
					</div>
					<p className="sb-step-hint">
						{ __(
							"Tu obtiendras un Client ID et un Client Secret à coller dans le formulaire « Configuration OAuth » plus bas.",
							'slashbooking'
						) }
					</p>
					<ExternalLink href="https://console.cloud.google.com/apis/credentials">
						{ __( '→ Ouvrir Credentials → Create OAuth client ID', 'slashbooking' ) }
					</ExternalLink>
				</Step>

				<Step
					n={ 4 }
					title={ __( 'Coller les identifiants ici-bas', 'slashbooking' ) }
					open={ open[ 4 ] }
					onToggle={ () => toggle( 4 ) }
				>
					<p>
						{ __(
							"Renseigne le Client ID + Secret dans le formulaire « Configuration OAuth » juste en-dessous, enregistre, puis clique « Connecter mon Google Calendar ».",
							'slashbooking'
						) }
					</p>
					<p className="sb-step-hint">
						{ __(
							"Astuce : pour de la prod (≥ 100 utilisateurs ou refresh tokens permanents), bascule l'app en « Production » dans la console Google. Sinon, le mode « Testing » suffit largement avec les test users de l'étape 2.",
							'slashbooking'
						) }
					</p>
				</Step>

				<p style={ { marginTop: 16, fontSize: 13, color: '#6b7280' } }>
					{ __( 'Besoin du détail complet ?', 'slashbooking' ) }{ ' ' }
					<ExternalLink href="https://slashbox.fr/slashbooking/">
						{ __( 'Voir la documentation pas-à-pas (PDF disponible)', 'slashbooking' ) }
					</ExternalLink>
				</p>
			</CardBody>
		</Card>
	);
}

function Step( { n, title, open, onToggle, children } ) {
	return (
		<div className={ `sb-setup-step ${ open ? 'is-open' : 'is-closed' }` }>
			<button
				type="button"
				className="sb-setup-step__header"
				onClick={ onToggle }
				aria-expanded={ open }
			>
				<span className="sb-setup-step__n">{ n }</span>
				<span className="sb-setup-step__title">{ title }</span>
				<span className="sb-setup-step__chevron">{ open ? '−' : '+' }</span>
			</button>
			{ open && <div className="sb-setup-step__body">{ children }</div> }
		</div>
	);
}
