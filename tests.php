<?php
/**
 * Writer Test Suite
 * Run: php tests.php
 * Spins up its own test server on port 8091 using test.sqlite
 * (never touches writer.sqlite).
 *
 * Unit groups run in-process: WRITER_TESTING is defined before
 * index.php is required, so its bootstrap returns before
 * initDatabase()/session/dispatch.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('WRITER_TESTING', true);

$TEST_PORT = 8091;
$TEST_DB = __DIR__ . '/test.sqlite';
$BASE = "http://localhost:$TEST_PORT";
$passed = 0;
$failed = 0;
$skipped = 0;

// Clean previous test DB
@unlink($TEST_DB);
@unlink($TEST_DB . '-wal');
@unlink($TEST_DB . '-shm');
putenv("WRITER_DB_PATH=$TEST_DB");
$_ENV['WRITER_DB_PATH'] = $TEST_DB;

require __DIR__ . '/index.php';

// Start a dedicated test server (HTTP groups: full bootstrap)
$serverCmd = sprintf(
    'WRITER_DB_PATH=%s php -S localhost:%d -t %s %s/index.php',
    escapeshellarg($TEST_DB),
    $TEST_PORT,
    escapeshellarg(__DIR__),
    escapeshellarg(__DIR__)
);
$serverProc = proc_open($serverCmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!$serverProc) {
    echo "Failed to start test server.\n";
    exit(1);
}
register_shutdown_function(function () use ($serverProc, $TEST_DB) {
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($TEST_DB);
    @unlink($TEST_DB . '-wal');
    @unlink($TEST_DB . '-shm');
});
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('localhost', $TEST_PORT, $errno, $errstr, 0.1);
    if ($sock) {
        fclose($sock);
        $ready = true;
        break;
    }
    usleep(100_000);
}
if (!$ready) {
    echo "Test server failed to start on port $TEST_PORT.\n";
    exit(1);
}

// ─── HARNESS ───────────────────────────────────────────────

function colorGreen(string $s): string
{
    return "\033[32m" . $s . "\033[0m";
}

function colorRed(string $s): string
{
    return "\033[31m" . $s . "\033[0m";
}

function colorYellow(string $s): string
{
    return "\033[33m" . $s . "\033[0m";
}

function group(string $name): void
{
    echo "\n### {$name} ###\n";
}

function pass(string $msg): void
{
    global $passed;
    $passed++;
    echo '  ' . colorGreen('PASS') . ' ' . $msg . "\n";
}

function fail(string $msg, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo '  ' . colorRed('FAIL') . ' ' . $msg . "\n";
    if ($detail !== '') {
        echo '    ' . colorRed($detail) . "\n";
    }
}

function skip(string $msg): void
{
    global $skipped;
    $skipped++;
    echo '  ' . colorYellow('SKIP') . ' ' . $msg . "\n";
}

function assertSame($expected, $actual, string $msg): void
{
    if ($expected === $actual) {
        pass($msg);
    } else {
        fail($msg, 'expected: ' . var_export($expected, true) . "\n    actual:   " . var_export($actual, true));
    }
}

function assertTrue($cond, string $msg): void
{
    if ($cond) {
        pass($msg);
    } else {
        fail($msg, 'expected: true' . "\n    actual:   false");
    }
}

function assertContains(string $needle, string $haystack, string $msg): void
{
    if (str_contains($haystack, $needle)) {
        pass($msg);
    } else {
        fail($msg, 'missing substring: ' . var_export($needle, true));
    }
}

function assertNotContains(string $needle, string $haystack, string $msg): void
{
    if (!str_contains($haystack, $needle)) {
        pass($msg);
    } else {
        fail($msg, 'unexpected substring: ' . var_export($needle, true));
    }
}

/**
 * HTTP request against the test server.
 * opts: json (array), form (array), headers (string[]), cookie (jar path)
 * returns: status, headers (assoc, lowercase), body, json (decoded or null)
 */
function req(string $method, string $path, array $opts = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = [];
    foreach ($opts['headers'] ?? [] as $k => $v) {
        $headers[] = is_int($k) ? $v : "$k: $v";
    }
    $payload = null;

    if (isset($opts['json'])) {
        $payload = json_encode($opts['json']);
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opts['raw'])) {
        $payload = $opts['raw'];
    } elseif (isset($opts['form'])) {
        $payload = http_build_query($opts['form']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (!empty($opts['cookie'])) {
        $curlOpts[CURLOPT_COOKIEFILE] = $opts['cookie'];
        $curlOpts[CURLOPT_COOKIEJAR] = $opts['cookie'];
    }
    if ($payload !== null) {
        $curlOpts[CURLOPT_POSTFIELDS] = $payload;
    }
    if ($headers) {
        $curlOpts[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $curlOpts);

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $headersAssoc = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headersAssoc[strtolower(trim($k))] = trim($v);
        }
    }

    return [
        'status' => $status,
        'headers' => $headersAssoc,
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

function newSession(): string
{
    return tempnam(sys_get_temp_dir(), 'writer_test_');
}

/** Extract the _csrf hidden input value from an HTML page. */
function csrfFrom(string $html): string
{
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value="([^"]+)"\s+name="_csrf"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

/** Log in over the web form; returns the cookie jar, CSRF token, and responses. */
function webLogin(string $password, string $user = 'admin', string $ip = ''): array
{
    $jar = newSession();
    $headers = $ip !== '' ? ['X-Real-IP' => $ip] : [];
    $get = req('GET', '/login', ['cookie' => $jar, 'headers' => $headers]);
    $csrf = csrfFrom($get['body']);
    $post = req('POST', '/login', [
        'cookie' => $jar,
        'headers' => $headers,
        'form' => ['_csrf' => $csrf, 'username' => $user, 'password' => $password],
    ]);
    return ['jar' => $jar, 'csrf' => $csrf, 'get' => $get, 'post' => $post];
}

function summary(): never
{
    global $passed, $failed, $skipped;
    $bar = str_repeat('=', 80);
    $total = $passed + $failed + $skipped;
    echo "\n{$bar}\n";
    echo colorGreen("{$passed} PASSED.") . ' ' . ($failed > 0 ? colorRed("{$failed} FAILED.") : "{$failed} FAILED.") . ' ' . colorYellow("{$skipped} SKIP.") . "\n";
    echo "TOTAL: {$total} TEST CASES.\n";
    echo "{$bar}\n";
    exit($failed > 0 ? 1 : 0);
}

echo "Writer Test Suite\n";
echo str_repeat('-', 40) . "\n";

// ─── HEALTH ────────────────────────────────────────────────
group('Health Check');

$r = req('GET', '/api/health');
assertSame(200, $r['status'], 'GET /api/health returns 200');
assertSame('ok', $r['json']['status'] ?? null, 'health status is "ok"');
assertTrue(
    is_string($r['json']['timestamp'] ?? null)
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $r['json']['timestamp']) === 1,
    'health timestamp is ISO 8601'
);
assertSame('application/json', $r['headers']['content-type'] ?? null, 'health returns JSON content type');

// ─── SENSITIVE FILES ───────────────────────────────────────
group('Sensitive Files');

$bakBackup = is_file(__DIR__ . '/writer.sqlite.bak') ? file_get_contents(__DIR__ . '/writer.sqlite.bak') : null;
file_put_contents(__DIR__ . '/writer.sqlite.bak', 'x');
$r = req('GET', '/writer.sqlite.bak');
assertSame(404, $r['status'], '.sqlite.bak is not served');
if ($bakBackup === null) {
    unlink(__DIR__ . '/writer.sqlite.bak');
} else {
    file_put_contents(__DIR__ . '/writer.sqlite.bak', $bakBackup);
}

file_put_contents(__DIR__ . '/guard.sqlite-wal', 'x');
$r = req('GET', '/guard.sqlite-wal');
assertSame(404, $r['status'], 'a sqlite WAL sidecar is not served');
unlink(__DIR__ . '/guard.sqlite-wal');

file_put_contents(__DIR__ . '/guard.sqlite-shm', 'x');
$r = req('GET', '/guard.sqlite-shm');
assertSame(404, $r['status'], 'a sqlite SHM sidecar is not served');
unlink(__DIR__ . '/guard.sqlite-shm');

$r = req('GET', '/tests.php');
assertSame(404, $r['status'], 'tests.php is not served');

$importBackup = is_file(__DIR__ . '/import.php') ? file_get_contents(__DIR__ . '/import.php') : null;
file_put_contents(__DIR__ . '/import.php', '<?php // temp');
$r = req('GET', '/import.php');
assertSame(404, $r['status'], 'import.php is not served');
if ($importBackup === null) {
    unlink(__DIR__ . '/import.php');
} else {
    file_put_contents(__DIR__ . '/import.php', $importBackup);
}

$envBackup = is_file(__DIR__ . '/.env') ? file_get_contents(__DIR__ . '/.env') : null;
file_put_contents(__DIR__ . '/.env', 'SESSION_KEY=x');
$r = req('GET', '/.env');
assertSame(404, $r['status'], '.env is not served');
if ($envBackup === null) {
    unlink(__DIR__ . '/.env');
} else {
    file_put_contents(__DIR__ . '/.env', $envBackup);
}

$r = req('GET', '/storage/data/posts.json');
assertSame(404, $r['status'], 'storage/data is not served');

// ─── SCHEMA MIGRATIONS ─────────────────────────────────────
group('Schema Migrations');

initDatabase();
initDatabase(); // idempotent

$db = getDb();
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach (['confirmations', 'gist_cache', 'login_attempts', 'posts', 'posts_fts', 'settings'] as $t) {
    assertTrue(in_array($t, $tables, true), "table {$t} exists");
}
assertSame(2, (int) $db->query('PRAGMA user_version')->fetchColumn(), 'user_version is 2');

$triggers = $db->query("SELECT name FROM sqlite_master WHERE type='trigger'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['posts_ai', 'posts_ad', 'posts_au'] as $t) {
    assertTrue(in_array($t, $triggers, true), "trigger {$t} exists");
}

$cols = $db->query("PRAGMA table_info(login_attempts)")->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (['bucket', 'ip', 'attempted_at', 'success'] as $c) {
    assertTrue(in_array($c, $cols, true), "login_attempts.$c column exists");
}

$cols = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (['id', 'title', 'author', 'description', 'username', 'password_hash', 'recovery_key_hash', 'auth_token', 'about', 'custom_scripts', 'csp_script_domains', 'onboarding_complete', 'show_docs', 'created_at', 'updated_at'] as $c) {
    assertTrue(in_array($c, $cols, true), "settings.$c column exists");
}

$cols = $db->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (['id', 'title', 'slug', 'body', 'excerpt', 'cover_image', 'keywords', 'meta_description', 'reading_time', 'status', 'tags', 'created_at', 'updated_at', 'published_at'] as $c) {
    assertTrue(in_array($c, $cols, true), "posts.$c column exists");
}

$uniq = $db->query("SELECT sql FROM sqlite_master WHERE name='posts'")->fetchColumn();
assertContains('UNIQUE', $uniq, 'posts.slug has a UNIQUE constraint');

// ─── FTS INDEX ────────────────────────────────────────────
group('FTS Index');

$db->exec("INSERT INTO posts (id, title, slug, body, excerpt, keywords, meta_description, reading_time, status, tags, created_at, updated_at, published_at)
           VALUES ('fts-1', 'Hello World', 'hello-world', 'The quick brown fox', 'The quick brown fox', 'fox, brown', '', 1, 'published', '[\"alpha\"]', '2026-01-01', '2026-01-01', '2026-01-01')");
$db->exec("INSERT INTO posts (id, title, slug, body, excerpt, keywords, meta_description, reading_time, status, tags, created_at, updated_at, published_at)
           VALUES ('fts-2', 'Second Post', 'second-post', 'Something else entirely', '', '', '', 1, 'draft', '[]', '2026-01-02', '2026-01-02', '2026-01-02')");
$db->exec("INSERT INTO posts (id, title, slug, body, excerpt, keywords, meta_description, reading_time, status, tags, created_at, updated_at, published_at)
           VALUES ('fts-3', 'Third Post', 'third-post', 'More content here', '', '', '', 1, 'draft', '[]', '2026-01-03', '2026-01-03', '2026-01-03')");

$rows = $db->query("SELECT p.id FROM posts p JOIN posts_fts f ON f.rowid = p.rowid WHERE posts_fts MATCH 'ello'")->fetchAll(PDO::FETCH_COLUMN);
assertSame(['fts-1'], $rows, 'trigram match finds substring "ello" in "Hello World"');

$rows = $db->query("SELECT p.id FROM posts p JOIN posts_fts f ON f.rowid = p.rowid WHERE posts_fts MATCH 'quick'")->fetchAll(PDO::FETCH_COLUMN);
assertSame(['fts-1'], $rows, 'body term is indexed');

$db->exec("UPDATE posts SET body = 'Nothing matches now', excerpt = 'Nothing matches now' WHERE id = 'fts-1'");
$rows = $db->query("SELECT p.id FROM posts p JOIN posts_fts f ON f.rowid = p.rowid WHERE posts_fts MATCH 'quick'")->fetchAll(PDO::FETCH_COLUMN);
assertSame([], $rows, 'UPDATE removes old text from index');
$rows = $db->query("SELECT p.id FROM posts p JOIN posts_fts f ON f.rowid = p.rowid WHERE posts_fts MATCH 'nothing'")->fetchAll(PDO::FETCH_COLUMN);
assertSame(['fts-1'], $rows, 'UPDATE indexes new text');

$db->exec("DELETE FROM posts WHERE id = 'fts-1'");
$rows = $db->query("SELECT p.id FROM posts p JOIN posts_fts f ON f.rowid = p.rowid WHERE posts_fts MATCH 'nothing'")->fetchAll(PDO::FETCH_COLUMN);
assertSame([], $rows, 'DELETE removes row from index');
$count = (int) $db->query("SELECT count(*) FROM posts_fts")->fetchColumn();
assertSame(2, $count, 'index holds only remaining rows');

$db->exec("DELETE FROM posts");

// ─── MARKDOWN RENDER ──────────────────────────────────────
group('Markdown Render');

$mdCases = [
    'heading renders h1' => ['# Hello World', "<h1>Hello World</h1>\n"],
    'heading renders h2' => ['## Sub Heading', "<h2>Sub Heading</h2>\n"],
    'horizontal rule' => ['---', "<hr>\n"],
    'paragraph' => ['Just a paragraph.', "<p>Just a paragraph.</p>\n"],
    'bold' => ['This is **bold** text.', "<p>This is <strong>bold</strong> text.</p>\n"],
    'italic' => ['This is *italic* text.', "<p>This is <em>italic</em> text.</p>\n"],
    'nested strong/em' => ['**a *b* c**', "<p><strong>a <em>b</em> c</strong></p>\n"],
    'two strongs' => ['**a** **b**', "<p><strong>a</strong> <strong>b</strong></p>\n"],
    'inline code' => ['Use `print()` here.', "<p>Use <code>print()</code> here.</p>\n"],
    'link' => ['[Example](https://example.com)', "<p><a href=\"https://example.com\">Example</a></p>\n"],
    'link with title' => ['[Example](https://example.com "T")', "<p><a href=\"https://example.com\" title=\"T\">Example</a></p>\n"],
    'autolink' => ['<https://example.com>', "<p><a href=\"https://example.com\">https://example.com</a></p>\n"],
    'image' => ['![Alt text](https://img.example/x.png)', "<p><img src=\"https://img.example/x.png\" alt=\"Alt text\"></p>\n"],
    'strikethrough' => ['~~coded~~', "<p><del>coded</del></p>\n"],
    'hard break' => ["line one  \nline two", "<p>line one<br>line two</p>\n"],
    'escaped asterisks' => ['\\*not em\\*', "<p>*not em*</p>\n"],
    'entity preserved' => ['a &amp; b', "<p>a &amp; b</p>\n"],
    'unordered list' => ["- one\n- two", "<ul>\n<li>one</li>\n<li>two</li>\n</ul>\n"],
    'ordered list' => ["1. one\n2. two", "<ol>\n<li>one</li>\n<li>two</li>\n</ol>\n"],
    'blockquote' => ['> quoted line', "<blockquote>\n<p>quoted line</p>\n</blockquote>\n"],
    'fenced code' => ["```php\necho 1;\n```", "<pre><code>echo 1;\n</code></pre>\n"],
    'gfm table' => [
        "| A | B |\n| --- | --- |\n| 1 | 2 |",
        "<table>\n<thead>\n<tr>\n<th>A</th>\n<th>B</th>\n</tr>\n</thead>\n<tbody><tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody></table>\n",
    ],
];
foreach ($mdCases as $name => [$input, $expected]) {
    assertSame($expected, renderBodyHtml($input), $name);
}

// ─── SANITIZER ────────────────────────────────────────────
group('Sanitizer');

assertSame('', renderBodyHtml('<script>alert(1)</script>'), 'a non-gist script is removed');
assertSame('', renderBodyHtml('<script>alert(1)</script >'), 'a script with a spaced closer is removed');
assertSame('', renderBodyHtml("<script>alert(1)</script\t>"), 'a script with a tabbed closer is removed');
assertSame('', renderBodyHtml('<script>alert(1)'), 'an unterminated script is removed');
assertSame('', renderBodyHtml('<script type="text/javascript">alert(1)</script>'), 'a typed script is removed');
assertNotContains('<script', renderBodyHtml('a <script>bad()</script> b'), 'inline script content never survives');
assertSame('<div>hi</div>', renderBodyHtml('<div>hi</div>'), 'an allowed block tag is kept');
$gistTag = '<script src="https://gist.github.com/user/abc123.js"></script>';
assertSame($gistTag, renderBodyHtml($gistTag), 'a gist script survives sanitizing');
assertNotContains('onerror', renderBodyHtml('<img src="x.png" onerror="alert(1)">'), 'an onerror handler is stripped');
assertNotContains('onclick', renderBodyHtml('<a href="https://ok.example" onclick="alert(1)">go</a>'), 'an onclick handler is stripped');
assertNotContains('javascript:', renderBodyHtml('[x](javascript:alert(1))'), 'a javascript: URL is neutralized');
assertNotContains('<iframe', renderBodyHtml('<iframe src="https://evil.example"></iframe>'), 'an iframe is neutralized');
assertNotContains('<style>', renderBodyHtml('<style>body{color:red}</style>'), 'a style tag is neutralized');

// ─── METADATA ─────────────────────────────────────────────
group('Metadata');

assertSame('hello-world', generateSlug('Hello World'), 'generateSlug lowercases title');
assertSame('test-post', generateSlug('Test @# Post!'), 'generateSlug removes special characters');
assertSame('multiple-spaces', generateSlug('Multiple   Spaces'), 'generateSlug handles multiple spaces');
assertSame('', generateSlug(''), 'generateSlug handles empty string');

assertSame('hello', generateUniqueSlug([['slug' => 'other']], 'hello'), 'uniqueSlug returns base when unused');
assertSame('hello-1', generateUniqueSlug([['slug' => 'hello']], 'hello'), 'uniqueSlug appends -1 when taken');
assertSame('hello-3', generateUniqueSlug([['slug' => 'hello'], ['slug' => 'hello-1'], ['slug' => 'hello-2']], 'hello'), 'uniqueSlug finds next number');
assertSame('hello', generateUniqueSlug([['id' => '1', 'slug' => 'hello'], ['id' => '2', 'slug' => 'hello-1']], 'hello', '1'), 'uniqueSlug excludes id');

$kw = extractKeywords('JavaScript Tutorial', 'Learn JavaScript programming with examples and tutorials');
assertContains('javascript', $kw, 'extractKeywords finds javascript');
assertContains('tutorial', $kw, 'extractKeywords finds tutorial');
assertTrue(!str_contains(extractKeywords('The Test', 'the a an and but'), 'the'), 'extractKeywords filters stop words');
assertSame('', extractKeywords('', ''), 'extractKeywords returns empty for empty input');

$longDesc = generateMetaDescription(str_repeat('A', 200));
assertTrue(mb_strlen($longDesc) <= 160, 'generateMetaDescription truncates to 160');
assertTrue(str_ends_with($longDesc, '...'), 'generateMetaDescription ends with ellipsis');
assertSame('Short', generateMetaDescription('Short'), 'generateMetaDescription keeps short text');

assertSame(1, calculateReadingTime(str_repeat('word ', 200)), 'readingTime: 200 words is 1 minute');
assertSame(2, calculateReadingTime(str_repeat('word ', 201)), 'readingTime rounds up');
assertSame(1, calculateReadingTime('short'), 'readingTime minimum is 1 minute');

assertTrue(mb_strlen(generateExcerpt(str_repeat('A', 300), 200)) <= 200, 'generateExcerpt truncates to max length');
$md = generateExcerpt("# Header\n![alt](img.jpg)\n[link](url.com)\n**bold** and ~strike~");
assertTrue(!str_contains($md, '[') && !str_contains($md, '!') && !str_contains($md, '*') && !str_contains($md, '~'), 'generateExcerpt removes markdown syntax');
assertSame('This is  text with  content', generateExcerpt('This is ~removed~ text with ~~also removed~~ content'), 'generateExcerpt removes strikethrough content');
$scr = generateExcerpt('Some text <script src="gist.js">alert(1)</script> more text');
assertTrue(!str_contains($scr, 'gist') && !str_contains($scr, 'alert') && !str_contains($scr, '<script'), 'generateExcerpt removes script tags and content');
assertSame('Some text  more text', trim($scr), 'generateExcerpt leaves surrounding text');

$meta = inferMetadata('Test Title', 'This is the body content');
assertTrue(
    isset($meta['slug'], $meta['keywords'], $meta['meta_description'], $meta['reading_time'], $meta['excerpt']),
    'inferMetadata returns all metadata fields'
);
assertSame('my-test-post', inferMetadata('My Test Post', '')['slug'], 'inferMetadata generates slug from title');

// ─── API: DOCS + SECURITY HEADERS ─────────────────────────
group('API Docs & Headers');

$r = req('GET', '/api');
assertSame(200, $r['status'], 'GET /api returns 200');
assertSame('Writer API', $r['json']['name'] ?? null, 'GET /api returns API docs');
assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, 'GET /api sends X-Content-Type-Options');
assertSame('DENY', $r['headers']['x-frame-options'] ?? null, 'GET /api sends X-Frame-Options');
assertTrue(isset($r['headers']['content-security-policy']), 'GET /api sends CSP');
assertTrue(isset($r['headers']['strict-transport-security']), 'GET /api sends HSTS');

$r = req('GET', '/');
assertSame(302, $r['status'], 'an unconfigured blog redirects the home page');
assertSame('/onboarding', $r['headers']['location'] ?? '', 'the home page redirects to /onboarding');
assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, 'GET / sends X-Content-Type-Options');
assertSame('DENY', $r['headers']['x-frame-options'] ?? null, 'GET / sends X-Frame-Options');
assertTrue(isset($r['headers']['content-security-policy']), 'GET / sends CSP');
assertTrue(isset($r['headers']['strict-transport-security']), 'GET / sends HSTS');

$r = req('GET', '/search');
assertSame('/onboarding', $r['headers']['location'] ?? '', 'search redirects to onboarding before setup');
$r = req('GET', '/docs');
assertSame('/onboarding', $r['headers']['location'] ?? '', 'docs redirects to onboarding before setup');
$r = req('GET', '/post/anything');
assertSame('/onboarding', $r['headers']['location'] ?? '', 'a post page redirects to onboarding before setup');

$r = req('GET', '/api/health');
assertSame(200, $r['status'], 'the health check is not redirected before setup');
$r = req('GET', '/feed.xml');
assertSame(200, $r['status'], 'the feed is not redirected before setup');

$r = req('GET', '/api/settings');
assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, 'GET /api/settings sends security headers');

// ─── API: ONBOARDING + SETTINGS ───────────────────────────
group('Onboarding & Settings');

$r = req('POST', '/api/onboarding', ['json' => ['title' => 'Test Blog', 'author' => 'Test Author']]);
assertSame(201, $r['status'], 'POST /api/onboarding returns 201');
$token = $r['json']['auth_token'] ?? '';
assertTrue(is_string($token) && strlen($token) === 64, 'onboarding returns a 64-char auth token');
assertSame(true, $r['json']['onboarding_complete'] ?? null, 'onboarding_complete is true');

$r = req('GET', '/api/settings');
assertSame(200, $r['status'], 'GET /api/settings returns 200');
assertTrue(array_key_exists('onboarding_complete', $r['json'] ?? []), 'settings exposes onboarding_complete');
assertTrue(!array_key_exists('auth_token', $r['json'] ?? []), 'GET /api/settings does not leak auth_token');
assertTrue(!array_key_exists('username', $r['json'] ?? []), 'GET /api/settings does not leak username');

$r = req('POST', '/api/onboarding', ['json' => ['title' => 'Again']]);
assertSame(400, $r['status'], 'second onboarding returns 400 conflict');
assertContains('already configured', $r['json']['message'] ?? '', 'conflict message is clear');

// ─── API: AUTH ────────────────────────────────────────────
group('API Auth');

$r = req('POST', '/api/posts', ['json' => ['title' => 'X', 'body' => 'Y']]);
assertSame(401, $r['status'], 'POST /api/posts without token returns 401');
assertContains('X-Auth-Token', $r['json']['message'] ?? '', '401 explains the required header');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => 'wrong'], 'json' => ['title' => 'X', 'body' => 'Y']]);
assertSame(401, $r['status'], 'POST /api/posts with wrong token returns 401');
assertContains('Invalid auth token', $r['json']['message'] ?? '', '401 message is "Invalid auth token"');

// ─── API: POSTS CRUD ──────────────────────────────────────
group('API Posts CRUD');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Test Post', 'body' => 'Test body']]);
assertSame(201, $r['status'], 'POST /api/posts returns 201');
assertSame('test-post', $r['json']['slug'] ?? null, 'created post slug is inferred from title');
assertSame('draft', $r['json']['status'] ?? null, 'created post defaults to draft');
assertSame(1, $r['json']['reading_time'] ?? null, 'created post reading_time is computed');
assertSame('Test body', $r['json']['excerpt'] ?? null, 'created post excerpt is generated');
assertSame([], $r['json']['tags'] ?? null, 'created post tags default to empty array');
assertTrue(array_key_exists('bodyHtml', $r['json'] ?? []), 'created post includes bodyHtml');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Published Post', 'body' => 'Published body', 'status' => 'published', 'tags' => ['a', 'b']]]);
assertSame(201, $r['status'], 'create published post returns 201');
assertSame('published', $r['json']['status'] ?? null, 'published status is stored');
assertSame(['a', 'b'], $r['json']['tags'] ?? null, 'tags are stored and returned');

$r = req('GET', '/api/posts');
assertSame(200, $r['status'], 'GET /api/posts returns 200');
$statuses = array_column($r['json']['posts'] ?? [], 'status');
assertTrue($statuses !== [] && array_unique($statuses) === ['published'], 'GET /api/posts returns only published posts');
assertTrue(isset($r['json']['pagination']['total'], $r['json']['pagination']['pages']), 'listing includes pagination meta');

$r = req('GET', '/api/posts/published-post');
assertSame(200, $r['status'], 'GET published post by slug returns 200');

$r = req('GET', '/api/posts/test-post');
assertSame(404, $r['status'], 'GET draft post unauthenticated returns 404');

$r = req('GET', '/api/posts/test-post', ['headers' => ['X-Auth-Token' => $token]]);
assertSame(200, $r['status'], 'GET draft post authenticated returns 200');

$r = req('GET', '/api/posts/nonexistent');
assertSame(404, $r['status'], 'GET missing post returns 404');

// ─── API: POST VALIDATION ─────────────────────────────────
group('API Post Validation');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Test', 'body' => true]]);
assertSame(400, $r['status'], 'POST non-string body returns 400');
assertContains('Body is required', $r['json']['message'] ?? '', 'body error message is specific');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Validation Test', 'body' => 'Original body']]);
$vslug = $r['json']['slug'] ?? '';

$r = req('PUT', "/api/posts/$vslug", ['headers' => ['X-Auth-Token' => $token], 'json' => ['body' => []]]);
assertSame(400, $r['status'], 'PUT non-string body returns 400');
assertContains('Body must be a string', $r['json']['message'] ?? '', 'PUT body error message is specific');

$r = req('PUT', "/api/posts/$vslug", ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => []]]);
assertSame(400, $r['status'], 'PUT non-string title returns 400');
assertContains('Title must be a string', $r['json']['message'] ?? '', 'PUT title error message is specific');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token, 'Content-Type' => 'application/json'], 'raw' => 'not valid json']);
assertSame(400, $r['status'], 'POST malformed JSON returns 400');

$r = req('PUT', "/api/posts/$vslug", ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Updated Title']]);
assertSame(200, $r['status'], 'PUT with new title returns 200');
assertSame('Original body', $r['json']['body'] ?? null, 'PUT preserves body when omitted');
assertSame('validation-test', $r['json']['slug'] ?? null, 'PUT without body does not change slug');

// ─── API: CONFIRMATION FLOW ───────────────────────────────
group('Confirmation Flow');

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Delete Me', 'body' => 'bye']]);
$delSlug = $r['json']['slug'] ?? '';

$r = req('DELETE', "/api/posts/$delSlug", ['headers' => ['X-Auth-Token' => $token]]);
assertSame(202, $r['status'], 'DELETE requires confirmation (202)');
assertSame(true, $r['json']['confirmation_required'] ?? null, 'delete response requires confirmation');
$delUrl = $r['json']['confirmation_url'] ?? '';
assertTrue(str_starts_with($delUrl, '/api/confirm/'), 'delete returns a confirmation_url');

$r = req('POST', $delUrl, ['headers' => ['X-Auth-Token' => $token]]);
assertSame(200, $r['status'], 'confirming delete returns 200');
assertSame('Post deleted successfully', $r['json']['message'] ?? null, 'delete confirmation message matches');
assertSame($delSlug, $r['json']['slug'] ?? null, 'delete confirmation reports the slug');

$r = req('GET', "/api/posts/$delSlug", ['headers' => ['X-Auth-Token' => $token]]);
assertSame(404, $r['status'], 'confirmed delete removed the post');

$r = req('POST', '/api/settings/rotate-token', ['headers' => ['X-Auth-Token' => $token]]);
assertSame(202, $r['status'], 'rotate-token requires confirmation (202)');
$rotUrl = $r['json']['confirmation_url'] ?? '';

$r = req('POST', $rotUrl, ['headers' => ['X-Auth-Token' => $token]]);
assertSame(200, $r['status'], 'confirming rotate returns 200');
assertSame('Auth token rotated successfully', $r['json']['message'] ?? null, 'rotate confirmation message matches');
$newToken = $r['json']['auth_token'] ?? '';
assertTrue(is_string($newToken) && strlen($newToken) === 64, 'rotate returns a new token');
assertTrue($newToken !== $token, 'rotated token differs from the old one');
$token = $newToken;

$r = req('POST', '/api/confirm/invalidtoken123', ['headers' => ['X-Auth-Token' => $token]]);
assertSame(410, $r['status'], 'invalid confirmation token returns 410');

$r = req('GET', '/api/posts/test-post', ['headers' => ['X-Auth-Token' => $token]]);
assertSame(200, $r['status'], 'new token authenticates after rotation');

// ─── PASSWORD VERIFY ──────────────────────────────────────
group('Password Verify');

$shaHash = hash('sha256', 'hunter2');
assertTrue(verifyPasswordSecret('hunter2', $shaHash), 'legacy sha256 hash verifies');
assertTrue(!verifyPasswordSecret('nope', $shaHash), 'legacy sha256 rejects a wrong password');

$salt = bin2hex(random_bytes(16));
$key = bin2hex(sodium_crypto_pwhash_scryptsalsa208sha256(64, 'scryptpw', $salt, 524288, 16777216));
$scryptHash = '$scrypt$' . $salt . '$' . $key;
assertTrue(verifyPasswordSecret('scryptpw', $scryptHash), '$scrypt$ hash verifies (sodium)');
assertTrue(!verifyPasswordSecret('nope', $scryptHash), '$scrypt$ rejects a wrong password');
assertTrue(!verifyPasswordSecret('x', '$scrypt$bad'), 'malformed $scrypt$ hash is rejected');

$bcryptHash = hashPasswordSecret('bcryptpw');
assertTrue(str_starts_with($bcryptHash, '$2y$'), 'new hashes are bcrypt');
assertTrue(verifyPasswordSecret('bcryptpw', $bcryptHash), 'bcrypt hash verifies');
assertTrue(!verifyPasswordSecret('nope', $bcryptHash), 'bcrypt rejects a wrong password');
assertTrue(!verifyPasswordSecret('x', ''), 'empty stored hash rejects');

assertSame(false, passwordNeedsRehash($bcryptHash), 'bcrypt does not need rehash');
assertSame(true, passwordNeedsRehash($shaHash), 'sha256 needs rehash');
assertSame(true, passwordNeedsRehash($scryptHash), '$scrypt$ needs rehash');

// ─── RECOVERY KEY ─────────────────────────────────────────
group('Recovery Key');

$recovery = generateRecoveryKey();
assertSame(32, strlen($recovery), 'recovery key is 32 hex characters');
$recoveryHash = hashRecoveryKey($recovery);
assertSame(64, strlen($recoveryHash), 'recovery hash is a sha256 hex digest');
assertTrue(verifyRecoveryKey($recovery, $recoveryHash), 'recovery key verifies against its hash');
assertTrue(!verifyRecoveryKey('deadbeef', $recoveryHash), 'wrong recovery key is rejected');
assertTrue(!verifyRecoveryKey($recovery, ''), 'empty stored recovery hash rejects');

// ─── SETTINGS API ─────────────────────────────────────────
group('Settings API');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Renamed Blog', 'about' => 'About text', 'description' => 'Desc', 'show_docs' => false]]);
assertSame(200, $r['status'], 'PUT /api/settings returns 200');
assertSame('Renamed Blog', $r['json']['title'] ?? null, 'PUT returns the updated title');
assertTrue(array_key_exists('auth_token', $r['json'] ?? []), 'PUT /api/settings returns auth_token (parity)');

$r = req('GET', '/api/settings');
assertSame('Renamed Blog', $r['json']['title'] ?? null, 'title persisted');
assertSame('About text', $r['json']['about'] ?? null, 'about persisted');
assertSame('Desc', $r['json']['description'] ?? null, 'description persisted');
assertSame(false, $r['json']['show_docs'] ?? null, 'show_docs persisted as a boolean');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => 'wrong'], 'json' => ['title' => 'X']]);
assertSame(401, $r['status'], 'PUT /api/settings rejects a bad token');

// ─── CREDENTIALS API ──────────────────────────────────────
group('Credentials API');

$r = req('PUT', '/api/settings/credentials', ['headers' => ['X-Auth-Token' => $token], 'json' => ['username' => 'admin', 'password' => 's3cret-pw']]);
assertSame(200, $r['status'], 'PUT /api/settings/credentials returns 200');
assertSame('admin', $r['json']['username'] ?? null, 'credentials returns the username');
assertSame(true, $r['json']['hasPassword'] ?? null, 'credentials reports hasPassword');
assertSame('Credentials updated successfully', $r['json']['message'] ?? null, 'credentials message matches');

$stored = getDb()->query("SELECT password_hash FROM settings WHERE id = 'settings'")->fetchColumn();
assertTrue(is_string($stored) && str_starts_with($stored, '$2y$'), 'new credential password is stored as bcrypt');
assertTrue(verifyPasswordSecret('s3cret-pw', $stored), 'stored bcrypt verifies the password');

$r = req('GET', '/api/settings');
assertTrue(!array_key_exists('username', $r['json'] ?? []), 'GET settings still hides username');

// ─── CSP SCRIPT DOMAINS ───────────────────────────────────
group('CSP Script Domains');

$r = req('GET', '/');
$csp = $r['headers']['content-security-policy'] ?? '';
assertContains("script-src 'self' 'unsafe-inline'", $csp, 'default CSP includes self and unsafe-inline');
assertNotContains('googletagmanager.com', $csp, 'default CSP has no extra domains');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['csp_script_domains' => 'https://www.googletagmanager.com, https://www.google-analytics.com']]);
assertSame(200, $r['status'], 'PUT /api/settings updates csp_script_domains');

$r = req('GET', '/');
$csp = $r['headers']['content-security-policy'] ?? '';
assertContains('https://www.googletagmanager.com', $csp, 'CSP includes the configured domain');
assertContains('https://www.google-analytics.com', $csp, 'CSP includes the second configured domain');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['csp_script_domains' => 'https://example.com, , ,  ']]);
assertSame(200, $r['status'], 'PUT tolerates whitespace-only domains');
$r = req('GET', '/');
$csp = $r['headers']['content-security-policy'] ?? '';
assertContains('https://example.com', $csp, 'CSP includes the only valid domain');
assertNotContains('googletagmanager.com', $csp, 'CSP drops domains no longer configured');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['csp_script_domains' => '']]);
assertSame(200, $r['status'], 'PUT clears csp_script_domains');
$r = req('GET', '/');
$csp = $r['headers']['content-security-policy'] ?? '';
assertNotContains('https://example.com', $csp, 'CSP resets when cleared');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['csp_script_domains' => 'https://cdn.example.com']]);
$r = req('GET', '/api/settings');
assertSame('https://cdn.example.com', $r['json']['csp_script_domains'] ?? null, 'csp_script_domains is persisted and returned');

// ─── ONBOARDING RATE LIMIT ────────────────────────────────
group('Onboarding Rate Limit');

$rlHeaders = ['X-Real-IP' => '203.0.113.77'];
for ($i = 1; $i <= 5; $i++) {
    $r = req('POST', '/api/onboarding', ['headers' => $rlHeaders, 'json' => ['title' => 'RL']]);
    assertSame(400, $r['status'], "onboarding attempt {$i} is allowed through (400 conflict)");
}
$r = req('POST', '/api/onboarding', ['headers' => $rlHeaders, 'json' => ['title' => 'RL']]);
assertSame(429, $r['status'], 'sixth onboarding attempt is rate limited');
assertContains('Too many onboarding', $r['json']['message'] ?? '', 'rate-limit message matches');

// ─── WEB: TOPICS CONTRACT ─────────────────────────────────
group('Web Topics Contract');

$topicTags = ['alpha', 'beta', 'gamma', 'delta'];
foreach ($topicTags as $t) {
    req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
        'title' => "Topic $t", 'body' => "Body $t", 'tags' => [$t], 'status' => 'published',
    ]]);
}

$r = req('GET', '/');
assertSame(200, $r['status'], 'GET / returns 200');
assertContains('class="tag tag-list-toggle"', $r['body'], 'home renders the topics expand control');
assertContains('aria-controls="tag-list"', $r['body'], 'topics control exposes aria-controls');
assertContains('aria-expanded="false"', $r['body'], 'topics control starts collapsed');
assertContains('class="tag-list-more"', $r['body'], 'topics control has the "more" label');
assertContains('Show all topics', $r['body'], 'topics control has the expand label text');
assertContains('class="tag-list-less"', $r['body'], 'topics control has the "less" label');
assertContains('Show less', $r['body'], 'topics control has the collapse text');

$r = req('GET', '/search?q=post');
assertSame(200, $r['status'], 'GET /search returns 200');
assertContains('class="tag tag-list-toggle"', $r['body'], 'search renders the topics expand control');
assertContains('aria-controls="tag-list"', $r['body'], 'search topics control exposes aria-controls');

$r = req('GET', '/?tag=alpha');
assertSame(200, $r['status'], 'GET /?tag= returns 200');
assertTrue(preg_match('/class="tag"\s+data-filter=""/', $r['body']) === 1, 'home renders an All anchor');
foreach ($topicTags as $t) {
    assertContains('data-filter="' . $t . '"', $r['body'], "home renders a tag anchor for $t");
}
assertTrue(preg_match('/class="tag is-active"\s+data-filter="alpha"/', $r['body']) === 1, 'the active tag is marked');
assertTrue(preg_match('/class="tag"\s+data-filter="beta"/', $r['body']) === 1, 'inactive tags are not marked');

$r = req('GET', '/');
assertTrue(preg_match('/class="tag is-active"\s+data-filter=""/', $r['body']) === 1, 'home with no tag marks All as active');

$r = req('GET', '/search?q=post');
assertTrue(preg_match('/class="tag is-active"/', $r['body']) === 0, 'search marks no tag as active');

// ─── WEB: HOME PAGE ───────────────────────────────────────
group('Web Home Page');

$r = req('GET', '/');
assertSame(200, $r['status'], 'home returns 200');
assertContains('class="posts-grid"', $r['body'], 'home renders the posts grid');
assertContains('Topic alpha', $r['body'], 'home lists a published post');
assertContains('class="search-box"', $r['body'], 'home renders the search box');
assertContains('<title>' . h(webSettings()['title']) . '</title>', $r['body'], 'home renders the blog title in <title>');

$r = req('GET', '/?tag=alpha');
assertContains('Topic alpha', $r['body'], 'tag filter keeps matching posts');
assertNotContains('Topic beta', $r['body'], 'tag filter drops non-matching posts');

$r = req('GET', '/?tag=nosuchtag');
assertContains('empty-state', $r['body'], 'unknown tag renders the empty state');

for ($i = 1; $i <= 12; $i++) {
    req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
        'title' => sprintf('Pager %02d', $i), 'body' => "Pager body $i", 'tags' => ['pager'], 'status' => 'published',
    ]]);
}
$r = req('GET', '/?tag=pager');
assertSame(200, $r['status'], 'tagged listing returns 200');
assertContains('class="pagination"', $r['body'], 'tagged listing paginates past ten posts');
assertContains('page=2&amp;tag=pager', $r['body'], 'pagination links to page 2 with the tag');
$r = req('GET', '/?tag=pager&page=2');
assertSame(200, $r['status'], 'page 2 returns 200');
assertContains('Pager ', $r['body'], 'page 2 lists the remaining posts');

// ─── WEB: SEARCH (FTS) ────────────────────────────────────
group('Web Search (FTS)');

req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
    'title' => 'Falcon Guide', 'body' => 'nothing here', 'tags' => ['birds'], 'status' => 'published',
]]);
req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
    'title' => 'Body Only Article', 'body' => 'falcon', 'tags' => ['birds'], 'status' => 'published',
]]);
req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
    'title' => 'Falcon Draft', 'body' => 'falcon falcon', 'status' => 'draft',
]]);

$r = req('GET', '/search?q=falcon');
assertSame(200, $r['status'], 'search returns 200');
assertContains('Falcon Guide', $r['body'], 'search finds a title match');
assertContains('Body Only Article', $r['body'], 'search finds a body match');
assertNotContains('Falcon Draft', $r['body'], 'search excludes drafts');
assertTrue(
    strpos($r['body'], 'Falcon Guide') < strpos($r['body'], 'Body Only Article'),
    'a title match ranks above a body-only match'
);

$r = req('GET', '/search?q=fa');
assertContains('Falcon Guide', $r['body'], 'short query falls back to a title LIKE');
assertNotContains('Body Only Article', $r['body'], 'short query does not scan the body');

$r = req('GET', '/search?q=');
assertSame(200, $r['status'], 'empty search returns 200');
assertContains('Enter a search term', $r['body'], 'empty search shows the prompt');

$r = req('GET', '/search?q=zzzznomatch');
assertSame(200, $r['status'], 'no-match search returns 200');
assertContains('No posts found', $r['body'], 'no-match search shows the empty state');

// ─── WEB: POST PAGE ───────────────────────────────────────
group('Web Post Page');

$r = req('GET', '/post/published-post');
assertSame(200, $r['status'], 'GET a published post returns 200');
assertContains('class="post-detail-title"', $r['body'], 'post page renders the title');
assertContains('Published Post', $r['body'], 'post page shows the post title');
assertContains('class="post-nav"', $r['body'], 'post page renders prev/next navigation');
assertContains('min read', $r['body'], 'post page shows the reading time');

$r = req('GET', '/post/test-post');
assertSame(404, $r['status'], 'a draft is 404 for anonymous visitors');
assertContains('This post may be a draft or has been removed.', $r['body'], 'draft 404 explains why');

$r = req('GET', '/post/does-not-exist');
assertSame(404, $r['status'], 'an unknown slug returns 404');
assertContains('Page Not Found', $r['body'], 'unknown slug renders the 404 page');

// ─── WEB: FOOTER CREDITS ──────────────────────────────────
group('Footer Credits');

$r = req('GET', '/');
assertContains('Powered by', $r['body'], 'footer says "Powered by"');
assertContains('>Writer</a>', $r['body'], 'footer credits Writer');
assertNotContains('API Docs', $r['body'], 'show_docs=false hides the API Docs link');

// ─── WEB: SETTINGS UPDATE FORM ────────────────────────────
group('Web Settings Update Form');

$login = webLogin('s3cret-pw', 'admin', '198.51.100.14');
$jar = $login['jar'];
$r = req('POST', '/settings/update', ['cookie' => $jar, 'form' => ['_csrf' => $login['csrf'], 'title' => 'Web Form Blog']]);
assertSame(302, $r['status'], '/settings/update redirects after saving');
assertSame('/settings', $r['headers']['location'] ?? null, '/settings/update redirects to /settings');
$r = req('GET', '/api/settings');
assertSame('Web Form Blog', $r['json']['title'] ?? null, '/settings/update persisted the title');

$r = req('POST', '/settings/update', ['cookie' => $jar, 'form' => ['title' => 'No CSRF']]);
assertSame(403, $r['status'], '/settings/update without CSRF is forbidden');

$r = req('POST', '/settings/update', ['form' => ['_csrf' => $login['csrf'], 'title' => 'X']]);
assertSame(302, $r['status'], '/settings/update without a session redirects');
assertContains('/login', $r['headers']['location'] ?? '', '/settings/update sends anonymous users to /login');

// ─── WEB: LOGIN ───────────────────────────────────────────
group('Web Login');

$r = req('GET', '/login');
assertSame(200, $r['status'], 'GET /login returns 200');
assertContains('name="_csrf"', $r['body'], 'login form embeds a CSRF token');
assertContains('name="username"', $r['body'], 'login form shows the username field');
assertContains('Enter your credentials', $r['body'], 'login form shows the subtitle');

$login = webLogin('s3cret-pw', 'admin', '198.51.100.10');
assertSame(302, $login['post']['status'], 'valid credentials redirect');
$jar = $login['jar'];
$r = req('GET', '/post/test-post', ['cookie' => $jar]);
assertSame(200, $r['status'], 'a session can preview a draft');
assertContains('Preview mode', $r['body'], 'draft preview shows the banner');
assertContains('edit-post-link', $r['body'], 'authenticated post view shows the edit link');

$r = req('GET', '/login', ['cookie' => $jar]);
assertSame(302, $r['status'], 'authenticated GET /login redirects away');

$bad = webLogin('wrong-password', 'admin', '198.51.100.16');
assertSame(200, $bad['post']['status'], 'invalid credentials re-render the login page');
assertContains('Invalid credentials', $bad['post']['body'], 'invalid credentials show an error');

// ─── WEB: REHASH ON LOGIN ─────────────────────────────────
group('Rehash On Login');

getDb()->prepare("UPDATE settings SET password_hash = ? WHERE id = 'settings'")->execute([hash('sha256', 'legacy-pw')]);
$login = webLogin('legacy-pw', 'admin', '198.51.100.11');
assertSame(302, $login['post']['status'], 'a legacy sha256 password still logs in');
$stored = getDb()->query("SELECT password_hash FROM settings WHERE id = 'settings'")->fetchColumn();
assertTrue(str_starts_with($stored, '$2y$'), 'the legacy hash is rehashed to bcrypt on login');
getDb()->prepare("UPDATE settings SET password_hash = ? WHERE id = 'settings'")->execute([hashPasswordSecret('s3cret-pw')]);

// ─── WEB: RECOVERY KEY LOGIN ──────────────────────────────
group('Recovery Key Login');

$rk = generateRecoveryKey();
getDb()->prepare("UPDATE settings SET recovery_key_hash = ? WHERE id = 'settings'")->execute([hashRecoveryKey($rk)]);
$login = webLogin($rk, 'admin', '198.51.100.12');
assertSame(302, $login['post']['status'], 'a recovery key logs in');
$after = getDb()->query("SELECT recovery_key_hash FROM settings WHERE id = 'settings'")->fetchColumn();
assertTrue($after !== hashRecoveryKey($rk), 'the recovery key rotates after use');
assertTrue(!verifyRecoveryKey($rk, $after), 'the used recovery key no longer verifies');

$r = req('GET', '/', ['cookie' => $login['jar']]);
assertContains('notification is-warning', $r['body'], 'the rotated key is announced as a warning banner');
assertContains('Save your new recovery key', $r['body'], 'the new recovery key is shown after use');
$r = req('GET', '/', ['cookie' => $login['jar']]);
assertNotContains('Save your new recovery key', $r['body'], 'the new recovery key is shown only once');

// ─── WEB: LOGOUT ──────────────────────────────────────────
group('Web Logout');

$login = webLogin('s3cret-pw', 'admin', '198.51.100.13');
$jar = $login['jar'];
$r = req('POST', '/logout', ['cookie' => $jar, 'form' => ['_csrf' => $login['csrf']]]);
assertSame(302, $r['status'], 'logout redirects');
$r = req('GET', '/post/test-post', ['cookie' => $jar]);
assertSame(404, $r['status'], 'logout clears the session');

// ─── LOGIN RATE LIMIT ─────────────────────────────────────
group('Login Rate Limit');

$ip = '198.51.100.60';
$jar = newSession();
$get = req('GET', '/login', ['cookie' => $jar, 'headers' => ['X-Real-IP' => $ip]]);
$csrf = csrfFrom($get['body']);
for ($i = 1; $i <= 5; $i++) {
    $r = req('POST', '/login', ['cookie' => $jar, 'headers' => ['X-Real-IP' => $ip], 'form' => ['_csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong']]);
    assertSame(200, $r['status'], "failed login attempt $i is allowed");
}
$r = req('POST', '/login', ['cookie' => $jar, 'headers' => ['X-Real-IP' => $ip], 'form' => ['_csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong']]);
assertSame(429, $r['status'], 'the sixth failed login is rate limited');

for ($i = 1; $i <= 6; $i++) {
    $login = webLogin('s3cret-pw', 'admin', '198.51.100.61');
    assertSame(302, $login['post']['status'], "successful login $i is never rate limited");
}

// ─── SESSION ROTATION INVALIDATES ─────────────────────────
group('Session Rotation Invalidates');

$login = webLogin('s3cret-pw', 'admin', '198.51.100.15');
$jar = $login['jar'];
$r = req('GET', '/post/test-post', ['cookie' => $jar]);
assertSame(200, $r['status'], 'a session previews a draft before rotation');

$r = req('POST', '/api/settings/rotate-token', ['headers' => ['X-Auth-Token' => $token]]);
$rot = $r['json']['confirmation_url'] ?? '';
$r = req('POST', $rot, ['headers' => ['X-Auth-Token' => $token]]);
assertSame(200, $r['status'], 'token rotation is confirmed');
$token = $r['json']['auth_token'] ?? $token;

$r = req('GET', '/post/test-post', ['cookie' => $jar]);
assertSame(404, $r['status'], 'an existing session is invalidated by token rotation');

// ─── RSS FEED ─────────────────────────────────────────────
group('RSS Feed');

req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Tom & Jerry <Blog>', 'description' => 'RSS <desc>']]);

$r = req('GET', '/feed.xml');
assertSame(200, $r['status'], 'GET /feed.xml returns 200');
assertContains('application/rss+xml', $r['headers']['content-type'] ?? '', 'feed is served as RSS');
assertSame('public, max-age=3600', $r['headers']['cache-control'] ?? null, 'feed sets a 1-hour cache');
assertTrue(str_starts_with($r['body'], '<?xml version="1.0" encoding="UTF-8"?>'), 'feed starts with the XML declaration');
assertContains('<rss version="2.0">', $r['body'], 'feed declares RSS 2.0');
assertContains('<channel>', $r['body'], 'feed has a channel');
assertContains('<title>Tom &amp; Jerry &lt;Blog&gt;</title>', $r['body'], 'feed escapes the channel title');
assertContains('<description>RSS &lt;desc&gt;</description>', $r['body'], 'feed escapes the channel description');
assertContains('<language>en-us</language>', $r['body'], 'feed declares a language');
assertContains('<generator>Writer</generator>', $r['body'], 'feed credits the generator');
assertContains('<item>', $r['body'], 'feed contains at least one item');
assertContains('<title>Published Post</title>', $r['body'], 'feed lists the published post');
assertContains('<link>http://localhost:8091/post/published-post</link>', $r['body'], 'feed links the published post');
assertContains('<guid isPermaLink="true">http://localhost:8091/post/published-post</guid>', $r['body'], 'feed sets a permanent guid');
assertContains('GMT</pubDate>', $r['body'], 'feed pubDate is an RFC 1123 GMT date');
assertNotContains('/post/test-post', $r['body'], 'feed excludes drafts');

req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Renamed Blog', 'description' => 'Desc']]);

// ─── OG IMAGES ────────────────────────────────────────────
group('OG Images');

$ogDir = __DIR__ . '/og-images';
@unlink($ogDir . '/site.png');
@unlink($ogDir . '/published-post.png');

$r = req('GET', '/og/site.png');
assertSame(200, $r['status'], 'GET /og/site.png returns 200');
assertSame('image/png', $r['headers']['content-type'] ?? null, 'site OG is served as image/png');
assertSame('public, max-age=86400', $r['headers']['cache-control'] ?? null, 'site OG sets a 1-day cache');
assertTrue(str_starts_with($r['body'], "\x89PNG\r\n\x1a\n"), 'site OG body is a PNG');
clearstatcache();
assertTrue(is_file($ogDir . '/site.png'), 'site OG is written to the cache');

$r = req('GET', '/og/published-post.png');
assertSame(200, $r['status'], 'GET /og/<published slug>.png returns 200');
assertSame('image/png', $r['headers']['content-type'] ?? null, 'post OG is served as image/png');
assertTrue(str_starts_with($r['body'], "\x89PNG\r\n\x1a\n"), 'post OG body is a PNG');
clearstatcache();
assertTrue(is_file($ogDir . '/published-post.png'), 'post OG is written to the cache');
$png = getimagesize($ogDir . '/published-post.png');
assertSame([1200, 630], [$png[0] ?? 0, $png[1] ?? 0], 'post OG is 1200x630');

$r = req('GET', '/og/nonexistent.png');
assertSame(404, $r['status'], 'GET /og/<unknown>.png returns 404');
assertSame('Not found', $r['body'], 'unknown OG slug returns plain "Not found"');

$r = req('GET', '/og/test-post.png');
assertSame(404, $r['status'], 'GET /og/<draft slug>.png returns 404');

$r = req('GET', '/og/published-post.png');
assertSame(file_get_contents($ogDir . '/published-post.png'), $r['body'], 'cached OG bytes are served on the second request');

$r = req('PUT', '/api/posts/published-post', ['headers' => ['X-Auth-Token' => $token], 'json' => ['body' => 'Updated body for OG']]);
assertSame(200, $r['status'], 'updating the post succeeds');
clearstatcache();
assertTrue(!is_file($ogDir . '/published-post.png'), 'a post update clears its OG cache');

$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'OG Blog']]);
assertSame(200, $r['status'], 'settings update succeeds');
$r = req('GET', '/og/site.png');
clearstatcache();
assertTrue(is_file($ogDir . '/site.png'), 'site OG regenerates after a settings update');
$r = req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'Renamed Blog']]);
clearstatcache();
assertTrue(!is_file($ogDir . '/site.png'), 'a settings update clears the site OG cache');

// ─── GIST EMBEDS ──────────────────────────────────────────
group('Gist Embeds');

assertSame('abc123def456', extractGistId('https://gist.github.com/user/abc123def456.js'), 'extracts the gist id from a .js URL');
assertSame(null, extractGistId('https://example.com/nope.js'), 'returns null for a non-gist URL');
assertSame('<p>hi</p>', renderGistsInHtml('<p>hi</p>'), 'html without gists is returned unchanged');
assertContains('Unable to load gist', renderGistError('abc123def456'), 'the gist error block offers a fallback');

$gistId = 'abc123def456';
$gistPayload = json_encode([
    'html_url' => 'https://gist.github.com/user/' . $gistId,
    'files' => [
        'example.php' => ['filename' => 'example.php', 'language' => 'PHP', 'content' => "<?php\necho 'hi';\n"],
    ],
]);
getDb()->prepare('INSERT OR REPLACE INTO gist_cache (gist_id, payload, fetched_at) VALUES (?, ?, ?)')
    ->execute([$gistId, $gistPayload, time()]);

$r = req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
    'title' => 'Gist Post',
    'body' => "Before\n\n<script src=\"https://gist.github.com/user/$gistId.js\"></script>\n\nAfter",
    'status' => 'published',
]]);
assertSame(201, $r['status'], 'create a post containing a gist');
$gistSlug = $r['json']['slug'] ?? '';

$r = req('GET', '/post/' . $gistSlug);
assertSame(200, $r['status'], 'GET the gist post returns 200');
assertContains('class="gist-embed"', $r['body'], 'the gist script is replaced by an embed');
assertContains('class="gist-file"', $r['body'], 'the embed renders a gist file');
assertContains('class="gist-filename">example.php', $r['body'], 'the embed shows the filename');
assertContains('class="hljs language-php"', $r['body'], 'the code block carries the hljs language hook');
assertContains('&lt;?php', $r['body'], 'gist code is HTML-escaped');
assertContains('View on GitHub', $r['body'], 'the embed links back to GitHub');
assertNotContains('gist.github.com/user/' . $gistId . '.js', $r['body'], 'the original gist script tag is gone');
assertContains('class="gist-file-body"', $r['body'], 'the embed renders the code body');

// ─── PANEL UI ─────────────────────────────────────────────
group('Panel UI');

$r = req('GET', '/panel');
assertSame(302, $r['status'], 'GET /panel redirects anonymous visitors');
assertContains('/login?redirect=', $r['headers']['location'] ?? '', 'the panel redirect targets login');

$r = req('GET', '/posts');
assertSame(302, $r['status'], 'GET /posts redirects');
assertSame('/panel', $r['headers']['location'] ?? '', '/posts redirects to /panel');

$login = webLogin('s3cret-pw', 'admin', '198.51.100.30');
$jar = $login['jar'];
$r = req('GET', '/panel', ['cookie' => $jar]);
assertSame(200, $r['status'], 'GET /panel returns 200 when authenticated');
assertContains('class="panel-layout"', $r['body'], 'panel renders the panel layout');
assertContains('class="panel-nav"', $r['body'], 'panel renders the admin nav');
assertContains('class="panel-logo" href="/panel"', $r['body'], 'panel nav links to the panel');
assertContains('class="panel-search-form"', $r['body'], 'panel renders the search form');
assertContains('data-action="create-post"', $r['body'], 'panel offers a New Post button');
assertContains('class="posts-table"', $r['body'], 'panel lists posts in a table');
assertContains('data-action="edit-post"', $r['body'], 'panel lists editable posts');
assertContains('data-action="delete-post"', $r['body'], 'panel lists deletable posts');
assertContains('id="editForm"', $r['body'], 'panel includes the edit form');
assertContains('id="tiptap-editor"', $r['body'], 'panel includes the Tiptap mount point');
assertContains('esm.sh/@tiptap/core@2.11.7', $r['body'], 'panel loads Tiptap from the CDN');
assertContains('esm.sh/turndown@7.2.4', $r['body'], 'panel loads Turndown from the CDN');
assertContains('const authToken = ' . json_encode($token) . ';', $r['body'], 'panel embeds the auth token for the editor');

$r = req('GET', '/panel?q=published', ['cookie' => $jar]);
assertSame(200, $r['status'], 'panel search returns 200');
assertContains('Published Post', $r['body'], 'panel search finds a matching post');
assertContains('class="panel-search-clear"', $r['body'], 'panel search shows a Clear link');

$r = req('GET', '/panel?q=zzzznomatch', ['cookie' => $jar]);
assertSame(200, $r['status'], 'panel search with no hits returns 200');
assertContains('No posts match your search.', $r['body'], 'panel shows an empty search state');

// Pagination: create enough posts to cross the 15-per-page limit.
for ($i = 1; $i <= 16; $i++) {
    req('POST', '/api/posts', ['headers' => ['X-Auth-Token' => $token], 'json' => [
        'title' => 'Bulk Post ' . $i,
        'body' => 'Bulk body ' . $i,
        'status' => 'draft',
    ]]);
}
$r = req('GET', '/panel', ['cookie' => $jar]);
assertContains('id="pagination"', $r['body'], 'panel paginates once it exceeds one page');
assertContains('class="pagination-info"', $r['body'], 'panel shows the page counter');
$r = req('GET', '/panel?page=2', ['cookie' => $jar]);
assertSame(200, $r['status'], 'panel page 2 returns 200');
assertContains('class="pagination-link"', $r['body'], 'panel page 2 links back');

$r = req('GET', '/panel?q=bulk&page=2', ['cookie' => $jar]);
assertContains('&amp;q=bulk', $r['body'], 'panel pagination preserves the query string');

// ─── SETTINGS UI ──────────────────────────────────────────
group('Settings UI');

$r = req('GET', '/settings');
assertSame(302, $r['status'], 'GET /settings redirects anonymous visitors');
assertContains('/login', $r['headers']['location'] ?? '', 'the settings redirect targets login');

$r = req('GET', '/settings', ['cookie' => $jar]);
assertSame(200, $r['status'], 'GET /settings returns 200 when authenticated');
assertContains('class="panel-layout"', $r['body'], 'settings renders the panel layout');
assertContains('class="panel-nav"', $r['body'], 'settings renders the admin nav');
assertContains('href="/panel"', $r['body'], 'settings nav links back to the panel');
assertContains('id="settingsForm"', $r['body'], 'settings has the general form');
assertContains('id="credentialsForm"', $r['body'], 'settings has the credentials form');
assertContains('id="aboutForm"', $r['body'], 'settings has the about form');
assertContains('id="customScriptsForm"', $r['body'], 'settings has the custom scripts form');
assertContains('id="rotateTokenForm"', $r['body'], 'settings has the rotate token form');
assertContains('name="csp_script_domains"', $r['body'], 'settings has the CSP domains field');
assertContains('action="/settings/update"', $r['body'], 'the general form posts to the web route');
assertContains('action="/settings/update-credentials"', $r['body'], 'the credentials form posts to the web route');
assertContains('id="result"', $r['body'], 'settings includes the token result box');
$csrf = csrfFrom($r['body']);
assertTrue($csrf !== '', 'the settings page embeds a CSRF token');

req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['title' => 'A & B <C>']]);
$r = req('GET', '/settings', ['cookie' => $jar]);
assertContains('value="A &amp; B &lt;C&gt;"', $r['body'], 'the settings title field is HTML-escaped');
assertContains('A &amp; B &lt;C&gt;', $r['body'], 'the settings page renders the title everywhere');

// General form via the no-JS fallback (form-encoded).
$r = req('POST', '/settings/update', ['cookie' => $jar, 'form' => [
    '_csrf' => $csrf, 'title' => 'Panel Blog', 'author' => 'Panel Author', 'show_docs' => 'on',
]]);
assertSame(302, $r['status'], 'the settings form redirects on success');
assertSame('/settings', $r['headers']['location'] ?? '', 'the settings form redirects to /settings');
$r = req('GET', '/api/settings');
assertSame('Panel Blog', $r['json']['title'] ?? null, 'the web settings form persists the title');
assertSame(true, $r['json']['show_docs'] ?? null, 'a checked show_docs checkbox persists as true');

// Credentials form: mismatch re-renders, match updates and redirects.
$r = req('POST', '/settings/update-credentials', ['cookie' => $jar, 'form' => [
    '_csrf' => $csrf, 'username' => 'admin', 'password' => 'aaa', 'confirmPassword' => 'bbb',
]]);
assertSame(200, $r['status'], 'a password mismatch re-renders the settings page');
assertContains('Passwords do not match', $r['body'], 'the mismatch is reported');

$r = req('POST', '/settings/update-credentials', ['cookie' => $jar, 'form' => [
    '_csrf' => $csrf, 'username' => 'editor', 'password' => 'panel-pass', 'confirmPassword' => 'panel-pass',
]]);
assertSame(302, $r['status'], 'the credentials form redirects on success');
$r = req('POST', '/settings/update-credentials', ['cookie' => $jar, 'form' => ['username' => 'x']]);
assertSame(403, $r['status'], 'the credentials form requires CSRF');

$fresh = webLogin('panel-pass', 'editor', '198.51.100.31');
assertSame(302, $fresh['post']['status'], 'the new web credentials authenticate');
$r = req('GET', '/panel', ['cookie' => $fresh['jar']]);
assertSame(200, $r['status'], 'the new credentials grant panel access');

// ─── SECURITY HARDENING ───────────────────────────────────
group('Security Hardening');

// Protected API routes reject anonymous callers.
$r = req('PUT', '/api/posts/published-post', ['json' => ['title' => 'X']]);
assertSame(401, $r['status'], 'PUT /api/posts/:slug requires a token');
$r = req('DELETE', '/api/posts/published-post');
assertSame(401, $r['status'], 'DELETE /api/posts/:slug requires a token');
$r = req('PUT', '/api/settings/credentials', ['json' => ['username' => 'x']]);
assertSame(401, $r['status'], 'PUT /api/settings/credentials requires a token');
$r = req('POST', '/api/confirm/abc123');
assertSame(401, $r['status'], 'POST /api/confirm/:token requires a token');

// CSRF on the web login/logout forms (fresh IP to dodge the login limiter).
$csrfJar = newSession();
$r = req('POST', '/login', ['cookie' => $csrfJar, 'headers' => ['X-Real-IP' => '203.0.113.7'], 'form' => ['username' => 'admin', 'password' => 'panel-pass']]);
assertSame(403, $r['status'], 'POST /login without CSRF is forbidden');
$r = req('POST', '/logout', ['cookie' => $csrfJar, 'form' => []]);
assertSame(403, $r['status'], 'POST /logout without CSRF is forbidden');

// Open redirect: /login must ignore an off-site redirect target.
$r = req('GET', '/login?redirect=//evil.example');
assertSame(200, $r['status'], 'GET /login returns 200 for an off-site redirect');
assertContains('name="redirect" value="/"', $r['body'], 'an off-site redirect target is reset to /');

// Onboarding rejects form-encoded bodies (cross-site form vector).
$r = req('POST', '/api/onboarding', ['form' => ['title' => 'Owned', 'username' => 'x', 'password' => 'y']]);
assertSame(415, $r['status'], 'form-encoded onboarding is rejected');

// OG path traversal is rejected.
$r = req('GET', '/og/..%2f..%2f..%2findex.png');
assertSame(404, $r['status'], 'an OG slug with traversal is rejected');

// Unknown paths return 404 (HTML page and API).
$r = req('GET', '/no-such-page');
assertSame(404, $r['status'], 'an unknown web path returns 404');
assertContains('could not be found', $r['body'], 'the generic 404 renders the message');
$r = req('GET', '/api/no-such-endpoint');
assertSame(404, $r['status'], 'an unknown API path returns 404');

// ─── WEB MISC ─────────────────────────────────────────────
group('Web Misc');

$onb = viewOnboarding();
assertContains('<h2>Blog Created!</h2>', $onb, 'the result box uses an h2 title');
assertContains('<h4>Your auth token:</h4>', $onb, 'the token label is an h4');
assertContains('class="token-usage"', $onb, 'the token note carries the token-usage class');
assertContains('<h4>Your recovery key (save it somewhere safe):</h4>', $onb, 'the recovery label is an h4');
assertContains('Use the token in the <code>X-Auth-Token</code> header for all protected API operations.', $onb, 'the result box explains the token header');
assertTrue(strpos($onb, 'X-Auth-Token</code> header') < strpos($onb, 'Save this token!'), 'the token guidance sits above the save warning');
assertContains('Your recovery key is a backup credential.', $onb, 'the result box explains the recovery key');
assertTrue(strpos($onb, 'Your recovery key (save it somewhere safe)') < strpos($onb, 'backup credential'), 'the recovery key explanation follows its value');
assertContains('.onboarding-result .token-warning', cssInline(), 'the tight spacing is a CSS rule, not inline style');
assertNotContains('style="margin', $onb, 'the result box uses no inline spacing styles');

$r = req('GET', '/docs');
assertSame(200, $r['status'], 'GET /docs returns 200');
assertContains('X-Auth-Token', $r['body'], 'the docs page describes API auth');

$r = req('GET', '/onboarding');
assertSame(302, $r['status'], 'GET /onboarding redirects once configured');
assertSame('/', $r['headers']['location'] ?? '', 'onboarding redirects to the home page');

req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['custom_scripts' => '<meta name="wtest" content="1">']]);
$r = req('GET', '/');
assertContains('<meta name="wtest" content="1">', $r['body'], 'custom_scripts are injected into the page');
req('PUT', '/api/settings', ['headers' => ['X-Auth-Token' => $token], 'json' => ['custom_scripts' => '']]);

// ─── IMPORTER ─────────────────────────────────────────────
group('Importer');

$importScript = __DIR__ . '/import.php';
$importSource = __DIR__ . '/storage/data/posts.json';
if (!is_file($importScript) || !is_file($importSource)) {
    skip('transitional importer or storage/data is absent');
} else {
    $importDb = sys_get_temp_dir() . '/writer_import_test.sqlite';
    @unlink($importDb);
    @unlink($importDb . '-wal');
    @unlink($importDb . '-shm');

    $handle = popen('WRITER_DB_PATH=' . escapeshellarg($importDb) . ' php ' . escapeshellarg($importScript) . ' 2>&1', 'r');
    $importOut = (string) stream_get_contents($handle);
    pclose($handle);

    assertContains('Posts: 92 (published 16, draft 76)', $importOut, 'the dry run counts the corpus');
    assertContains('Posts imported: 92', $importOut, 'the importer inserts all 92 posts');
    assertContains('FTS rebuilt.', $importOut, 'the importer rebuilds the FTS index');

    $idb = new PDO('sqlite:' . $importDb);
    assertSame(92, (int) $idb->query('SELECT COUNT(*) FROM posts')->fetchColumn(), 'the imported DB holds 92 posts');
    assertSame(16, (int) $idb->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn(), '16 posts import as published');
    assertSame(1, (int) $idb->query('SELECT COUNT(*) FROM settings')->fetchColumn(), 'the settings row imports');
    $fts = (int) $idb->query('SELECT COUNT(*) FROM posts_fts WHERE posts_fts MATCH \'"blog"\'')->fetchColumn();
    assertTrue($fts > 0, 'the rebuilt FTS index is searchable');

    @unlink($importDb);
    @unlink($importDb . '-wal');
    @unlink($importDb . '-shm');
}

summary();