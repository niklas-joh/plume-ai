# SEO & Images Admin Pages — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the React UI for the two Pro-gated WP AI Mind admin pages — SEO and Images — using a shared `PostListTable` component, matching the design spec at `docs/superpowers/specs/2026-03-27-seo-images-admin-pages-design.md`.

**Architecture:** Two new webpack entries (`seo/index`, `images/index`) each mount a standalone React app. Both apps share a `PostListTable` component that handles the list + inline-expand UX pattern. The SEO page adds a `wpaim_seo_status` field to the WP REST API (registered in PHP) to surface metadata gap status per post.

**Tech Stack:** React 18 via `@wordpress/element`, `@wordpress/api-fetch`, `lucide-react`, Brain\Monkey PHPUnit tests, `@wordpress/scripts` build toolchain.

---

## Pre-flight

```bash
cd /Users/niklas/Documents/Homepages/wp-ai-mind
docker compose up -d          # WordPress must be running for manual checks
./vendor/bin/phpunit tests/Unit/ --colors=always   # all green before you start
npm run build                 # confirm baseline build passes
```

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `webpack.config.js` | Modify | Add seo/index and images/index entries |
| `includes/Modules/Seo/SeoModule.php` | Modify | Register `wpaim_seo_status` REST field + `adminUrl` to localized data |
| `includes/Modules/Images/ImagesModule.php` | Modify | Add `adminUrl` to localized data |
| `tests/Unit/Modules/Seo/SeoStatusFieldTest.php` | Create | PHPUnit tests for `get_seo_status()` |
| `src/shared/PostListTable.jsx` | Create | Paginated list + inline expand shell |
| `src/shared/shared.css` | Create | CSS shared between seo and images pages |
| `src/seo/index.js` | Create | Entry point — mounts SeoApp, sets nonce middleware |
| `src/seo/SeoApp.jsx` | Create | Pro gate + page header + PostListTable wiring |
| `src/seo/SeoBadge.jsx` | Create | Complete / Partial / Missing badge |
| `src/seo/SeoWorkArea.jsx` | Create | Generate → edit fields → apply work area |
| `src/seo/seo.css` | Create | SEO-page-specific styles |
| `src/images/index.js` | Create | Entry point — mounts ImagesApp, sets nonce middleware |
| `src/images/ImagesApp.jsx` | Create | Pro gate + page header + PostListTable wiring |
| `src/images/ImagesBadge.jsx` | Create | Has image / No image badge + thumbnail |
| `src/images/ImagesWorkArea.jsx` | Create | Prompt → generate → pick → set featured image |
| `src/images/images.css` | Create | Images-page-specific styles |

---

## Task 1: Webpack entries

**Files:**
- Modify: `webpack.config.js`

- [ ] **Step 1: Add the two new entries**

Open `webpack.config.js`. It currently reads:

```js
entry: {
    'admin/index':     path.resolve( __dirname, 'src/admin/index.js' ),
    'editor/index':    path.resolve( __dirname, 'src/editor/index.js' ),
    'generator/index': path.resolve( __dirname, 'src/generator/index.js' ),
    'usage/index':     path.resolve( __dirname, 'src/usage/index.js' ),
    'frontend/widget': path.resolve( __dirname, 'src/frontend/widget.js' ),
},
```

Replace with:

```js
entry: {
    'admin/index':     path.resolve( __dirname, 'src/admin/index.js' ),
    'editor/index':    path.resolve( __dirname, 'src/editor/index.js' ),
    'generator/index': path.resolve( __dirname, 'src/generator/index.js' ),
    'usage/index':     path.resolve( __dirname, 'src/usage/index.js' ),
    'frontend/widget': path.resolve( __dirname, 'src/frontend/widget.js' ),
    'seo/index':       path.resolve( __dirname, 'src/seo/index.js' ),
    'images/index':    path.resolve( __dirname, 'src/images/index.js' ),
},
```

- [ ] **Step 2: Commit**

```bash
git add webpack.config.js
git commit -m "build: add seo/index and images/index webpack entries"
```

---

## Task 2: PHP — `wpaim_seo_status` REST field

**Files:**
- Modify: `includes/Modules/Seo/SeoModule.php`
- Modify: `includes/Modules/Images/ImagesModule.php`
- Create: `tests/Unit/Modules/Seo/SeoStatusFieldTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Seo/SeoStatusFieldTest.php`:

```php
<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Seo\SeoModule;
use PHPUnit\Framework\TestCase;

class SeoStatusFieldTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_all_empty_when_no_meta_set(): void {
        $post_id = 42;

        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

        $post              = new \stdClass();
        $post->post_excerpt = '';
        Functions\when( 'get_post' )->justReturn( $post );

        $result = SeoModule::get_seo_status( [ 'id' => $post_id ] );

        $this->assertSame( 'empty', $result['meta_title'] );
        $this->assertSame( 'empty', $result['og_description'] );
        $this->assertSame( 'empty', $result['excerpt'] );
        $this->assertSame( 'empty', $result['alt_text'] );
    }

    public function test_yoast_meta_title_detected_as_filled(): void {
        Functions\expect( 'get_post_meta' )
            ->with( 42, '_yoast_wpseo_title', true )
            ->andReturn( 'My Yoast Title' );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
        $post               = new \stdClass();
        $post->post_excerpt = '';
        Functions\when( 'get_post' )->justReturn( $post );

        $result = SeoModule::get_seo_status( [ 'id' => 42 ] );

        $this->assertSame( 'filled', $result['meta_title'] );
    }

    public function test_rank_math_title_detected_as_filled_when_yoast_empty(): void {
        Functions\expect( 'get_post_meta' )
            ->with( 42, '_yoast_wpseo_title', true )
            ->andReturn( '' );
        Functions\expect( 'get_post_meta' )
            ->with( 42, 'rank_math_title', true )
            ->andReturn( 'My RankMath Title' );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
        $post               = new \stdClass();
        $post->post_excerpt = '';
        Functions\when( 'get_post' )->justReturn( $post );

        $result = SeoModule::get_seo_status( [ 'id' => 42 ] );

        $this->assertSame( 'filled', $result['meta_title'] );
    }

    public function test_excerpt_detected_as_filled(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
        $post               = new \stdClass();
        $post->post_excerpt = 'A nice summary.';
        Functions\when( 'get_post' )->justReturn( $post );

        $result = SeoModule::get_seo_status( [ 'id' => 42 ] );

        $this->assertSame( 'filled', $result['excerpt'] );
    }

    public function test_alt_text_filled_when_featured_image_has_alt(): void {
        Functions\when( 'get_post_meta' )
            ->alias( function( $id, $key, $single ) {
                if ( $key === '_wp_attachment_image_alt' ) return 'A descriptive alt text';
                return '';
            } );
        Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );
        $post               = new \stdClass();
        $post->post_excerpt = '';
        Functions\when( 'get_post' )->justReturn( $post );

        $result = SeoModule::get_seo_status( [ 'id' => 42 ] );

        $this->assertSame( 'filled', $result['alt_text'] );
    }

    public function test_alt_text_empty_when_no_featured_image(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
        $post               = new \stdClass();
        $post->post_excerpt = '';
        Functions\when( 'get_post' )->justReturn( $post );

        $result = SeoModule::get_seo_status( [ 'id' => 42 ] );

        $this->assertSame( 'empty', $result['alt_text'] );
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
./vendor/bin/phpunit tests/Unit/Modules/Seo/SeoStatusFieldTest.php --colors=always
```

Expected: FAIL — `SeoModule::get_seo_status` does not exist yet.

- [ ] **Step 3: Add `get_seo_status` and `register_seo_status_field` to SeoModule**

In `includes/Modules/Seo/SeoModule.php`, make the following changes:

**In `register()`**, add a third `add_action` call:

```php
public static function register(): void {
    \add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    \add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    \add_action( 'rest_api_init', [ self::class, 'register_seo_status_field' ] );
}
```

**In `enqueue_assets()`**, add `adminUrl` to the localized data array:

```php
\wp_localize_script(
    'wp-ai-mind-seo',
    'wpAiMindData',
    [
        'nonce'    => \wp_create_nonce( 'wp_rest' ),
        'restUrl'  => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
        'isPro'    => \wp_ai_mind_is_pro(),
        'adminUrl' => \esc_url_raw( \admin_url() ),
    ]
);
```

**Add two new public static methods** at the end of the class, before the closing `}`:

```php
public static function register_seo_status_field(): void {
    foreach ( [ 'post', 'page' ] as $post_type ) {
        \register_rest_field(
            $post_type,
            'wpaim_seo_status',
            [
                'get_callback'    => [ self::class, 'get_seo_status' ],
                'update_callback' => null,
                'schema'          => [
                    'type'       => 'object',
                    'context'    => [ 'view' ],
                    'properties' => [
                        'meta_title'     => [ 'type' => 'string', 'enum' => [ 'filled', 'empty' ] ],
                        'og_description' => [ 'type' => 'string', 'enum' => [ 'filled', 'empty' ] ],
                        'excerpt'        => [ 'type' => 'string', 'enum' => [ 'filled', 'empty' ] ],
                        'alt_text'       => [ 'type' => 'string', 'enum' => [ 'filled', 'empty' ] ],
                    ],
                ],
            ]
        );
    }
}

public static function get_seo_status( array $post_data ): array {
    $post_id = $post_data['id'];

    $meta_title = \get_post_meta( $post_id, '_yoast_wpseo_title', true )
        ?: \get_post_meta( $post_id, 'rank_math_title', true );

    $og_description = \get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
        ?: \get_post_meta( $post_id, 'rank_math_description', true );

    $post    = \get_post( $post_id );
    $excerpt = $post->post_excerpt ?? '';

    $thumb_id = \get_post_thumbnail_id( $post_id );
    $alt_text = $thumb_id
        ? \get_post_meta( $thumb_id, '_wp_attachment_image_alt', true )
        : '';

    return [
        'meta_title'     => $meta_title     ? 'filled' : 'empty',
        'og_description' => $og_description ? 'filled' : 'empty',
        'excerpt'        => $excerpt        ? 'filled' : 'empty',
        'alt_text'       => $alt_text       ? 'filled' : 'empty',
    ];
}
```

- [ ] **Step 4: Add `adminUrl` to ImagesModule localized data**

In `includes/Modules/Images/ImagesModule.php`, in `enqueue_assets()`, update the `wp_localize_script` call:

```php
\wp_localize_script(
    'wp-ai-mind-images',
    'wpAiMindData',
    [
        'nonce'    => \wp_create_nonce( 'wp_rest' ),
        'restUrl'  => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
        'isPro'    => \wp_ai_mind_is_pro(),
        'adminUrl' => \esc_url_raw( \admin_url() ),
    ]
);
```

- [ ] **Step 5: Run tests — expect green**

```bash
./vendor/bin/phpunit tests/Unit/Modules/Seo/SeoStatusFieldTest.php --colors=always
```

Expected: 6 tests, 6 assertions, PASS.

- [ ] **Step 6: Run full suite to confirm no regressions**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add includes/Modules/Seo/SeoModule.php \
        includes/Modules/Images/ImagesModule.php \
        tests/Unit/Modules/Seo/SeoStatusFieldTest.php
git commit -m "feat: register wpaim_seo_status REST field on posts and pages"
```

---

## Task 3: Shared PostListTable + CSS

**Files:**
- Create: `src/shared/PostListTable.jsx`
- Create: `src/shared/shared.css`

- [ ] **Step 1: Create `src/shared/shared.css`**

```css
/* ── Shared: used by both SEO and Images pages ── */

/* Page shell */
.wpaim-page {
  max-width: 1200px;
}

.wpaim-page-header {
  margin-bottom: 20px;
}

.wpaim-page-header h1 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 23px;
  font-weight: 400;
  color: var(--color-text-primary);
  margin: 0 0 4px;
}

.wpaim-page-header p {
  color: var(--color-text-secondary);
  margin: 0;
}

/* Pro badge */
.wpaim-pro-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 700;
  background: rgba(var(--wp-admin-theme-color--rgb, 0, 115, 170), 0.12);
  color: var(--wp-admin-theme-color);
  letter-spacing: 0.05em;
  vertical-align: middle;
}

/* Pro gate (lock screen) */
.wpaim-pro-gate {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 24px;
  text-align: center;
  gap: 12px;
  color: var(--color-text-muted);
}

.wpaim-pro-gate h2 {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-text-primary);
  margin: 0;
}

.wpaim-pro-gate p {
  max-width: 360px;
  color: var(--color-text-secondary);
  margin: 0;
}

/* Status badges */
.wpaim-badge {
  display: inline-block;
  padding: 1px 8px;
  border-radius: 99px;
  font-size: 11px;
  font-weight: 600;
}

.wpaim-badge--complete,
.wpaim-badge--has {
  background: rgba(22, 163, 74, 0.12);
  color: #16a34a;
}

.wpaim-badge--partial {
  background: rgba(217, 119, 6, 0.12);
  color: #d97706;
}

.wpaim-badge--missing,
.wpaim-badge--none {
  background: rgba(220, 38, 38, 0.12);
  color: #dc2626;
}

/* Type badge (post / page) */
.wpaim-type-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-family: var(--font-mono);
  background: var(--color-surface-2);
  color: var(--color-text-secondary);
}

/* PostListTable toolbar */
.wpaim-list-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.wpaim-list-tabs {
  display: flex;
  gap: 2px;
}

.wpaim-tab {
  padding: 4px 12px;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 400;
  cursor: pointer;
  color: var(--color-text-secondary);
  background: transparent;
  border: none;
  line-height: 1.4;
}

.wpaim-tab:hover {
  background: var(--color-surface-2);
  color: var(--color-text-primary);
}

.wpaim-tab.is-active {
  background: var(--color-surface-2);
  color: var(--color-text-primary);
  font-weight: 600;
}

.wpaim-list-search {
  padding: 4px 8px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  font-size: 13px;
  color: var(--color-text-primary);
  background: #fff;
  min-width: 220px;
}

/* Table: expanded row */
.wpaim-work-row > td {
  padding: 0 !important;
  border-top: 2px solid var(--wp-admin-theme-color);
}

/* Pagination */
.wpaim-list-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid var(--color-border);
}

.wpaim-list-count {
  font-size: 12px;
  color: var(--color-text-muted);
}

.wpaim-list-page-btns {
  display: flex;
  gap: 6px;
}

/* Work area base */
.wpaim-work-area {
  padding: 16px 20px;
  background: var(--color-surface);
}

.wpaim-work-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.wpaim-work-title {
  flex: 1;
  font-weight: 600;
  font-size: 14px;
  color: var(--color-text-primary);
}

.wpaim-work-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border);
}

.wpaim-action-link {
  font-size: 12px;
  color: var(--color-text-muted);
  margin-right: auto;
}

.wpaim-action-link:hover {
  color: var(--wp-admin-theme-color);
}

.wpaim-work-error {
  color: var(--color-error);
  font-size: 12px;
  margin: 8px 0 0;
}

.wpaim-work-warning {
  color: var(--color-warning);
  font-size: 12px;
  margin: 8px 0 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.wpaim-dismiss {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-text-muted);
  padding: 0;
  font-size: 11px;
}

/* Spinner */
@keyframes wpaim-spin {
  to { transform: rotate(360deg); }
}

.wpaim-spin {
  animation: wpaim-spin 0.7s linear infinite;
  display: inline-block;
  vertical-align: middle;
  margin-right: 4px;
}

.wpaim-list-loading,
.wpaim-list-error {
  padding: 24px;
  color: var(--color-text-muted);
  font-size: 13px;
}
```

- [ ] **Step 2: Create `src/shared/PostListTable.jsx`**

```jsx
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PER_PAGE = 20;

export default function PostListTable( { tabs, WorkArea, columns = [] } ) {
    const [ posts, setPosts ]         = useState( [] );
    const [ loading, setLoading ]     = useState( true );
    const [ error, setError ]         = useState( null );
    const [ activeTab, setActiveTab ] = useState( tabs[ 0 ]?.id ?? 'all' );
    const [ search, setSearch ]       = useState( '' );
    const [ page, setPage ]           = useState( 1 );
    const [ expandedId, setExpandedId ] = useState( null );

    useEffect( () => {
        const fetchAll = async () => {
            try {
                const [ postsRes, pagesRes ] = await Promise.all( [
                    apiFetch( { path: '/wp/v2/posts?per_page=100&_embed=1' } ),
                    apiFetch( { path: '/wp/v2/pages?per_page=100&_embed=1' } ),
                ] );
                const merged = [ ...postsRes, ...pagesRes ].sort(
                    ( a, b ) => new Date( b.modified ) - new Date( a.modified )
                );
                setPosts( merged );
            } catch ( e ) {
                setError( e.message ?? 'Failed to load posts.' );
            } finally {
                setLoading( false );
            }
        };
        fetchAll();
    }, [] );

    const handlePostUpdate = ( updatedFields ) => {
        setPosts( prev =>
            prev.map( p => p.id === updatedFields.id ? { ...p, ...updatedFields } : p )
        );
    };

    const activeFilter = tabs.find( t => t.id === activeTab )?.filter ?? ( () => true );
    const filtered = posts
        .filter( activeFilter )
        .filter( p =>
            p.title.rendered.toLowerCase().includes( search.toLowerCase() )
        );

    const totalPages = Math.ceil( filtered.length / PER_PAGE );
    const visible    = filtered.slice( ( page - 1 ) * PER_PAGE, page * PER_PAGE );

    const handleExpand = ( id ) => {
        setExpandedId( prev => ( prev === id ? null : id ) );
    };

    const handleTabChange = ( id ) => {
        setActiveTab( id );
        setPage( 1 );
        setExpandedId( null );
    };

    const handleSearch = ( e ) => {
        setSearch( e.target.value );
        setPage( 1 );
        setExpandedId( null );
    };

    if ( loading ) return <div className="wpaim-list-loading">Loading posts…</div>;
    if ( error )   return <div className="wpaim-list-error">Error: { error }</div>;

    return (
        <div className="wpaim-post-list">
            <div className="wpaim-list-toolbar">
                <div className="wpaim-list-tabs">
                    { tabs.map( tab => (
                        <button
                            key={ tab.id }
                            className={ `wpaim-tab${ activeTab === tab.id ? ' is-active' : '' }` }
                            onClick={ () => handleTabChange( tab.id ) }
                        >
                            { tab.label }
                        </button>
                    ) ) }
                </div>
                <input
                    type="search"
                    className="wpaim-list-search"
                    placeholder="Search posts…"
                    value={ search }
                    onChange={ handleSearch }
                />
            </div>

            <table className="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th style={ { width: 70 } }>Type</th>
                        { columns.map( col => (
                            <th key={ col.label } style={ col.width ? { width: col.width } : {} }>
                                { col.label }
                            </th>
                        ) ) }
                        <th style={ { width: 90 } }>Updated</th>
                        <th style={ { width: 120 } }></th>
                    </tr>
                </thead>
                <tbody>
                    { visible.length === 0 && (
                        <tr>
                            <td
                                colSpan={ 4 + columns.length }
                                style={ { textAlign: 'center', color: 'var(--color-text-muted)', padding: '20px' } }
                            >
                                No posts found.
                            </td>
                        </tr>
                    ) }
                    { visible.map( post => (
                        <PostRow
                            key={ post.id }
                            post={ post }
                            columns={ columns }
                            expanded={ expandedId === post.id }
                            onExpand={ () => handleExpand( post.id ) }
                            onClose={ () => setExpandedId( null ) }
                            onUpdate={ handlePostUpdate }
                            WorkArea={ WorkArea }
                        />
                    ) ) }
                </tbody>
            </table>

            { totalPages > 1 && (
                <div className="wpaim-list-pagination">
                    <span className="wpaim-list-count">
                        Showing { visible.length } of { filtered.length } posts and pages
                    </span>
                    <div className="wpaim-list-page-btns">
                        <button
                            className="button"
                            onClick={ () => setPage( p => p - 1 ) }
                            disabled={ page === 1 }
                        >
                            ← Prev
                        </button>
                        <button
                            className="button"
                            onClick={ () => setPage( p => p + 1 ) }
                            disabled={ page === totalPages }
                        >
                            Next →
                        </button>
                    </div>
                </div>
            ) }
        </div>
    );
}

function PostRow( { post, columns, expanded, onExpand, onClose, onUpdate, WorkArea } ) {
    const colSpan = 4 + columns.length;
    const updated = new Date( post.modified ).toLocaleDateString( 'en-GB', {
        day: 'numeric', month: 'short',
    } );

    return (
        <>
            <tr className={ expanded ? 'is-expanded' : '' }>
                <td dangerouslySetInnerHTML={ { __html: post.title.rendered } } />
                <td><span className="wpaim-type-badge">{ post.type }</span></td>
                { columns.map( col => (
                    <td key={ col.label }>{ col.render( post ) }</td>
                ) ) }
                <td>{ updated }</td>
                <td>
                    <button className="button button-small" onClick={ onExpand }>
                        { expanded ? '▲ Close' : 'Generate ▼' }
                    </button>
                </td>
            </tr>
            { expanded && (
                <tr className="wpaim-work-row">
                    <td colSpan={ colSpan }>
                        <WorkArea
                            post={ post }
                            onClose={ onClose }
                            onUpdate={ onUpdate }
                        />
                    </td>
                </tr>
            ) }
        </>
    );
}
```

- [ ] **Step 3: Commit**

```bash
git add src/shared/PostListTable.jsx src/shared/shared.css
git commit -m "feat: add shared PostListTable component and base CSS"
```

---

## Task 4: SEO page — SeoApp and entry point

**Files:**
- Create: `src/seo/index.js`
- Create: `src/seo/SeoApp.jsx`

- [ ] **Step 1: Create `src/seo/index.js`**

```js
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import SeoApp from './SeoApp';
import '../styles/tokens.css';
import './seo.css';

const { nonce } = window.wpAiMindData ?? {};
if ( nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const root = document.getElementById( 'wp-ai-mind-seo' );
if ( root ) {
    render( <SeoApp />, root );
}
```

- [ ] **Step 2: Create `src/seo/SeoApp.jsx`**

```jsx
import { Lock } from 'lucide-react';
import PostListTable from '../shared/PostListTable';
import SeoBadge, { getSeoStatus } from './SeoBadge';
import SeoWorkArea from './SeoWorkArea';

const { isPro } = window.wpAiMindData ?? {};

const SEO_TABS = [
    { id: 'all',      label: 'All',      filter: () => true },
    { id: 'missing',  label: 'Missing',  filter: p => getSeoStatus( p ) === 'missing' },
    { id: 'partial',  label: 'Partial',  filter: p => getSeoStatus( p ) === 'partial' },
    { id: 'complete', label: 'Complete', filter: p => getSeoStatus( p ) === 'complete' },
];

const SEO_COLUMNS = [
    {
        label: 'SEO Status',
        width: 130,
        render: post => <SeoBadge status={ getSeoStatus( post ) } />,
    },
];

export default function SeoApp() {
    if ( ! isPro ) {
        return (
            <div className="wpaim-pro-gate">
                <Lock size={ 32 } />
                <h2>AI SEO requires WP AI Mind Pro</h2>
                <p>
                    Automatically generate meta titles, OG descriptions, excerpts, and image alt
                    text for every post — in one click.
                </p>
                <a href="#" className="button button-primary button-large">
                    Upgrade to Pro →
                </a>
            </div>
        );
    }

    return (
        <div className="wpaim-page">
            <div className="wpaim-page-header">
                <h1>SEO <span className="wpaim-pro-badge">PRO</span></h1>
                <p>Generate and apply AI-written SEO metadata for your posts and pages.</p>
            </div>
            <PostListTable
                tabs={ SEO_TABS }
                WorkArea={ SeoWorkArea }
                columns={ SEO_COLUMNS }
            />
        </div>
    );
}
```

- [ ] **Step 3: Commit**

```bash
git add src/seo/index.js src/seo/SeoApp.jsx
git commit -m "feat: add SEO page entry point and SeoApp shell"
```

---

## Task 5: SeoBadge

**Files:**
- Create: `src/seo/SeoBadge.jsx`

- [ ] **Step 1: Create `src/seo/SeoBadge.jsx`**

```jsx
const STATUS_LABELS = {
    complete: 'Complete',
    partial:  'Partial',
    missing:  'Missing',
};

export function getSeoStatus( post ) {
    const s = post.wpaim_seo_status;
    if ( ! s ) return 'missing';
    const values = Object.values( s );
    const filledCount = values.filter( v => v === 'filled' ).length;
    if ( filledCount === 0 )            return 'missing';
    if ( filledCount === values.length ) return 'complete';
    return 'partial';
}

export default function SeoBadge( { status } ) {
    return (
        <span className={ `wpaim-badge wpaim-badge--${ status }` }>
            { STATUS_LABELS[ status ] ?? status }
        </span>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add src/seo/SeoBadge.jsx
git commit -m "feat: add SeoBadge component"
```

---

## Task 6: SeoWorkArea

**Files:**
- Create: `src/seo/SeoWorkArea.jsx`

- [ ] **Step 1: Create `src/seo/SeoWorkArea.jsx`**

```jsx
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Loader2 } from 'lucide-react';

const { nonce, restUrl, adminUrl = '/wp-admin/' } = window.wpAiMindData ?? {};

const EMPTY_FIELDS = { meta_title: '', og_description: '', excerpt: '', alt_text: '' };

export default function SeoWorkArea( { post, onClose, onUpdate } ) {
    const [ fields, setFields ]           = useState( EMPTY_FIELDS );
    const [ hasGenerated, setHasGenerated ] = useState( false );
    const [ generating, setGenerating ]   = useState( false );
    const [ applying, setApplying ]       = useState( false );
    const [ error, setError ]             = useState( null );

    const editUrl = `${ adminUrl }post.php?post=${ post.id }&action=edit`;

    const setField = ( key ) => ( e ) =>
        setFields( f => ( { ...f, [ key ]: e.target.value } ) );

    const handleGenerate = async () => {
        if (
            hasGenerated &&
            ! window.confirm( 'Replace current suggestions?' ) // eslint-disable-line no-alert
        ) {
            return;
        }
        setGenerating( true );
        setError( null );
        try {
            const data = await apiFetch( {
                url:     `${ restUrl }/seo/generate`,
                method:  'POST',
                headers: { 'X-WP-Nonce': nonce },
                data:    { post_id: post.id },
            } );
            setFields( {
                meta_title:     data.meta_title     ?? '',
                og_description: data.og_description ?? '',
                excerpt:        data.excerpt        ?? '',
                alt_text:       data.alt_text       ?? '',
            } );
            setHasGenerated( true );
        } catch ( e ) {
            setError( e.message ?? 'Generation failed.' );
        } finally {
            setGenerating( false );
        }
    };

    const handleApply = async () => {
        setApplying( true );
        setError( null );
        try {
            await apiFetch( {
                url:     `${ restUrl }/seo/apply`,
                method:  'POST',
                headers: { 'X-WP-Nonce': nonce },
                data:    { post_id: post.id, ...fields },
            } );
            const prev = post.wpaim_seo_status ?? {};
            onUpdate( {
                id: post.id,
                wpaim_seo_status: {
                    meta_title:     fields.meta_title     ? 'filled' : prev.meta_title,
                    og_description: fields.og_description ? 'filled' : prev.og_description,
                    excerpt:        fields.excerpt        ? 'filled' : prev.excerpt,
                    alt_text:       fields.alt_text       ? 'filled' : prev.alt_text,
                },
            } );
            onClose();
        } catch ( e ) {
            setError( e.message ?? 'Apply failed.' );
        } finally {
            setApplying( false );
        }
    };

    const inputClass = ( base = 'wpaim-field-input' ) =>
        `${ base }${ hasGenerated ? ' is-generated' : '' }`;

    return (
        <div className="wpaim-work-area">
            <div className="wpaim-work-header">
                <span
                    className="wpaim-work-title"
                    dangerouslySetInnerHTML={ { __html: post.title.rendered } }
                />
                <button
                    className="button button-primary"
                    onClick={ handleGenerate }
                    disabled={ generating }
                >
                    { generating
                        ? <>
                            <Loader2 size={ 12 } className="wpaim-spin" />
                            { ' ' }Generating…
                          </>
                        : '✦ Generate SEO'
                    }
                </button>
            </div>

            <div className="wpaim-seo-fields-grid">
                <div className="wpaim-field">
                    <label className="wpaim-field-label">
                        Meta title
                        <span className="wpaim-char-count">
                            { fields.meta_title.length } / 60
                        </span>
                    </label>
                    <input
                        type="text"
                        className={ inputClass() }
                        value={ fields.meta_title }
                        onChange={ setField( 'meta_title' ) }
                        placeholder="AI will generate this…"
                    />
                </div>

                <div className="wpaim-field">
                    <label className="wpaim-field-label">
                        OG description
                        <span className="wpaim-char-count">
                            { fields.og_description.length } / 160
                        </span>
                    </label>
                    <input
                        type="text"
                        className={ inputClass() }
                        value={ fields.og_description }
                        onChange={ setField( 'og_description' ) }
                        placeholder="AI will generate this…"
                    />
                </div>

                <div className="wpaim-field wpaim-field--full">
                    <label className="wpaim-field-label">Excerpt</label>
                    <textarea
                        className={ inputClass() }
                        value={ fields.excerpt }
                        onChange={ setField( 'excerpt' ) }
                        placeholder="AI will generate this…"
                        rows={ 3 }
                    />
                </div>

                <div className="wpaim-field wpaim-field--full">
                    <label className="wpaim-field-label">
                        Featured image alt text
                    </label>
                    <input
                        type="text"
                        className={ inputClass() }
                        value={ fields.alt_text }
                        onChange={ setField( 'alt_text' ) }
                        placeholder="AI will generate this…"
                    />
                </div>
            </div>

            { error && <p className="wpaim-work-error">{ error }</p> }

            <div className="wpaim-work-actions">
                <a
                    href={ editUrl }
                    target="_blank"
                    rel="noreferrer"
                    className="wpaim-action-link"
                >
                    Edit post →
                </a>
                <button
                    className="button"
                    onClick={ onClose }
                    disabled={ applying }
                >
                    Discard
                </button>
                <button
                    className="button button-primary"
                    onClick={ handleApply }
                    disabled={ applying || ! hasGenerated }
                >
                    { applying ? 'Applying…' : '✓ Apply all' }
                </button>
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add src/seo/SeoWorkArea.jsx
git commit -m "feat: add SeoWorkArea — generate, edit, and apply SEO fields"
```

---

## Task 7: SEO CSS + first build

**Files:**
- Create: `src/seo/seo.css`

- [ ] **Step 1: Create `src/seo/seo.css`**

```css
@import '../shared/shared.css';

/* SEO fields grid */
.wpaim-seo-fields-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 4px;
}

.wpaim-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.wpaim-field--full {
  grid-column: 1 / -1;
}

.wpaim-field-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-secondary);
  margin-bottom: 0;
}

.wpaim-char-count {
  font-weight: 400;
  color: var(--color-text-muted);
  letter-spacing: 0;
}

.wpaim-field-input {
  width: 100%;
  padding: 6px 8px;
  font-size: 13px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: #fff;
  color: var(--color-text-primary);
  box-sizing: border-box;
}

.wpaim-field-input.is-generated {
  border-color: var(--wp-admin-theme-color);
  background: rgba(var(--wp-admin-theme-color--rgb, 0, 115, 170), 0.04);
}

textarea.wpaim-field-input {
  resize: vertical;
  min-height: 72px;
}

.wpaim-field-input:focus {
  outline: none;
  border-color: var(--wp-admin-theme-color);
  box-shadow: 0 0 0 1px var(--wp-admin-theme-color);
}
```

- [ ] **Step 2: Run the build**

```bash
npm run build 2>&1 | tail -20
```

Expected: build completes with no errors. `assets/seo/index.js` and `assets/seo/index.css` are created.

- [ ] **Step 3: Smoke-test in browser**

1. Navigate to `http://localhost:8080/wp-admin/admin.php?page=wp-ai-mind-seo`
2. If `isPro` is false: lock screen renders.
3. If `isPro` is true: page header + table of posts and pages loads.

- [ ] **Step 4: Commit**

```bash
git add src/seo/seo.css
git commit -m "feat: add SEO page CSS; complete SEO page implementation"
```

---

## Task 8: Images page — ImagesApp and entry point

**Files:**
- Create: `src/images/index.js`
- Create: `src/images/ImagesApp.jsx`

- [ ] **Step 1: Create `src/images/index.js`**

```js
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ImagesApp from './ImagesApp';
import '../styles/tokens.css';
import './images.css';

const { nonce } = window.wpAiMindData ?? {};
if ( nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const root = document.getElementById( 'wp-ai-mind-images' );
if ( root ) {
    render( <ImagesApp />, root );
}
```

- [ ] **Step 2: Create `src/images/ImagesApp.jsx`**

```jsx
import { Lock } from 'lucide-react';
import PostListTable from '../shared/PostListTable';
import ImagesBadge from './ImagesBadge';
import ImagesWorkArea from './ImagesWorkArea';

const { isPro } = window.wpAiMindData ?? {};

const IMAGES_TABS = [
    { id: 'all',       label: 'All',       filter: () => true },
    { id: 'no-image',  label: 'No image',  filter: p => ! p.featured_media },
    { id: 'has-image', label: 'Has image', filter: p => !! p.featured_media },
];

const IMAGES_COLUMNS = [
    {
        label: 'Featured Image',
        width: 180,
        render: post => <ImagesBadge post={ post } />,
    },
];

export default function ImagesApp() {
    if ( ! isPro ) {
        return (
            <div className="wpaim-pro-gate">
                <Lock size={ 32 } />
                <h2>AI image generation requires WP AI Mind Pro</h2>
                <p>
                    Generate beautiful featured images from a text prompt and set them directly
                    on any post or page.
                </p>
                <a href="#" className="button button-primary button-large">
                    Upgrade to Pro →
                </a>
            </div>
        );
    }

    return (
        <div className="wpaim-page">
            <div className="wpaim-page-header">
                <h1>Images <span className="wpaim-pro-badge">PRO</span></h1>
                <p>Generate featured images for your posts and pages with AI.</p>
            </div>
            <PostListTable
                tabs={ IMAGES_TABS }
                WorkArea={ ImagesWorkArea }
                columns={ IMAGES_COLUMNS }
            />
        </div>
    );
}
```

- [ ] **Step 3: Commit**

```bash
git add src/images/index.js src/images/ImagesApp.jsx
git commit -m "feat: add Images page entry point and ImagesApp shell"
```

---

## Task 9: ImagesBadge

**Files:**
- Create: `src/images/ImagesBadge.jsx`

- [ ] **Step 1: Create `src/images/ImagesBadge.jsx`**

```jsx
export default function ImagesBadge( { post } ) {
    const media    = post._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ];
    const thumbUrl = media?.media_details?.sizes?.thumbnail?.source_url
        ?? media?.source_url;

    if ( post.featured_media && thumbUrl ) {
        return (
            <span className="wpaim-image-badge-cell">
                <img
                    src={ thumbUrl }
                    alt=""
                    className="wpaim-list-thumb"
                />
                <span className="wpaim-badge wpaim-badge--has">Has image</span>
            </span>
        );
    }

    return <span className="wpaim-badge wpaim-badge--none">No image</span>;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/images/ImagesBadge.jsx
git commit -m "feat: add ImagesBadge component"
```

---

## Task 10: ImagesWorkArea

**Files:**
- Create: `src/images/ImagesWorkArea.jsx`

- [ ] **Step 1: Create `src/images/ImagesWorkArea.jsx`**

```jsx
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Loader2 } from 'lucide-react';

const { nonce, restUrl, adminUrl = '/wp-admin/' } = window.wpAiMindData ?? {};

const ASPECT_RATIOS = [ '16:9', '1:1', '4:3', '9:16' ];

export default function ImagesWorkArea( { post, onClose, onUpdate } ) {
    const [ prompt, setPrompt ]         = useState( '' );
    const [ aspectRatio, setAspectRatio ] = useState( '16:9' );
    const [ count, setCount ]           = useState( 2 );
    const [ images, setImages ]         = useState( [] );
    const [ selectedId, setSelectedId ] = useState( null );
    const [ generating, setGenerating ] = useState( false );
    const [ setting, setSetting ]       = useState( false );
    const [ error, setError ]           = useState( null );
    const [ warning, setWarning ]       = useState( null );

    const editUrl = `${ adminUrl }post.php?post=${ post.id }&action=edit`;

    const handleGenerate = async () => {
        if ( ! prompt.trim() ) return;
        setGenerating( true );
        setError( null );
        setWarning( null );
        setImages( [] );
        setSelectedId( null );
        try {
            const data = await apiFetch( {
                url:     `${ restUrl }/images/generate`,
                method:  'POST',
                headers: { 'X-WP-Nonce': nonce },
                data:    { prompt, aspect_ratio: aspectRatio, count },
            } );
            setImages( data.images ?? [] );
            if ( data.errors?.length ) {
                const failCount = data.errors.length;
                const okCount   = data.images?.length ?? 0;
                setWarning(
                    `${ failCount } of ${ failCount + okCount } image${ failCount > 1 ? 's' : '' } failed to generate.`
                );
            }
        } catch ( e ) {
            setError( e.message ?? 'Generation failed.' );
        } finally {
            setGenerating( false );
        }
    };

    const handleSetFeatured = async () => {
        if ( ! selectedId ) return;
        setSetting( true );
        setError( null );
        try {
            const endpoint = post.type === 'page'
                ? `/wp/v2/pages/${ post.id }`
                : `/wp/v2/posts/${ post.id }`;
            await apiFetch( {
                path:   endpoint,
                method: 'POST',
                data:   { featured_media: selectedId },
            } );
            const selected = images.find( img => img.attachment_id === selectedId );
            onUpdate( {
                id:             post.id,
                featured_media: selectedId,
                _embedded: {
                    ...post._embedded,
                    'wp:featuredmedia': [ {
                        source_url:    selected?.url ?? '',
                        media_details: {
                            sizes: {
                                thumbnail: {
                                    source_url: selected?.thumbnail_url ?? selected?.url ?? '',
                                },
                            },
                        },
                    } ],
                },
            } );
            onClose();
        } catch ( e ) {
            setError( e.message ?? 'Failed to set featured image.' );
        } finally {
            setSetting( false );
        }
    };

    return (
        <div className="wpaim-work-area">
            <div className="wpaim-work-header">
                <span
                    className="wpaim-work-title"
                    dangerouslySetInnerHTML={ { __html: post.title.rendered } }
                />
            </div>

            <div className="wpaim-images-prompt-row">
                <textarea
                    className="wpaim-prompt-input"
                    placeholder="Describe the image you want to generate…"
                    value={ prompt }
                    onChange={ e => setPrompt( e.target.value ) }
                    rows={ 4 }
                />
                <div className="wpaim-images-controls">
                    <div className="wpaim-control">
                        <label className="wpaim-field-label">Aspect ratio</label>
                        <select
                            className="wpaim-field-input"
                            value={ aspectRatio }
                            onChange={ e => setAspectRatio( e.target.value ) }
                        >
                            { ASPECT_RATIOS.map( r => (
                                <option key={ r } value={ r }>{ r }</option>
                            ) ) }
                        </select>
                    </div>
                    <div className="wpaim-control">
                        <label className="wpaim-field-label">Count</label>
                        <div className="wpaim-count-pills">
                            { [ 1, 2, 3 ].map( n => (
                                <button
                                    key={ n }
                                    className={ `wpaim-pill${ count === n ? ' is-active' : '' }` }
                                    onClick={ () => setCount( n ) }
                                >
                                    { n }
                                </button>
                            ) ) }
                        </div>
                    </div>
                    <button
                        className="button button-primary"
                        onClick={ handleGenerate }
                        disabled={ generating || ! prompt.trim() }
                    >
                        { generating
                            ? <>
                                <Loader2 size={ 12 } className="wpaim-spin" />
                                { ' ' }Generating…
                              </>
                            : '✦ Generate'
                        }
                    </button>
                </div>
            </div>

            { warning && (
                <p className="wpaim-work-warning">
                    ⚠ { warning }
                    <button className="wpaim-dismiss" onClick={ () => setWarning( null ) }>
                        ✕
                    </button>
                </p>
            ) }

            { images.length > 0 && (
                <div className="wpaim-image-grid">
                    { images.map( img => (
                        <div
                            key={ img.attachment_id }
                            className={ `wpaim-image-card${ selectedId === img.attachment_id ? ' is-selected' : '' }` }
                            onClick={ () => setSelectedId( img.attachment_id ) }
                            role="button"
                            tabIndex={ 0 }
                            onKeyDown={ e => e.key === 'Enter' && setSelectedId( img.attachment_id ) }
                        >
                            <img
                                src={ img.url }
                                alt={ prompt }
                                className="wpaim-image-thumb"
                            />
                            { selectedId === img.attachment_id && (
                                <span className="wpaim-selected-badge">✓ Selected</span>
                            ) }
                            <div className="wpaim-image-footer">
                                <a
                                    href={ `${ adminUrl }post.php?post=${ img.attachment_id }&action=edit` }
                                    target="_blank"
                                    rel="noreferrer"
                                    onClick={ e => e.stopPropagation() }
                                >
                                    View →
                                </a>
                            </div>
                        </div>
                    ) ) }
                </div>
            ) }

            { error && <p className="wpaim-work-error">{ error }</p> }

            <div className="wpaim-work-actions">
                <a
                    href={ editUrl }
                    target="_blank"
                    rel="noreferrer"
                    className="wpaim-action-link"
                >
                    Edit post →
                </a>
                <button className="button" onClick={ onClose } disabled={ setting }>
                    Discard
                </button>
                <button
                    className="button button-primary"
                    onClick={ handleSetFeatured }
                    disabled={ setting || ! selectedId }
                >
                    { setting ? 'Setting…' : '✓ Set as featured image' }
                </button>
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add src/images/ImagesWorkArea.jsx
git commit -m "feat: add ImagesWorkArea — generate, pick, and set featured image"
```

---

## Task 11: Images CSS + final build and verification

**Files:**
- Create: `src/images/images.css`

- [ ] **Step 1: Create `src/images/images.css`**

```css
@import '../shared/shared.css';

/* Thumbnail in table cell */
.wpaim-image-badge-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.wpaim-list-thumb {
  width: 36px;
  height: 36px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border);
  flex-shrink: 0;
}

/* Prompt + controls layout */
.wpaim-images-prompt-row {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
  align-items: flex-start;
}

.wpaim-prompt-input {
  flex: 1;
  padding: 8px 10px;
  font-size: 13px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: #fff;
  color: var(--color-text-primary);
  resize: vertical;
  min-height: 88px;
  box-sizing: border-box;
}

.wpaim-prompt-input:focus {
  outline: none;
  border-color: var(--wp-admin-theme-color);
  box-shadow: 0 0 0 1px var(--wp-admin-theme-color);
}

.wpaim-images-controls {
  width: 160px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.wpaim-control {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.wpaim-field-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-secondary);
}

.wpaim-field-input {
  padding: 4px 6px;
  font-size: 13px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: #fff;
  color: var(--color-text-primary);
  width: 100%;
  box-sizing: border-box;
}

/* Count pills */
.wpaim-count-pills {
  display: flex;
  gap: 4px;
}

.wpaim-pill {
  flex: 1;
  padding: 3px 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: #fff;
  color: var(--color-text-secondary);
  font-size: 12px;
  cursor: pointer;
  text-align: center;
}

.wpaim-pill.is-active {
  background: var(--wp-admin-theme-color);
  border-color: var(--wp-admin-theme-color);
  color: #fff;
}

/* Image result grid */
.wpaim-image-grid {
  display: grid;
  grid-template-columns: repeat( 3, 1fr );
  gap: 10px;
  margin: 12px 0;
}

.wpaim-image-card {
  position: relative;
  border: 2px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.wpaim-image-card:hover {
  border-color: var(--wp-admin-theme-color);
}

.wpaim-image-card.is-selected {
  border-color: var(--wp-admin-theme-color);
}

.wpaim-image-thumb {
  display: block;
  width: 100%;
  height: auto;
  object-fit: cover;
}

.wpaim-selected-badge {
  position: absolute;
  top: 6px;
  right: 6px;
  background: var(--wp-admin-theme-color);
  color: #fff;
  border-radius: 99px;
  padding: 1px 8px;
  font-size: 10px;
  font-weight: 700;
}

.wpaim-image-footer {
  background: var(--color-surface);
  padding: 4px 8px;
  font-size: 11px;
  text-align: right;
}
```

- [ ] **Step 2: Run full build**

```bash
npm run build 2>&1 | tail -20
```

Expected: build completes with no errors. `assets/images/index.js` and `assets/images/index.css` are created.

- [ ] **Step 3: Run PHP tests one final time**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

Expected: all green.

- [ ] **Step 4: Manual end-to-end verification (Pro user)**

Use Docker local environment at `http://localhost:8080`.

**SEO page** (`/wp-admin/admin.php?page=wp-ai-mind-seo`):
- [ ] List of posts and pages loads with Complete/Partial/Missing badges
- [ ] "Missing" tab shows only posts with no SEO fields
- [ ] Search input filters by post title
- [ ] Clicking "Generate ▼" expands the work area; clicking again collapses it
- [ ] Only one row is open at a time (opening a second closes the first)
- [ ] "✦ Generate SEO" → spinner appears → fields populate with blue border highlight
- [ ] Editing a field updates the character count
- [ ] "✓ Apply all" → row closes → badge in list updates
- [ ] "Discard" → row closes → no changes saved
- [ ] "Edit post →" opens Gutenberg in a new tab

**Images page** (`/wp-admin/admin.php?page=wp-ai-mind-images`):
- [ ] List loads; posts with featured images show 36×36px thumbnails + "Has image" badge
- [ ] "No image" tab filters correctly
- [ ] Prompt textarea + aspect ratio + count controls render
- [ ] Count pill buttons toggle correctly (only one active)
- [ ] "✦ Generate" disabled when prompt is empty
- [ ] After generation: image grid appears; clicking a card selects it (blue border + "✓ Selected")
- [ ] "✓ Set as featured image" disabled until an image is selected
- [ ] After setting: row closes; table cell updates to show new thumbnail + "Has image" badge
- [ ] "View →" link opens WP media library in new tab
- [ ] HTTP 207 partial success: warning banner shown, successful images still displayed

**Non-Pro** (temporarily set `isPro` to false in localized data or test with a non-Pro account):
- [ ] SEO page shows lock screen, list not rendered
- [ ] Images page shows lock screen, list not rendered

- [ ] **Step 5: Commit**

```bash
git add src/images/images.css
git commit -m "feat: add Images page CSS; complete Images page implementation"
```

---

## Done

Commit the plan document to the repo:

```bash
cp /Users/niklas/.claude/plans/snuggly-marinating-lighthouse.md \
   /Users/niklas/Documents/Homepages/wp-ai-mind/docs/superpowers/plans/2026-03-27-seo-images-admin-pages.md
cd /Users/niklas/Documents/Homepages/wp-ai-mind
git add docs/superpowers/plans/2026-03-27-seo-images-admin-pages.md
git commit -m "docs: add implementation plan for SEO and Images admin pages"
```
