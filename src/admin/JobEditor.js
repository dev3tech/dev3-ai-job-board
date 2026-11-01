import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, Inline, JobProgress, Notice, NumberField, PageHeader, Select, SkeletonCard, Stack, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import { STAGES, applyFixes, termOptions, useApp } from './shared';

/**
 * HTML → simple markup ("## Heading", "- item", blank-line paragraphs).
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function htmlToSimple( html ) {
	return String( html || '' )
		.replace( /\r/g, '' )
		.replace( />\s+</g, '><' )
		.replace( /<h[2-4][^>]*>([\s\S]*?)<\/h[2-4]>/gi, '\n\n## $1\n' )
		.replace( /<li[^>]*>([\s\S]*?)<\/li>/gi, '- $1\n' )
		.replace( /<\/(ul|ol)>/gi, '\n\n' )
		.replace( /<(ul|ol)[^>]*>/gi, '' )
		.replace( /<\/p>/gi, '\n\n' )
		.replace( /<br\s*\/?>/gi, '\n' )
		.replace( /<[^>]+>/g, '' )
		.replace( /&amp;/g, '&' )
		.replace( /&#0?39;|&#8217;/g, "'" )
		.replace( /&quot;/g, '"' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /\n{3,}/g, '\n\n' )
		.trim();
}

/**
 * Simple markup → HTML.
 *
 * @param {string} text Text.
 * @return {string} HTML.
 */
export function simpleToHtml( text ) {
	const esc = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	const out = [];
	let list = [];
	let para = [];
	const flushList = () => {
		if ( list.length ) {
			out.push( `<ul>\n${ list.map( ( i ) => `<li>${ esc( i ) }</li>` ).join( '\n' ) }\n</ul>` );
			list = [];
		}
	};
	const flushPara = () => {
		if ( para.length ) {
			out.push( `<p>${ esc( para.join( ' ' ) ) }</p>` );
			para = [];
		}
	};
	String( text || '' )
		.split( '\n' )
		.forEach( ( raw ) => {
			const line = raw.trim();
			if ( ! line ) {
				flushList();
				flushPara();
				return;
			}
			const h = line.match( /^#{2,4}\s*(.+)$/ );
			const li = line.match( /^[-*•]\s+(.+)$/ );
			if ( h ) {
				flushList();
				flushPara();
				out.push( `<h3>${ esc( h[ 1 ] ) }</h3>` );
			} else if ( li ) {
				flushPara();
				list.push( li[ 1 ] );
			} else {
				flushList();
				para.push( line );
			}
		} );
	flushList();
	flushPara();
	return out.join( '\n' );
}

const EMPTY = {
	title: '',
	content: '',
	excerpt: '',
	status: 'draft',
	company_id: 0,
	author: 0,
	categories: [],
	types: [],
	locations: [],
	skills: '',
	workplace: 'onsite',
	remote_region: '',
	address: '',
	salary_min: '',
	salary_max: '',
	salary_currency: '',
	salary_period: 'year',
	salary_hidden: false,
	experience: '',
	apply_method: 'internal',
	apply_url: '',
	apply_email: '',
	expires: '',
	deadline: '',
	featured: false,
	filled: false,
};

const FIELDS = [
	{ key: 'title', label: __( 'Job title', 'dev3-ai-job-board' ), maxLength: 160 },
	{ key: 'excerpt', label: __( 'Search snippet', 'dev3-ai-job-board' ), maxLength: 200 },
	{ key: 'body', label: __( 'Description', 'dev3-ai-job-board' ), multiline: true, maxLength: 8000 },
	{ key: 'skills', label: __( 'Skills', 'dev3-ai-job-board' ), maxLength: 500 },
];

/**
 * Job editor with the DEV3 AI writer and the inclusive-language check.
 *
 * @param {Object} props    Props.
 * @param {string} props.id Job id or "new".
 * @return {Element} Editor.
 */
export default function JobEditor( { id } ) {
	const { meta, navigate, onUsage } = useApp();
	const toast = useToast();
	const isNew = id === 'new';
	const [ job, setJob ] = useState( isNew ? { ...EMPTY, salary_currency: meta.currency } : null );
	const [ body, setBody ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notes, setNotes ] = useState( '' );
	const [ tone, setTone ] = useState( 'professional' );
	const [ writing, setWriting ] = useState( false );
	const [ draft, setDraft ] = useState( null );
	const [ aiError, setAiError ] = useState( null );
	const [ issues, setIssues ] = useState( null );
	const [ fixes, setFixes ] = useState( [] );
	const [ checking, setChecking ] = useState( false );
	const [ queue, setQueue ] = useState( null );

	useEffect( () => {
		if ( isNew ) {
			return;
		}
		request( `admin/jobs/${ id }` )
			.then( ( j ) => {
				setJob( {
					...EMPTY,
					...j,
					company_id: j.company_id || 0,
					categories: j.categories.map( ( t ) => t.id ),
					types: j.types.map( ( t ) => t.id ),
					locations: j.locations.map( ( t ) => t.id ),
					skills: j.skills.map( ( s ) => s.name ).join( ', ' ),
					salary_min: j.salary_min === null ? '' : j.salary_min,
					salary_max: j.salary_max === null ? '' : j.salary_max,
				} );
				setBody( htmlToSimple( j.content ) );
			} )
			.catch( setError );
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error && ! job ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! job ) {
		return <SkeletonCard lines={ 8 } />;
	}

	const set = ( patch ) => setJob( { ...job, ...patch } );
	const toggleIn = ( key, termId ) => set( { [ key ]: job[ key ].includes( termId ) ? job[ key ].filter( ( x ) => x !== termId ) : [ ...job[ key ], termId ] } );

	const save = ( statusOverride ) => {
		setSaving( true );
		setError( null );
		const data = { ...job, content: simpleToHtml( body ), status: statusOverride || job.status };
		request( isNew ? 'admin/jobs' : `admin/jobs/${ id }`, { method: 'POST', data } )
			.then( ( saved ) => {
				toast.success( __( 'Job saved.', 'dev3-ai-job-board' ) );
				if ( isNew ) {
					navigate( `/jobs/${ saved.id }` );
				} else {
					set( { status: saved.status, expires: saved.expires } );
				}
			} )
			.catch( setError )
			.finally( () => setSaving( false ) );
	};

	const write = () => {
		setWriting( true );
		setAiError( null );
		const loc = meta.terms.locations.find( ( t ) => job.locations.includes( t.id ) );
		request( 'admin/ai/describe', {
			method: 'POST',
			data: {
				title: job.title,
				notes: notes || body,
				company_id: job.company_id,
				workplace: job.workplace,
				experience: job.experience,
				types: job.types,
				skills: job.skills,
				location: loc ? loc.name : job.remote_region,
				tone,
			},
		} )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => {
				setWriting( false );
				onUsage();
			} );
	};

	const check = () => {
		setChecking( true );
		setAiError( null );
		request( 'admin/ai/inclusive', { method: 'POST', data: { content: simpleToHtml( body ) } } )
			.then( ( r ) => {
				setIssues( r );
				setFixes( r.issues.map( ( _, i ) => i ) );
			} )
			.catch( setAiError )
			.finally( () => {
				setChecking( false );
				onUsage();
			} );
	};

	const applyChecked = () => {
		const chosen = issues.issues.filter( ( _, i ) => fixes.includes( i ) );
		setBody( htmlToSimple( applyFixes( simpleToHtml( body ), chosen ) ) );
		set( { title: applyFixes( job.title, chosen ) } );
		/* translators: %d: number of fixes */
		toast.success( sprintf( __( '%d wording fixes applied. Save the job to keep them.', 'dev3-ai-job-board' ), chosen.length ) );
		setIssues( null );
	};

	const scoreAll = ( ai ) =>
		request( `admin/jobs/${ id }/score`, { method: 'POST', data: { ai } } )
			.then( ( r ) => setQueue( r.job ) )
			.catch( ( e ) => toast.error( e ) );

	const ai = meta.ai.writer;
	const countAll = job.counts ? STAGES.reduce( ( a, s ) => a + ( job.counts[ s ] || 0 ), 0 ) : 0;

	return (
		<>
			<PageHeader
				back={ { href: '#/jobs', label: __( 'Jobs', 'dev3-ai-job-board' ) } }
				title={ isNew ? __( 'New job', 'dev3-ai-job-board' ) : job.title || __( 'Untitled job', 'dev3-ai-job-board' ) }
				actions={
					<>
						{ ! isNew && (
							<Button variant="ghost" icon="external" href={ job.url } target="_blank">
								{ __( 'View', 'dev3-ai-job-board' ) }
							</Button>
						) }
						{ job.status === 'pending' && (
							<Button variant="secondary" icon="check" onClick={ () => save( 'publish' ) } loading={ saving }>
								{ __( 'Approve & publish', 'dev3-ai-job-board' ) }
							</Button>
						) }
						<Button variant="primary" onClick={ () => save() } loading={ saving }>
							{ __( 'Save job', 'dev3-ai-job-board' ) }
						</Button>
					</>
				}
			/>
			<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
			<div className="dev3jb-editor__cols">
				<Stack gap={ 16 }>
					<Card title={ __( 'Job details', 'dev3-ai-job-board' ) }>
						<Stack gap={ 16 }>
							<TextField label={ __( 'Job title', 'dev3-ai-job-board' ) } value={ job.title } onChange={ ( v ) => set( { title: v } ) } required maxLength={ 160 } />
							<FormGrid>
								<Select label={ __( 'Workplace', 'dev3-ai-job-board' ) } value={ job.workplace } onChange={ ( v ) => set( { workplace: v } ) } options={ Object.keys( meta.workplaces ).map( ( k ) => ( { value: k, label: meta.workplaces[ k ] } ) ) } />
								<Select label={ __( 'Location', 'dev3-ai-job-board' ) } value={ String( job.locations[ 0 ] || '' ) } onChange={ ( v ) => set( { locations: v ? [ Number( v ) ] : [] } ) } options={ termOptions( meta.terms.locations, __( 'No location', 'dev3-ai-job-board' ) ) } />
								<TextField label={ job.workplace === 'remote' ? __( 'Remote region', 'dev3-ai-job-board' ) : __( 'Street address (optional)', 'dev3-ai-job-board' ) } value={ job.workplace === 'remote' ? job.remote_region : job.address } onChange={ ( v ) => set( job.workplace === 'remote' ? { remote_region: v } : { address: v } ) } />
								<Select label={ __( 'Experience level', 'dev3-ai-job-board' ) } value={ job.experience } onChange={ ( v ) => set( { experience: v } ) } options={ [ { value: '', label: __( 'Not specified', 'dev3-ai-job-board' ) }, ...Object.keys( meta.levels ).map( ( k ) => ( { value: k, label: meta.levels[ k ] } ) ) ] } />
							</FormGrid>
							<fieldset className="dev3jb-fieldset">
								<legend>{ __( 'Job type', 'dev3-ai-job-board' ) }</legend>
								<div className="dev3jb-checkgrid">
									{ meta.terms.types.map( ( t ) => (
										<Checkbox key={ t.id } label={ t.name } checked={ job.types.includes( t.id ) } onChange={ () => toggleIn( 'types', t.id ) } />
									) ) }
								</div>
							</fieldset>
							<fieldset className="dev3jb-fieldset">
								<legend>{ __( 'Categories', 'dev3-ai-job-board' ) }</legend>
								<div className="dev3jb-checkgrid">
									{ termOptions( meta.terms.categories ).map( ( o ) => (
										<Checkbox key={ o.value } label={ o.label } checked={ job.categories.includes( Number( o.value ) ) } onChange={ () => toggleIn( 'categories', Number( o.value ) ) } />
									) ) }
								</div>
							</fieldset>
							<TextField label={ __( 'Skills', 'dev3-ai-job-board' ) } value={ job.skills } onChange={ ( v ) => set( { skills: v } ) } help={ __( 'Separate with commas. Skills drive search and candidate matching.', 'dev3-ai-job-board' ) } />
						</Stack>
					</Card>

					<Card title={ __( 'Salary', 'dev3-ai-job-board' ) }>
						<FormGrid>
							<NumberField label={ __( 'From', 'dev3-ai-job-board' ) } value={ job.salary_min } onChange={ ( v ) => set( { salary_min: v } ) } min={ 0 } step={ 500 } />
							<NumberField label={ __( 'To', 'dev3-ai-job-board' ) } value={ job.salary_max } onChange={ ( v ) => set( { salary_max: v } ) } min={ 0 } step={ 500 } />
							<TextField label={ __( 'Currency', 'dev3-ai-job-board' ) } value={ job.salary_currency } onChange={ ( v ) => set( { salary_currency: v.toUpperCase().slice( 0, 3 ) } ) } maxLength={ 3 } />
							<Select label={ __( 'Period', 'dev3-ai-job-board' ) } value={ job.salary_period } onChange={ ( v ) => set( { salary_period: v } ) } options={ Object.keys( meta.periods ).map( ( k ) => ( { value: k, label: meta.periods[ k ] } ) ) } />
						</FormGrid>
						<Toggle label={ __( 'Hide the salary on the job page', 'dev3-ai-job-board' ) } checked={ !! job.salary_hidden } onChange={ ( v ) => set( { salary_hidden: v } ) } />
					</Card>

					<Card title={ __( 'Description', 'dev3-ai-job-board' ) } description={ __( 'Write paragraphs, "## Heading" for headings and "- " for bullet points.', 'dev3-ai-job-board' ) }>
						<Textarea label={ __( 'Job description', 'dev3-ai-job-board' ) } hideLabel value={ body } onChange={ setBody } rows={ 16 } />
						<TextField label={ __( 'Search snippet', 'dev3-ai-job-board' ) } value={ job.excerpt } onChange={ ( v ) => set( { excerpt: v } ) } maxLength={ 200 } help={ __( 'One sentence shown in search results and alerts.', 'dev3-ai-job-board' ) } />
					</Card>

					<Card tone="ai" title={ __( 'Write with DEV3 AI', 'dev3-ai-job-board' ) } description={ ai ? __( 'Turn rough notes into a clear, inclusive job ad. You review every change before it is used.', 'dev3-ai-job-board' ) : __( 'AI is off, so this builds a structured outline from your notes. Add an API key for full AI writing.', 'dev3-ai-job-board' ) }>
						<Stack gap={ 12 }>
							<Textarea label={ __( 'Notes about the role', 'dev3-ai-job-board' ) } value={ notes } onChange={ setNotes } rows={ 4 } placeholder={ __( 'What the person will do, must-have skills, team, benefits… (leave empty to improve the current description)', 'dev3-ai-job-board' ) } />
							<Inline gap={ 8 }>
								{ ai && <Select label={ __( 'Tone', 'dev3-ai-job-board' ) } hideLabel value={ tone } onChange={ setTone } options={ [ { value: 'professional', label: __( 'Professional', 'dev3-ai-job-board' ) }, { value: 'friendly', label: __( 'Friendly', 'dev3-ai-job-board' ) }, { value: 'startup', label: __( 'Startup', 'dev3-ai-job-board' ) }, { value: 'formal', label: __( 'Formal', 'dev3-ai-job-board' ) } ] } /> }
								<AiButton loading={ writing } loadingText={ __( 'Writing…', 'dev3-ai-job-board' ) } onClick={ write } disabled={ ! job.title && ! notes && ! body }>
									{ ai ? __( 'Generate with DEV3 AI', 'dev3-ai-job-board' ) : __( 'Build outline', 'dev3-ai-job-board' ) }
								</AiButton>
								<Button variant="secondary" icon="check" onClick={ check } loading={ checking } disabled={ ! body }>
									{ __( 'Check inclusive language', 'dev3-ai-job-board' ) }
								</Button>
							</Inline>
							<ErrorNotice error={ aiError } onRetry={ write } onDismiss={ () => setAiError( null ) } />
							{ draft && (
								<AiDraftReview
									title={ draft.source === 'ai' ? __( 'DEV3 AI · Job ad draft', 'dev3-ai-job-board' ) : __( 'Outline from your notes', 'dev3-ai-job-board' ) }
									fields={ FIELDS }
									original={ { title: job.title, excerpt: job.excerpt, body, skills: job.skills } }
									draft={ { title: draft.title, excerpt: draft.excerpt, body: htmlToSimple( draft.content ), skills: draft.skills.join( ', ' ) } }
									meta={ draft.meta }
									onRegenerate={ write }
									regenerating={ writing }
									onReject={ () => setDraft( null ) }
									onAccept={ ( v ) => {
										set( { title: v.title, excerpt: v.excerpt, skills: v.skills, experience: draft.experience || job.experience } );
										setBody( v.body );
										setDraft( null );
										toast.success( __( 'Draft applied. Save the job to publish the changes.', 'dev3-ai-job-board' ) );
									} }
									acceptLabel={ __( 'Use this draft', 'dev3-ai-job-board' ) }
								/>
							) }
							{ issues && (
								<div className="dev3jb-issues">
									{ issues.issues.length === 0 ? (
										<Notice tone="success" title={ __( 'No exclusionary wording found', 'dev3-ai-job-board' ) }>
											{ issues.source === 'ai' ? __( 'DEV3 AI and the word list found nothing to change.', 'dev3-ai-job-board' ) : __( 'The built-in word list found nothing to change.', 'dev3-ai-job-board' ) }
										</Notice>
									) : (
										<>
											<p className="dev3jb-issues__head">
												<strong>{ sprintf( /* translators: %d: count */ __( '%d suggestions', 'dev3-ai-job-board' ), issues.issues.length ) }</strong> { __( 'Choose the fixes to apply:', 'dev3-ai-job-board' ) }
											</p>
											<ul className="dev3jb-issues__list">
												{ issues.issues.map( ( it, i ) => (
													<li key={ it.phrase }>
														<Checkbox
															label={
																<span>
																	<del>{ it.phrase }</del> → <strong>{ it.suggestion || __( '(remove)', 'dev3-ai-job-board' ) }</strong> { it.source === 'ai' && <Badge tone="brand">AI</Badge> }
																</span>
															}
															checked={ fixes.includes( i ) }
															onChange={ () => setFixes( fixes.includes( i ) ? fixes.filter( ( x ) => x !== i ) : [ ...fixes, i ] ) }
															help={ it.reason }
														/>
													</li>
												) ) }
											</ul>
											<Inline gap={ 8 }>
												<Button variant="primary" onClick={ applyChecked } disabled={ ! fixes.length }>
													{ __( 'Apply selected fixes', 'dev3-ai-job-board' ) }
												</Button>
												<Button variant="ghost" onClick={ () => setIssues( null ) }>
													{ __( 'Dismiss', 'dev3-ai-job-board' ) }
												</Button>
											</Inline>
										</>
									) }
								</div>
							) }
						</Stack>
					</Card>
				</Stack>

				<Stack gap={ 16 }>
					<Card title={ __( 'Publishing', 'dev3-ai-job-board' ) }>
						<Stack gap={ 12 }>
							<Select
								label={ __( 'Status', 'dev3-ai-job-board' ) }
								value={ job.status }
								onChange={ ( v ) => set( { status: v } ) }
								options={ [
									{ value: 'publish', label: __( 'Live', 'dev3-ai-job-board' ) },
									{ value: 'pending', label: __( 'In review', 'dev3-ai-job-board' ) },
									{ value: 'draft', label: __( 'Draft', 'dev3-ai-job-board' ) },
									{ value: 'dev3jb_unpaid', label: __( 'Awaiting payment', 'dev3-ai-job-board' ) },
									{ value: 'dev3jb_expired', label: __( 'Expired', 'dev3-ai-job-board' ) },
								] }
							/>
							<Select label={ __( 'Company', 'dev3-ai-job-board' ) } value={ String( job.company_id || '' ) } onChange={ ( v ) => set( { company_id: Number( v ) || 0 } ) } options={ [ { value: '', label: __( 'No company', 'dev3-ai-job-board' ) }, ...meta.companies.map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) ] } />
							<Select label={ __( 'Employer account', 'dev3-ai-job-board' ) } value={ String( job.author || '' ) } onChange={ ( v ) => set( { author: Number( v ) || 0 } ) } options={ [ { value: '', label: __( 'Me', 'dev3-ai-job-board' ) }, ...meta.employers.map( ( u ) => ( { value: String( u.value ), label: u.label } ) ) ] } help={ __( 'The employer sees and manages applications in their dashboard.', 'dev3-ai-job-board' ) } />
							<TextField type="date" label={ __( 'Expires on', 'dev3-ai-job-board' ) } value={ job.expires } onChange={ ( v ) => set( { expires: v } ) } help={ __( 'Empty: set from the package or the default duration when it goes live.', 'dev3-ai-job-board' ) } />
							<TextField type="date" label={ __( 'Application deadline', 'dev3-ai-job-board' ) } value={ job.deadline } onChange={ ( v ) => set( { deadline: v } ) } />
							<Toggle label={ __( 'Featured', 'dev3-ai-job-board' ) } checked={ !! job.featured } onChange={ ( v ) => set( { featured: v } ) } help={ __( 'Pinned above other jobs in search.', 'dev3-ai-job-board' ) } />
							<Toggle label={ __( 'Position filled', 'dev3-ai-job-board' ) } checked={ !! job.filled } onChange={ ( v ) => set( { filled: v } ) } help={ __( 'Stays visible but stops taking applications.', 'dev3-ai-job-board' ) } />
						</Stack>
					</Card>
					<Card title={ __( 'How to apply', 'dev3-ai-job-board' ) }>
						<Stack gap={ 12 }>
							<Select
								label={ __( 'Applications', 'dev3-ai-job-board' ) }
								value={ job.apply_method }
								onChange={ ( v ) => set( { apply_method: v } ) }
								options={ [
									{ value: 'internal', label: __( 'On this site (pipeline)', 'dev3-ai-job-board' ) },
									{ value: 'url', label: __( 'External link', 'dev3-ai-job-board' ) },
									{ value: 'email', label: __( 'By email', 'dev3-ai-job-board' ) },
								] }
							/>
							{ job.apply_method === 'url' && <TextField type="url" label={ __( 'Application link', 'dev3-ai-job-board' ) } value={ job.apply_url } onChange={ ( v ) => set( { apply_url: v } ) } required /> }
							<TextField type="email" label={ job.apply_method === 'email' ? __( 'Application email', 'dev3-ai-job-board' ) : __( 'Notify this email (optional)', 'dev3-ai-job-board' ) } value={ job.apply_email } onChange={ ( v ) => set( { apply_email: v } ) } />
						</Stack>
					</Card>
					{ ! isNew && (
						<Card title={ __( 'Applications', 'dev3-ai-job-board' ) } actions={ <Button size="sm" variant="ghost" href={ `#/applications/${ id }` }>{ __( 'Open pipeline', 'dev3-ai-job-board' ) }</Button> }>
							<Stack gap={ 12 }>
								<p className="dev3jb-big">{ countAll }</p>
								{ countAll > 0 && (
									<Inline gap={ 8 }>
										<Button size="sm" onClick={ () => scoreAll( false ) }>
											{ __( 'Score all (rules)', 'dev3-ai-job-board' ) }
										</Button>
										{ meta.ai.match && (
											<AiButton size="sm" onClick={ () => scoreAll( true ) }>
												{ __( 'Score all with DEV3 AI', 'dev3-ai-job-board' ) }
											</AiButton>
										) }
									</Inline>
								) }
								{ queue && <JobProgress jobId={ queue } title={ __( 'Scoring applications', 'dev3-ai-job-board' ) } onDone={ () => onUsage() } /> }
							</Stack>
						</Card>
					) }
				</Stack>
			</div>
		</>
	);
}
