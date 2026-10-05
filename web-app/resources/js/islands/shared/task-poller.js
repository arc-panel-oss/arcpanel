// Polls a background task (E13 spec D4) while it runs: fetchTask() returns
// {status, ...}; onUpdate gets every answer, onFinish the first one that is
// not "running". Errors are passed to onError and polling goes on, so a
// short network failure does not lose the task. E15 (Job Center) keeps the
// same contract.

/**
 * @param {{
 *   fetchTask: () => Promise<{status: string}>,
 *   onUpdate?: (task: object) => void,
 *   onFinish?: (task: object) => void,
 *   onError?: (error: unknown) => void,
 *   interval?: number,
 *   timers?: {setTimeout: typeof setTimeout, clearTimeout: typeof clearTimeout},
 * }} options
 */
export function createTaskPoller({ fetchTask, onUpdate, onFinish, onError, interval = 2000, timers = globalThis }) {
	let timer = null;
	let active = false;

	async function tick() {
		timer = null;
		if (!active) {
			return;
		}
		try {
			const task = await fetchTask();
			if (!active) {
				return;
			}
			onUpdate?.(task);
			if (task?.status !== 'running') {
				active = false;
				onFinish?.(task);
				return;
			}
		} catch (error) {
			onError?.(error);
		}
		if (active) {
			timer = timers.setTimeout(tick, interval);
		}
	}

	return {
		start() {
			if (active) {
				return;
			}
			active = true;
			timer = timers.setTimeout(tick, interval);
		},
		stop() {
			active = false;
			if (timer !== null) {
				timers.clearTimeout(timer);
				timer = null;
			}
		},
		get running() {
			return active;
		},
	};
}
