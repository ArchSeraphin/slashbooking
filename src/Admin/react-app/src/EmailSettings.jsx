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
import { fetchSettings, saveSettings } from './api';

export default function EmailSettings() {
	const [ loading, setLoading ]       = useState( true );
	const [ saving, setSaving ]         = useState( false );
	const [ error, setError ]           = useState( null );
	const [ savedMsg, setSavedMsg ]     = useState( '' );
	const [ notifEmail, setNotifEmail ] = useState( '' );
	const [ fallback, setFallback ]     = useState( '' );
	const [ logo, setLogo ]             = useState( '' );
	const [ phone, setPhone ]           = useState( '' );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const s = await fetchSettings();
			setNotifEmail( s.notification_email ?? '' );
			setFallback( s.admin_email_fallback ?? '' );
			setLogo( s.company_logo ?? '' );
			setPhone( s.company_phone ?? '' );
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
			await saveSettings( {
				notificationEmail: notifEmail,
				companyLogo:       logo,
				companyPhone:      phone,
			} );
			setSavedMsg(
				__( 'Paramètres enregistrés.', 'slashbooking' )
			);
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const effectiveTarget = notifEmail.trim() !== '' ? notifEmail : fallback;

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Paramètres e-mail', 'slashbooking' ) }</h2>
			</CardHeader>
			<CardBody>
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! loading && (
					<>
						<TextControl
							label={ __(
								'Adresse de réception des notifications',
								'slashbooking'
							) }
							help={
								notifEmail.trim() === ''
									? __(
											"Laissé vide : les notifications vont à l'e-mail admin WP (",
											'slashbooking'
									  ) + fallback + ').'
									: __(
											'Les notifications de nouvelles demandes iront ici, indépendamment de l\'e-mail admin WP.',
											'slashbooking'
									  )
							}
							type="email"
							value={ notifEmail }
							onChange={ setNotifEmail }
							placeholder={ fallback }
							__nextHasNoMarginBottom
						/>

						<div style={ { height: 12 } } />

						<TextControl
							label={ __( 'URL logo société (utilisé par {company_logo})', 'slashbooking' ) }
							type="url"
							value={ logo }
							onChange={ setLogo }
							placeholder="https://exemple.com/logo.png"
							__nextHasNoMarginBottom
						/>

						<div style={ { height: 12 } } />

						<TextControl
							label={ __( 'Téléphone société (utilisé par {company_phone})', 'slashbooking' ) }
							value={ phone }
							onChange={ setPhone }
							placeholder="+33 1 23 45 67 89"
							__nextHasNoMarginBottom
						/>

						<div style={ { marginTop: 16, display: 'flex', gap: 8, alignItems: 'center' } }>
							<Button variant="primary" onClick={ save } disabled={ saving }>
								{ __( 'Enregistrer', 'slashbooking' ) }
							</Button>
							{ savedMsg && (
								<span style={ { color: '#15803d', fontSize: 13 } }>
									{ savedMsg }
								</span>
							) }
						</div>

						<p style={ { marginTop: 16, fontSize: 12, color: '#6b7280' } }>
							{ __( 'Les notifications "nouvelle demande" partiront vers : ', 'slashbooking' ) }
							<strong>{ effectiveTarget || __( '(aucune adresse définie)', 'slashbooking' ) }</strong>
						</p>
					</>
				) }
			</CardBody>
		</Card>
	);
}
