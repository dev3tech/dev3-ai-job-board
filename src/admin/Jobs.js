import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ErrorNotice, Inline, Modal, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import { StatusBadge, formatDate, useApp } from './shared';

const PER_PAGE = 20;
const STATUSES = [ 'publish', 'pending', 'draft', 'dev3jb_unpaid', 'dev3jb_expired' ];

/**
 * Jobs table with status tabs, search, company filter, sorting and bulk actions.
 *
 * @param {Object} props               Props.
 * @param {string} props.initialStatus Status tab.
 * @return {Element} Jobs.
 */
export default function Jobs( { initialStatus = '' } ) {
	const { meta, navigate } = useApp();
	const toast = useToast();
	const [ status, setStatus ] = useState( STATUSES.includes( initialStatus ) ? initialStatus : '' );
	const [ company, setCompany ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { key: 'date', dir: 'desc' } );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const load = () => {
		setError( null );
		const params = new URLSearchParams( { page, per_page: PER_PAGE, status, company, search: query, orderby: sort.key === 'title' ? 'title' : 'date', order: sort.dir } );
		request( `admin/jobs?${ params.toString() }` )
			.then( ( d ) => {
				setData( d );
				setSelected( [] );
			} )
			.catch( setError );
	};
	useEffect( load, [ page, status, company, query, sort.key, sort.dir ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const bulk = ( action ) => {
		setBusy( true );
		request( 'admin/jobs/bulk', { method: 'POST', data: { ids: selected, action } } )
			.then( ( r ) => {
				/* translators: %d: number of jobs */
				toast.success( sprintf( __( '%d jobs updated.', 'dev3-ai-job-board' ), r.updated ) );
				setConfirm( false );
				load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	const counts = data ? data.counts : {};
	const all = Object.values( counts ).reduce( ( a, b ) => a + b, 0 );
	const tabs = [
		{ id: '', label: __( 'All', 'dev3-ai-job-board' ), badge: all || undefined },
		{ id: 'publish', label: __( 'Live', 'dev3-ai-job-board' ), badge: counts.publish || undefined },
		{ id: 'pending', label: __( 'In review', 'dev3-ai-job-board' ), badge: counts.pending || undefined },
		{ id: 'dev3jb_unpaid', label: __( 'Awaiting payment', 'dev3-ai-job-board' ), badge: counts.dev3jb_unpaid || undefined },
		{ id: 'dev3jb_expired', label: __( 'Expired', 'dev3-ai-job-board' ), badge: counts.dev3jb_expired || undefined },
		{ id: 'draft', label: __( 'Drafts', 'dev3-ai-job-board' ), badge: counts.draft || undefined },
	];
	const rows = data ? data.items : [];
	const allSelected = rows.length > 0 && rows.every( ( r ) => selected.includes( r.id ) );
	const toggle = ( id ) => setSelected( selected.includes( id ) ? selected.filter( ( x ) => x !== id ) : [ ...selected, id ] );

	return (
		<>
			<PageHeader
				title={ __( 'Jobs', 'dev3-ai-job-board' ) }
				description={ __( 'Every job on your board: approve submissions, feature, expire and edit.', 'dev3-ai-job-board' ) }
				actions={
					<Button variant="primary" icon="plus" href="#/jobs/new">
						{ __( 'Add job', 'dev3-ai-job-board' ) }
					</Button>
				}
			/>
			<div className="dev3jb-tabscroll">
				<Tabs
					label={ __( 'Job status', 'dev3-ai-job-board' ) }
					tabs={ tabs }
					value={ status }
					onChange={ ( v ) => {
						setStatus( v );
						setPage( 1 );
					} }
				/>
			</div>
			<Card flush>
				<div className="dev3jb-toolbar">
					<form
						className="dev3jb-toolbar__search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							setPage( 1 );
							setQuery( search );
						} }
					>
						<TextField label={ __( 'Search jobs', 'dev3-ai-job-board' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search by title or description…', 'dev3-ai-job-board' ) } />
						<Button type="submit" icon="search">
							{ __( 'Search', 'dev3-ai-job-board' ) }
						</Button>
					</form>
					<Select
						label={ __( 'Company', 'dev3-ai-job-board' ) }
						hideLabel
						value={ company }
						onChange={ ( v ) => {
							setCompany( v );
							setPage( 1 );
						} }
						options={ [ { value: '', label: __( 'All companies', 'dev3-ai-job-board' ) }, ...meta.companies.map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) ] }
					/>
				</div>
				<div className="dev3jb-bulkbar" role="region" aria-label={ __( 'Bulk actions', 'dev3-ai-job-board' ) }>
					<Checkbox label={ selected.length ? sprintf( /* translators: %d: count */ __( '%d selected', 'dev3-ai-job-board' ), selected.length ) : __( 'Select all on this page', 'dev3-ai-job-board' ) } checked={ allSelected } onChange={ () => setSelected( allSelected ? [] : rows.map( ( r ) => r.id ) ) } />
					{ selected.length > 0 && (
						<Inline gap={ 8 }>
							<Button size="sm" onClick={ () => bulk( 'approve' ) } loading={ busy }>
								{ __( 'Approve / publish', 'dev3-ai-job-board' ) }
							</Button>
							<Button size="sm" onClick={ () => bulk( 'feature' ) }>
								{ __( 'Feature', 'dev3-ai-job-board' ) }
							</Button>
							<Button size="sm" onClick={ () => bulk( 'unfeature' ) }>
								{ __( 'Unfeature', 'dev3-ai-job-board' ) }
							</Button>
							<Button size="sm" onClick={ () => bulk( 'expire' ) }>
								{ __( 'Expire', 'dev3-ai-job-board' ) }
							</Button>
							<Button size="sm" variant="danger" icon="trash" onClick={ () => setConfirm( true ) }>
								{ __( 'Move to trash', 'dev3-ai-job-board' ) }
							</Button>
						</Inline>
					) }
				</div>
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					caption={ __( 'Jobs', 'dev3-ai-job-board' ) }
					loading={ ! data && ! error }
					rows={ rows }
					sort={ sort }
					onSortChange={ setSort }
					onRowClick={ ( r ) => navigate( `/jobs/${ r.id }` ) }
					pagination={ data ? { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'list',
						title: query ? __( 'No jobs match your search', 'dev3-ai-job-board' ) : __( 'No jobs here yet', 'dev3-ai-job-board' ),
						description: __( 'Add a job, or let employers post their own from the front end.', 'dev3-ai-job-board' ),
						action: <Button href="#/jobs/new">{ __( 'Add job', 'dev3-ai-job-board' ) }</Button>,
					} }
					columns={ [
						{
							key: 'select',
							label: __( 'Select', 'dev3-ai-job-board' ),
							width: 44,
							hideOnMobile: true,
							render: ( r ) => (
								// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
								<span onClick={ ( e ) => e.stopPropagation() }>
									<Checkbox label={ <span className="screen-reader-text">{ sprintf( /* translators: %s: job */ __( 'Select %s', 'dev3-ai-job-board' ), r.title ) }</span> } checked={ selected.includes( r.id ) } onChange={ () => toggle( r.id ) } />
								</span>
							),
						},
						{
							key: 'title',
							label: __( 'Job', 'dev3-ai-job-board' ),
							primary: true,
							sortable: true,
							render: ( r ) => (
								<span className="dev3jb-cell-title">
									{ r.company && r.company.logo ? <img src={ r.company.logo } alt="" /> : <span className="dev3jb-cell-ph" aria-hidden="true">{ ( r.company ? r.company.name : r.title ).slice( 0, 1 ) }</span> }
									<span>
										<a href={ `#/jobs/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>
											{ r.title }
										</a>
										<span className="dev3jb-cell-sub">
											{ r.company ? r.company.name : __( 'No company', 'dev3-ai-job-board' ) }
											{ r.featured && <Badge tone="brand">{ __( 'Featured', 'dev3-ai-job-board' ) }</Badge> }
											{ r.filled && <Badge>{ __( 'Filled', 'dev3-ai-job-board' ) }</Badge> }
										</span>
									</span>
								</span>
							),
						},
						{ key: 'status', label: __( 'Status', 'dev3-ai-job-board' ), render: ( r ) => <StatusBadge status={ r.status } /> },
						{ key: 'type', label: __( 'Type', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( r ) => r.types.map( ( t ) => t.name ).join( ', ' ) || '—' },
						{ key: 'applications', label: __( 'Applications', 'dev3-ai-job-board' ), align: 'end', render: ( r ) => ( r.applications ? <a href={ `#/applications/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>{ r.applications }</a> : '0' ) },
						{ key: 'expires', label: __( 'Expires', 'dev3-ai-job-board' ), hideOnMobile: true, nowrap: true, render: ( r ) => ( r.expires ? formatDate( r.expires ) : '—' ) },
						{ key: 'date', label: __( 'Posted', 'dev3-ai-job-board' ), sortable: true, align: 'end', nowrap: true, render: ( r ) => formatDate( r.date ) },
					] }
					rowActions={ ( r ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span className="dev3jb-rowactions" onClick={ ( e ) => e.stopPropagation() }>
							<Button size="sm" variant="ghost" icon="edit" href={ `#/jobs/${ r.id }` } label={ __( 'Edit', 'dev3-ai-job-board' ) } />
							<Button size="sm" variant="ghost" icon="external" href={ r.url } target="_blank" label={ __( 'View', 'dev3-ai-job-board' ) } />
						</span>
					) }
				/>
			</Card>
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				/* translators: %d: count */
				title={ sprintf( __( 'Move %d jobs to the trash?', 'dev3-ai-job-board' ), selected.length ) }
				description={ __( 'They disappear from the board. You can restore them from the WordPress trash for 30 days.', 'dev3-ai-job-board' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-job-board' ) }
						</Button>
						<Button variant="danger" onClick={ () => bulk( 'delete' ) } loading={ busy }>
							{ __( 'Move to trash', 'dev3-ai-job-board' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
