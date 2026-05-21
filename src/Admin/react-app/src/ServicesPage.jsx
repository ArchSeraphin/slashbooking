import { useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	Spinner,
	Button,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	listServices,
	saveService,
	createService,
	deleteService,
} from './api';
import ServiceEditor from './ServiceEditor';

function formatDuration( min ) {
	if ( min < 60 ) return `${ min } min`;
	const h = Math.floor( min / 60 );
	const m = min % 60;
	return m === 0 ? `${ h } h` : `${ h } h ${ String( m ).padStart( 2, '0' ) }`;
}

const DAY_LABELS = [ 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim' ];

function daysSummary( weekly ) {
	const open = [];
	for ( let d = 1; d <= 7; d++ ) {
		const ranges = weekly[ String( d ) ] || weekly[ d ] || [];
		if ( ranges.length > 0 ) open.push( DAY_LABELS[ d - 1 ] );
	}
	return open.length === 0 ? __( 'Aucun jour', 'slashbooking' ) : open.join( ' · ' );
}

export default function ServicesPage() {
	const [ items, setItems ]       = useState( null );
	const [ loading, setLoading ]   = useState( true );
	const [ error, setError ]       = useState( null );
	const [ selected, setSelected ] = useState( null );
	const [ busySlug, setBusySlug ] = useState( '' );
	const [ showAdd, setShowAdd ]   = useState( false );
	const [ newName, setNewName ]   = useState( '' );
	const [ newSlug, setNewSlug ]   = useState( '' );
	const [ addErr, setAddErr ]     = useState( null );
	const [ addBusy, setAddBusy ]   = useState( false );

	const reload = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await listServices();
			setItems( data.services );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => { reload(); }, [] );

	const toggleActive = async ( svc ) => {
		setBusySlug( svc.slug );
		setError( null );
		try {
			await saveService( svc.slug, { active: ! svc.active } );
			await reload();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setBusySlug( '' );
		}
	};

	const onDelete = async ( svc ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm(
			__( 'Supprimer définitivement ce service ? Cette action est irréversible.', 'slashbooking' )
		) ) {
			return;
		}
		setBusySlug( svc.slug );
		setError( null );
		try {
			await deleteService( svc.slug );
			await reload();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setBusySlug( '' );
		}
	};

	const submitAdd = async () => {
		if ( newName.trim() === '' ) {
			setAddErr( __( 'Le nom est requis.', 'slashbooking' ) );
			return;
		}
		setAddBusy( true );
		setAddErr( null );
		try {
			const created = await createService( {
				name: newName.trim(),
				slug: newSlug.trim() || undefined,
			} );
			setShowAdd( false );
			setNewName( '' );
			setNewSlug( '' );
			await reload();
			// Open editor for fine-tuning duration/hours/etc.
			setSelected( created.slug );
		} catch ( e ) {
			setAddErr( e.message ?? String( e ) );
		} finally {
			setAddBusy( false );
		}
	};

	if ( selected ) {
		return (
			<ServiceEditor
				slug={ selected }
				onClose={ () => { setSelected( null ); reload(); } }
			/>
		);
	}

	return (
		<Card>
			<CardHeader>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' } }>
					<h2 style={ { margin: 0, fontSize: 16, fontWeight: 600 } }>
						{ __( 'Services & horaires', 'slashbooking' ) }
					</h2>
					<Button variant="primary" onClick={ () => setShowAdd( true ) }>
						+ { __( 'Ajouter un service', 'slashbooking' ) }
					</Button>
				</div>
			</CardHeader>
			<CardBody>
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>{ error }</Notice>
				) }
				{ items && items.length === 0 && (
					<p>{ __( 'Aucun service.', 'slashbooking' ) }</p>
				) }
				{ items && items.length > 0 && (
					<table className="sb-table">
						<thead>
							<tr>
								<th>{ __( 'Service', 'slashbooking' ) }</th>
								<th>{ __( 'Durée', 'slashbooking' ) }</th>
								<th>{ __( 'Buffer', 'slashbooking' ) }</th>
								<th>{ __( 'Jours ouverts', 'slashbooking' ) }</th>
								<th>{ __( 'Statut', 'slashbooking' ) }</th>
								<th style={ { textAlign: 'right' } }></th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( s ) => (
								<tr key={ s.slug }>
									<td>
										<div className="sb-table__customer">{ s.name }</div>
										<div className="sb-table__customer-meta">
											<code>{ s.slug }</code>
										</div>
									</td>
									<td className="sb-table__time">
										{ formatDuration( s.duration_min ) }
									</td>
									<td className="sb-table__time">
										{ s.buffer_after_min > 0
											? `+${ s.buffer_after_min } min`
											: '—' }
									</td>
									<td>{ daysSummary( s.weekly_hours ) }</td>
									<td>
										<span className={ `sb-status sb-status--${ s.active ? 'confirmed' : 'cancelled' }` }>
											{ s.active
												? __( 'Actif', 'slashbooking' )
												: __( 'Désactivé', 'slashbooking' ) }
										</span>
									</td>
									<td style={ { textAlign: 'right' } }>
										<div style={ { display: 'inline-flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' } }>
											<Button
												variant="secondary"
												size="small"
												onClick={ () => setSelected( s.slug ) }
												disabled={ busySlug === s.slug }
											>
												{ __( 'Modifier', 'slashbooking' ) }
											</Button>
											<Button
												variant="tertiary"
												size="small"
												onClick={ () => toggleActive( s ) }
												disabled={ busySlug === s.slug }
											>
												{ s.active
													? __( 'Désactiver', 'slashbooking' )
													: __( 'Activer', 'slashbooking' ) }
											</Button>
											<Button
												variant="tertiary"
												size="small"
												isDestructive
												onClick={ () => onDelete( s ) }
												disabled={ busySlug === s.slug }
											>
												{ __( 'Supprimer', 'slashbooking' ) }
											</Button>
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardBody>

			{ showAdd && (
				<Modal
					title={ __( 'Ajouter un service', 'slashbooking' ) }
					onRequestClose={ () => {
						if ( ! addBusy ) {
							setShowAdd( false );
							setAddErr( null );
						}
					} }
				>
					<p style={ { marginTop: 0, color: '#475569' } }>
						{ __(
							'Choisis un nom (et éventuellement un identifiant). Tu pourras configurer la durée et les horaires juste après.',
							'slashbooking'
						) }
					</p>

					{ addErr && (
						<Notice status="error" isDismissible={ false }>{ addErr }</Notice>
					) }

					<TextControl
						label={ __( 'Nom du service', 'slashbooking' ) }
						value={ newName }
						onChange={ setNewName }
						placeholder={ __( 'Ex : Audit énergétique', 'slashbooking' ) }
						__nextHasNoMarginBottom
					/>
					<div style={ { height: 12 } } />
					<TextControl
						label={ __( 'Identifiant (slug) — optionnel', 'slashbooking' ) }
						help={ __(
							'Laisse vide pour générer automatiquement depuis le nom. Caractères autorisés : a-z, 0-9, tirets.',
							'slashbooking'
						) }
						value={ newSlug }
						onChange={ setNewSlug }
						placeholder={ __( 'auto', 'slashbooking' ) }
						__nextHasNoMarginBottom
					/>

					<div style={ { marginTop: 20, display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
						<Button
							variant="tertiary"
							onClick={ () => { setShowAdd( false ); setAddErr( null ); } }
							disabled={ addBusy }
						>
							{ __( 'Annuler', 'slashbooking' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ submitAdd }
							disabled={ addBusy || newName.trim() === '' }
						>
							{ addBusy
								? __( 'Création…', 'slashbooking' )
								: __( 'Créer et configurer', 'slashbooking' ) }
						</Button>
					</div>
				</Modal>
			) }
		</Card>
	);
}
