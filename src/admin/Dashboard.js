import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, Skeleton, Stat, StatGrid } from '../core3/ui';
import { ScoreBadge, StageBadge, STAGES, formatDate, money, useApp } from './shared';

/**
 * Daily applications: single-series bar chart with hover/focus tooltips and a screen-reader table.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.days [ { date, count } ].
 * @return {Element} Chart.
 */
export function DailyChart( { days } ) {
	const [ hover, setHover ] = useState( null );
	const max = Math.max( 1, ...days.map( ( d ) => d.count ) );
	const total = days.reduce( ( a, d ) => a + d.count, 0 );
	return (
		<figure className="dev3jb-chart">
			<div className="dev3jb-chart__plot" role="img" aria-label={ sprintf( /* translators: 1: total, 2: days */ __( '%1$d applications in the last %2$d days', 'dev3-ai-job-board' ), total, days.length ) }>
				<span className="dev3jb-chart__max">{ max }</span>
				{ days.map( ( d, i ) => (
					<button
						type="button"
						key={ d.date }
						className="dev3jb-chart__col"
						onMouseEnter={ () => setHover( i ) }
						onMouseLeave={ () => setHover( null ) }
						onFocus={ () => setHover( i ) }
						onBlur={ () => setHover( null ) }
						aria-label={ sprintf( /* translators: 1: date, 2: count */ __( '%1$s: %2$d applications', 'dev3-ai-job-board' ), formatDate( d.date ), d.count ) }
					>
						<span className="dev3jb-chart__bar" style={ { height: `${ Math.max( d.count ? 4 : 0, ( d.count / max ) * 100 ) }%` } } />
						{ hover === i && (
							<span className="dev3jb-chart__tip" role="tooltip">
								<strong>{ d.count }</strong> { formatDate( d.date ) }
							</span>
						) }
					</button>
				) ) }
			</div>
			<figcaption className="dev3jb-chart__axis">
				<span>{ formatDate( days[ 0 ] && days[ 0 ].date ) }</span>
				<span>{ formatDate( days[ days.length - 1 ] && days[ days.length - 1 ].date ) }</span>
			</figcaption>
		</figure>
	);
}

export default function Dashboard() {
	const { meta, navigate } = useApp();
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'admin/stats' ).then( setStats ).catch( setError );
	};
	useEffect( load, [] );

	const jobs = stats ? stats.jobs : {};
	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-job-board' ) }
				description={ __( 'Your job board at a glance: jobs, applications, revenue and what needs your attention.', 'dev3-ai-job-board' ) }
				actions={
					<>
						<Button variant="secondary" icon="external" href={ meta.urls.jobs } target="_blank">
							{ __( 'View job board', 'dev3-ai-job-board' ) }
						</Button>
						<Button variant="primary" icon="plus" href="#/jobs/new">
							{ __( 'Add job', 'dev3-ai-job-board' ) }
						</Button>
					</>
				}
			/>
			{ ! meta.ai.key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-job-board' ) } actions={ <Button variant="ai" size="sm" href="#/api">{ __( 'Add API key', 'dev3-ai-job-board' ) }</Button> }>
					{ __( 'Everything works without AI. Add a Google Gemini API key to turn on the job description writer, resume parsing and explained match scores.', 'dev3-ai-job-board' ) }
				</Notice>
			) }
			<ErrorNotice error={ error } onRetry={ load } />
			<StatGrid>
				<Stat label={ __( 'Live jobs', 'dev3-ai-job-board' ) } value={ stats ? jobs.publish : '' } icon="list" tone="brand" loading={ ! stats } />
				<Stat label={ __( 'Waiting for review', 'dev3-ai-job-board' ) } value={ stats ? jobs.pending : '' } icon="clock" tone={ stats && jobs.pending ? 'warning' : 'neutral' } loading={ ! stats } />
				<Stat label={ __( 'Applications (30 days)', 'dev3-ai-job-board' ) } value={ stats ? stats.applications_30 : '' } hint={ stats ? sprintf( /* translators: %d: count */ __( '%d in the last 7 days', 'dev3-ai-job-board' ), stats.applications_7 ) : '' } icon="inbox" loading={ ! stats } />
				<Stat label={ __( 'Candidate profiles', 'dev3-ai-job-board' ) } value={ stats ? stats.candidates : '' } icon="users" loading={ ! stats } />
				<Stat label={ __( 'Revenue (30 days)', 'dev3-ai-job-board' ) } value={ stats ? money( stats.revenue_30, meta.currency ) : '' } icon="chart" tone="success" loading={ ! stats } />
			</StatGrid>
			<Columns min={ 320 }>
				<Card title={ __( 'Applications per day', 'dev3-ai-job-board' ) } description={ __( 'Last 14 days', 'dev3-ai-job-board' ) }>
					{ stats ? <DailyChart days={ stats.daily } /> : <Skeleton height={ 160 } /> }
				</Card>
				<Card title={ __( 'Pipeline', 'dev3-ai-job-board' ) } description={ __( 'All open applications by stage', 'dev3-ai-job-board' ) } actions={ <Button size="sm" variant="ghost" href="#/applications">{ __( 'Open', 'dev3-ai-job-board' ) }</Button> }>
					{ stats ? (
						<ul className="dev3jb-stagebars">
							{ STAGES.map( ( s ) => {
								const n = stats.stages[ s ] || 0;
								const all = Math.max( 1, ...STAGES.map( ( x ) => stats.stages[ x ] || 0 ) );
								return (
									<li key={ s }>
										<span className="dev3jb-stagebars__label">{ meta.stages[ s ] }</span>
										<span className="dev3jb-stagebars__track">
											<span className="dev3jb-stagebars__fill" style={ { width: `${ ( n / all ) * 100 }%` } } />
										</span>
										<span className="dev3jb-stagebars__n">{ n }</span>
									</li>
								);
							} ) }
						</ul>
					) : (
						<Skeleton lines={ 6 } />
					) }
				</Card>
			</Columns>
			<Columns min={ 320 }>
				<Card title={ __( 'Latest applications', 'dev3-ai-job-board' ) } flush>
					<ResponsiveTable
						loading={ ! stats }
						rows={ stats ? stats.recent : [] }
						onRowClick={ ( r ) => navigate( `/applications/${ r.job_id }/${ r.id }` ) }
						columns={ [
							{ key: 'name', label: __( 'Candidate', 'dev3-ai-job-board' ), primary: true, render: ( r ) => <><strong>{ r.name }</strong><br /><span className="dev3jb-muted">{ r.job_title }</span></> },
							{ key: 'stage', label: __( 'Stage', 'dev3-ai-job-board' ), render: ( r ) => <StageBadge stage={ r.stage } labels={ meta.stages } /> },
							{ key: 'match_score', label: __( 'Match', 'dev3-ai-job-board' ), render: ( r ) => ( r.match_score === null ? '—' : <ScoreBadge score={ r.match_score } /> ) },
							{ key: 'created_at', label: __( 'Applied', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( r ) => formatDate( r.created_at ) },
						] }
						empty={ { icon: 'inbox', title: __( 'No applications yet', 'dev3-ai-job-board' ), description: __( 'They appear here as soon as candidates apply.', 'dev3-ai-job-board' ) } }
					/>
				</Card>
				<Card title={ __( 'Needs your review', 'dev3-ai-job-board' ) } flush>
					{ stats && ! stats.pending.length ? (
						<EmptyState compact icon="success" title={ __( 'All caught up', 'dev3-ai-job-board' ) } description={ __( 'No jobs are waiting for approval.', 'dev3-ai-job-board' ) } />
					) : (
						<ResponsiveTable
							loading={ ! stats }
							rows={ stats ? stats.pending : [] }
							onRowClick={ ( r ) => navigate( `/jobs/${ r.id }` ) }
							columns={ [
								{ key: 'title', label: __( 'Job', 'dev3-ai-job-board' ), primary: true },
								{ key: 'date', label: __( 'Submitted', 'dev3-ai-job-board' ), render: ( r ) => formatDate( r.date ) },
							] }
						/>
					) }
				</Card>
			</Columns>
			<Card title={ __( 'Most applied jobs', 'dev3-ai-job-board' ) } flush>
				<ResponsiveTable
					loading={ ! stats }
					rows={ stats ? stats.top : [] }
					onRowClick={ ( r ) => navigate( `/applications/${ r.id }` ) }
					columns={ [
						{ key: 'title', label: __( 'Job', 'dev3-ai-job-board' ), primary: true },
						{ key: 'company', label: __( 'Company', 'dev3-ai-job-board' ) },
						{ key: 'applications', label: __( 'Applications', 'dev3-ai-job-board' ), align: 'end' },
						{ key: 'views', label: __( 'Views', 'dev3-ai-job-board' ), align: 'end', hideOnMobile: true },
					] }
					empty={ { icon: 'list', title: __( 'No live jobs yet', 'dev3-ai-job-board' ), action: <Button href="#/jobs/new" variant="primary">{ __( 'Add job', 'dev3-ai-job-board' ) }</Button> } }
				/>
			</Card>
		</>
	);
}
