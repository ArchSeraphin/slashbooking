import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';
import { SelectControl, Spinner, Button, Notice, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listBookings, actBooking, setupApi } from './api';
import BookingRow from './BookingRow';
import ShortcodeMemo from './ShortcodeMemo';

setupApi();

const STATUSES = [
	{ value: '',          label: __( 'Tous statuts', 'slashbooking' ) },
	{ value: 'pending',   label: __( 'En attente',   'slashbooking' ) },
	{ value: 'confirmed', label: __( 'Confirmés',    'slashbooking' ) },
	{ value: 'rejected',  label: __( 'Refusés',      'slashbooking' ) },
	{ value: 'cancelled', label: __( 'Annulés',      'slashbooking' ) },
];

const PER_PAGE = 20;

export default function BookingsPage() {
	const [ items, setItems ]   = useState( [] );
	const [ total, setTotal ]   = useState( 0 );
	const [ page, setPage ]     = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ busy, setBusy ]     = useState( false );
	const [ error, setError ]   = useState( null );

	// KPI counts derived from a separate unfiltered fetch (first page is enough for typical SMB volumes).
	const [ kpi, setKpi ] = useState( { total: 0, pending: 0, confirmed: 0, upcoming: 0 } );

	const computeKpis = ( rows ) => {
		const now = new Date();
		let pending = 0, confirmed = 0, upcoming = 0;
		rows.forEach( ( b ) => {
			if ( b.status === 'pending' ) pending++;
			if ( b.status === 'confirmed' ) confirmed++;
			if ( b.status === 'confirmed' && new Date( b.starts_at_utc ) >= now ) upcoming++;
		} );
		return { pending, confirmed, upcoming };
	};

	const load = useCallback( async () => {
		setBusy( true );
		setError( null );
		try {
			const res = await listBookings( {
				page,
				per_page: PER_PAGE,
				...( status ? { status } : {} ),
			} );
			setItems( res.items );
			setTotal( res.total );

			// Refresh KPI from an unfiltered probe (lightweight: first 100 rows).
			const probe = await listBookings( { page: 1, per_page: 100 } );
			const counts = computeKpis( probe.items );
			setKpi( { total: probe.total, ...counts } );
		} catch ( e ) {
			setError( e.message || String( e ) );
		} finally {
			setBusy( false );
		}
	}, [ page, status ] );

	useEffect( () => { load(); }, [ load ] );

	const onAct = async ( id, action ) => {
		try {
			await actBooking( id, action );
			await load();
		} catch ( e ) {
			setError( e.message || String( e ) );
		}
	};

	const lastPage = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	const kpiCards = useMemo( () => ( [
		{
			label: __( 'Total RDV', 'slashbooking' ),
			value: kpi.total,
			variant: 'primary',
			hint:  __( 'Toutes périodes', 'slashbooking' ),
		},
		{
			label: __( 'À valider', 'slashbooking' ),
			value: kpi.pending,
			variant: 'warning',
			hint:  __( 'En attente de confirmation', 'slashbooking' ),
		},
		{
			label: __( 'Confirmés', 'slashbooking' ),
			value: kpi.confirmed,
			variant: 'accent',
			hint:  __( 'Validés par l\'admin', 'slashbooking' ),
		},
		{
			label: __( 'À venir', 'slashbooking' ),
			value: kpi.upcoming,
			variant: 'primary',
			hint:  __( 'Confirmés futurs', 'slashbooking' ),
		},
	] ), [ kpi ] );

	return (
		<section className="sb-bookings">
			<div className="sb-kpi-grid">
				{ kpiCards.map( ( c ) => (
					<div key={ c.label } className={ `sb-kpi sb-kpi--${ c.variant }` }>
						<p className="sb-kpi__label">{ c.label }</p>
						<p className="sb-kpi__value">{ c.value }</p>
						<p className="sb-kpi__hint">{ c.hint }</p>
					</div>
				) ) }
			</div>

			<Card>
				<CardBody>
					<div className="sb-bookings__toolbar">
						<SelectControl
							label={ __( 'Filtrer par statut', 'slashbooking' ) }
							value={ status }
							options={ STATUSES }
							onChange={ ( v ) => { setPage( 1 ); setStatus( v ); } }
						/>
					</div>

					{ error && (
						<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
							{ error }
						</Notice>
					) }

					{ busy ? (
						<div style={ { padding: 32, textAlign: 'center' } }><Spinner /></div>
					) : items.length === 0 ? (
						<div className="sb-empty">
							<svg className="sb-empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
								<rect x="3" y="4" width="18" height="18" rx="2"/>
								<path d="M16 2v4M8 2v4M3 10h18"/>
							</svg>
							<p className="sb-empty__title">{ __( 'Aucune réservation', 'slashbooking' ) }</p>
							<p className="sb-empty__hint">
								{ __( 'Les RDV apparaîtront ici dès la première prise via le formulaire public.', 'slashbooking' ) }
							</p>
						</div>
					) : (
						<table className="sb-table">
							<thead>
								<tr>
									<th>{ __( 'Date',    'slashbooking' ) }</th>
									<th>{ __( 'Service', 'slashbooking' ) }</th>
									<th>{ __( 'Client',  'slashbooking' ) }</th>
									<th>{ __( 'Statut',  'slashbooking' ) }</th>
									<th style={ { textAlign: 'right' } }>{ __( 'Actions', 'slashbooking' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( b ) => (
									<BookingRow key={ b.id } booking={ b } onAct={ onAct } />
								) ) }
							</tbody>
						</table>
					) }

					{ items.length > 0 && (
						<div className="sb-bookings__pager">
							<Button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
								{ __( '← Précédent', 'slashbooking' ) }
							</Button>
							<span>
								{ __( 'Page', 'slashbooking' ) } { page } / { lastPage }
							</span>
							<Button disabled={ page >= lastPage } onClick={ () => setPage( page + 1 ) }>
								{ __( 'Suivant →', 'slashbooking' ) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>

			<ShortcodeMemo />
		</section>
	);
}
