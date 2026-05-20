import { useEffect, useState } from '@wordpress/element';
import {
	Card, CardBody, CardHeader,
	Button, TextControl, ToggleControl,
	Notice, Spinner, Flex, FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchService, saveService } from './api';

const DAYS = [
	{ key: '1', label: __( 'Lundi',    'trinity-booking' ) },
	{ key: '2', label: __( 'Mardi',    'trinity-booking' ) },
	{ key: '3', label: __( 'Mercredi', 'trinity-booking' ) },
	{ key: '4', label: __( 'Jeudi',    'trinity-booking' ) },
	{ key: '5', label: __( 'Vendredi', 'trinity-booking' ) },
	{ key: '6', label: __( 'Samedi',   'trinity-booking' ) },
	{ key: '7', label: __( 'Dimanche', 'trinity-booking' ) },
];

export default function ServiceEditor( { slug, onClose } ) {
	const [ service, setService ]   = useState( null );
	const [ loading, setLoading ]   = useState( true );
	const [ saving,  setSaving ]    = useState( false );
	const [ error,   setError ]     = useState( null );
	const [ message, setMessage ]   = useState( null );
	const [ isDirty, setIsDirty ]   = useState( false );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await fetchService( slug );
			setService( data );
			setIsDirty( false );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => { load(); }, [ slug ] );

	const patch = ( fields ) => {
		setService( ( s ) => ( { ...s, ...fields } ) );
		setIsDirty( true );
	};

	const patchDay = ( dayKey, ranges ) => {
		setService( ( s ) => ( {
			...s,
			weekly_hours: { ...s.weekly_hours, [ dayKey ]: ranges },
		} ) );
		setIsDirty( true );
	};

	const addRange = ( dayKey ) => {
		const existing = ( service.weekly_hours[ dayKey ] || [] );
		patchDay( dayKey, [ ...existing, { open: '09:00', close: '18:00' } ] );
	};

	const removeRange = ( dayKey, idx ) => {
		const next = ( service.weekly_hours[ dayKey ] || [] ).filter( ( _, i ) => i !== idx );
		patchDay( dayKey, next );
	};

	const setRange = ( dayKey, idx, field, value ) => {
		const next = ( service.weekly_hours[ dayKey ] || [] ).map( ( r, i ) => (
			i === idx ? { ...r, [ field ]: value } : r
		) );
		patchDay( dayKey, next );
	};

	const toggleDay = ( dayKey, enabled ) => {
		if ( enabled ) {
			patchDay( dayKey, [ { open: '09:00', close: '18:00' } ] );
		} else {
			patchDay( dayKey, [] );
		}
	};

	const save = async () => {
		setSaving( true );
		setError( null );
		setMessage( null );
		try {
			const payload = {
				name: service.name,
				duration_min: service.duration_min,
				buffer_before_min: service.buffer_before_min,
				buffer_after_min: service.buffer_after_min,
				min_lead_time_hours: service.min_lead_time_hours,
				max_horizon_days: service.max_horizon_days,
				color: service.color,
				active: service.active,
				weekly_hours: service.weekly_hours,
			};
			const updated = await saveService( slug, payload );
			setService( updated );
			setIsDirty( false );
			setMessage( __( 'Service enregistré.', 'trinity-booking' ) );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const close = () => {
		if ( isDirty ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Modifications non sauvegardées, quitter ?', 'trinity-booking' ) ) ) {
				return;
			}
		}
		onClose();
	};

	if ( loading || ! service ) {
		return (
			<Card>
				<CardBody><Spinner /></CardBody>
			</Card>
		);
	}

	return (
		<Card>
			<CardHeader>
				<Flex>
					<FlexItem>
						<h2 style={ { margin: 0, fontSize: 16, fontWeight: 600 } }>
							{ __( 'Édition : ', 'trinity-booking' ) }
							<code>{ slug }</code>
						</h2>
					</FlexItem>
					<FlexItem>
						<Button variant="tertiary" onClick={ close }>
							← { __( 'Retour aux services', 'trinity-booking' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				{ message && (
					<Notice status="success" onRemove={ () => setMessage( null ) }>
						{ message }
					</Notice>
				) }
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }

				<div className="tb-service-form">

					{/* Bloc identité */}
					<section className="tb-form-section">
						<h3 className="tb-form-section__title">
							{ __( 'Identité du service', 'trinity-booking' ) }
						</h3>
						<div className="tb-form-grid">
							<TextControl
								label={ __( 'Nom affiché', 'trinity-booking' ) }
								value={ service.name }
								onChange={ ( v ) => patch( { name: v } ) }
							/>
							<TextControl
								label={ __( 'Couleur (hex)', 'trinity-booking' ) }
								value={ service.color }
								onChange={ ( v ) => patch( { color: v } ) }
								type="text"
							/>
							<ToggleControl
								label={ __( 'Service actif (visible sur le formulaire public)', 'trinity-booking' ) }
								checked={ !! service.active }
								onChange={ ( v ) => patch( { active: v } ) }
							/>
						</div>
					</section>

					{/* Bloc durée + buffers */}
					<section className="tb-form-section">
						<h3 className="tb-form-section__title">
							{ __( 'Durée & règles', 'trinity-booking' ) }
						</h3>
						<div className="tb-form-grid">
							<TextControl
								label={ __( 'Durée du RDV (minutes)', 'trinity-booking' ) }
								type="number"
								value={ service.duration_min }
								onChange={ ( v ) => patch( { duration_min: parseInt( v, 10 ) || 0 } ) }
								min={ 5 } max={ 600 }
							/>
							<TextControl
								label={ __( 'Buffer avant (minutes)', 'trinity-booking' ) }
								type="number"
								value={ service.buffer_before_min }
								onChange={ ( v ) => patch( { buffer_before_min: parseInt( v, 10 ) || 0 } ) }
								min={ 0 } max={ 240 }
							/>
							<TextControl
								label={ __( 'Buffer après / trajet (minutes)', 'trinity-booking' ) }
								type="number"
								value={ service.buffer_after_min }
								onChange={ ( v ) => patch( { buffer_after_min: parseInt( v, 10 ) || 0 } ) }
								min={ 0 } max={ 240 }
							/>
							<TextControl
								label={ __( 'Délai minimum avant RDV (heures)', 'trinity-booking' ) }
								type="number"
								value={ service.min_lead_time_hours }
								onChange={ ( v ) => patch( { min_lead_time_hours: parseInt( v, 10 ) || 0 } ) }
								min={ 0 } max={ 720 }
							/>
							<TextControl
								label={ __( 'Horizon de réservation (jours)', 'trinity-booking' ) }
								type="number"
								value={ service.max_horizon_days }
								onChange={ ( v ) => patch( { max_horizon_days: parseInt( v, 10 ) || 0 } ) }
								min={ 1 } max={ 365 }
							/>
						</div>
					</section>

					{/* Bloc jours / horaires */}
					<section className="tb-form-section">
						<h3 className="tb-form-section__title">
							{ __( 'Jours & horaires de travail', 'trinity-booking' ) }
						</h3>
						<p className="tb-form-section__hint">
							{ __( 'Cochez les jours travaillés et définissez les plages horaires. Vous pouvez ajouter plusieurs plages par jour (matin / après-midi).', 'trinity-booking' ) }
						</p>

						<div className="tb-week">
							{ DAYS.map( ( d ) => {
								const ranges = service.weekly_hours[ d.key ] || [];
								const open = ranges.length > 0;
								return (
									<div key={ d.key } className={ `tb-day ${ open ? 'is-open' : 'is-closed' }` }>
										<div className="tb-day__head">
											<ToggleControl
												label={ d.label }
												checked={ open }
												onChange={ ( v ) => toggleDay( d.key, v ) }
											/>
										</div>
										{ open && (
											<div className="tb-day__ranges">
												{ ranges.map( ( r, i ) => (
													<div key={ i } className="tb-range">
														<input
															type="time"
															className="tb-time-input"
															value={ r.open }
															onChange={ ( e ) => setRange( d.key, i, 'open', e.target.value ) }
														/>
														<span className="tb-range__sep">→</span>
														<input
															type="time"
															className="tb-time-input"
															value={ r.close }
															onChange={ ( e ) => setRange( d.key, i, 'close', e.target.value ) }
														/>
														<Button
															variant="tertiary"
															isDestructive
															size="small"
															onClick={ () => removeRange( d.key, i ) }
														>
															×
														</Button>
													</div>
												) ) }
												<Button
													variant="link"
													size="small"
													onClick={ () => addRange( d.key ) }
												>
													+ { __( 'Ajouter une plage', 'trinity-booking' ) }
												</Button>
											</div>
										) }
									</div>
								);
							} ) }
						</div>
					</section>

					<Flex gap={ 2 } style={ { marginTop: 20, paddingTop: 16, borderTop: '1px solid #e2e8f0' } }>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ save }
								isBusy={ saving }
								disabled={ ! isDirty || saving }
							>
								{ __( 'Enregistrer', 'trinity-booking' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="tertiary" onClick={ close }>
								{ __( 'Annuler', 'trinity-booking' ) }
							</Button>
						</FlexItem>
					</Flex>

				</div>
			</CardBody>
		</Card>
	);
}
