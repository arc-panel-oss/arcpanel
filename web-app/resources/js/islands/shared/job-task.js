// The task panels of the islands follow their job on the job route of the
// Job Center (E15 spec §6.2): GET /jobs/{id}/status answers the job and its
// log from a byte offset, or the end of the log without one. jobAsTask keeps
// the task shape the panels know (status running, done or failed; running;
// exit; start; end; log; offset), so that createTaskPoller and the templates
// do not change.
import { request } from '../../http.js';

export const JOBS_BASE = '/jobs';

const STATUS = { queued: 'running', running: 'running', succeeded: 'done', failed: 'failed', cancelled: 'failed' };

/** URL of the state of job ID; OFFSET null asks for the end of the log */
export function jobStatusUrl(id, offset = null) {
	const url = `${JOBS_BASE}/${encodeURIComponent(String(id))}/status`;
	return offset === null || offset === undefined ? url : `${url}?offset=${offset}`;
}

/** Time of the API (2026-11-06T10:00:00) as the panels show it */
function time(value) {
	return value ? String(value).replace('T', ' ') : '';
}

/**
 * The task PREVIOUS updated with JOB (the `task` of /jobs/{id}/status). The
 * log replaces the previous one when it was read without an offset or from
 * 0, and is appended otherwise.
 *
 * @param {object} previous
 * @param {object} job
 * @param {number|null} offset  the offset the job was read from
 */
export function jobAsTask(previous, job, offset) {
	const chunk = typeof job.log === 'string' ? job.log : '';
	const log = offset === null || offset === undefined || offset === 0 ? chunk : `${previous?.log ?? ''}${chunk}`;
	return {
		...(previous ?? {}),
		id: job.id ?? previous?.id,
		status: STATUS[job.status] ?? job.status,
		jobStatus: job.status,
		running: !job.finished,
		exit: job.exit_code === null || job.exit_code === undefined ? '' : String(job.exit_code),
		error: job.error ?? '',
		start: time(job.started_at) || (previous?.start ?? ''),
		end: time(job.finished_at),
		log,
		offset: Number.isInteger(job.offset) ? job.offset : (previous?.offset ?? 0),
		url: job.url ?? previous?.url,
	};
}

/**
 * A fetchTask for createTaskPoller: the first call reads the end of the log,
 * the next ones the log after the last offset. current() gives the task the
 * panel shows (with its id).
 *
 * @param {() => object} current
 */
export function jobFetcher(current) {
	let id = null;
	let offset = null;
	return async () => {
		const task = current();
		if (task?.id !== id) {
			id = task?.id ?? null;
			offset = null;
		}
		const data = await request(jobStatusUrl(id, offset));
		const next = jobAsTask(task, data.task, offset);
		offset = next.offset;
		return next;
	};
}

/** The whole log of job ID from its start (a finished task opened again) */
export async function fetchJobTask(previous, offset = 0) {
	const data = await request(jobStatusUrl(previous.id, offset));
	return jobAsTask(previous, data.task, offset);
}
