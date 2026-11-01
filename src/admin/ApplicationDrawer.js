import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Drawer, ErrorNotice, Inline, Notice, ProgressBar, Select, SkeletonCard, Stack, Textarea, Toggle, useToast } from '../core3/ui';
import { STAGES, StageBadge, formatDate, scoreTone, useApp } from './shared';

/**
 * Match result card (decision support).
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.match   Match result or null.
 * @param {boolean}  props.ai      AI available.
 * @param {boolean}  props.busy    Scoring.
 * @param {Function} props.onScore ( ai ) => void.
 * @return {Element} Card.
 */
export function MatchCard( { match, ai, busy, onScore } ) {
	const labels = {
		skills: __( 'Skills', 'dev3-ai-job-board' ),
		experience: __( 'Experience', 'dev3-ai-job-board' ),
		role: __( 'Role fit', 'dev3-ai-job-board' ),
		location: __( 'Location', 'dev3-ai-job-board' ),
	};
	return (
		<section className="dev3jb-match" aria-labelledby="dev3jb-match-h">
			<div className="dev3jb-match__head">
				<h3 id="dev3jb-match-h">{ __( 'Match', 'dev3-ai-job-board' ) }</h3>
				{ match && (
					<Badge tone={ match.source === 'ai' ? 'brand' : 'neutral' }>{ match.source === 'ai' ? __( 'DEV3 AI explanation', 'dev3-ai-job-board' ) : __( 'Rule-based', 'dev3-ai-job-board' ) }</Badge>
				) }
			</div>
			{ match ? (
				<>
					<div className="dev3jb-match__score">
						<span className={ `dev3jb-match__num is-${ scoreTone( match.score ) }` }>{ match.score }</span>
						<span className="dev3jb-match__of">/100</span>
						<p>{ match.summary }</p>
					</div>
					<ul className="dev3jb-match__criteria">
						{ Object.keys( labels ).map( ( k ) => (
							<li key={ k }>
								<span>{ labels[ k ] }</span>
								<ProgressBar value={ match.criteria[ k ] } label={ `${ labels[ k ] } ${ match.criteria[ k ] }%` } tone={ match.criteria[ k ] >= 70 ? 'success' : 'brand' } />
								<span className="dev3jb-match__pct">{ match.criteria[ k ] }</span>
							</li>
						) ) }
					</ul>
					{ match.strengths && match.strengths.length > 0 && (
						<div>
							<h4>{ __( 'Strengths', 'dev3-ai-job-board' ) }</h4>
							<ul className="dev3jb-plainlist">
								{ match.strengths.map( ( s ) => (
									<li key={ s }>{ s }</li>
								) ) }
							</ul>
						</div>
					) }
					{ match.gaps && match.gaps.length > 0 && (
						<div>
							<h4>{ __( 'Gaps to explore', 'dev3-ai-job-board' ) }</h4>
							<ul className="dev3jb-plainlist">
								{ match.gaps.map( ( s ) => (
									<li key={ s }>{ s }</li>
								) ) }
							</ul>
						</div>
					) }
				</>
			) : (
				<p className="dev3jb-muted">{ __( 'No score yet.', 'dev3-ai-job-board' ) }</p>
			) }
			<Notice tone="info">{ __( 'Decision support only: review every application yourself. Scores use skills, experience, role and location fit; names, contact details and personal characteristics are never used, and a score never moves or rejects anyone.', 'dev3-ai-job-board' ) }</Notice>
			<Inline gap={ 8 }>
				<Button size="sm" onClick={ () => onScore( false ) } loading={ busy === 'rules' }>
					{ match ? __( 'Recalculate (rules)', 'dev3-ai-job-board' ) : __( 'Calculate match', 'dev3-ai-job-board' ) }
				</Button>
				{ ai && (
					<AiButton size="sm" onClick={ () => onScore( true ) } loading={ busy === 'ai' } loadingText={ __( 'Scoring…', 'dev3-ai-job-board' ) }>
						{ __( 'Explain with DEV3 AI', 'dev3-ai-job-board' ) }
					</AiButton>
				) }
			</Inline>
		</section>
	);
}

/**
 * Application drawer: profile, resume, match, rating, stage change and notes.
 *
 * @param {Object}   props           Props.
 * @param {number}   props.id        Application id.
 * @param {Function} props.onClose   Close.
 * @param {Function} props.onChanged ( card ) => void.
 * @return {Element} Drawer.
 */
export default function ApplicationDrawer( { id, onClose, onChanged } ) {
	const { meta, onUsage } = useApp();
	const toast = useToast();
	const [ app, setApp ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ stage, setStage ] = useState( '' );
	const [ notify, setNotify ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		if ( ! id ) {
			return;
		}
		setApp( null );
		setError( null );
		request( `admin/applications/${ id }` )
			.then( ( a ) => {
				setApp( a );
				setStage( a.stage );
			} )
			.catch( setError );
	}, [ id ] );

	const update = ( data, okMsg ) => {
		setBusy( 'update' );
		return request( `admin/applications/${ id }`, { method: 'POST', data } )
			.then( ( a ) => {
				setApp( a );
				setStage( a.stage );
				onChanged( a.card );
				if ( okMsg ) {
					toast.success( okMsg );
				}
				setNotify( false );
				setMessage( '' );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const score = ( ai ) => {
		setBusy( ai ? 'ai' : 'rules' );
		request( `admin/applications/${ id }/match`, { method: 'POST', data: { ai } } )
			.then( ( a ) => {
				setApp( a );
				onChanged( a.card );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const addNote = () => {
		setBusy( 'note' );
		request( `admin/applications/${ id }/notes`, { method: 'POST', data: { note } } )
			.then( ( events ) => {
				setApp( { ...app, events } );
				setNote( '' );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const p = app && app.profile;
	return (
		<Drawer open={ !! id } onClose={ onClose } size="lg" title={ app ? app.name : __( 'Application', 'dev3-ai-job-board' ) } description={ app ? app.job_title : '' }>
			<ErrorNotice error={ error } />
			{ ! app && ! error && <SkeletonCard lines={ 6 } /> }
			{ app && (
				<Stack gap={ 20 }>
					<div className="dev3jb-appmeta">
						<StageBadge stage={ app.stage } labels={ meta.stages } />
						<span>{ sprintf( /* translators: %s: date */ __( 'Applied %s', 'dev3-ai-job-board' ), formatDate( app.created_at, true ) ) }</span>
						<a href={ `mailto:${ app.email }` }>{ app.email }</a>
						{ app.phone && <a href={ `tel:${ app.phone }` }>{ app.phone }</a> }
					</div>
					<div className="dev3jb-stars" role="group" aria-label={ __( 'Your rating', 'dev3-ai-job-board' ) }>
						{ [ 1, 2, 3, 4, 5 ].map( ( n ) => (
							<button key={ n } type="button" aria-pressed={ app.rating >= n } aria-label={ sprintf( /* translators: %d: stars */ __( '%d of 5 stars', 'dev3-ai-job-board' ), n ) } onClick={ () => update( { rating: app.rating === n ? 0 : n } ) }>
								★
							</button>
						) ) }
					</div>
					{ app.stage !== 'withdrawn' ? (
						<div className="dev3jb-stagebox">
							<Select label={ __( 'Stage', 'dev3-ai-job-board' ) } value={ stage } onChange={ setStage } options={ STAGES.map( ( s ) => ( { value: s, label: meta.stages[ s ] } ) ) } />
							{ stage !== app.stage && (
								<>
									<Toggle label={ __( 'Email the candidate about this change', 'dev3-ai-job-board' ) } checked={ notify } onChange={ setNotify } />
									{ notify && <Textarea label={ __( 'Personal message (optional)', 'dev3-ai-job-board' ) } value={ message } onChange={ setMessage } rows={ 3 } /> }
									<Button variant="primary" onClick={ () => update( { stage, notify, message }, __( 'Stage updated.', 'dev3-ai-job-board' ) ) } loading={ busy === 'update' }>
										{ __( 'Update stage', 'dev3-ai-job-board' ) }
									</Button>
								</>
							) }
						</div>
					) : (
						<Notice tone="warning">{ __( 'The candidate withdrew this application.', 'dev3-ai-job-board' ) }</Notice>
					) }
					<MatchCard match={ app.match } ai={ app.ai } busy={ busy } onScore={ score } />
					<section>
						<h3 className="dev3jb-h3">{ __( 'Resume', 'dev3-ai-job-board' ) }</h3>
						{ app.file ? (
							<Button icon="file" variant="secondary" href={ app.file.url } target="_blank">
								{ app.file.name }
							</Button>
						) : (
							<p className="dev3jb-muted">{ __( 'No resume attached.', 'dev3-ai-job-board' ) }</p>
						) }
					</section>
					{ app.cover_letter && (
						<section>
							<h3 className="dev3jb-h3">{ __( 'Cover letter', 'dev3-ai-job-board' ) }</h3>
							<p className="dev3jb-prewrap">{ app.cover_letter }</p>
						</section>
					) }
					{ p && (
						<section className="dev3jb-profileview">
							<h3 className="dev3jb-h3">{ __( 'Profile', 'dev3-ai-job-board' ) }</h3>
							{ p.headline && <p><strong>{ p.headline }</strong>{ p.location ? ` · ${ p.location }` : '' }</p> }
							{ p.years > 0 && <p className="dev3jb-muted">{ sprintf( /* translators: %s: years */ __( '%s years of experience', 'dev3-ai-job-board' ), p.years ) }</p> }
							{ p.summary && <p className="dev3jb-prewrap">{ p.summary }</p> }
							{ p.skills.length > 0 && (
								<div className="dev3jb-chiprow">
									{ p.skills.map( ( s ) => (
										<Badge key={ s }>{ s }</Badge>
									) ) }
								</div>
							) }
							{ p.experience.length > 0 && (
								<>
									<h4>{ __( 'Experience', 'dev3-ai-job-board' ) }</h4>
									<ul className="dev3jb-timeline">
										{ p.experience.map( ( e, i ) => (
											<li key={ i }>
												<strong>{ e.title }</strong> { e.company && `· ${ e.company }` }
												<span className="dev3jb-muted"> { e.start } – { e.current ? __( 'now', 'dev3-ai-job-board' ) : e.end }</span>
												{ e.summary && <p>{ e.summary }</p> }
											</li>
										) ) }
									</ul>
								</>
							) }
							{ p.education.length > 0 && (
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
						</section>
					) }
					<section>
						<h3 className="dev3jb-h3">{ __( 'Notes & activity', 'dev3-ai-job-board' ) }</h3>
						<Textarea label={ __( 'Add a private note', 'dev3-ai-job-board' ) } value={ note } onChange={ setNote } rows={ 2 } />
						<Button size="sm" onClick={ addNote } disabled={ ! note.trim() } loading={ busy === 'note' }>
							{ __( 'Save note', 'dev3-ai-job-board' ) }
						</Button>
						<ul className="dev3jb-events">
							{ app.events.map( ( ev ) => (
								<li key={ ev.id } className={ `is-${ ev.kind }` }>
									<span className="dev3jb-events__body">{ ev.body }</span>
									<span className="dev3jb-muted">
										{ ev.author ? `${ ev.author } · ` : '' }
										{ formatDate( ev.created_at, true ) }
									</span>
								</li>
							) ) }
						</ul>
					</section>
				</Stack>
			) }
		</Drawer>
	);
}
