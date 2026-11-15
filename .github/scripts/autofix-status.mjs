#!/usr/bin/env node
/* eslint-disable no-console -- CLI script: stdout carries workflow commands (::warning::) and logs. */
/**
 * Review / auto-fix accounting (review aid, never a check).
 *
 * Every open `code-review` issue for a PR is either auto-fixed or reported as
 * NOT fixed with a reason. The decision is made here, deterministically, from
 * GitHub state and git history, never from LLM prose.
 *
 * Zero dependencies (node:* built-ins only). TRUST: this file must always be
 * run from a copy taken from the default branch (never from the PR tree),
 * because it runs with write tokens.
 *
 * Subcommands:
 *   partition         (review) Split open review issues into queued / manual.
 *   upsert-comment    Post or edit the marker comment on the PR (bot-owned only).
 *   validate-payload  (batch) Validate the repository_dispatch payload.
 *   report            (batch) Compute outcomes, post ONE report, label unfixed.
 *   render-report     Render a report from a fixture JSON file (dry run, no network).
 *
 * Exclusions are recorded on the issue itself at creation time: label
 * `needs-manual-fix` plus a body line `**Not auto-fixed:** <reason>`.
 */

import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

export const MANUAL_LABEL = 'needs-manual-fix';
export const REVIEW_MARKER = '<!-- plume-review-queue -->';
export const REPORT_MARKER = '<!-- plume-autofix-report -->';
export const BOT_LOGIN = 'github-actions[bot]';
export const NO_REASON = '(no reason recorded)';
export const MAX_REASON = 200;
export const MAX_ISSUES = 300;

// Fix-session step outcomes that mean it did not complete ('skipped' and
// 'success' are not failures; an empty value means unknown).
export const SESSION_FAILED = new Set( [
	'failure',
	'cancelled',
	'timed_out',
] );

// Reasons that are explicitly NOT a valid exclusion: the issue is force-queued.
const DENY_REASON_RE =
	/\bCI\b|human review|manual review|needs? (?:a )?review|code review/i;
const BRANCH_RE = /^[A-Za-z0-9._/-]+$/;
const NOT_FIXED_LINE_RE = /^\*\*Not auto-fixed:\*\*[ \t]*(.*)$/m;

// ── Pure helpers (unit-tested) ──────────────────────────────────────────────

/**
 * Makes untrusted free text safe for a one-line markdown reason: no control
 * characters or newlines, no backticks, angle brackets, square brackets or
 * pipes, no `@` mentions, capped length.
 *
 * @param {unknown} text Raw reason.
 * @param {number}  max  Maximum length.
 * @return {string} Sanitised reason, or NO_REASON when nothing is left.
 */
export function sanitiseReason( text, max = MAX_REASON ) {
	let out = String( text ?? '' )
		.replace( /[\u0000-\u001f\u007f]+/g, ' ' )
		.replace( /[`<>[\]|]/g, '' )
		.replace( /@/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
	if ( out.length > max ) {
		out = `${ out.slice( 0, max - 1 ).trimEnd() }…`;
	}
	return out || NO_REASON;
}

/**
 * Whether an issue body says it was found during review of exactly this PR.
 * Boundary-safe: `#10` must not match `#1060` (nor the reverse).
 *
 * @param {string} body     Issue body.
 * @param {number} prNumber PR number.
 * @return {boolean} True when it references this PR.
 */
export function issueMentionsPr( body, prNumber ) {
	const re = new RegExp(
		`Found during review of PR #${ Number( prNumber ) }(?![0-9])`
	);
	return re.test( String( body ?? '' ) );
}

/**
 * Whether a commit message references issue #N as a whole number.
 *
 * @param {string} message Full commit message (%B).
 * @param {number} number  Issue number.
 * @return {boolean} True when `#N` appears not followed by a digit.
 */
export function commitMentionsIssue( message, number ) {
	return new RegExp( `#${ Number( number ) }(?![0-9])` ).test(
		String( message ?? '' )
	);
}

/**
 * Extracts the `**Not auto-fixed:**` reason from an issue body.
 *
 * @param {string} body Issue body.
 * @return {string|null} Raw reason, or null when the line is absent.
 */
export function extractReason( body ) {
	const m = NOT_FIXED_LINE_RE.exec(
		String( body ?? '' ).replace( /\r/g, '' )
	);
	return m ? m[ 1 ] : null;
}

/**
 * @param {{labels?: Array<{name: string}|string>}} issue Issue.
 * @return {boolean} True when it carries the needs-manual-fix label.
 */
function hasManualLabel( issue ) {
	return ( issue.labels ?? [] ).some(
		( l ) => ( typeof l === 'string' ? l : l?.name ) === MANUAL_LABEL
	);
}

/**
 * Splits open review issues for one PR into queued and manual (not queued).
 * Default is queue: only an issue labelled needs-manual-fix is excluded, and
 * an exclusion whose reason is "needs CI / human review" is force-queued.
 *
 * @param {Array<{number: number, body?: string, labels?: Array}>} issues   Open code-review issues.
 * @param {number}                                                   prNumber PR number.
 * @return {{queued: number[], manual: Array<{number: number, reason: string}>, forced: Array<{number: number, reason: string}>, matched: number}} Partition.
 */
export function partitionReviewIssues( issues, prNumber ) {
	const seen = new Set();
	const queued = [];
	const manual = [];
	const forced = [];
	for ( const issue of issues ?? [] ) {
		if (
			! Number.isInteger( issue?.number ) ||
			seen.has( issue.number ) ||
			! issueMentionsPr( issue.body, prNumber )
		) {
			continue;
		}
		seen.add( issue.number );
		if ( ! hasManualLabel( issue ) ) {
			queued.push( issue.number );
			continue;
		}
		const raw = extractReason( issue.body );
		const reason = sanitiseReason( raw );
		if ( raw && DENY_REASON_RE.test( raw ) ) {
			forced.push( { number: issue.number, reason } );
			queued.push( issue.number );
		} else {
			manual.push( { number: issue.number, reason } );
		}
	}
	const byNumber = ( a, b ) => ( a.number ?? a ) - ( b.number ?? b );
	return {
		queued: queued.sort( byNumber ),
		manual: manual.sort( byNumber ),
		forced: forced.sort( byNumber ),
		matched: seen.size,
	};
}

/**
 * Validates and normalises the batch-auto-fix repository_dispatch payload.
 *
 * @param {unknown} p Parsed client_payload.
 * @return {{ok: boolean, errors: string[], value: object}} Result.
 */
export function validatePayload( p ) {
	const errors = [];
	const src = p && typeof p === 'object' ? p : {};
	const isNum = ( v ) =>
		( typeof v === 'number' && Number.isSafeInteger( v ) && v > 0 ) ||
		( typeof v === 'string' && /^[1-9][0-9]*$/.test( v ) );

	if ( ! isNum( src.pr_number ) ) {
		errors.push( 'pr_number must be a positive integer' );
	}
	const branch = ( name ) => {
		const v = src[ name ];
		if (
			typeof v !== 'string' ||
			v.length > 255 ||
			! BRANCH_RE.test( v ) ||
			v.startsWith( '-' ) ||
			v.startsWith( '/' ) ||
			v.includes( '..' )
		) {
			errors.push( `${ name } contains unsafe characters` );
			return '';
		}
		return v;
	};
	const headBranch = branch( 'head_branch' );
	const baseBranch = branch( 'base_branch' );

	let issues = [];
	if ( src.issue_numbers === undefined || src.issue_numbers === null ) {
		issues = [];
	} else if (
		! Array.isArray( src.issue_numbers ) ||
		src.issue_numbers.length > MAX_ISSUES ||
		! src.issue_numbers.every(
			( n ) => typeof n === 'number' && isNum( n )
		)
	) {
		errors.push( 'issue_numbers must be an array of positive integers' );
	} else {
		issues = [ ...new Set( src.issue_numbers ) ].sort( ( a, b ) => a - b );
	}

	let manual = [];
	const rawManual = src.manual_issues ?? [];
	if ( ! Array.isArray( rawManual ) || rawManual.length > MAX_ISSUES ) {
		errors.push( 'manual_issues must be an array' );
	} else {
		for ( const m of rawManual ) {
			if ( ! isNum( m?.number ) ) {
				errors.push( 'manual_issues entries need a numeric number' );
				break;
			}
			manual.push( {
				number: Number( m.number ),
				reason: sanitiseReason( m.reason ),
			} );
		}
		manual = manual.sort( ( a, b ) => a.number - b.number );
	}

	return {
		ok: errors.length === 0,
		errors,
		value: {
			prNumber: errors.length ? 0 : Number( src.pr_number ),
			headBranch,
			baseBranch,
			issues,
			manual,
		},
	};
}

/**
 * Parses `number<TAB>reason` lines written (optionally) by the fix session.
 *
 * @param {string} text File contents.
 * @return {Record<number, string>} Reasons by issue number.
 */
export function parseUnfixedTsv( text ) {
	const out = {};
	for ( const line of String( text ?? '' ).split( /\r?\n/ ) ) {
		const m = /^\s*#?([0-9]+)\t(.+)$/.exec( line );
		if ( m ) {
			out[ Number( m[ 1 ] ) ] = sanitiseReason( m[ 2 ] );
		}
	}
	return out;
}

/**
 * Reason for an unfixed issue from its latest comment made during this run.
 *
 * @param {Array<{body: string, createdAt: string}>} comments Issue comments.
 * @param {string}                                   sinceIso Run start (ISO).
 * @return {string|null} Sanitised first line, or null.
 */
export function reasonFromComments( comments, sinceIso ) {
	const since = Date.parse( sinceIso );
	const recent = ( comments ?? [] )
		.filter( ( c ) => Date.parse( c?.createdAt ) >= since )
		.sort(
			( a, b ) => Date.parse( a.createdAt ) - Date.parse( b.createdAt )
		);
	const last = recent[ recent.length - 1 ];
	const line = String( last?.body ?? '' )
		.split( /\r?\n/ )
		.map( ( l ) => l.trim() )
		.find( Boolean );
	return line ? sanitiseReason( line ) : null;
}

/**
 * Classifies each dispatched issue. Fixed requires ALL of: issue closed, a
 * commit in the run range whose full message references it, and HEAD pushed.
 * `pushed === null` means the remote SHA could not be determined: it is not
 * evidence of a missing push, so only `false` counts as "not pushed".
 *
 * @param {Object}                                     args             Inputs.
 * @param {number[]}                                   args.dispatched  Issues handed to the fix session.
 * @param {Record<number, {state?: string, reason?: string}>} args.issues State and best-known reason by number.
 * @param {string[]}                                   args.commits     Full messages in START_SHA..HEAD.
 * @param {boolean|null}                               args.pushed      Whether HEAD is on the remote branch (null: unknown).
 * @param {boolean}                                    [args.sessionFailed] The fix session did not succeed.
 * @return {{fixed: number[], fixPushed: number[], closedNoCommit: Array<{number: number, note: string}>, notFixed: Array<{number: number, reason: string, hasCommit: boolean, retry: boolean}>}} Outcome.
 */
export function classifyOutcome( {
	dispatched,
	issues,
	commits,
	pushed,
	sessionFailed = false,
} ) {
	const fixed = [];
	const fixPushed = [];
	const closedNoCommit = [];
	const notFixed = [];
	const isPushed = pushed !== false;
	for ( const n of dispatched ?? [] ) {
		const info = issues?.[ n ] ?? {};
		const hasCommit = ( commits ?? [] ).some( ( m ) =>
			commitMentionsIssue( m, n )
		);
		const closed = String( info.state ).toUpperCase() === 'CLOSED';
		if ( closed && hasCommit && isPushed ) {
			fixed.push( n );
		} else if ( closed ) {
			closedNoCommit.push( {
				number: n,
				note: hasCommit
					? 'closed, matching commit exists but was not pushed'
					: 'closed, no matching pushed commit',
			} );
		} else if ( hasCommit && isPushed ) {
			fixPushed.push( n );
		} else if ( hasCommit ) {
			notFixed.push( {
				number: n,
				reason: 'fix commit exists but was not pushed',
				hasCommit: true,
				retry: false,
			} );
		} else {
			notFixed.push( {
				number: n,
				reason: sessionFailed
					? 'not fixed (session failed)'
					: sanitiseReason( info.reason ),
				hasCommit: false,
				retry: sessionFailed,
			} );
		}
	}
	return { fixed, fixPushed, closedNoCommit, notFixed };
}

/**
 * Text to append to a PR body so merging closes the given issues.
 *
 * @param {string}   body    Current PR body.
 * @param {number[]} numbers Issues to close on merge.
 * @return {string} Text to append ('' when nothing is missing).
 */
export function closingKeywordsToAppend( body, numbers ) {
	let out = '';
	for ( const n of numbers ?? [] ) {
		const re = new RegExp(
			`(close[sd]?|fix(e[sd])?|resolve[sd]?) #${ Number( n ) }([^0-9]|$)`,
			'i'
		);
		if ( ! re.test( String( body ?? '' ) ) ) {
			out += `\n\nCloses #${ Number( n ) }`;
		}
	}
	return out;
}

/**
 * Open review issues for the PR that are in no list at all.
 *
 * @param {number[]} openQueued Open, unlabelled review issues for this PR.
 * @param {number[]} dispatched Issues handed to the fix session.
 * @param {Array<{number: number}>} manual Issues reported as not queued.
 * @return {number[]} Unaccounted issue numbers.
 */
export function findUnaccounted( openQueued, dispatched, manual ) {
	const known = new Set( [
		...( dispatched ?? [] ),
		...( manual ?? [] ).map( ( m ) => m.number ),
	] );
	return ( openQueued ?? [] ).filter( ( n ) => ! known.has( n ) );
}

/**
 * Merges payload manual issues with those found open on GitHub; excludes
 * anything that was dispatched to the fix session.
 *
 * @param {Array<{number: number, reason: string}>} payload Payload manual issues.
 * @param {Array<{number: number, reason: string}>} live    Live needs-manual-fix issues.
 * @param {number[]}                                dispatched Dispatched issues.
 * @return {Array<{number: number, reason: string}>} Merged, sorted, de-duplicated.
 */
export function mergeManual( payload, live, dispatched ) {
	const skip = new Set( dispatched ?? [] );
	const map = new Map();
	for ( const m of [ ...( live ?? [] ), ...( payload ?? [] ) ] ) {
		if ( ! skip.has( m.number ) ) {
			map.set( m.number, m );
		}
	}
	return [ ...map.values() ].sort( ( a, b ) => a.number - b.number );
}

/**
 * Finds the bot-owned marker comment. Anti-spoof: both the marker AND the
 * author must match, so a user cannot plant a marker to hijack edits.
 *
 * @param {Array<{id: number, body?: string, user?: {login?: string}}>} comments PR comments.
 * @param {string} marker Hidden marker.
 * @return {number|null} Comment id or null.
 */
export function findOwnComment( comments, marker ) {
	const hit = ( comments ?? [] ).find(
		( c ) =>
			c?.user?.login === BOT_LOGIN &&
			String( c?.body ?? '' ).includes( marker )
	);
	return hit ? hit.id : null;
}

const list = ( items, fmt ) =>
	items.length ? items.map( fmt ).join( '\n' ) : '- none';

/**
 * Renders the footer the review workflow adds to the PR.
 *
 * @param {Object} args        Inputs.
 * @param {number[]} args.queued Queued issue numbers.
 * @param {Array<{number: number, reason: string}>} args.manual Exclusions.
 * @return {string} Markdown with hidden marker.
 */
export function renderQueueFooter( { queued, manual } ) {
	return [
		REVIEW_MARKER,
		'### Review follow-ups',
		'',
		`**Queued for auto-fix (${ queued.length }):**`,
		list( queued, ( n ) => `- #${ n }` ),
		'',
		`**Not auto-fixed, needs manual follow-up (${ manual.length }):**`,
		list( manual, ( m ) => `- #${ m.number }: ${ m.reason }` ),
		'',
	].join( '\n' );
}

/**
 * Renders the single auto-fix report comment.
 *
 * @param {Object} args Inputs.
 * @param {number[]} args.fixed Fixed issues.
 * @param {number[]} [args.fixPushed] Open issues whose fix is pushed (close on merge).
 * @param {Array<{number: number, note: string}>} args.closedNoCommit Closed without a matching pushed commit.
 * @param {Array<{number: number, reason: string}>} args.notFixed Open and unfixed.
 * @param {Array<{number: number, reason: string}>} args.notQueued Excluded by review.
 * @param {number[]} args.unaccounted Open review issues in no list.
 * @param {string} [args.runUrl] Link to the workflow run.
 * @param {string} [args.sessionOutcome] Outcome of the fix session step.
 * @return {string} Markdown with hidden marker.
 */
export function renderReport( {
	fixed,
	fixPushed = [],
	closedNoCommit,
	notFixed,
	notQueued,
	unaccounted,
	runUrl = '',
	sessionOutcome = '',
} ) {
	const counts = [
		`Fixed ${ fixed.length }`,
		`Not fixed ${ notFixed.length }`,
		`Not queued by review ${ notQueued.length }`,
	];
	if ( fixPushed.length ) {
		counts.push( `Fix pushed ${ fixPushed.length }` );
	}
	if ( closedNoCommit.length ) {
		counts.push(
			`Closed without a matching pushed commit ${ closedNoCommit.length }`
		);
	}
	if ( unaccounted.length ) {
		counts.push( `Unaccounted ${ unaccounted.length }` );
	}
	const lines = [
		REPORT_MARKER,
		'### Auto-fix report',
		'',
		counts.join( ' · ' ),
		'',
	];
	if ( SESSION_FAILED.has( sessionOutcome ) ) {
		lines.push(
			`> The auto-fix session ended with status \`${ sanitiseReason( sessionOutcome, 30 ) }\`; the lists below are computed from GitHub and git state.`,
			''
		);
	}
	lines.push(
		`**Fixed (${ fixed.length })**`,
		list( fixed, ( n ) => `- #${ n }` ),
		''
	);
	if ( fixPushed.length ) {
		lines.push(
			`**Fix pushed, issue closes on merge (${ fixPushed.length })**`,
			list( fixPushed, ( n ) => `- #${ n }` ),
			''
		);
	}
	if ( closedNoCommit.length ) {
		lines.push(
			`**Closed, no matching pushed commit (${ closedNoCommit.length })** (not counted as fixed)`,
			list( closedNoCommit, ( c ) => `- #${ c.number }: ${ c.note }` ),
			''
		);
	}
	lines.push(
		`**Not fixed (${ notFixed.length })**`,
		list( notFixed, ( n ) => `- #${ n.number }: ${ n.reason }` ),
		'',
		`**Not queued by review (${ notQueued.length })**`,
		list( notQueued, ( n ) => `- #${ n.number }: ${ n.reason }` ),
		''
	);
	if ( unaccounted.length ) {
		lines.push(
			`**Open review issues not accounted for (${ unaccounted.length })**`,
			list( unaccounted, ( n ) => `- #${ n }` ),
			''
		);
	}
	if ( runUrl ) {
		lines.push( `[Workflow run](${ runUrl })`, '' );
	}
	return lines.join( '\n' );
}

// ── CLI wrappers (side effects) ─────────────────────────────────────────────

/**
 * @param {string} name Env var.
 * @return {string} Its value.
 * @throws {Error} When unset.
 */
function need( name ) {
	const v = process.env[ name ];
	if ( ! v ) {
		throw new Error( `${ name } is not set` );
	}
	return v;
}

/**
 * Runs a binary without a shell.
 *
 * @param {string}   cmd  Binary.
 * @param {string[]} args Arguments.
 * @return {{ok: boolean, out: string}} Result.
 */
function run( cmd, args ) {
	const r = spawnSync( cmd, args, {
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	} );
	return { ok: r.status === 0, out: r.stdout ?? '' };
}

/**
 * @param {string[]} args gh arguments.
 * @return {unknown} Parsed JSON.
 * @throws {Error} When gh fails.
 */
function ghJson( args ) {
	const r = run( 'gh', args );
	if ( ! r.ok ) {
		throw new Error( `gh ${ args.slice( 0, 2 ).join( ' ' ) } failed` );
	}
	return JSON.parse( r.out || 'null' );
}

/**
 * Writes a step output (single line).
 *
 * @param {string} name  Output name.
 * @param {string} value Single-line value.
 */
function setOutput( name, value ) {
	const line = `${ name }=${ String( value ).replace( /[\r\n]+/g, ' ' ) }\n`;
	if ( process.env.GITHUB_OUTPUT ) {
		fs.appendFileSync( process.env.GITHUB_OUTPUT, line );
	} else {
		process.stdout.write( line );
	}
}

/**
 * Lists open code-review issues for the PR straight from GitHub.
 *
 * @param {string} repo     owner/name.
 * @param {number} prNumber PR number.
 * @return {ReturnType<typeof partitionReviewIssues>} Partition.
 */
function liveReviewIssues( repo, prNumber ) {
	const issues = ghJson( [
		'issue',
		'list',
		'--repo',
		repo,
		'--label',
		'code-review',
		'--state',
		'open',
		'--limit',
		String( MAX_ISSUES ),
		'--json',
		'number,body,labels',
	] );
	return partitionReviewIssues( issues, prNumber );
}

function cmdPartition() {
	const prNumber = Number( need( 'PR_NUMBER' ) );
	const issues = JSON.parse(
		fs.readFileSync( need( 'ISSUES_FILE' ), 'utf8' )
	);
	const p = partitionReviewIssues( issues, prNumber );
	for ( const f of p.forced ) {
		console.log(
			`::warning::#${ f.number } was marked needs-manual-fix for a reason that is not valid ("${ f.reason }"); queued for auto-fix anyway.`
		);
	}
	if ( p.matched === 0 ) {
		console.log(
			`::notice::No open review issues reference PR #${ prNumber }.`
		);
	}
	setOutput( 'issues', JSON.stringify( p.queued ) );
	setOutput( 'manual', JSON.stringify( p.manual ) );
	fs.writeFileSync(
		need( 'FOOTER_FILE' ),
		renderQueueFooter( { queued: p.queued, manual: p.manual } )
	);
	console.log(
		`Queued: ${ p.queued.join( ', ' ) || 'none' }; manual: ${ p.manual.map( ( m ) => m.number ).join( ', ' ) || 'none' }`
	);
}

function cmdUpsertComment() {
	const repo = need( 'REPO' );
	const pr = need( 'PR_NUMBER' );
	if ( ! /^[0-9]+$/.test( pr ) ) {
		throw new Error( 'PR_NUMBER must be numeric' );
	}
	const marker = need( 'MARKER' );
	const body = fs.readFileSync( need( 'BODY_FILE' ), 'utf8' );
	if ( ! body.includes( marker ) ) {
		throw new Error( 'body does not contain the marker' );
	}
	const base = `repos/${ repo }/issues/${ pr }/comments`;
	const pages = ghJson( [
		'api',
		'--paginate',
		'--slurp',
		`${ base }?per_page=100`,
	] );
	const id = findOwnComment( [].concat( ...pages ), marker );
	const args = id
		? [
				'api',
				'--method',
				'PATCH',
				`repos/${ repo }/issues/comments/${ id }`,
			]
		: [ 'api', '--method', 'POST', base ];
	// gh reads the body from a file to avoid argv limits and quoting.
	const res = spawnSync(
		'gh',
		[ ...args, '-F', `body=@${ process.env.BODY_FILE }` ],
		{ encoding: 'utf8' }
	);
	if ( res.status !== 0 ) {
		throw new Error( 'could not post the comment' );
	}
	console.log( id ? `Edited comment ${ id }.` : 'Posted a new comment.' );
}

function cmdValidatePayload() {
	const parsed = JSON.parse( need( 'PAYLOAD_JSON' ) );
	const res = validatePayload( parsed );
	if ( ! res.ok ) {
		for ( const e of res.errors ) {
			console.log( `::error::Invalid dispatch payload: ${ e }` );
		}
		process.exitCode = 1;
		return;
	}
	const v = res.value;
	setOutput( 'pr_number', v.prNumber );
	setOutput( 'head_branch', v.headBranch );
	setOutput( 'base_branch', v.baseBranch );
	setOutput( 'issues', JSON.stringify( v.issues ) );
	setOutput( 'manual', JSON.stringify( v.manual ) );
	setOutput( 'issues_csv', v.issues.map( ( n ) => `#${ n }` ).join( ', ' ) );
	setOutput( 'has_issues', v.issues.length ? 'true' : 'false' );
}

/**
 * Gathers state from GitHub and git, then classifies it.
 *
 * @return {Object} Data for renderReport plus the issues that need labelling.
 */
function gather() {
	const repo = need( 'REPO' );
	const prNumber = Number( need( 'PR_NUMBER' ) );
	const dispatched = JSON.parse( need( 'ISSUES_JSON' ) );
	const payloadManual = JSON.parse( process.env.MANUAL_JSON || '[]' );
	const sinceIso = process.env.START_TIME || '1970-01-01T00:00:00Z';
	const tsv =
		process.env.UNFIXED_TSV && fs.existsSync( process.env.UNFIXED_TSV )
			? parseUnfixedTsv(
					fs.readFileSync( process.env.UNFIXED_TSV, 'utf8' )
				)
			: {};

	const issues = {};
	const bodies = {};
	for ( const n of dispatched ) {
		try {
			const d = ghJson( [
				'issue',
				'view',
				String( n ),
				'--repo',
				repo,
				'--json',
				'state,body,comments',
			] );
			bodies[ n ] = d.body ?? '';
			issues[ n ] = {
				state: d.state,
				reason:
					reasonFromComments( d.comments, sinceIso ) ??
					tsv[ n ] ??
					null,
			};
		} catch {
			issues[ n ] = {
				state: 'UNKNOWN',
				reason: 'issue state could not be read',
			};
		}
	}

	let commits = [];
	let pushed = null;
	const startSha = process.env.START_SHA;
	const branch = process.env.HEAD_BRANCH;
	if ( startSha && branch && dispatched.length ) {
		const log = run( 'git', [
			'log',
			`${ startSha }..HEAD`,
			'--format=%B%x00',
		] );
		commits = log.ok
			? log.out
					.split( '\0' )
					.map( ( s ) => s.trim() )
					.filter( Boolean )
			: [];
		const head = run( 'git', [ 'rev-parse', 'HEAD' ] ).out.trim();
		let remote = run( 'git', [
			'ls-remote',
			'origin',
			`refs/heads/${ branch }`,
		] ).out.split( /\s/ )[ 0 ];
		if ( ! remote ) {
			console.log(
				'::warning::git ls-remote failed; falling back to the GitHub API for the pushed SHA.'
			);
			remote = run( 'gh', [
				'api',
				`repos/${ repo }/commits/${ branch }`,
				'--jq',
				'.sha',
			] ).out.trim();
		}
		if ( remote && head ) {
			pushed = head === remote;
		} else {
			pushed = null;
			console.log(
				'::warning::Could not determine whether HEAD is pushed; not reporting fixes as unpushed.'
			);
		}
	}

	const sessionFailed = SESSION_FAILED.has( process.env.SESSION_OUTCOME );
	const outcome = classifyOutcome( {
		dispatched,
		issues,
		commits,
		pushed,
		sessionFailed,
	} );
	let live = { queued: [], manual: [] };
	try {
		live = liveReviewIssues( repo, prNumber );
	} catch ( err ) {
		console.log(
			`::warning::Could not list open review issues: ${ err.message }`
		);
	}
	const notQueued = mergeManual( payloadManual, live.manual, dispatched );
	const unaccounted = findUnaccounted( live.queued, dispatched, notQueued );
	return { outcome, notQueued, unaccounted, bodies, repo };
}

/**
 * Labels deterministic "not fixed" issues (with the reason recorded on the
 * issue) so a re-run does not re-queue them forever.
 *
 * @param {ReturnType<typeof gather>} g Gathered data.
 */
function labelUnfixed( g ) {
	for ( const n of g.outcome.notFixed.filter(
		( x ) => ! x.hasCommit && ! x.retry
	) ) {
		const edit = [
			'issue',
			'edit',
			String( n.number ),
			'--repo',
			g.repo,
			'--add-label',
			MANUAL_LABEL,
		];
		const body = g.bodies[ n.number ] ?? '';
		if ( body && extractReason( body ) === null ) {
			fs.writeFileSync(
				`${ process.env.RUNNER_TEMP || '.' }/autofix-body-${ n.number }.txt`,
				`${ body }\n\n**Not auto-fixed:** ${ n.reason }\n`
			);
			edit.push(
				'--body-file',
				`${ process.env.RUNNER_TEMP || '.' }/autofix-body-${ n.number }.txt`
			);
		}
		if ( ! run( 'gh', edit ).ok ) {
			console.log(
				`::warning::Could not label #${ n.number } ${ MANUAL_LABEL }.`
			);
		}
	}
}

/**
 * Adds `Closes #N` to the PR body for issues whose fix is pushed only, so
 * merging never auto-closes an issue that was not fixed.
 *
 * @param {ReturnType<typeof gather>} g Gathered data.
 */
function addClosingKeywords( g ) {
	const numbers = [ ...g.outcome.fixed, ...g.outcome.fixPushed ];
	if ( ! numbers.length ) {
		return;
	}
	const pr = process.env.PR_NUMBER;
	const view = run( 'gh', [
		'pr',
		'view',
		pr,
		'--repo',
		g.repo,
		'--json',
		'body',
		'--jq',
		'.body // ""',
	] );
	if ( ! view.ok ) {
		console.log(
			'::warning::Could not read the PR body to add closing keywords.'
		);
		return;
	}
	const current = view.out.replace( /\n$/, '' );
	const extra = closingKeywordsToAppend( current, numbers );
	if (
		extra &&
		! run( 'gh', [
			'pr',
			'edit',
			pr,
			'--repo',
			g.repo,
			'--body',
			`${ current }${ extra }`,
		] ).ok
	) {
		console.log(
			'::warning::Could not add closing keywords to the PR body.'
		);
	}
}

function reportData( g ) {
	return {
		fixed: g.outcome.fixed,
		fixPushed: g.outcome.fixPushed,
		closedNoCommit: g.outcome.closedNoCommit,
		notFixed: g.outcome.notFixed,
		notQueued: g.notQueued,
		unaccounted: g.unaccounted,
		runUrl: process.env.RUN_URL || '',
		sessionOutcome: process.env.SESSION_OUTCOME || '',
	};
}

function cmdReport() {
	const g = gather();
	if ( g.unaccounted.length ) {
		const msg = `Open review issues not in any list: ${ g.unaccounted.map( ( n ) => `#${ n }` ).join( ', ' ) }`;
		console.log( `::warning::${ msg }` );
		if ( process.env.GITHUB_STEP_SUMMARY ) {
			fs.appendFileSync(
				process.env.GITHUB_STEP_SUMMARY,
				`**Warning:** ${ msg }\n`
			);
		}
	}
	labelUnfixed( g );
	addClosingKeywords( g );
	const body = renderReport( reportData( g ) );
	const file = `${ process.env.RUNNER_TEMP || '.' }/autofix-report.md`;
	fs.writeFileSync( file, body );
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		fs.appendFileSync( process.env.GITHUB_STEP_SUMMARY, `${ body }\n` );
	}
	process.env.BODY_FILE = file;
	process.env.MARKER = REPORT_MARKER;
	cmdUpsertComment();
}

function cmdRenderReport() {
	const fixture = JSON.parse( fs.readFileSync( process.argv[ 3 ], 'utf8' ) );
	const outcome = classifyOutcome( fixture );
	const manual = mergeManual(
		fixture.payloadManual,
		fixture.liveManual,
		fixture.dispatched
	);
	const unaccounted = findUnaccounted(
		fixture.openQueued,
		fixture.dispatched,
		manual
	);
	console.log(
		renderReport( {
			...outcome,
			notQueued: manual,
			unaccounted,
			sessionOutcome: fixture.sessionOutcome,
		} )
	);
}

// ── Entry point ─────────────────────────────────────────────────────────────

const COMMANDS = {
	partition: cmdPartition,
	'upsert-comment': cmdUpsertComment,
	'validate-payload': cmdValidatePayload,
	report: cmdReport,
	'render-report': cmdRenderReport,
};

/**
 * Runs a subcommand. Only validate-payload may fail the job (unsafe input must
 * stop it); everything else is a review aid and degrades to a warning.
 *
 * @param {string} name Subcommand.
 */
export function main( name ) {
	const command = COMMANDS[ name ];
	if ( ! command ) {
		console.log( `::warning::autofix-status: unknown command "${ name }"` );
		return;
	}
	try {
		command();
	} catch ( err ) {
		if ( name === 'validate-payload' ) {
			console.log(
				`::error::autofix-status ${ name }: ${ err.message }`
			);
			process.exitCode = 1;
			return;
		}
		console.log(
			`::warning::autofix-status (${ name }) incomplete: ${ err.message }`
		);
		if ( name === 'partition' ) {
			setOutput( 'issues', '[]' );
			setOutput( 'manual', '[]' );
		}
	}
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	main( process.argv[ 2 ] ?? '' );
}
