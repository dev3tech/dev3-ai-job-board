import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, PageHeader, ResponsiveTable, useToast } from '../core3/ui';
import { formatDate, useApp } from './shared';

/**
 * Job alerts (saved searches emailed as digests).
 *
 * @return {Element} Screen.
 */
export default function Alerts() {
	const { settings } = useApp();
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ sending, setSending ] = useState( false );

	const load = () => request( 'admin/alerts' ).then( setRows ).catch( setError );
	useEffect( () => {
		load();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const send = () => {
		setSending( true );
		request( 'admin/alerts/send', { method: 'POST' } )
			.then( ( r ) => {
				/* translators: %d: emails */
				toast.success( sprintf( __( '%d digest emails sent.', 'dev3-ai-job-board' ), r.sent ) );
				load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSending( false ) );
	};
	const remove = ( a ) =>
		request( `admin/alerts/${ a.id }`, { method: 'DELETE' } )
			.then( load )
			.catch( ( e ) => toast.error( e ) );

	return (
		<>
			<PageHeader
				title={ __( 'Job alerts', 'dev3-ai-job-board' ) }
				description={ __( 'Candidates and visitors save searches and get new matching jobs by email, daily or weekly. Guests confirm by email first.', 'dev3-ai-job-board' ) }
				actions={
					<Button icon="refresh" onClick={ send } loading={ sending } disabled={ ! settings.alerts_enabled }>
						{ __( 'Send due digests now', 'dev3-ai-job-board' ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } />
			<Card flush>
				<ResponsiveTable
					loading={ ! rows && ! error }
					rows={ rows || [] }
					pageSize={ 25 }
					columns={ [
						{ key: 'label', label: __( 'Search', 'dev3-ai-job-board' ), primary: true },
						{ key: 'email', label: __( 'Email', 'dev3-ai-job-board' ) },
						{ key: 'frequency', label: __( 'Frequency', 'dev3-ai-job-board' ), render: ( a ) => ( a.frequency === 'weekly' ? __( 'Weekly', 'dev3-ai-job-board' ) : __( 'Daily', 'dev3-ai-job-board' ) ) },
						{ key: 'confirmed', label: __( 'Status', 'dev3-ai-job-board' ), render: ( a ) => ( a.confirmed ? <Badge tone="success">{ __( 'Active', 'dev3-ai-job-board' ) }</Badge> : <Badge tone="warning">{ __( 'Waiting for confirmation', 'dev3-ai-job-board' ) }</Badge> ) },
						{ key: 'last_sent', label: __( 'Last checked', 'dev3-ai-job-board' ), hideOnMobile: true, render: ( a ) => formatDate( a.last_sent, true ) },
					] }
					rowActions={ ( a ) => <Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-job-board' ) } onClick={ () => remove( a ) } /> }
					empty={ { icon: 'inbox', title: __( 'No job alerts yet', 'dev3-ai-job-board' ), description: __( 'Visitors create alerts from the job board with "Email me jobs like these".', 'dev3-ai-job-board' ) } }
				/>
			</Card>
		</>
	);
}
