/**
 * Derives the current month key (`YYYY-MM`) used for usage KV keys, matching
 * getCurrentMonth() in src/index.ts. Extracted so tests do not re-inline the
 * same date-formatting snippet across suites.
 *
 * @return {string} Current month key in `YYYY-MM` form.
 */
export function currentMonthKey(): string {
	const now = new Date();
	return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart(
		2,
		'0'
	) }`;
}
