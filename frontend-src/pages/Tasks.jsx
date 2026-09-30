const { useState, useEffect, useRef } = wp.element;
const { Button, Spinner } = wp.components;
const { __ } = wp.i18n;

import { api } from '../api';
import { dateFormat } from '../format';
import { ProgressBar, TaskStatusBadge } from '../components/common';
import { TASK_TYPE_LABELS } from '../format';

export default function Tasks() {
	const [ tasks, setTasks ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const timerRef = useRef( null );
	const tickingRef = useRef( false );

	const load = async () => {
		try {
			const data = await api.tasks( 30 );
			setTasks( data.tasks || [] );
		} catch ( e ) {
			// Silent: transient during requests.
		}
	};

	useEffect( () => {
		load();
		const timer = setInterval( load, 5000 );
		return () => clearInterval( timer );
	}, [] );

	// Drive the task loop while something is queued/running.
	useEffect( () => {
			const active = tasks && tasks.some( ( t ) => [ 'queued', 'running' ].includes( t.status ) );
		if ( active && ! timerRef.current ) {
			timerRef.current = setInterval( async () => {
					if ( tickingRef.current ) return;
					tickingRef.current = true;
				try {
					await api.tick();
					await load();
				} catch ( e ) {
					// ignore transient tick errors
					} finally {
						tickingRef.current = false;
				}
			}, 4000 );
		} else if ( ! active && timerRef.current ) {
			clearInterval( timerRef.current );
			timerRef.current = null;
		}
		return () => {
				if ( timerRef.current ) {
				clearInterval( timerRef.current );
				timerRef.current = null;
			}
		};
	}, [ tasks ] );

	const control = async ( id, action ) => {
		setBusy( true );
		try {
			await api.taskControl( id, action );
			await load();
		} catch ( e ) {
			// notice-free: task may already be finished
		} finally {
			setBusy( false );
		}
	};

	if ( ! tasks ) {
		return <Spinner />;
	}

	return (
		<div>
			<p className="msw-page-hint">
				{ __( 'All heavy work runs as resumable background tasks, stepped in batches. The page keeps ticking them while it is open; WP-Cron continues when you close it.', 'mediasweep' ) }
			</p>

			{ tasks.length === 0 && (
				<p className="msw-empty">{ __( 'No tasks yet. Start a scan from the Dashboard.', 'mediasweep' ) }</p>
			) }

			{ tasks.map( ( task ) => (
				<div className="msw-task-card" key={ task.id }>
					<div className="msw-task-head">
						<strong>#{ task.id } { TASK_TYPE_LABELS[ task.type ] || task.type }</strong>
						<TaskStatusBadge status={ task.status } />
						<span className="msw-muted">{ dateFormat( task.updated_at ) }</span>
					</div>
					<ProgressBar processed={ task.processed } total={ task.total } failed={ task.failed } />
					{ [ 'queued', 'running', 'paused' ].includes( task.status ) && (
						<div className="msw-task-actions">
							{ 'running' === task.status && (
								<Button size="small" variant="secondary" onClick={ () => control( task.id, 'pause' ) } disabled={ busy }>
									{ __( 'Pause', 'mediasweep' ) }
								</Button>
							) }
							{ 'paused' === task.status && (
								<Button size="small" variant="secondary" onClick={ () => control( task.id, 'resume' ) } disabled={ busy }>
									{ __( 'Resume', 'mediasweep' ) }
								</Button>
							) }
							<Button size="small" variant="tertiary" isDestructive onClick={ () => control( task.id, 'cancel' ) } disabled={ busy }>
								{ __( 'Cancel', 'mediasweep' ) }
							</Button>
						</div>
					) }
				</div>
			) ) }
		</div>
	);
}
