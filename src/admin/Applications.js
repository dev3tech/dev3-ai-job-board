import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Button, Card, ErrorNotice, Inline, JobProgress, PageHeader, ResponsiveTable, Select, SkeletonCard, Tabs, TextField, useToast } from '../core3/ui';
import { STAGES, ScoreBadge, StageBadge, formatDate, useApp } from './shared';
import Pipeline, { moveLocal } from './Pipeline';
import ApplicationDrawer from './ApplicationDrawer';

/**
 * Applications: per-job kanban pipeline (list on phones) or a searchable list across all jobs.
 *
 * @param {Object} props            Props.
 * @param {number} props.initialJob Job to open.
 * @param {number} props.initialApp Application to open.
 * @return {Element} Screen.
 */
export default function Applications( { initialJob = 0, initialApp = 0 } ) {
	const { meta, onUsage } = useApp();
	const toast = useToast();
	const [ jobs, setJobs ] = useState( null );
	const [ jobId, setJobId ] = useState( initialJob );
	const [ view, setView ] = useState( initialJob ? 'board' : 'list' );
	const [ byScore, setByScore ] = useState( false );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( initialApp );
	const [ stage, setStage ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ queue, setQueue ] = useState( null );

	useEffect( () => {
		request( 'admin/jobs?per_page=100&status=' )
			.then( ( r ) => setJobs( r.items ) )
			.catch( setError );
	}, [] );

	const load = () => {
		setError( null );
		const board = view === 'board' && jobId;
		const params = new URLSearchParams( { job: jobId || '', stage: board ? '' : stage, search: board ? '' : query, page: board ? 1 : page, per_page: board ? 500 : 25, orderby: board ? 'position' : byScore ? 'score' : 'date' } );
		request( `admin/applications?${ params.toString() }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ jobId, view, stage, query, page, byScore ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const move = ( id, to, index ) => {
		const before = data.items;
		setData( { ...data, items: moveLocal( data.items, id, to, index ) } );
		request( `admin/applications/${ id }`, { method: 'POST', data: { stage: to, index } } )
			.then( ( a ) => {
				/* translators: 1: candidate, 2: stage */
				toast.success( sprintf( __( '%1$s moved to %2$s.', 'dev3-ai-job-board' ), a.name, meta.stages[ to ] ) );
			} )
			.catch( ( e ) => {
				setData( { ...data, items: before } );
				toast.error( e );
			} );
	};

	const changed = ( card ) => setData( ( d ) => ( d ? { ...d, items: d.items.map( ( a ) => ( a.id === card.id ? { ...a, ...card } : a ) ) } : d ) );

	const scoreAll = ( ai ) =>
		request( `admin/jobs/${ jobId }/score`, { method: 'POST', data: { ai } } )
			.then( ( r ) => setQueue( r.job ) )
			.catch( ( e ) => toast.error( e ) );

	const jobOptions = [ { value: '', label: __( 'All jobs', 'dev3-ai-job-board' ) }, ...( jobs || [] ).map( ( j ) => ( { value: String( j.id ), label: `${ j.title } (${ j.applications })` } ) ) ];
	const counts = data ? data.counts : {};
	const total = STAGES.reduce( ( a, s ) => a + ( counts[ s ] || 0 ), 0 );

	return (
		<>
			<PageHeader title={ __( 'Applications', 'dev3-ai-job-board' ) } description={ __( 'Move candidates through your hiring stages. Drag cards on a computer, or use "Move to" on any device.', 'dev3-ai-job-board' ) } />
			<Card>
				<div className="dev3jb-toolbar dev3jb-toolbar--plain">
					<Select
						label={ __( 'Job', 'dev3-ai-job-board' ) }
						value={ String( jobId || '' ) }
						onChange={ ( v ) => {
							setJobId( Number( v ) || 0 );
							setView( v ? 'board' : 'list' );
							setPage( 1 );
						} }
						options={ jobOptions }
					/>
					<Inline gap={ 8 }>
						{ jobId > 0 && (
							<div className="dev3jb-seg" role="group" aria-label={ __( 'View', 'dev3-ai-job-board' ) }>
								<Button size="sm" variant={ view === 'board' ? 'primary' : 'secondary' } icon="columns" onClick={ () => setView( 'board' ) } aria-pressed={ view === 'board' }>
									{ __( 'Board', 'dev3-ai-job-board' ) }
								</Button>
								<Button size="sm" variant={ view === 'list' ? 'primary' : 'secondary' } icon="list" onClick={ () => setView( 'list' ) } aria-pressed={ view === 'list' }>
									{ __( 'List', 'dev3-ai-job-board' ) }
								</Button>
							</div>
						) }
						<Button size="sm" variant={ byScore ? 'primary' : 'secondary' } icon="sort" onClick={ () => setByScore( ! byScore ) } aria-pressed={ byScore }>
							{ __( 'Best match first', 'dev3-ai-job-board' ) }
						</Button>
						{ jobId > 0 && total > 0 && (
							<>
								<Button size="sm" onClick={ () => scoreAll( false ) }>
									{ __( 'Score all', 'dev3-ai-job-board' ) }
								</Button>
								{ meta.ai.match && (
									<AiButton size="sm" onClick={ () => scoreAll( true ) }>
										{ __( 'Explain all with DEV3 AI', 'dev3-ai-job-board' ) }
									</AiButton>
								) }
							</>
						) }
					</Inline>
				</div>
				{ queue && (
					<JobProgress
						jobId={ queue }
						title={ __( 'Scoring applications', 'dev3-ai-job-board' ) }
						onDone={ () => {
							load();
							onUsage();
						} }
					/>
				) }
				<p className="dev3jb-fairnote">{ __( 'Match scores are decision support only. They use job-relevant skills, experience, role and location fit; never names, photos, age, gender or other personal characteristics.', 'dev3-ai-job-board' ) }</p>
			</Card>
			<ErrorNotice error={ error } onRetry={ load } />
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && view === 'board' && jobId > 0 && <Pipeline items={ data.items } labels={ meta.stages } byScore={ byScore } onMove={ move } onOpen={ setOpen } /> }
			{ data && ( view === 'list' || ! jobId ) && (
				<>
					<div className="dev3jb-tabscroll">
						<Tabs
							label={ __( 'Stage', 'dev3-ai-job-board' ) }
							tabs={ [ { id: '', label: __( 'All', 'dev3-ai-job-board' ), badge: total || undefined }, ...STAGES.map( ( s ) => ( { id: s, label: meta.stages[ s ], badge: counts[ s ] || undefined } ) ) ] }
							value={ stage }
							onChange={ ( v ) => {
								setStage( v );
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
								<TextField label={ __( 'Search applications', 'dev3-ai-job-board' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Name or email…', 'dev3-ai-job-board' ) } />
								<Button type="submit" icon="search">
									{ __( 'Search', 'dev3-ai-job-board' ) }
								</Button>
							</form>
						</div>
						<ResponsiveTable
							rows={ data.items }
							onRowClick={ ( r ) => setOpen( r.id ) }
							pagination={ { page, perPage: 25, total: data.total, onPageChange: setPage } }
							columns={ [
								{ key: 'name', label: __( 'Candidate', 'dev3-ai-job-board' ), primary: true, render: ( r ) => <><strong>{ r.name }</strong><br /><span className="dev3jb-muted">{ r.headline || r.email }</span></> },
								{ key: 'job_title', label: __( 'Job', 'dev3-ai-job-board' ), hideOnMobile: !! jobId },
								{ key: 'stage', label: __( 'Stage', 'dev3-ai-job-board' ), render: ( r ) => <StageBadge stage={ r.stage } labels={ meta.stages } /> },
								{ key: 'match_score', label: __( 'Match', 'dev3-ai-job-board' ), render: ( r ) => ( r.match_score === null ? '—' : <ScoreBadge score={ r.match_score } /> ) },
								{ key: 'rating', label: __( 'Rating', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( r ) => ( r.rating ? '★'.repeat( r.rating ) : '—' ) },
								{ key: 'created_at', label: __( 'Applied', 'dev3-ai-job-board' ), align: 'end', nowrap: true, render: ( r ) => formatDate( r.created_at ) },
							] }
							empty={ { icon: 'inbox', title: __( 'No applications here', 'dev3-ai-job-board' ), description: __( 'Applications from the job pages appear here.', 'dev3-ai-job-board' ) } }
						/>
					</Card>
				</>
			) }
			<ApplicationDrawer id={ open } onClose={ () => setOpen( 0 ) } onChanged={ changed } />
		</>
	);
}
