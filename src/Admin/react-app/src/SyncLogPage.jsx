import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { fetchSyncLog } from './api';

const STATUS_FILTERS = [
	{ label: '— Tous —', value: '' },
	{ label: 'OK', value: 'ok' },
	{ label: 'Retry', value: 'retry' },
	{ label: 'Failed', value: 'failed' },
];

export default function SyncLogPage() {
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const perPage = 50;

	useEffect( () => {
		let cancelled = false;
		const load = async () => {
			setLoading( true );
			setError( null );
			try {
				const data = await fetchSyncLog( { page, perPage, status } );
				if ( cancelled ) {
					return;
				}
				setItems( data.items );
				setTotal( data.total );
			} catch ( e ) {
				if ( ! cancelled ) {
					setError( e.message ?? String( e ) );
				}
			} finally {
				if ( ! cancelled ) {
					setLoading( false );
				}
			}
		};
		load();
		return () => {
			cancelled = true;
		};
	}, [ page, status ] );

	if ( loading && items.length === 0 ) {
		return <Spinner />;
	}

	const pages = Math.max( 1, Math.ceil( total / perPage ) );

	return (
		<div>
			<h2>{ __( 'Journal de synchronisation', 'slashbooking' ) }</h2>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<SelectControl
				label={ __( 'Statut', 'slashbooking' ) }
				value={ status }
				options={ STATUS_FILTERS }
				onChange={ ( v ) => {
					setStatus( v );
					setPage( 1 );
				} }
			/>
			<table className="wp-list-table widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Date', 'slashbooking' ) }</th>
						<th>{ __( 'Direction', 'slashbooking' ) }</th>
						<th>{ __( 'Entité', 'slashbooking' ) }</th>
						<th>{ __( 'Action', 'slashbooking' ) }</th>
						<th>{ __( 'Statut', 'slashbooking' ) }</th>
						<th>{ __( 'Erreur', 'slashbooking' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( it ) => (
						<tr key={ it.id }>
							<td>{ it.ts }</td>
							<td>{ it.direction }</td>
							<td>
								{ it.entity }#{ it.entity_id ?? '–' }
							</td>
							<td>{ it.action }</td>
							<td>{ it.status }</td>
							<td>{ it.error_message ?? '' }</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<div className="sb-pagination">
				<Button
					disabled={ page <= 1 }
					onClick={ () => setPage( page - 1 ) }
				>
					‹
				</Button>
				<span>
					{ page } / { pages }
				</span>
				<Button
					disabled={ page >= pages }
					onClick={ () => setPage( page + 1 ) }
				>
					›
				</Button>
			</div>
		</div>
	);
}
