import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Drawer, ErrorNotice, Inline, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { StageBadge, formatDate, useApp } from './shared';

/**
 * Read-only profile view.
 *
 * @param {Object} props   Props.
 * @param {Object} props.p Profile.
 * @return {Element} View.
 */
export function ProfileView( { p } ) {
	return (
		<div className="dev3jb-profileview">
			{ p.headline && (
				<p>
					<strong>{ p.headline }</strong>
					{ p.location ? ` · ${ p.location }` : '' }
				</p>
			) }
			{ p.summary && <p className="dev3jb-prewrap">{ p.summary }</p> }
			{ p.skills && p.skills.length > 0 && (
				<div className="dev3jb-chiprow">
					{ p.skills.map( ( s ) => (
						<Badge key={ s }>{ s }</Badge>
					) ) }
				</div>
			) }
			{ p.experience && p.experience.length > 0 && (
				<>
					<h4>{ __( 'Experience', 'dev3-ai-job-board' ) }</h4>
					<ul className="dev3jb-timeline">
						{ p.experience.map( ( e, i ) => (
							<li key={ i }>
								<strong>{ e.title }</strong> { e.company && `· ${ e.company }` }
								<span className="dev3jb-muted">
									{ ' ' }
									{ e.start } – { e.current ? __( 'now', 'dev3-ai-job-board' ) : e.end }
								</span>
								{ e.summary && <p>{ e.summary }</p> }
							</li>
						) ) }
					</ul>
				</>
			) }
			{ p.education && p.education.length > 0 && (
				<>
					<h4>{ __( 'Education', 'dev3-ai-job-board' ) }</h4>
					<ul className="dev3jb-plainlist">
						{ p.education.map( ( e, i ) => (
							<li key={ i }>
								{ [ e.degree, e.field ].filter( Boolean ).join( ', ' ) } { e.school && `· ${ e.school }` } { e.year && `(${ e.year })` }
							</li>
						) ) }
					</ul>
				</>
			) }
			{ p.links && p.links.length > 0 && (
				<Inline gap={ 8 }>
					{ p.links.map( ( l ) => (
						<a key={ l.url } href={ l.url } target="_blank" rel="noopener noreferrer">
							{ l.label || l.url }
						</a>
					) ) }
				</Inline>
			) }
		</div>
	);
}

/**
 * Resume parser playground: upload a file and see what the parser reads (nothing is stored).
 *
 * @return {Element} Card.
 */
function ParserTest() {
	const { meta, onUsage } = useApp();
	const toast = useToast();
	const input = useRef( null );
	const [ useAi, setUseAi ] = useState( meta.ai.parse );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	const run = () => {
		const file = input.current && input.current.files[ 0 ];
		if ( ! file ) {
			toast.warning( __( 'Choose a resume file first.', 'dev3-ai-job-board' ) );
			return;
		}
		const body = new window.FormData();
		body.append( 'file', file );
		body.append( 'ai', useAi ? '1' : 'false' );
		setBusy( true );
		setError( null );
		request( 'admin/ai/parse', { method: 'POST', body } )
			.then( setResult )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	return (
		<Card tone="ai" title={ __( 'Try the resume parser', 'dev3-ai-job-board' ) } description={ __( 'Upload a sample resume (PDF, Word or text) to see what candidates get filled in. The file is deleted right after.', 'dev3-ai-job-board' ) }>
			<Stack gap={ 12 }>
				<Inline gap={ 12 }>
					<label className="dev3jb-filefield">
						<span className="screen-reader-text">{ __( 'Resume file', 'dev3-ai-job-board' ) }</span>
						<input ref={ input } type="file" accept=".pdf,.docx,.txt" />
					</label>
					{ meta.ai.parse && <Toggle label={ __( 'Use DEV3 AI', 'dev3-ai-job-board' ) } checked={ useAi } onChange={ setUseAi } /> }
					<AiButton onClick={ run } loading={ busy } loadingText={ __( 'Reading…', 'dev3-ai-job-board' ) }>
						{ useAi ? __( 'Parse with DEV3 AI', 'dev3-ai-job-board' ) : __( 'Parse without AI', 'dev3-ai-job-board' ) }
					</AiButton>
				</Inline>
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
				{ result && (
					<>
						<Notice tone={ result.source === 'ai' ? 'ai' : 'info' } title={ result.source === 'ai' ? __( 'Read by DEV3 AI', 'dev3-ai-job-board' ) : __( 'Read by the built-in parser', 'dev3-ai-job-board' ) }>
							{ sprintf( /* translators: %s: sections */ __( 'Filled: %s. Protected attributes (age, gender, photo, nationality…) are never extracted.', 'dev3-ai-job-board' ), result.filled.join( ', ' ) || __( 'nothing', 'dev3-ai-job-board' ) ) }
						</Notice>
						<ProfileView p={ { experience: [], education: [], links: [], skills: [], ...result.profile } } />
					</>
				) }
			</Stack>
		</Card>
	);
}

/**
 * Candidates (private profiles).
 *
 * @return {Element} Screen.
 */
export default function Candidates() {
	const { meta } = useApp();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ open, setOpen ] = useState( 0 );
	const [ profile, setProfile ] = useState( null );

	const load = () => {
		setError( null );
		request( `admin/candidates?${ new URLSearchParams( { search: query, page } ).toString() }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ query, page ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		setProfile( null );
		if ( open ) {
			request( `admin/candidates/${ open }` ).then( setProfile ).catch( setError );
		}
	}, [ open ] );

	return (
		<>
			<PageHeader title={ __( 'Candidates', 'dev3-ai-job-board' ) } description={ __( 'Candidate profiles are private: only the candidate, employers they apply to and site managers can see them.', 'dev3-ai-job-board' ) } />
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
						<TextField label={ __( 'Search candidates', 'dev3-ai-job-board' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Headline…', 'dev3-ai-job-board' ) } />
						<Button type="submit" icon="search">
							{ __( 'Search', 'dev3-ai-job-board' ) }
						</Button>
					</form>
				</div>
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					loading={ ! data && ! error }
					rows={ data ? data.items : [] }
					onRowClick={ ( r ) => setOpen( r.id ) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					columns={ [
						{ key: 'name', label: __( 'Candidate', 'dev3-ai-job-board' ), primary: true, render: ( r ) => <><strong>{ r.name }</strong><br /><span className="dev3jb-muted">{ r.headline || r.email }</span></> },
						{ key: 'skills', label: __( 'Top skills', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( r ) => r.skills.slice( 0, 4 ).join( ', ' ) || '—' },
						{ key: 'years', label: __( 'Experience', 'dev3-ai-job-board' ), render: ( r ) => ( r.years ? sprintf( /* translators: %s: years */ __( '%s yrs', 'dev3-ai-job-board' ), r.years ) : '—' ) },
						{ key: 'complete', label: __( 'Profile', 'dev3-ai-job-board' ), render: ( r ) => `${ r.complete }%` },
						{ key: 'applications', label: __( 'Applications', 'dev3-ai-job-board' ), align: 'end' },
						{ key: 'updated', label: __( 'Updated', 'dev3-ai-job-board' ), align: 'end', hideOnMobile: true, render: ( r ) => formatDate( r.updated ) },
					] }
					empty={ { icon: 'users', title: __( 'No candidate profiles yet', 'dev3-ai-job-board' ), description: __( 'Candidates create a profile in the candidate dashboard, often by uploading a resume.', 'dev3-ai-job-board' ) } }
				/>
			</Card>
			<ParserTest />
			<Drawer open={ !! open } onClose={ () => setOpen( 0 ) } size="lg" title={ profile ? profile.name : __( 'Candidate', 'dev3-ai-job-board' ) } description={ profile ? profile.email : '' }>
				{ ! profile && <SkeletonCard lines={ 6 } /> }
				{ profile && (
					<Stack gap={ 16 }>
						{ profile.file && (
							<Button icon="file" variant="secondary" href={ profile.file.url } target="_blank">
								{ profile.file.name }
							</Button>
						) }
						<ProfileView p={ profile } />
						<h3 className="dev3jb-h3">{ __( 'Applications', 'dev3-ai-job-board' ) }</h3>
						{ profile.applications.length ? (
							<ul className="dev3jb-plainlist">
								{ profile.applications.map( ( a ) => (
									<li key={ a.id }>
										<a href={ `#/applications/${ a.job_id }/${ a.id }` }>{ a.job_title }</a> <StageBadge stage={ a.stage } labels={ meta.stages } />
									</li>
								) ) }
							</ul>
						) : (
							<p className="dev3jb-muted">{ __( 'No applications yet.', 'dev3-ai-job-board' ) }</p>
						) }
					</Stack>
				) }
			</Drawer>
		</>
	);
}
