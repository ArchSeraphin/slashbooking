import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ExternalLink,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchSettings, saveSettings } from './api';

export default function FormSettings() {
	const [ loading, setLoading ]           = useState( true );
	const [ saving, setSaving ]             = useState( false );
	const [ error, setError ]               = useState( null );
	const [ savedMsg, setSavedMsg ]         = useState( '' );
	const [ disclaimer, setDisclaimer ]     = useState( '' );
	const [ siteKey, setSiteKey ]           = useState( '' );
	const [ secretInput, setSecretInput ]   = useState( '' );
	const [ secretSet, setSecretSet ]       = useState( false );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const s = await fetchSettings();
			setDisclaimer( s.form_disclaimer ?? '' );
			setSiteKey( s.turnstile_site_key ?? '' );
			setSecretSet( !! s.turnstile_secret_set );
			setSecretInput( '' );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => { load(); }, [] );

	const save = async () => {
		setSaving( true );
		setSavedMsg( '' );
		setError( null );
		try {
			const payload = {
				formDisclaimer:   disclaimer,
				turnstileSiteKey: siteKey,
			};
			// Only send secret if user typed something. Empty input = keep current.
			if ( secretInput.trim() !== '' ) {
				payload.turnstileSecretKey = secretInput.trim();
			}
			await saveSettings( payload );
			setSavedMsg( __( 'Paramètres enregistrés.', 'slashbooking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const clearSecret = async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Effacer la clé secrète Turnstile ? Cela désactivera la vérification anti-robot.', 'slashbooking' ) ) ) {
			return;
		}
		setSaving( true );
		setSavedMsg( '' );
		setError( null );
		try {
			await saveSettings( { turnstileSecretKey: '__CLEAR__' } );
			setSavedMsg( __( 'Clé secrète effacée.', 'slashbooking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const turnstileActive = siteKey.trim() !== '' && ( secretSet || secretInput.trim() !== '' );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Paramètres du formulaire public', 'slashbooking' ) }</h2>
			</CardHeader>
			<CardBody>
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>{ error }</Notice>
				) }

				{ ! loading && (
					<>
						<TextareaControl
							label={ __( 'Mention en bas du formulaire', 'slashbooking' ) }
							help={ __(
								"Affichée juste au-dessus du bouton « Confirmer la demande ». Laissez vide pour ne rien afficher.",
								'slashbooking'
							) }
							value={ disclaimer }
							onChange={ setDisclaimer }
							rows={ 3 }
							placeholder={ __(
								'Ex : Notre équipe devra approuver la date et l\'heure proposées afin de confirmer votre rendez-vous.',
								'slashbooking'
							) }
							__nextHasNoMarginBottom
						/>

						<hr style={ { margin: '24px 0', border: 'none', borderTop: '1px solid #e5e7eb' } } />

						<h3 style={ { margin: '0 0 4px', fontSize: 14, fontWeight: 600 } }>
							{ __( 'Cloudflare Turnstile (anti-robot)', 'slashbooking' ) }
						</h3>
						<p style={ { margin: '0 0 16px', fontSize: 13, color: '#6b7280' } }>
							{ __( 'Optionnel — protège le formulaire contre les bots. Laissez vide pour désactiver.', 'slashbooking' ) }{ ' ' }
							<ExternalLink href="https://dash.cloudflare.com/?to=/:account/turnstile">
								{ __( 'Créer un site sur Cloudflare Turnstile', 'slashbooking' ) }
							</ExternalLink>
						</p>

						<TextControl
							label={ __( 'Site Key (publique)', 'slashbooking' ) }
							value={ siteKey }
							onChange={ setSiteKey }
							placeholder="0x4AAAAAAA..."
							__nextHasNoMarginBottom
						/>

						<div style={ { height: 12 } } />

						<TextControl
							label={
								secretSet
									? __( 'Secret Key — déjà configurée (saisir pour remplacer)', 'slashbooking' )
									: __( 'Secret Key (privée)', 'slashbooking' )
							}
							type="password"
							value={ secretInput }
							onChange={ setSecretInput }
							placeholder={ secretSet ? '••••••••••••••' : '0x4AAAAAAA...' }
							__nextHasNoMarginBottom
						/>

						{ secretSet && (
							<p style={ { margin: '6px 0 0' } }>
								<Button variant="link" isDestructive onClick={ clearSecret } disabled={ saving }>
									{ __( 'Effacer la clé secrète (= désactiver Turnstile)', 'slashbooking' ) }
								</Button>
							</p>
						) }

						<div style={ { marginTop: 20, display: 'flex', gap: 8, alignItems: 'center' } }>
							<Button variant="primary" onClick={ save } disabled={ saving }>
								{ __( 'Enregistrer', 'slashbooking' ) }
							</Button>
							{ savedMsg && (
								<span style={ { color: '#15803d', fontSize: 13 } }>{ savedMsg }</span>
							) }
						</div>

						<p style={ { marginTop: 16, fontSize: 12, color: '#6b7280' } }>
							{ __( 'Statut anti-robot : ', 'slashbooking' ) }
							<strong>
								{ turnstileActive
									? __( '✓ activé', 'slashbooking' )
									: __( 'désactivé', 'slashbooking' ) }
							</strong>
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}
