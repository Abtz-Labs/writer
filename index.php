<?php

/**
 * Writer — A single-file blog engine.
 *
 * Requirements: PHP 8.1+ with pdo_sqlite (FTS5), gd (FreeType),
 *               sodium, mbstring.
 *
 * Dev server: php -S localhost:8080 index.php
 * Tests:      php tests.php
 */

// ============================================================================
// CLI-SERVER GUARD
// ============================================================================

if (php_sapi_name() === 'cli-server') {
  $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
  // Sensitive files: never served (mirrors .htaccess)
  if (
    preg_match('/\.(sqlite3?(-(wal|shm))?|db|sql|env|bak|log)$/i', $uri)
    || preg_match('#/(tests|import)\.php$#i', $uri)
    || str_contains($uri, '/.git')
    || str_contains($uri, '/storage/')
  ) {
    http_response_code(404);
    echo 'Not Found';
    return true;
  }
  if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false; // let the built-in server serve the static asset (mirrors .htaccess !-f)
  }
}

// ============================================================================
// CONFIGURATION
// ============================================================================

define('APP_VERSION', '1.0.13');
define('DB_FILE', getenv('WRITER_DB_PATH') ?: __DIR__ . '/writer.sqlite');
define('OG_DIR', __DIR__ . '/og-images');
define('OG_FONT', __DIR__ . '/assets/fonts/DejaVuSans.ttf');
define('OG_FONT_BOLD', __DIR__ . '/assets/fonts/DejaVuSans-Bold.ttf');
define('GIST_CACHE_TTL', 3600);
define('SESSION_LIFETIME', 86400);
define('PAGE_SIZE', 10);
define('ESM_ORIGIN', 'https://esm.sh');
define('HLJS_ORIGIN', 'https://cdn.jsdelivr.net');
define('GIST_DOMAIN', 'gist.github.com');

// ============================================================================
// DATABASE SETUP
// ============================================================================

function getDb(): PDO
{
  static $db = null;
  if ($db === null) {
    $db = new PDO('sqlite:' . DB_FILE, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=5000');
  }
  return $db;
}

function initDatabase(): void
{
  migrateDatabase();
}

function migrateDatabase(): void
{
  $db = getDb();
  $version = (int) $db->query('PRAGMA user_version')->fetchColumn();

  if ($version < 1) {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL DEFAULT '',
            author TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            username TEXT NOT NULL DEFAULT '',
            password_hash TEXT NOT NULL DEFAULT '',
            recovery_key_hash TEXT NOT NULL DEFAULT '',
            auth_token TEXT NOT NULL DEFAULT '',
            about TEXT NOT NULL DEFAULT '',
            custom_scripts TEXT NOT NULL DEFAULT '',
            csp_script_domains TEXT NOT NULL DEFAULT '',
            onboarding_complete INTEGER NOT NULL DEFAULT 0,
            show_docs INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        )");

    $db->exec("CREATE TABLE IF NOT EXISTS posts (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL DEFAULT '',
            slug TEXT NOT NULL UNIQUE,
            body TEXT NOT NULL DEFAULT '',
            excerpt TEXT NOT NULL DEFAULT '',
            cover_image TEXT NOT NULL DEFAULT '',
            keywords TEXT NOT NULL DEFAULT '',
            meta_description TEXT NOT NULL DEFAULT '',
            reading_time INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'draft',
            tags TEXT NOT NULL DEFAULT '[]',
            created_at TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT '',
            published_at TEXT NOT NULL DEFAULT ''
        )");

    $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS posts_fts USING fts5(
            title, slug, tags, keywords, excerpt, body,
            content='posts', content_rowid='rowid', tokenize='trigram'
        )");

    $db->exec("CREATE TRIGGER IF NOT EXISTS posts_ai AFTER INSERT ON posts BEGIN
            INSERT INTO posts_fts(rowid, title, slug, tags, keywords, excerpt, body)
            VALUES (new.rowid, new.title, new.slug, new.tags, new.keywords, new.excerpt, new.body);
        END");
    $db->exec("CREATE TRIGGER IF NOT EXISTS posts_ad AFTER DELETE ON posts BEGIN
            INSERT INTO posts_fts(posts_fts, rowid, title, slug, tags, keywords, excerpt, body)
            VALUES ('delete', old.rowid, old.title, old.slug, old.tags, old.keywords, old.excerpt, old.body);
        END");
    $db->exec("CREATE TRIGGER IF NOT EXISTS posts_au AFTER UPDATE ON posts BEGIN
            INSERT INTO posts_fts(posts_fts, rowid, title, slug, tags, keywords, excerpt, body)
            VALUES ('delete', old.rowid, old.title, old.slug, old.tags, old.keywords, old.excerpt, old.body);
            INSERT INTO posts_fts(rowid, title, slug, tags, keywords, excerpt, body)
            VALUES (new.rowid, new.title, new.slug, new.tags, new.keywords, new.excerpt, new.body);
        END");

    $db->exec("CREATE TABLE IF NOT EXISTS confirmations (
            token TEXT PRIMARY KEY,
            action TEXT NOT NULL,
            data TEXT NOT NULL DEFAULT '{}',
            expires_at INTEGER NOT NULL
        )");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            ip TEXT NOT NULL,
            attempted_at INTEGER NOT NULL,
            success INTEGER NOT NULL DEFAULT 0
        )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)');

    $db->exec("CREATE TABLE IF NOT EXISTS gist_cache (
            gist_id TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            fetched_at INTEGER NOT NULL
        )");

    $version = 1;
  }

  if ($version < 2) {
    // Rate-limit buckets (login vs onboarding) share the login_attempts table.
    $columns = array_column($db->query('PRAGMA table_info(login_attempts)')->fetchAll(), 'name');
    if (!in_array('bucket', $columns, true)) {
      $db->exec("ALTER TABLE login_attempts ADD COLUMN bucket TEXT NOT NULL DEFAULT ''");
    }
    $version = 2;
  }

  $db->exec('PRAGMA user_version = ' . $version);
}

// ============================================================================
// SECURITY
// ============================================================================

function sendSecurityHeaders(?string $extraDomains = null): void
{
  $scriptSrc = "'self' 'unsafe-inline' " . ESM_ORIGIN . ' ' . HLJS_ORIGIN;
  $connectSrc = "'self' " . ESM_ORIGIN;
  if ($extraDomains) {
    foreach (array_filter(array_map('trim', explode(',', $extraDomains))) as $domain) {
      $scriptSrc .= ' ' . $domain;
      $origin = filter_var($domain, FILTER_VALIDATE_URL)
        ? rtrim(preg_replace('#/.*$#', '', $domain), '/')
        : $domain;
      $connectSrc .= ' ' . $origin;
    }
  }

  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  header('Cross-Origin-Resource-Policy: cross-origin');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header(
    "Content-Security-Policy: default-src 'self'; " .
      "script-src {$scriptSrc}; " .
      "connect-src {$connectSrc}; " .
      "img-src 'self' data: https:; " .
      "style-src 'self' 'unsafe-inline'; " .
      "script-src-attr 'none'"
  );
}

function bootSession(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }
  session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  ]);
  ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
  session_name('session');
  session_start();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  }
}

function csrfToken(): string
{
  return $_SESSION['csrf_token'] ?? '';
}

function verifyCsrf(): void
{
  $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
  if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
    jsonResponse(['error' => 'Forbidden', 'message' => 'Invalid or missing CSRF token'], 403);
  }
}

function generateToken(int $bytes = 32): string
{
  return bin2hex(random_bytes($bytes));
}

/** Hash a new password with bcrypt (native, fast, self-salting). */
function hashPasswordSecret(string $password): string
{
  return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify a password against any stored format:
 *   - legacy SHA-256 (64 hex chars — Node's pre-scrypt scheme)
 *   - `$scrypt$<salt>$<hash>` (Node's scryptSync; libsodium interactive
 *     limits reproduce N=16384, r=8, p=1 byte-for-byte)
 *   - bcrypt (`$2y$...`, PHP password_hash)
 */
function verifyPasswordSecret(string $password, string $stored): bool
{
  if ($stored === '') {
    return false;
  }
  if (preg_match('/^[a-f0-9]{64}$/i', $stored)) {
    return hash_equals(strtolower($stored), hash('sha256', $password));
  }
  if (str_starts_with($stored, '$scrypt$')) {
    $parts = explode('$', $stored);
    if (count($parts) !== 4 || strlen($parts[2]) !== 32) {
      return false;
    }
    $expected = bin2hex(sodium_crypto_pwhash_scryptsalsa208sha256(64, $password, $parts[2], 524288, 16777216));
    return hash_equals($parts[3], $expected);
  }
  return password_verify($password, $stored);
}

/** True when a stored hash is not in the current (bcrypt) format. */
function passwordNeedsRehash(string $stored): bool
{
  return str_starts_with($stored, '$scrypt$') || (bool) preg_match('/^[a-f0-9]{64}$/i', $stored);
}

function generateRecoveryKey(): string
{
  return bin2hex(random_bytes(16));
}

function hashRecoveryKey(string $key): string
{
  return hash('sha256', $key);
}

function verifyRecoveryKey(string $key, string $stored): bool
{
  return $stored !== '' && hash_equals($stored, hash('sha256', $key));
}

/** Client IP, honouring the trusted reverse proxy headers. */
function clientIp(): string
{
  foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
    $value = $_SERVER[$header] ?? '';
    if (is_string($value) && trim($value) !== '') {
      $first = trim(explode(',', $value)[0]);
      if ($first !== '') {
        return $first;
      }
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Sliding-window attempt limiter.
 * Returns true when the client is over the limit; otherwise records the
 * attempt and returns false.
 */
function rateLimitExceeded(string $bucket, string $ip, int $max, int $windowSec): bool
{
  $db = getDb();
  $cutoff = time() - $windowSec;
  $db->prepare('DELETE FROM login_attempts WHERE bucket = ? AND ip = ? AND attempted_at < ?')
    ->execute([$bucket, $ip, $cutoff]);
  $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE bucket = ? AND ip = ? AND attempted_at >= ?');
  $stmt->execute([$bucket, $ip, $cutoff]);
  if ((int) $stmt->fetchColumn() >= $max) {
    return true;
  }
  $db->prepare('INSERT INTO login_attempts (bucket, ip, attempted_at, success) VALUES (?, ?, ?, 0)')
    ->execute([$bucket, $ip, time()]);
  return false;
}

/** Clear attempts after a successful login, so valid logins are never limited. */
function rateLimitClear(string $bucket, string $ip): void
{
  getDb()->prepare('DELETE FROM login_attempts WHERE bucket = ? AND ip = ?')->execute([$bucket, $ip]);
}

// ============================================================================
// HELPERS
// ============================================================================

function jsonResponse(mixed $data, int $status = 200): never
{
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function getInput(): array
{
  $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
  if (str_contains($contentType, 'application/json')) {
    return json_decode(file_get_contents('php://input'), true) ?? [];
  }
  return $_POST;
}

/**
 * Require a JSON body. Blocks the cross-site, form-encoded ("simple request")
 * CSRF vector on the unauthenticated onboarding write — a cross-origin fetch
 * cannot set this content type without a CORS preflight.
 */
function requireJsonBody(): void
{
  if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    jsonResponse(['error' => 'Unsupported Media Type', 'message' => 'Content-Type must be application/json'], 415);
  }
}

function baseUrl(): string
{
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

function h(?string $value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
  http_response_code(302);
  header('Location: ' . $path);
  exit;
}

function requestPath(): string
{
  return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

/** JavaScript truthiness (arrays/objects are always truthy). */
function jsTruthy(mixed $v): bool
{
  if (is_bool($v)) {
    return $v;
  }
  if ($v === null) {
    return false;
  }
  if (is_string($v)) {
    return $v !== '';
  }
  if (is_int($v) || is_float($v)) {
    return $v != 0;
  }
  return true;
}

/** ISO 8601 UTC timestamp, matching JavaScript's Date.toISOString(). */
function isoNow(): string
{
  $now = microtime(true);
  $sec = (int) floor($now);
  $ms = (int) round(($now - $sec) * 1000);
  if ($ms > 999) {
    $ms = 999;
  }
  return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03d', $ms) . 'Z';
}

/** RFC 4122 v4 UUID, matching crypto.randomUUID(). */
function genUuid(): string
{
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ============================================================================
// METADATA
// ============================================================================

function jsTrim(string $s): string
{
  $sp = '[\x09-\x0d\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]';
  return preg_replace('/^' . $sp . '+|' . $sp . '+$/u', '', $s);
}

function plainTextFromBody(string $body): string
{
  $text = preg_replace('/<script[\s\S]*?<\/script>/i', '', $body);
  $text = preg_replace('/!\[.*?\]\(.*?\)/', '', $text);
  $text = preg_replace('/\[.*?\]\(.*?\)/', '', $text);
  $text = preg_replace('/~~[\s\S]+?~~/', '', $text);
  $text = preg_replace('/~[\s\S]+?~/', '', $text);
  $text = preg_replace('/[#*_`~]/', '', $text);
  $text = preg_replace('/\n+/', ' ', $text);
  return jsTrim($text);
}

function generateExcerpt(string $body, int $maxLength = 200): string
{
  if ($body === '') {
    return '';
  }
  $plain = plainTextFromBody($body);
  if (mb_strlen($plain) <= $maxLength) {
    return $plain;
  }
  return jsTrim(mb_substr($plain, 0, $maxLength - 3)) . '...';
}

function generateMetaDescription(string $body): string
{
  if ($body === '') {
    return '';
  }
  $plain = plainTextFromBody($body);
  if (mb_strlen($plain) <= 160) {
    return $plain;
  }
  return jsTrim(mb_substr($plain, 0, 157)) . '...';
}

function generateSlug(string $title): string
{
  if ($title === '') {
    return '';
  }
  $slug = strtolower($title);
  $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
  $slug = preg_replace('/\s+/', '-', $slug);
  $slug = preg_replace('/-+/', '-', $slug);
  return jsTrim($slug);
}

function generateUniqueSlug(array $posts, string $baseSlug, ?string $excludeId = null): string
{
  $slugs = [];
  foreach ($posts as $p) {
    if ($excludeId !== null && ($p['id'] ?? null) === $excludeId) {
      continue;
    }
    $slugs[] = $p['slug'];
  }
  if (!in_array($baseSlug, $slugs, true)) {
    return $baseSlug;
  }
  $counter = 1;
  $slug = "{$baseSlug}-{$counter}";
  while (in_array($slug, $slugs, true)) {
    $counter++;
    $slug = "{$baseSlug}-{$counter}";
  }
  return $slug;
}

function extractKeywords(string $title, string $body): string
{
  static $stopWords = [
    'the' => 1,
    'a' => 1,
    'an' => 1,
    'and' => 1,
    'or' => 1,
    'but' => 1,
    'in' => 1,
    'on' => 1,
    'at' => 1,
    'to' => 1,
    'for' => 1,
    'of' => 1,
    'with' => 1,
    'by' => 1,
    'from' => 1,
    'as' => 1,
    'is' => 1,
    'was' => 1,
    'are' => 1,
    'were' => 1,
    'been' => 1,
    'be' => 1,
    'have' => 1,
    'has' => 1,
    'had' => 1,
    'do' => 1,
    'does' => 1,
    'did' => 1,
    'will' => 1,
    'would' => 1,
    'could' => 1,
    'should' => 1,
    'may' => 1,
    'might' => 1,
    'must' => 1,
    'shall' => 1,
    'can' => 1,
    'need' => 1,
    'this' => 1,
    'that' => 1,
    'these' => 1,
    'those' => 1,
    'i' => 1,
    'you' => 1,
    'he' => 1,
    'she' => 1,
    'it' => 1,
    'we' => 1,
    'they' => 1,
    'what' => 1,
    'which' => 1,
    'who' => 1,
    'whom' => 1,
    'whose' => 1,
    'where' => 1,
    'when' => 1,
    'why' => 1,
    'how' => 1,
    'all' => 1,
    'each' => 1,
    'every' => 1,
    'both' => 1,
    'few' => 1,
    'more' => 1,
    'most' => 1,
    'other' => 1,
    'some' => 1,
    'such' => 1,
    'no' => 1,
    'nor' => 1,
    'not' => 1,
    'only' => 1,
    'own' => 1,
    'same' => 1,
    'so' => 1,
    'than' => 1,
    'too' => 1,
    'very' => 1,
    'just' => 1,
    'into' => 1,
    'over' => 1,
    'after' => 1,
    'before' => 1,
    'between' => 1,
  ];
  $text = strtolower($title . ' ' . $body);
  preg_match_all('/\b[a-z]{3,}\b/', $text, $m);
  $counts = [];
  $order = [];
  foreach ($m[0] as $word) {
    if (isset($stopWords[$word])) {
      continue;
    }
    if (!isset($counts[$word])) {
      $counts[$word] = 0;
      $order[] = $word;
    }
    $counts[$word]++;
  }
  usort($order, fn($a, $b) => $counts[$b] <=> $counts[$a]);
  return implode(', ', array_slice($order, 0, 10));
}

function calculateReadingTime(string $body): int
{
  if ($body === '') {
    return 1;
  }
  $trimmed = jsTrim($body);
  $words = count(array_filter(preg_split('/\s+/', $trimmed), fn($w) => $w !== ''));
  return max(1, (int) ceil($words / 200));
}

function inferMetadata(string $title, string $body): array
{
  return [
    'slug' => generateSlug($title),
    'keywords' => extractKeywords($title, $body),
    'meta_description' => generateMetaDescription($body),
    'reading_time' => calculateReadingTime($body),
    'excerpt' => generateExcerpt($body),
  ];
}

function firstImageFromBody(string $body, string $cover = ''): string
{
  if ($body === '') {
    return $cover;
  }
  if (preg_match('/!\[.*?\]\((.*?)(?:\s+["\'].*?["\'])?\)/', $body, $m)) {
    return $m[1];
  }
  if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $m)) {
    return $m[1];
  }
  return $cover;
}

// ============================================================================
// MARKDOWN
// ============================================================================

function mdEscape(string $s): string
{
  return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], $s);
}

function mdCleanUrl(string $href): string
{
  static $safe = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.!~*'()#;,/?:@&=+\$";
  $out = '';
  $len = strlen($href);
  for ($i = 0; $i < $len; $i++) {
    $ch = $href[$i];
    $out .= strpos($safe, $ch) !== false ? $ch : '%' . strtoupper(bin2hex($ch));
  }
  return str_replace('%25', '%', $out);
}

function isBlankLine(string $l): bool
{
  return preg_match('/^[ \t]*$/', $l) === 1;
}

function leadingIndent(string $line): int
{
  $n = 0;
  $len = strlen($line);
  for ($k = 0; $k < $len; $k++) {
    if ($line[$k] === ' ') {
      $n++;
    } elseif ($line[$k] === "\t") {
      $n += 4 - ($n % 4);
    } else {
      break;
    }
  }
  return $n;
}

function stripIndent(string $line, int $count): string
{
  $removed = 0;
  $len = strlen($line);
  for ($k = 0; $k < $len && $removed < $count; $k++) {
    if ($line[$k] === ' ') {
      $removed++;
    } elseif ($line[$k] === "\t") {
      $removed += 4 - ($removed % 4);
    } else {
      break;
    }
  }
  return substr($line, $k);
}

function tableAlignAttr(string $a): string
{
  return $a === '' ? '' : ' align="' . $a . '"';
}

function htmlBlockType(string $line, bool $interrupting): ?string
{
  if (preg_match('/^ {0,3}<(script|pre|style|textarea)([ \t]|>|$)/i', $line)) {
    return 'script';
  }
  if (preg_match('/^ {0,3}<!--/', $line)) {
    return 'comment';
  }
  if (preg_match('/^ {0,3}<[?!]/', $line)) {
    return 'other';
  }
  if (preg_match('/^ {0,3}<\/?([a-z][a-z0-9]*)([ \t]|\/?>|$)/i', $line, $m)) {
    static $blockTags = ['address', 'article', 'aside', 'base', 'basefont', 'blockquote', 'body', 'caption', 'center', 'col', 'colgroup', 'dd', 'details', 'dialog', 'dir', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html', 'iframe', 'legend', 'li', 'link', 'main', 'menu', 'menuitem', 'nav', 'noframes', 'ol', 'optgroup', 'option', 'p', 'param', 'search', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'ul'];
    if (in_array(strtolower($m[1]), $blockTags, true)) {
      return 'type6';
    }
  }
  if (!$interrupting && preg_match('/^ {0,3}<\/?[a-zA-Z][a-zA-Z0-9-]*(\s+[^>]*)?\/?>[ \t]*$/', $line)) {
    return 'type7';
  }
  return null;
}

function startsBlockLine(string $l): bool
{
  if (preg_match('/^ {0,3}#{1,6}([ \t]|$)/', $l)) {
    return true;
  }
  if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $l)) {
    return true;
  }
  if (preg_match('/^ {0,3}>/', $l)) {
    return true;
  }
  if (preg_match('/^ {0,3}([-+*]|\d{1,9}[.)])([ \t]|$)/', $l)) {
    return true;
  }
  if (preg_match('/^ {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $l)) {
    return true;
  }
  if (preg_match('/^( {4}|\t)/', $l)) {
    return true;
  }
  return htmlBlockType($l, true) !== null;
}

function splitTableRow(string $row): array
{
  $row = trim($row);
  if (str_starts_with($row, '|')) {
    $row = substr($row, 1);
  }
  if (str_ends_with($row, '|')) {
    $row = substr($row, 0, -1);
  }
  $cells = preg_split('/(?<!\\\\)\|/', $row);
  return array_map(fn($c) => str_replace('\\|', '|', trim($c)), $cells);
}

function parseMarkdown(string $md): string
{
  if ($md === '') {
    return '';
  }
  $md = str_replace(["\r\n", "\r"], "\n", $md);
  return renderBlocks(explode("\n", $md));
}

function renderBlocks(array $lines, bool $tight = false): string
{
  $out = '';
  $i = 0;
  $n = count($lines);

  while ($i < $n) {
    $line = $lines[$i];

    if (isBlankLine($line)) {
      $i++;
      continue;
    }

    // Fenced code
    if (
      preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $m)
      && ($m[1][0] === '~' || strpos($m[2], '`') === false)
    ) {
      $fenceChar = $m[1][0];
      $fenceLen = strlen($m[1]);
      $code = '';
      $i++;
      while ($i < $n) {
        if (preg_match('/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLen . ',}[ \t]*$/', $lines[$i])) {
          $i++;
          break;
        }
        $code .= $lines[$i] . "\n";
        $i++;
      }
      $out .= '<pre><code>' . mdEscape($code) . "</code></pre>\n";
      continue;
    }

    // Indented code
    if (preg_match('/^( {4}|\t)/', $line)) {
      $codeLines = [];
      while ($i < $n) {
        if (preg_match('/^( {4}|\t)/', $lines[$i])) {
          $codeLines[] = preg_replace('/^( {4}|\t)/', '', $lines[$i]);
          $i++;
        } elseif (isBlankLine($lines[$i])) {
          $j = $i;
          while ($j < $n && isBlankLine($lines[$j])) {
            $j++;
          }
          if ($j < $n && preg_match('/^( {4}|\t)/', $lines[$j])) {
            while ($i < $j) {
              $codeLines[] = '';
              $i++;
            }
          } else {
            break;
          }
        } else {
          break;
        }
      }
      while (!empty($codeLines) && end($codeLines) === '') {
        array_pop($codeLines);
      }
      $out .= '<pre><code>' . mdEscape(implode("\n", $codeLines) . "\n") . "</code></pre>\n";
      continue;
    }

    // ATX heading
    if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?[ \t]*$/', $line, $m)) {
      $level = strlen($m[1]);
      $content = rtrim($m[2] ?? '', " \t");
      $content = preg_replace('/[ \t]+#+$/', '', $content);
      $out .= "<h{$level}>" . parseInline(jsTrim($content)) . "</h{$level}>\n";
      $i++;
      continue;
    }

    // Horizontal rule
    if (preg_match('/^ {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $line)) {
      $out .= "<hr>\n";
      $i++;
      continue;
    }

    // Blockquote
    if (preg_match('/^ {0,3}>/', $line)) {
      $bq = [];
      while ($i < $n) {
        if (preg_match('/^ {0,3}>[ ]?(.*)$/', $lines[$i], $m)) {
          $bq[] = $m[1];
          $i++;
        } elseif (isBlankLine($lines[$i])) {
          break;
        } else {
          $bq[] = $lines[$i];
          $i++;
        }
      }
      $out .= "<blockquote>\n" . renderBlocks($bq) . "</blockquote>\n";
      continue;
    }

    // List
    if (preg_match('/^( {0,3})([-+*]|\d{1,9}[.)])([ \t]+|$)/', $line)) {
      [$listHtml, $i] = renderList($lines, $i);
      $out .= $listHtml;
      continue;
    }

    // Table
    if (
      strpos($line, '|') !== false
      && $i + 1 < $n
      && preg_match('/^ *\|? *:?-+:? *(\| *:?-+:? *)*\|? *$/', $lines[$i + 1])
    ) {
      $headerCells = splitTableRow($line);
      $delimCells = splitTableRow($lines[$i + 1]);
      if (count($headerCells) > 0 && count($headerCells) === count($delimCells)) {
        $aligns = [];
        foreach ($delimCells as $dc) {
          $dc = trim($dc);
          if (str_starts_with($dc, ':') && str_ends_with($dc, ':')) {
            $aligns[] = 'center';
          } elseif (str_ends_with($dc, ':')) {
            $aligns[] = 'right';
          } elseif (str_starts_with($dc, ':')) {
            $aligns[] = 'left';
          } else {
            $aligns[] = '';
          }
        }
        $rows = [];
        $j = $i + 2;
        while ($j < $n && !isBlankLine($lines[$j]) && strpos($lines[$j], '|') !== false) {
          $rows[] = splitTableRow($lines[$j]);
          $j++;
        }
        $out .= "<table>\n<thead>\n<tr>\n";
        foreach ($headerCells as $k => $cell) {
          $out .= '<th' . tableAlignAttr($aligns[$k] ?? '') . '>' . parseInline(jsTrim($cell)) . "</th>\n";
        }
        $out .= "</tr>\n</thead>\n<tbody>";
        foreach ($rows as $row) {
          $out .= "<tr>\n";
          foreach ($headerCells as $k => $cell) {
            $out .= '<td' . tableAlignAttr($aligns[$k] ?? '') . '>' . parseInline(jsTrim($row[$k] ?? '')) . "</td>\n";
          }
          $out .= "</tr>\n";
        }
        $out .= "</tbody></table>\n";
        $i = $j;
        continue;
      }
    }

    // HTML block
    $htmlType = htmlBlockType($line, false);
    if ($htmlType !== null) {
      $raw = [];
      if ($htmlType === 'script') {
        while ($i < $n) {
          $raw[] = $lines[$i];
          $closes = preg_match('/<\/(script|pre|style|textarea)>/i', $lines[$i]) === 1;
          $i++;
          if ($closes) {
            break;
          }
        }
      } elseif ($htmlType === 'comment') {
        while ($i < $n) {
          $raw[] = $lines[$i];
          $done = strpos($lines[$i], '-->') !== false;
          $i++;
          if ($done) {
            break;
          }
        }
      } else {
        while ($i < $n && !isBlankLine($lines[$i])) {
          $raw[] = $lines[$i];
          $i++;
        }
      }
      $out .= implode("\n", $raw);
      continue;
    }

    // Setext heading
    if (
      $i + 1 < $n
      && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $lines[$i + 1], $sm)
      && !startsBlockLine($line)
    ) {
      $level = $sm[1][0] === '=' ? 1 : 2;
      $out .= "<h{$level}>" . parseInline(jsTrim($line)) . "</h{$level}>\n";
      $i += 2;
      continue;
    }

    // Paragraph
    $para = [];
    while ($i < $n && !isBlankLine($lines[$i])) {
      if (!empty($para) && startsBlockLine($lines[$i])) {
        break;
      }
      $para[] = $lines[$i];
      $i++;
    }
    $text = parseInline(implode("\n", $para));
    $out .= $tight ? $text : '<p>' . $text . "</p>\n";
  }

  return $out;
}

function renderList(array $lines, int $start): array
{
  $n = count($lines);
  preg_match('/^( {0,3})([-+*]|\d{1,9}[.)])([ \t]+|$)/', $lines[$start], $fm);
  $listIndent = strlen($fm[1]);
  $ordered = preg_match('/^\d/', $fm[2]) === 1;
  $itemRe = $ordered ? '/^( {0,3})(\d{1,9}[.)])([ \t]+|$)/' : '/^( {0,3})([-+*])([ \t]+|$)/';

  $items = [];
  $loose = false;
  $i = $start;

  while ($i < $n) {
    if (isBlankLine($lines[$i]) || !preg_match($itemRe, $lines[$i], $m) || strlen($m[1]) !== $listIndent) {
      break;
    }
    $contentIndent = strlen($m[1]) + strlen($m[2]) + strlen($m[3]);
    $itemLines = [substr($lines[$i], $contentIndent)];
    $i++;

    while ($i < $n) {
      $cl = $lines[$i];
      if (isBlankLine($cl)) {
        $j = $i;
        while ($j < $n && isBlankLine($lines[$j])) {
          $j++;
        }
        if ($j < $n && leadingIndent($lines[$j]) >= $contentIndent) {
          while ($i < $j) {
            $itemLines[] = '';
            $i++;
          }
          $loose = true;
          continue;
        }
        if ($j < $n && preg_match($itemRe, $lines[$j], $mm) && strlen($mm[1]) === $listIndent) {
          $loose = true;
        }
        $i = $j;
        break;
      }
      if (leadingIndent($cl) >= $contentIndent) {
        $itemLines[] = stripIndent($cl, $contentIndent);
        $i++;
        continue;
      }
      if (preg_match($itemRe, $cl, $mm) && strlen($mm[1]) === $listIndent) {
        break;
      }
      if (startsBlockLine($cl)) {
        break;
      }
      $itemLines[] = $cl;
      $i++;
    }

    $items[] = $itemLines;
  }

  $tag = $ordered ? 'ol' : 'ul';
  $out = "<{$tag}>\n";
  foreach ($items as $itemLines) {
    $out .= '<li>' . renderBlocks($itemLines, !$loose) . "</li>\n";
  }
  return [$out . "</{$tag}>\n", $i];
}

function mdDelimFlanking(string $char, string $prev, string $next): array
{
  $nextSpace = ($next === '' || $next === ' ' || $next === "\n" || $next === "\t");
  $prevSpace = ($prev === '' || $prev === ' ' || $prev === "\n" || $prev === "\t");
  $nextPunct = ($next !== '' && preg_match('/[[:punct:]]/', $next) === 1);
  $prevPunct = ($prev !== '' && preg_match('/[[:punct:]]/', $prev) === 1);
  $left = !$nextSpace && (!$nextPunct || $prevSpace || $prevPunct);
  $right = !$prevSpace && (!$prevPunct || $nextSpace || $nextPunct);
  if ($char === '_') {
    return [$left && (!$right || $prevPunct), $right && (!$left || $nextPunct)];
  }
  return [$left, $right];
}

function mdFindCloser(string $text, int $from, string $char, int $minRun, int $len): ?int
{
  $pos = $from;
  while ($pos < $len) {
    $k = strpos($text, $char, $pos);
    if ($k === false) {
      return null;
    }
    $run = strspn($text, $char, $k);
    [, $canClose] = mdDelimFlanking($char, $k > 0 ? $text[$k - 1] : '', ($k + $run < $len) ? $text[$k + $run] : '');
    if ($canClose && $run >= $minRun) {
      return $k;
    }
    $pos = $k + $run;
  }
  return null;
}

function mdFindClosingBracket(string $text, int $open): ?int
{
  $depth = 0;
  $len = strlen($text);
  for ($k = $open; $k < $len; $k++) {
    if ($text[$k] === '\\') {
      $k++;
      continue;
    }
    if ($text[$k] === '[') {
      $depth++;
    } elseif ($text[$k] === ']') {
      if (--$depth === 0) {
        return $k;
      }
    }
  }
  return null;
}

function mdFindClosingParen(string $text, int $open): ?int
{
  $depth = 0;
  $len = strlen($text);
  for ($k = $open; $k < $len; $k++) {
    if ($text[$k] === '\\') {
      $k++;
      continue;
    }
    if ($text[$k] === '(') {
      $depth++;
    } elseif ($text[$k] === ')') {
      if (--$depth === 0) {
        return $k;
      }
    }
  }
  return null;
}

function mdParseLinkDestination(string $s): array
{
  $s = trim($s);
  $title = null;
  if (str_starts_with($s, '<')) {
    $gt = strpos($s, '>');
    if ($gt === false) {
      return [null, null];
    }
    $url = substr($s, 1, $gt - 1);
    $rest = trim(substr($s, $gt + 1));
  } else {
    $sp = preg_match('/\s/', $s, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
    if ($sp === -1) {
      $url = $s;
      $rest = '';
    } else {
      $url = substr($s, 0, $sp);
      $rest = trim(substr($s, $sp));
    }
  }
  if ($rest !== '') {
    $first = $rest[0];
    if ($first === '"' || $first === "'") {
      $end = strrpos($rest, $first);
      if ($end <= 0 || trim(substr($rest, $end + 1)) !== '') {
        return [null, null];
      }
      $title = substr($rest, 1, $end - 1);
    } elseif ($first === '(') {
      $end = strrpos($rest, ')');
      if ($end <= 0 || trim(substr($rest, $end + 1)) !== '') {
        return [null, null];
      }
      $title = substr($rest, 1, $end - 1);
    } else {
      return [null, null];
    }
  }
  return [$url, $title];
}

function parseLinkOrImage(string $text, int $start, bool $isImage): ?array
{
  $bracket = $isImage ? $start + 1 : $start;
  $closeBracket = mdFindClosingBracket($text, $bracket);
  if ($closeBracket === null || ($text[$closeBracket + 1] ?? '') !== '(') {
    return null;
  }
  $closeParen = mdFindClosingParen($text, $closeBracket + 1);
  if ($closeParen === null) {
    return null;
  }
  $label = substr($text, $bracket + 1, $closeBracket - $bracket - 1);
  $inner = trim(substr($text, $closeBracket + 2, $closeParen - $closeBracket - 2));
  [$url, $title] = mdParseLinkDestination($inner);
  if ($url === null) {
    return null;
  }
  if ($isImage) {
    $html = '<img src="' . mdCleanUrl($url) . '" alt="' . mdEscape($label) . '"';
    if ($title !== null) {
      $html .= ' title="' . mdEscape($title) . '"';
    }
    $html .= '>';
  } else {
    $html = '<a href="' . mdCleanUrl($url) . '"';
    if ($title !== null) {
      $html .= ' title="' . mdEscape($title) . '"';
    }
    $html .= '>' . parseInline($label) . '</a>';
  }
  return ['html' => $html, 'end' => $closeParen + 1];
}

function parseInline(string $text): string
{
  if ($text === '') {
    return '';
  }
  $out = '';
  $len = strlen($text);
  for ($i = 0; $i < $len;) {
    $c = $text[$i];

    // Backslash escape
    if ($c === '\\' && $i + 1 < $len && preg_match('/[!-\/:-@\[-`{-~]/', $text[$i + 1])) {
      $out .= mdEscape($text[$i + 1]);
      $i += 2;
      continue;
    }

    // Code span
    if ($c === '`') {
      $run = strspn($text, '`', $i);
      $fence = str_repeat('`', $run);
      $close = strpos($text, $fence, $i + $run);
      if ($close !== false && ($text[$close + $run] ?? '`') !== '`') {
        $content = substr($text, $i + $run, $close - ($i + $run));
        if (preg_match('/^ .* $/s', $content)) {
          $content = substr($content, 1, -1);
        }
        $out .= '<code>' . mdEscape(str_replace("\n", ' ', $content)) . '</code>';
        $i = $close + $run;
        continue;
      }
      $out .= $fence;
      $i += $run;
      continue;
    }

    // Image / Link
    if (($c === '!' && ($text[$i + 1] ?? '') === '[') || $c === '[') {
      $parsed = parseLinkOrImage($text, $i, $c === '!');
      if ($parsed !== null) {
        $out .= $parsed['html'];
        $i = $parsed['end'];
        continue;
      }
    }

    // Autolink or raw HTML tag
    if ($c === '<') {
      $gt = strpos($text, '>', $i + 1);
      if ($gt !== false) {
        $inner = substr($text, $i + 1, $gt - $i - 1);
        $isEmail = preg_match('/^[^\s<>@]+@[^\s<>@]+\.[^\s<>@]+$/', $inner) === 1;
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:[^\s<>]*$#', $inner) || $isEmail) {
          $href = $isEmail ? 'mailto:' . mdCleanUrl($inner) : mdCleanUrl($inner);
          $out .= '<a href="' . $href . '">' . mdEscape($inner) . '</a>';
          $i = $gt + 1;
          continue;
        }
        if (preg_match('#^/?[a-zA-Z][a-zA-Z0-9-]*(\s[^<>]*)?/?$#', $inner)) {
          $out .= '<' . $inner . '>';
          $i = $gt + 1;
          continue;
        }
      }
    }

    // Bare URL (GFM)
    if (($c === 'h' || $c === 'f') && preg_match('#^(https?://|ftp://)[^\s<]*#', substr($text, $i), $um)) {
      $url = preg_replace('/[.,:;!?]+$/', '', $um[0]);
      while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
        $url = substr($url, 0, -1);
      }
      $out .= '<a href="' . mdCleanUrl($url) . '">' . mdEscape($url) . '</a>';
      $i += strlen($url);
      continue;
    }

    // Emphasis / strikethrough
    if ($c === '*' || $c === '_' || $c === '~') {
      $run = strspn($text, $c, $i);
      [$canOpen] = mdDelimFlanking($c, $i > 0 ? $text[$i - 1] : '', ($i + $run < $len) ? $text[$i + $run] : '');
      if ($canOpen) {
        $closer = mdFindCloser($text, $i + $run, $c, $run >= 2 ? 2 : 1, $len);
        if ($closer !== null) {
          $closeRun = strspn($text, $c, $closer);
          $inner = substr($text, $i + $run, $closer - ($i + $run));
          $tag = $c === '~' ? 'del' : ($run >= 2 ? 'strong' : 'em');
          $out .= '<' . $tag . '>' . parseInline($inner) . '</' . $tag . '>';
          $i = $closer + $closeRun;
          continue;
        }
      }
      $out .= str_repeat($c, $run);
      $i += $run;
      continue;
    }

    // Entity
    if ($c === '&' && preg_match('/^&(#(?:\d+|[xX][0-9a-fA-F]+)|[a-zA-Z][a-zA-Z0-9]*);/', substr($text, $i), $em)) {
      $out .= $em[0];
      $i += strlen($em[0]);
      continue;
    }

    // Newline -> break (marked breaks:true); trailing spaces before the
    // break are dropped (marked keeps them only at end of text)
    if ($c === "\n") {
      $out = rtrim($out, " \t");
      $out .= '<br>';
      $i++;
      continue;
    }

    $out .= mdEscape($c);
    $i++;
  }
  return $out;
}

// ============================================================================
// SANITIZER
// ============================================================================

function renderBodyHtml(string $body): string
{
  return sanitizeHtml(parseMarkdown($body));
}

function sanitizeHtml(string $html): string
{
  if ($html === '') {
    return '';
  }
  // Strip scripts before AND after filtering: the filter normalizes tag
  // boundaries, so a second pass closes any gap the first pass left.
  return stripNonGistScripts(xssFilter(stripNonGistScripts($html)));
}

/**
 * Remove every <script> (or an unterminated one, to end of input), keeping only
 * a terminated script whose src is an https gist URL. Blocks `<script>`, the
 * non-canonical closers (`</script >`, `</script\n>`) and unterminated tags that
 * the tag-name normalization in xssFilter would otherwise turn into a live tag.
 */
function stripNonGistScripts(string $html): string
{
  return preg_replace_callback(
    '#<script\b[^>]*>(?:.*?</script\s*>\s*|.*)#is',
    function (array $m): string {
      $tag = $m[0];
      if (!preg_match('#</script\s*>\s*$#i', $tag)) {
        return '';
      }
      if (!preg_match('/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $s)) {
        return '';
      }
      $src = $s[1] ?? '';
      if ($src === '') {
        $src = $s[2] ?? '';
      }
      if ($src === '') {
        $src = $s[3] ?? '';
      }
      $url = parse_url($src);
      if (
        is_array($url)
        && strtolower($url['scheme'] ?? '') === 'https'
        && strtolower($url['host'] ?? '') === GIST_DOMAIN
      ) {
        return $tag;
      }
      return '';
    },
    $html
  );
}

function xssWhiteList(): array
{
  return [
    'a' => ['target', 'href', 'title'],
    'abbr' => ['title'],
    'address' => [],
    'area' => ['shape', 'coords', 'href', 'alt'],
    'article' => [],
    'aside' => [],
    'audio' => ['autoplay', 'controls', 'crossorigin', 'loop', 'muted', 'preload', 'src'],
    'b' => [],
    'bdi' => ['dir'],
    'bdo' => ['dir'],
    'big' => [],
    'blockquote' => ['cite'],
    'br' => [],
    'caption' => [],
    'center' => [],
    'cite' => [],
    'code' => [],
    'col' => ['align', 'valign', 'span', 'width'],
    'colgroup' => ['align', 'valign', 'span', 'width'],
    'dd' => [],
    'del' => ['datetime'],
    'details' => ['open'],
    'div' => [],
    'dl' => [],
    'dt' => [],
    'em' => [],
    'figcaption' => [],
    'figure' => [],
    'font' => ['color', 'size', 'face'],
    'footer' => [],
    'h1' => [],
    'h2' => [],
    'h3' => [],
    'h4' => [],
    'h5' => [],
    'h6' => [],
    'header' => [],
    'hr' => [],
    'i' => [],
    'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
    'ins' => ['datetime'],
    'kbd' => [],
    'li' => [],
    'mark' => [],
    'nav' => [],
    'ol' => [],
    'p' => [],
    'pre' => [],
    's' => [],
    'section' => [],
    'small' => [],
    'span' => [],
    'strike' => [],
    'strong' => [],
    'sub' => [],
    'summary' => [],
    'sup' => [],
    'table' => ['width', 'border', 'align', 'valign'],
    'tbody' => ['align', 'valign'],
    'td' => ['width', 'rowspan', 'colspan', 'align', 'valign'],
    'tfoot' => ['align', 'valign'],
    'th' => ['width', 'rowspan', 'colspan', 'align', 'valign'],
    'thead' => ['align', 'valign'],
    'tr' => ['rowspan', 'align', 'valign'],
    'tt' => [],
    'u' => [],
    'ul' => [],
    'video' => ['autoplay', 'controls', 'crossorigin', 'loop', 'muted', 'playsinline', 'poster', 'preload', 'src', 'height', 'width'],
    'script' => ['src'],
  ];
}

function xssEscapeHtml(string $s): string
{
  return str_replace(['<', '>'], ['&lt;', '&gt;'], $s);
}

function xssEscapeAttrValue(string $str): string
{
  return xssEscapeHtml(str_replace('"', '&quot;', $str));
}

function xssClearNonPrintable(string $str): string
{
  return jsTrim(preg_replace('/[\x00-\x1f]/', ' ', $str));
}

function xssFriendlyAttrValue(string $str): string
{
  $str = str_replace('&quot;', '"', $str);
  $str = preg_replace_callback('/&#([a-zA-Z0-9]*);?/i', function ($m) {
    $code = $m[1];
    if ($code !== '' && ($code[0] === 'x' || $code[0] === 'X')) {
      return mb_chr((int) hexdec(substr($code, 1)), 'UTF-8');
    }
    return mb_chr((int) $code, 'UTF-8');
  }, $str);
  $str = preg_replace('/&colon;?/i', ':', $str);
  $str = preg_replace('/&newline;?/i', ' ', $str);
  return xssClearNonPrintable($str);
}

function xssSafeAttrValue(string $tag, string $name, string $value): string
{
  if ($tag === 'script' && $name === 'src') {
    $url = parse_url($value);
    if ($url !== false && ($url['host'] ?? '') === GIST_DOMAIN && ($url['scheme'] ?? '') === 'https') {
      return $value;
    }
    return '';
  }
  $value = xssFriendlyAttrValue($value);
  if ($name === 'href' || $name === 'src') {
    $value = trim($value);
    if ($value === '#') {
      return '#';
    }
    $ok = substr($value, 0, 7) === 'http://'
      || substr($value, 0, 8) === 'https://'
      || substr($value, 0, 7) === 'mailto:'
      || substr($value, 0, 4) === 'tel:'
      || substr($value, 0, 11) === 'data:image/'
      || substr($value, 0, 6) === 'ftp://'
      || substr($value, 0, 2) === './'
      || substr($value, 0, 3) === '../'
      || (isset($value[0]) && ($value[0] === '#' || $value[0] === '/'));
    if (!$ok) {
      return '';
    }
  }
  return xssEscapeAttrValue($value);
}

function xssSpaceIndex(string $s): int
{
  return preg_match('/\s|\n|\t/', $s, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
}

function xssGetTagName(string $html): string
{
  $i = xssSpaceIndex($html);
  $tagName = $i === -1 ? substr($html, 1, -1) : substr($html, 1, $i);
  $tagName = strtolower(trim($tagName));
  if (str_starts_with($tagName, '/')) {
    $tagName = substr($tagName, 1);
  }
  if (str_ends_with($tagName, '/')) {
    $tagName = substr($tagName, 0, -1);
  }
  return $tagName;
}

function xssGetAttrs(string $html): array
{
  $i = xssSpaceIndex($html);
  if ($i === -1) {
    return ['html' => '', 'closing' => ($html[strlen($html) - 2] ?? '') === '/'];
  }
  $html = trim(substr($html, $i + 1, -1));
  $isClosing = $html !== '' && $html[strlen($html) - 1] === '/';
  if ($isClosing) {
    $html = trim(substr($html, 0, -1));
  }
  return ['html' => $html, 'closing' => $isClosing];
}

function xssFindNextEqual(string $str, int $i): int
{
  $len = strlen($str);
  for (; $i < $len; $i++) {
    if ($str[$i] === ' ') {
      continue;
    }
    return $str[$i] === '=' ? $i : -1;
  }
  return -1;
}

function xssFindNextQuotationMark(string $str, int $i): int
{
  $len = strlen($str);
  for (; $i < $len; $i++) {
    if ($str[$i] === ' ') {
      continue;
    }
    return ($str[$i] === "'" || $str[$i] === '"') ? $i : -1;
  }
  return -1;
}

function xssFindBeforeEqual(string $str, int $i): int
{
  for (; $i > 0; $i--) {
    if ($str[$i] === ' ') {
      continue;
    }
    return $str[$i] === '=' ? $i : -1;
  }
  return -1;
}

function xssStripQuoteWrap(string $text): string
{
  $len = strlen($text);
  if (
    $len >= 2
    && (($text[0] === '"' && $text[$len - 1] === '"') || ($text[0] === "'" && $text[$len - 1] === "'"))
  ) {
    return substr($text, 1, $len - 2);
  }
  return $text;
}

function xssParseAttr(string $html, callable $onAttr): string
{
  $html = preg_replace('/\s|\n|\t/', ' ', $html);
  $lastPos = 0;
  $lastMarkPos = 0;
  $retAttrs = [];
  $tmpName = false;
  $len = strlen($html);

  $addAttr = function (string $name, string $value = '') use (&$retAttrs, $onAttr): void {
    $name = strtolower(preg_replace('/[^a-zA-Z0-9\\\\_:.\-]/i', '', trim($name)));
    if ($name === '') {
      return;
    }
    $ret = $onAttr($name, $value);
    if ($ret !== null && $ret !== '') {
      $retAttrs[] = $ret;
    }
  };

  for ($i = 0; $i < $len; $i++) {
    $c = $html[$i];
    if ($tmpName === false && $c === '=') {
      $tmpName = substr($html, $lastPos, $i - $lastPos);
      $lastPos = $i + 1;
      $next = $html[$lastPos] ?? '';
      $lastMarkPos = ($next === '"' || $next === "'") ? $lastPos : xssFindNextQuotationMark($html, $i + 1);
      continue;
    }
    if ($tmpName !== false && $i === $lastMarkPos) {
      $j = strpos($html, $c, $i + 1);
      if ($j === false) {
        break;
      }
      $addAttr($tmpName, trim(substr($html, $lastMarkPos + 1, $j - $lastMarkPos - 1)));
      $tmpName = false;
      $i = $j;
      $lastPos = $i + 1;
      continue;
    }
    if (preg_match('/\s|\n|\t/', $c)) {
      if ($tmpName === false) {
        $j = xssFindNextEqual($html, $i);
        if ($j === -1) {
          $addAttr(trim(substr($html, $lastPos, $i - $lastPos)));
          $lastPos = $i + 1;
          continue;
        }
        $i = $j - 1;
        continue;
      }
      $j = xssFindBeforeEqual($html, $i - 1);
      if ($j === -1) {
        $addAttr($tmpName, xssStripQuoteWrap(trim(substr($html, $lastPos, $i - $lastPos))));
        $tmpName = false;
        $lastPos = $i + 1;
      }
    }
  }

  if ($lastPos < strlen($html)) {
    if ($tmpName === false) {
      $addAttr(trim(substr($html, $lastPos)));
    } else {
      $addAttr($tmpName, xssStripQuoteWrap(trim(substr($html, $lastPos))));
    }
  }

  return trim(implode(' ', $retAttrs));
}

function xssParseTag(string $html, callable $onTag): string
{
  $retHtml = '';
  $lastPos = 0;
  $tagStart = false;
  $quoteStart = false;
  $len = strlen($html);

  for ($currentPos = 0; $currentPos < $len; $currentPos++) {
    $c = $html[$currentPos];
    if ($tagStart === false) {
      if ($c === '<') {
        $tagStart = $currentPos;
      }
      continue;
    }
    if ($quoteStart === false) {
      if ($c === '<') {
        $retHtml .= xssEscapeHtml(substr($html, $lastPos, $currentPos - $lastPos));
        $tagStart = $currentPos;
        $lastPos = $currentPos;
        continue;
      }
      if ($c === '>' || $currentPos === $len - 1) {
        $retHtml .= xssEscapeHtml(substr($html, $lastPos, $tagStart - $lastPos));
        $currentHtml = substr($html, $tagStart, $currentPos + 1 - $tagStart);
        $retHtml .= $onTag($tagStart, strlen($retHtml), xssGetTagName($currentHtml), $currentHtml, str_starts_with($currentHtml, '</'));
        $lastPos = $currentPos + 1;
        $tagStart = false;
        continue;
      }
      if ($c === '"' || $c === "'") {
        for ($i = 1; $currentPos - $i >= 0; $i++) {
          $ic = $html[$currentPos - $i];
          if (trim($ic) === '') {
            continue;
          }
          if ($ic === '=') {
            $quoteStart = $c;
          }
          break;
        }
      }
    } elseif ($c === $quoteStart) {
      $quoteStart = false;
    }
  }

  if ($lastPos < $len) {
    $retHtml .= xssEscapeHtml(substr($html, $lastPos));
  }

  return $retHtml;
}

function xssStripCommentTag(string $html): string
{
  $ret = '';
  $lastPos = 0;
  $len = strlen($html);
  while ($lastPos < $len) {
    $i = strpos($html, '<!--', $lastPos);
    if ($i === false) {
      $ret .= substr($html, $lastPos);
      break;
    }
    $ret .= substr($html, $lastPos, $i - $lastPos);
    $j = strpos($html, '-->', $i);
    if ($j === false) {
      break;
    }
    $lastPos = $j + 3;
  }
  return $ret;
}

function xssFilter(string $html): string
{
  if ($html === '') {
    return '';
  }
  $whiteList = xssWhiteList();
  $html = xssStripCommentTag($html);
  return xssParseTag($html, function (int $sourcePosition, int $position, string $tag, string $tagHtml, bool $isClosing) use ($whiteList) {
    if (!array_key_exists($tag, $whiteList)) {
      return xssEscapeHtml($tagHtml);
    }
    if ($isClosing) {
      return "</{$tag}>";
    }
    $attrs = xssGetAttrs($tagHtml);
    $whiteAttrList = $whiteList[$tag];
    $attrsHtml = xssParseAttr($attrs['html'], function (string $name, string $value) use ($tag, $whiteAttrList) {
      if (!in_array($name, $whiteAttrList, true)) {
        return null;
      }
      $value = xssSafeAttrValue($tag, $name, $value);
      return $value !== '' ? $name . '="' . $value . '"' : $name;
    });
    $out = '<' . $tag;
    if ($attrsHtml !== '') {
      $out .= ' ' . $attrsHtml;
    }
    if ($attrs['closing']) {
      $out .= ' /';
    }
    return $out . '>';
  });
}

// ============================================================================
// RSS
// ============================================================================

function escapeXml(?string $value): string
{
  if ($value === null || $value === '') {
    return '';
  }
  return str_replace(
    ['&', '<', '>', '"', "'"],
    ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
    $value
  );
}

/** RFC 1123 date in GMT, matching JS Date.toUTCString(). */
function httpDate(string $iso): string
{
  $ts = strtotime($iso);
  if ($ts === false) {
    $ts = time();
  }
  return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
}

function serveFeed(array $m): never
{
  $settings = webSettings();
  $base = baseUrl();
  [$published] = publishedPostsAndTags();

  $lastBuild = count($published) > 0
    ? httpDate($published[0]['updated_at'] ?: $published[0]['created_at'])
    : httpDate(isoNow());

  $items = '';
  foreach (array_slice($published, 0, 20) as $post) {
    $postUrl = $base . '/post/' . $post['slug'];
    $items .= "\n    <item>"
      . "\n      <title>" . escapeXml($post['title']) . "</title>"
      . "\n      <link>" . $postUrl . "</link>"
      . "\n      <description><![CDATA[" . ($post['excerpt'] ?: $post['meta_description'] ?: '') . "]]></description>"
      . "\n      <pubDate>" . httpDate($post['published_at']) . "</pubDate>"
      . "\n      <guid isPermaLink=\"true\">" . $postUrl . "</guid>"
      . "\n    </item>";
  }

  $feedXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . "\n<rss version=\"2.0\">"
    . "\n  <channel>"
    . "\n    <title>" . escapeXml($settings['title'] ?: 'Writer ') . "</title>"
    . "\n    <link>" . $base . "</link>"
    . "\n    <description>" . escapeXml($settings['description'] ?: '') . "</description>"
    . "\n    <language>en-us</language>"
    . "\n    <lastBuildDate>" . $lastBuild . "</lastBuildDate>"
    . "\n    <generator>Writer</generator>" . $items
    . "\n  </channel>"
    . "\n</rss>";

  header('Content-Type: application/rss+xml; charset=utf-8');
  header('Cache-Control: public, max-age=3600');
  echo trim($feedXml);
  exit;
}

// ============================================================================
// GIST
// ============================================================================

function extractGistId(string $url): ?string
{
  if (preg_match('#gist\.github\.com/[^/]+/([a-f0-9]+)(?:\.js)?(?:\?.*)?$#i', $url, $m)) {
    return $m[1];
  }
  return null;
}

/** Fetch and JSON-decode a URL (10s timeout), or null on any failure. */
function httpGetJson(string $url): ?array
{
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_USERAGENT => 'Writer/1.0',
      CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
      return null;
    }
  } else {
    // Degraded fallback when ext-curl is missing: no status-code check.
    $ctx = stream_context_create(['http' => [
      'timeout' => 10,
      'ignore_errors' => true,
      'header' => "User-Agent: Writer/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
      return null;
    }
  }
  $data = json_decode((string) $body, true);
  return is_array($data) ? $data : null;
}

/** Cached gist lookup (1h TTL, gist_cache table), or null on failure. */
function fetchGistJson(string $gistId): ?array
{
  $db = getDb();
  $stmt = $db->prepare('SELECT payload, fetched_at FROM gist_cache WHERE gist_id = ?');
  $stmt->execute([$gistId]);
  $row = $stmt->fetch();
  if ($row !== false && (time() - (int) $row['fetched_at']) < GIST_CACHE_TTL) {
    $cached = json_decode($row['payload'], true);
    if (is_array($cached)) {
      return $cached;
    }
  }

  $gist = httpGetJson('https://api.github.com/gists/' . $gistId);
  if ($gist === null) {
    return null;
  }
  $db->prepare('INSERT OR REPLACE INTO gist_cache (gist_id, payload, fetched_at) VALUES (?, ?, ?)')
    ->execute([$gistId, json_encode($gist), time()]);
  return $gist;
}

/** Replace the first occurrence only (JS String.replace string semantics). */
function strReplaceFirst(string $search, string $replace, string $subject): string
{
  $pos = strpos($subject, $search);
  if ($pos === false) {
    return $subject;
  }
  return substr($subject, 0, $pos) . $replace . substr($subject, $pos + strlen($search));
}

function gistIconSvg(): string
{
  return <<<'WR_GIST_SVG'
        <svg class="gist-icon" viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true">
          <path d="M1 2.5A2.5 2.5 0 0 1 3.5 0h8.75a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-8.5A1.75 1.75 0 0 0 3 6.75v6.5a1.75 1.75 0 0 0 1.75 1.75h8.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-.75.75h-8.75A2.5 2.5 0 0 1 1 13.5v-11Z"/>
          <path d="M3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5ZM3 5.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5ZM3 7.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5ZM3 9.5a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 0 1h-6a.5.5 0 0 1-.5-.5ZM3 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5Z"/>
        </svg>
WR_GIST_SVG;
}

/**
 * Render one gist file block. Content is escaped, not highlighted: highlight.js
 * runs client-side from the CDN (see renderPage), so the markup only carries the
 * `hljs language-*` hooks.
 */
function renderGistFile(string $filename, string $content, ?string $language, string $gistUrl): string
{
  $langClass = ($language !== null && $language !== '') ? 'language-' . strtolower($language) : '';
  return "\n    <div class=\"gist-file\">"
    . "\n      <div class=\"gist-file-header\">\n"
    . gistIconSvg()
    . "\n        <span class=\"gist-filename\">" . h($filename) . "</span>"
    . "\n        <a href=\"" . h($gistUrl) . "\" target=\"_blank\" rel=\"noopener noreferrer\" class=\"gist-link\">View on GitHub &rarr;</a>"
    . "\n      </div>"
    . "\n      <div class=\"gist-file-body\">"
    . "\n        <pre><code class=\"hljs " . $langClass . "\">" . h($content) . "</code></pre>"
    . "\n      </div>"
    . "\n    </div>\n  ";
}

function renderGistError(string $gistId): string
{
  return "\n        <div class=\"gist-embed gist-error\">"
    . "\n          <p>Unable to load gist. <a href=\"https://gist.github.com/" . h($gistId) . "\" target=\"_blank\" rel=\"noopener noreferrer\">View it on GitHub &rarr;</a></p>"
    . "\n        </div>\n      ";
}

/** Replace gist `<script src>` tags with styled embeds (fetch + 1h cache). */
function renderGistsInHtml(string $html): string
{
  if (!preg_match_all(
    '#<script[^>]+src=["\'](https://gist\.github\.com/[^"\']+)["\'][^>]*></script>#i',
    $html,
    $matches,
    PREG_SET_ORDER
  )) {
    return $html;
  }

  $result = $html;
  foreach ($matches as $match) {
    $fullTag = $match[0];
    $gistId = extractGistId($match[1]);
    if ($gistId === null) {
      continue;
    }

    $gist = fetchGistJson($gistId);
    if ($gist === null) {
      $result = strReplaceFirst($fullTag, renderGistError($gistId), $result);
      continue;
    }

    $files = array_values((array) ($gist['files'] ?? []));
    if (count($files) === 0) {
      continue;
    }

    $gistUrl = ($gist['html_url'] ?? '') !== '' ? $gist['html_url'] : 'https://gist.github.com/' . $gistId;
    $rendered = '';
    foreach ($files as $file) {
      $rendered .= renderGistFile(
        (string) ($file['filename'] ?? ''),
        (string) ($file['content'] ?? ''),
        isset($file['language']) ? (string) $file['language'] : null,
        (string) $gistUrl
      );
    }
    $result = strReplaceFirst($fullTag, '<div class="gist-embed">' . $rendered . '</div>', $result);
  }

  return $result;
}

// ============================================================================
// OG IMAGES
// ============================================================================

function ogCachePath(string $slug): string
{
  return OG_DIR . '/' . $slug . '.png';
}

function clearOGCache(string $slug): void
{
  $path = ogCachePath($slug);
  if (is_file($path)) {
    unlink($path);
  }
}

/** Horizontal advance width of a string in the given font/size. */
function measureText(string $font, float $size, string $text): float
{
  $bbox = imagettfbbox($size, 0, $font, $text);
  return (float) abs($bbox[2] - $bbox[0]);
}

function wrapOgText(string $font, float $size, string $text, float $maxWidth): array
{
  $words = explode(' ', $text);
  $lines = [];
  $current = array_shift($words);
  foreach ($words as $word) {
    if (measureText($font, $size, $current . ' ' . $word) < $maxWidth) {
      $current .= ' ' . $word;
    } else {
      $lines[] = $current;
      $current = $word;
    }
  }
  $lines[] = $current;
  return $lines;
}

/** Shrink the title until it fits the available height (floor 28px). */
function pickOgFontSize(string $font, string $text, float $maxWidth, float $maxHeight, float $baseSize): array
{
  $size = $baseSize;
  $lines = wrapOgText($font, $size, $text, $maxWidth);
  $lineHeight = $size * 1.2;
  $totalHeight = count($lines) * $lineHeight;
  if ($totalHeight > $maxHeight && $size > 28) {
    return pickOgFontSize($font, $text, $maxWidth, $maxHeight, $size - 4);
  }
  return ['size' => $size, 'lines' => $lines, 'lineHeight' => $lineHeight, 'totalHeight' => $totalHeight];
}

/**
 * 1200x630 OG card (Nord: nord0 bg, nord6 title, nord4 byline, nord14 accent).
 * Returns the PNG bytes and writes them to $outputPath when provided.
 */
function generateOGImage(string $title, string $byline, ?string $outputPath = null): string
{
  $width = 1200;
  $height = 630;
  $padX = 80.0;
  $padY = 60.0;

  $img = imagecreatetruecolor($width, $height);
  $bg = imagecolorallocate($img, 0x2e, 0x34, 0x40);
  $titleColor = imagecolorallocate($img, 0xec, 0xef, 0xf4);
  $bylineColor = imagecolorallocate($img, 0xd8, 0xde, 0xe9);
  $accent = imagecolorallocate($img, 0xa3, 0xbe, 0x8c);
  imagefilledrectangle($img, 0, 0, $width, $height, $bg);
  imagefilledrectangle($img, 0, 0, $width, 4, $accent);

  $maxTextWidth = $width - $padX * 2;
  $bylineText = $byline !== '' ? 'by ' . $byline : '';
  $bylineFontSize = 24.0;
  $bylineHeight = $bylineText !== '' ? $bylineFontSize * 1.2 + 40 : 0.0;
  $availableTitleHeight = $height - $padY * 2 - $bylineHeight;

  $picked = pickOgFontSize(OG_FONT_BOLD, $title, $maxTextWidth, $availableTitleHeight, 60.0);
  $size = $picked['size'];
  $lineHeight = $picked['lineHeight'];
  $totalHeight = $picked['totalHeight'];

  $startY = ($height - ($totalHeight + $bylineHeight)) / 2;

  foreach ($picked['lines'] as $i => $line) {
    $lineWidth = measureText(OG_FONT_BOLD, $size, $line);
    $x = (int) round(($width - $lineWidth) / 2);
    $y = (int) round($startY + $i * $lineHeight + $size);
    imagettftext($img, $size, 0, $x, $y, $titleColor, OG_FONT_BOLD, $line);
  }

  if ($bylineText !== '') {
    $bylineWidth = measureText(OG_FONT, $bylineFontSize, $bylineText);
    $x = (int) round(($width - $bylineWidth) / 2);
    $y = (int) round($startY + $totalHeight + 40 + $bylineFontSize);
    imagettftext($img, $bylineFontSize, 0, $x, $y, $bylineColor, OG_FONT, $bylineText);
  }

  ob_start();
  imagepng($img);
  $buffer = (string) ob_get_clean();

  if ($outputPath !== null) {
    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
      mkdir($dir, 0777, true);
    }
    file_put_contents($outputPath, $buffer);
  }
  return $buffer;
}

function sendPng(string $bytes): never
{
  header('Content-Type: image/png');
  header('Cache-Control: public, max-age=86400');
  header('Content-Length: ' . strlen($bytes));
  echo $bytes;
  exit;
}

function sendPngFile(string $path): never
{
  header('Content-Type: image/png');
  header('Cache-Control: public, max-age=86400');
  header('Content-Length: ' . (string) filesize($path));
  readfile($path);
  exit;
}

function serveOgSite(array $m): never
{
  $cachePath = ogCachePath('site');
  if (is_file($cachePath)) {
    sendPngFile($cachePath);
  }
  $settings = webSettings();
  $byline = implode(' — ', array_filter([$settings['author'] ?? '', $settings['description'] ?? '']));
  sendPng(generateOGImage($settings['title'] ?: 'Writer ', $byline, $cachePath));
}

function serveOgPost(array $m): never
{
  $slug = urldecode($m[1]);
  // The slug becomes a cache filename; reject anything that could escape OG_DIR.
  if ($slug === '' || str_contains($slug, '..') || preg_match('#[/\\\\\x00]#', $slug)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
  }
  $cachePath = ogCachePath($slug);
  if (is_file($cachePath)) {
    sendPngFile($cachePath);
  }

  $post = dbFindPostBySlug($slug);
  if ($post === null || $post['status'] !== 'published') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
  }

  $settings = webSettings();
  $byline = ($settings['author'] ?? '') !== '' ? $settings['author'] : ($settings['title'] ?? '');
  sendPng(generateOGImage($post['title'], $byline, $cachePath));
}

// ============================================================================
// WEB: STYLES
// ============================================================================

function cssInline(): string
{
  return <<<'WRITER_CSS'
/* ============================================
   CSS Variables & Reset
   ============================================ */

:root {
    --font-heading:
        "Charter", "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif;
    --font-body:
        -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu,
        sans-serif;
    --font-mono:
        "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;

    --color-text: #2e3440;
    --color-text-light: #4c566a;
    --color-text-red: #bf616a;
    --color-border: #d8dee9;
    --color-accent: #a3be8c;
    --color-bg: #eceff4;
    --color-bg-secondary: #e5e9f0;

    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

html {
    font-size: 16px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    height: auto;
}

body {
    font-family: var(--font-body);
    background-color: var(--color-bg);
    color: var(--color-text);
    margin: 0;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    max-width: 100%;
}

a {
    color: inherit;
    text-decoration: none;
}

a.brand {
    margin: 0 !important;
    display: inline-block;

    font-weight: bolder;
    font-style: italic;
    font-family: serif;
    font-size: 1.05rem;
}

/* ============================================
   Layout & Container (Mobile Default)
   ============================================ */

.container {
    width: 100%;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

main {
    flex: 1;
    min-height: 100vh;
    padding: var(--spacing-lg) 0;
}

/* ============================================
   Header (Mobile Default)
   ============================================ */

.header {
    border-bottom: 1px solid var(--color-border);
    padding: var(--spacing-md) 0;
    background: var(--color-bg);
    position: sticky;
    top: 0;
    z-index: 100;
}

.header-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 var(--spacing-lg);
}

.logo {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    color: var(--color-text);
}

.header-author {
    font-size: 0.9rem;
    color: var(--color-text-light);
}

.reading-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: transparent;
}

.reading-progress-bar {
    height: 100%;
    width: 0%;
    background: linear-gradient(to left, #88c0d0, #b48ead);
    transition: width 50ms linear;
    position: relative;
    z-index: 1;
}

.reading-progress-bar.pulse::after {
    content: '';
    position: absolute;
    top: -4px;
    left: -4px;
    right: -4px;
    bottom: -4px;
    background: linear-gradient(to left, #b48ead, #88c0d0);
    border-radius: 4px;
    z-index: -1;
    opacity: 0;
    animation: progress-pulse 600ms ease-out;
}

@keyframes progress-pulse {
    0% { opacity: 0.5; transform: scale(1); }
    100% { opacity: 0; transform: scale(1.5); }
}

/* ============================================
   Footer (Mobile Default)
   ============================================ */

.footer {
    border-top: 1px solid var(--color-border);
    padding: var(--spacing-lg) 0;
    text-align: center;
    font-size: 0.85rem;
    color: var(--color-text-light);
}

.footer a {
    margin: 0 0.75rem;
    transition: color 0.15s;
}

.footer a:hover {
    color: var(--color-text);
}

.footer p {
    margin: 0;
}

.footer .block + .block {
    margin-top: 8px;
}

/* ============================================
   Posts List - Layout (Mobile Default)
   ============================================ */

.posts-section {
    padding: var(--spacing-xl) 0;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 100%;
}

.posts-grid {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 100%;
    order: 2;
}

/* ============================================
   Sidebar Column (Mobile Default)
   ============================================ */

.sidebar-column {
    order: -1;
    width: 100%;
    max-width: 100%;
}

/* ============================================
   Sidebar (Mobile Default)
   ============================================ */

.sidebar {
    border-bottom: 1px solid var(--color-border);
    padding-bottom: var(--spacing-md);
    margin-bottom: var(--spacing-md);
    width: 100%;
    max-width: 100%;
    text-align: left;
}

.sidebar-title {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-text-light);
    margin-bottom: 0.75rem;
    text-align: left;
}

.post-about,
.search-box {
    margin-bottom: var(--spacing-lg);
}

.search-form {
    display: flex;
    align-items: center;
    gap: 0;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    overflow: hidden;
    background: var(--color-bg);
    transition:
        border-color 0.2s,
        box-shadow 0.2s;
}

.search-form:focus-within {
    border-color: var(--color-text);
    box-shadow: 0 0 0 2px rgba(41, 41, 41, 0.1);
}

.search-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    font-family: var(--font-body);
    color: var(--color-text);
    outline: none;
}

.search-input::placeholder {
    color: var(--color-text-light);
}

.search-button {
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    color: var(--color-text-light);
    transition: color 0.15s;
}

.search-button:hover {
    color: var(--color-text);
}

.search-header {
    margin-bottom: var(--spacing-lg);
    padding-bottom: var(--spacing-md);
    border-bottom: 1px solid var(--color-border);
}

.search-title {
    font-family: var(--font-heading);
    font-size: 1.4rem;
    font-weight: 600;
    margin: 0 0 var(--spacing-xs);
    line-height: 1.3;
}

.search-count {
    font-size: 0.85rem;
    color: var(--color-text-light);
}

.tag-list {
    --topics-toggle-width: 2.5rem;
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    position: relative;
    gap: var(--spacing-sm);
    justify-content: flex-start;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

/* ============================================
   Sidebar About (Mobile Default)
   ============================================ */

.about-box {
    display: none;
}

.about-box-mobile {
    order: 3;
    width: 100%;
    max-width: 100%;
    padding-top: var(--spacing-md);
    border-top: 1px solid var(--color-border);
    margin-top: var(--spacing-md);
}

.about-box-text {
    font-size: 0.9rem;
    color: var(--color-text-light);
    line-height: 1.5;
    margin: 0;
}

/* ============================================
   Tags (Mobile Default)
   ============================================ */

.tag {
    font-size: 0.75rem;
    color: var(--color-text-light);
    padding: 0.2rem 0.6rem;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    transition: all 0.15s;
    cursor: pointer;
}

.tag:hover,
.tag.is-active {
    background: var(--color-text);
    color: var(--color-bg);
    border-color: var(--color-text);
}

/* ============================================
   Topics Collapse (Mobile Default)
   ============================================ */

.tag-list.is-expanded {
    flex-wrap: wrap;
    overflow: visible;
}

.tag-list a.tag {
    flex: 0 0 auto;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tag-list.is-expanded a.tag {
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    overflow-wrap: break-word;
}

.tag-list::after {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: calc(var(--topics-toggle-width) * 2);
    background: linear-gradient(
        to right,
        transparent,
        var(--color-bg) var(--topics-toggle-width)
    );
    pointer-events: none;
}

.tag-list.is-expanded::after {
    display: none;
}

.tag-list-toggle {
    position: absolute;
    top: 50%;
    right: 0;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: var(--topics-toggle-width);
    min-width: var(--topics-toggle-width);
    padding: 0.2rem 0.6rem;
    background: var(--color-bg);
    border-style: solid;
    border-color: var(--color-text-light);
    color: var(--color-text);
    font-family: inherit;
    line-height: inherit;
    transform: translateY(-50%);
}

.tag-list.is-expanded .tag-list-toggle {
    position: static;
    width: auto;
    transform: none;
}

.tag-list-toggle[hidden] {
    display: none;
}

.tag-list-toggle .tag-list-less,
.tag-list.is-expanded .tag-list-more,
.tag-list.is-expanded .tag-list-more-text {
    display: none;
}

.tag-list.is-expanded .tag-list-toggle .tag-list-less {
    display: inline;
}

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
    border: 0;
}

/* ============================================
   Post Card (Mobile Default)
   ============================================ */

.post-card {
    padding: var(--spacing-lg) 0;
    border-bottom: 1px solid var(--color-border);
    display: block;
    width: 100%;
    max-width: 100%;
}

.post-card:first-child {
    padding-top: 0;
}

.post-card:last-child {
    border-bottom: none;
}

.post-card-image {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: var(--spacing-md);
    background: var(--color-bg-secondary);
}

.post-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}

.post-card-image:hover img {
    transform: scale(1.03);
}

.post-card .author-row {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-sm);
}

.author-avatar {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--color-text);
    color: var(--color-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 600;
}

.author-name {
    font-size: 0.9rem;
    color: var(--color-text);
}

.post-title {
    font-family: var(--font-heading);
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1.25;
    letter-spacing: -0.02em;
    margin-bottom: var(--spacing-sm);
    display: block;
    transition: color 0.15s;
}

.post-title:hover {
    color: var(--color-text-light);
}

.post-excerpt {
    font-size: 1rem;
    color: var(--color-text-light);
    line-height: 1.6;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.post-meta {
    font-size: 0.85rem;
    color: var(--color-text-light);
}

.post-meta .separator {
    margin: 0 0.4rem;
}

/* ============================================
   Empty State (Mobile Default)
   ============================================ */

.empty-state {
    text-align: center;
    padding: 4rem 1rem;
}

.empty-state h2 {
    font-family: var(--font-heading);
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: var(--spacing-md);
}

.empty-state p {
    color: var(--color-text-light);
    margin-bottom: var(--spacing-md);
}

.empty-state pre {
    background: var(--color-bg-secondary);
    padding: var(--spacing-md);
    border-radius: 4px;
    text-align: left;
    overflow-x: auto;
    font-size: 0.8rem;
    display: inline-block;
    font-family: var(--font-mono);
}

.empty-state .hint {
    font-size: 0.9rem;
    color: var(--color-text-light);
}

/* ============================================
   Post Detail (Mobile Default)
   ============================================ */

.post-detail-section {
    padding: var(--spacing-xl) 0;
    max-width: 680px;
    margin: 0 auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 180px);
}

.post-detail-section article {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.post-detail-section .content {
    flex: 1;
    min-height: 300px;
}

.post-detail-section .post-nav {
    margin-top: auto;
}

.post-cover-image {
    margin-left: 0;
    margin-right: 0;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: rgba(0, 0, 0, 0.2) 0 6px 12px;
}

.post-cover-image img {
    width: 100%;
    height: auto;
    display: block;
}

.post-detail-title {
    font-family: var(--font-heading);
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.02em;
    margin-bottom: var(--spacing-md);
}

.post-detail-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-md);
    font-size: 0.9rem;
    color: var(--color-text-light);
}

.author-detail {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.author-detail .author-avatar {
    width: 28px;
    height: 28px;
    font-size: 0.85rem;
}

.post-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: var(--spacing-md);
}

.post-tags .tag {
    font-size: 0.8rem;
    padding: 0.2rem 0.6rem;
}

.preview-banner {
    margin-bottom: var(--spacing-xl);
    text-align: center;
    font-weight: 500;
}

/* ============================================
   Post Content Typography (Mobile Default)
   ============================================ */

.content {
    font-family: var(--font-body);
    font-size: 1.05rem;
    line-height: 1.7;
}

.content h1,
.content h2,
.content h3 {
    font-family: var(--font-heading);
    font-weight: 600;
    margin: 2rem 0 0.75rem;
    line-height: 1.3;
}

.content h1 {
    font-size: 1.8rem;
}
.content h2 {
    font-size: 1.5rem;
}
.content h3 {
    font-size: 1.25rem;
}

.content p {
    margin-bottom: 1.25rem;
}

.content a {
    text-decoration: none;
    background-image: linear-gradient(var(--color-text), var(--color-text));
    background-size: 100% 1px;
    background-position: 0 100%;
    background-repeat: no-repeat;
}

.content a:hover {
    color: var(--color-text-light);
}

.content img {
    max-width: 100%;
    height: auto;
    margin: 1.5rem 0;
    border-radius: 4px;
    box-shadow: rgba(0, 0, 0, 0.2) 0 6px 12px;
}

.content blockquote {
    border-left: 3px solid var(--color-text);
    padding-left: 1.2rem;
    margin: 1.5rem 0;
    font-style: italic;
    color: var(--color-text-light);
}

.content pre {
    background: var(--color-bg-secondary);
    padding: 1rem 1.25rem;
    border-radius: 6px;
    overflow-x: auto;
    margin: 1.5rem 0;
    font-family: var(--font-mono);
    font-size: 0.85rem;
    line-height: 1.6;
    border: 1px solid var(--color-border);
}

.content code {
    font-family: var(--font-mono);
    background: var(--color-bg-secondary);
    padding: 0.12rem 0.35rem;
    border-radius: 3px;
    font-size: 0.88em;
    color: var(--color-text-red);
}

.content pre code {
    background: none;
    padding: 0;
    color: inherit;
}

.content ul,
.content ol {
    margin: 1.25rem 0;
    padding-left: 1.5rem;
}

.content li {
    margin-bottom: 0;
}

.content li > *:first-child {
    margin-top: 0;
}

.content li > *:last-child {
    margin-bottom: 0;
}

.content li p {
    margin: 0;
}

.content hr {
    border: none;
    border-top: 1px solid var(--color-border);
    margin: 2.5rem 0;
}

/* ============================================
   Markdown Tables (Bulma-inspired)
   ============================================ */

.content table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.5rem 0;
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: 4px;
    overflow: hidden;
    font-size: 0.95em;
}

.content table thead {
    background: var(--color-bg-secondary);
    border-bottom: 2px solid var(--color-border);
}

.content table thead th {
    padding: 0.75rem 1rem;
    font-weight: 600;
    text-align: left;
    color: var(--color-text);
    border-bottom: 2px solid var(--color-border);
    vertical-align: bottom;
}

.content table tbody tr {
    border-bottom: 1px solid var(--color-border);
    transition: background-color 0.15s ease;
}

.content table tbody tr:last-child {
    border-bottom: none;
}

.content table tbody tr:hover {
    background: var(--color-bg-secondary);
}

.content table tbody td {
    padding: 0.75rem 1rem;
    vertical-align: top;
    border-bottom: 1px solid var(--color-border);
    color: var(--color-text);
}

.content table tbody tr:last-child td {
    border-bottom: none;
}

.content table tbody td strong,
.content table tbody td b {
    color: var(--color-text);
}

/* Gist embed styling */
.gist-embed {
    margin: 1.75rem 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.gist-file {
    border-radius: 12px;
    overflow: hidden;
    background: #eceff4;
    border: 1px solid #d8dee9;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.06),
        0 4px 12px rgba(0, 0, 0, 0.04);
    transition: box-shadow 0.2s ease;
}

.gist-file:hover {
    box-shadow:
        0 2px 6px rgba(0, 0, 0, 0.08),
        0 8px 20px rgba(0, 0, 0, 0.06);
}

.gist-file-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1rem;
    background: #e5e9f0;
    border-bottom: 1px solid #d8dee9;
    font-size: 0.8rem;
}

.gist-icon {
    color: #4c566a;
    flex-shrink: 0;
}

.gist-filename {
    color: #2e3440;
    font-weight: 600;
    font-family: var(--font-mono);
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gist-link {
    color: #5e81ac;
    text-decoration: none;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
    background-image: none !important;
    transition: color 0.15s ease;
}

.gist-link:hover {
    color: #4a6b91;
    text-decoration: underline;
}

.gist-file-body pre {
    margin: 0;
    padding: 1rem 1.25rem;
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    overflow-x: hidden;
    white-space: pre-wrap;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.gist-file-body pre code.hljs {
    padding: 0;
    background: transparent;
    color: #2e3440;
    font-family: var(--font-mono);
    font-size: 0.82rem;
    line-height: 1.65;
    tab-size: 2;
    white-space: pre-wrap;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.gist-error {
    background: var(--color-bg-secondary);
    border: 1px dashed var(--color-border);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    color: var(--color-text-light);
    font-size: 0.85rem;
}

.gist-error a {
    color: var(--color-text);
    font-weight: 500;
}

.post-nav {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
    margin-top: var(--spacing-2xl);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--color-border);
}

.post-nav a {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.95rem;
    font-weight: 500;
    max-width: 100%;
}

.post-nav .nav-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.post-nav .nav-arrow {
    flex-shrink: 0;
}

.post-nav .nav-prev {
    text-align: left;
}

.post-nav .nav-next {
    text-align: right;
    margin-left: auto;
}

/* ============================================
   Form Section (Mobile Default)
   ============================================ */

.form-section {
    padding: 0 0 var(--spacing-2xl);
    padding-left: var(--spacing-md);
    padding-right: var(--spacing-md);
    margin: 0 auto;
}

.form-section .title {
    font-family: var(--font-heading);
    font-size: 2.2rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin-bottom: 0.5rem;
}

.form-section .subtitle {
    font-size: 1.05rem;
    color: var(--color-text-light);
    margin-bottom: var(--spacing-xl);
}

.field {
    margin-bottom: 1.25rem;
}

.field .label {
    display: block;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: var(--spacing-sm);
}

/* ============================================
   Tiptap Editor
   ============================================ */

.tiptap-editor {
    border: 1px solid var(--color-border);
    border-radius: 4px;
    overflow: hidden;
}

.tiptap-editor .tiptap-content {
    min-height: 300px;
    padding: 0.75rem;
    outline: none;
    font-family: var(--font-body);
    font-size: 1rem;
    line-height: 1.6;
}

.tiptap-editor .tiptap-content p {
    margin-bottom: 0.75rem;
}

.tiptap-editor .tiptap-content h1 {
    font-family: var(--font-heading);
    font-size: 1.8rem;
    font-weight: 600;
    margin: 1.5rem 0 0.75rem;
}

.tiptap-editor .tiptap-content h2 {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    font-weight: 600;
    margin: 1.25rem 0 0.5rem;
}

.tiptap-editor .tiptap-content h3 {
    font-family: var(--font-heading);
    font-size: 1.25rem;
    font-weight: 600;
    margin: 1rem 0 0.5rem;
}

.tiptap-editor .tiptap-content ul,
.tiptap-editor .tiptap-content ol {
    padding-left: 1.5rem;
    margin-bottom: 0.75rem;
}

.tiptap-editor .tiptap-content li {
    margin-bottom: 0.25rem;
}

.tiptap-editor .tiptap-content blockquote {
    border-left: 3px solid var(--color-text);
    padding-left: 1rem;
    margin: 1rem 0;
    font-style: italic;
    color: var(--color-text-light);
}

.tiptap-editor .tiptap-content pre {
    background: var(--color-bg-secondary);
    padding: 0.75rem 1rem;
    border-radius: 4px;
    font-family: var(--font-mono);
    font-size: 0.85rem;
    overflow-x: auto;
    margin: 1rem 0;
    border: 1px solid var(--color-border);
}

.tiptap-editor .tiptap-content code {
    font-family: var(--font-mono);
    background: var(--color-bg-secondary);
    padding: 0.15rem 0.35rem;
    border-radius: 3px;
    font-size: 0.88em;
    color: var(--color-text-red);
}

.tiptap-editor .tiptap-content pre code {
    background: none;
    padding: 0;
    color: inherit;
}

.tiptap-editor .tiptap-content img {
    max-width: 100%;
    width: 100%;
    max-height: 600px;
    height: auto;
    object-fit: contain;
    border-radius: 4px;
    margin: 1rem 0;
}

.tiptap-editor .tiptap-content a {
    color: var(--color-accent);
    text-decoration: underline;
}

.tiptap-editor .tiptap-content hr {
    border: none;
    border-top: 1px solid var(--color-border);
    margin: 1.5rem 0;
}

.tiptap-editor .tiptap-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    font-size: 0.95rem;
}

.tiptap-editor .tiptap-content th,
.tiptap-editor .tiptap-content td {
    border: 1px solid var(--color-border);
    padding: 0.5rem 0.75rem;
    text-align: left;
}

.tiptap-editor .tiptap-content th {
    background: var(--color-bg-secondary);
    font-weight: 600;
}

.tiptap-editor .tiptap-content tr:nth-child(even) {
    background: var(--color-bg-secondary);
}

.tiptap-editor .tiptap-content p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: var(--color-text-light);
    pointer-events: none;
    height: 0;
}

.field input,
.field textarea,
.field select {
    width: 100%;
    padding: 0.75rem;
    font-size: 1rem;
    font-family: var(--font-body);
    border: 1px solid var(--color-border);
    border-radius: 4px;
    transition:
        border-color 0.2s,
        box-shadow 0.2s;
    background: var(--color-bg);
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    outline: none;
    border-color: var(--color-text);
    box-shadow: 0 0 0 2px rgba(41, 41, 41, 0.1);
}

.field textarea {
    min-height: 150px;
    resize: vertical;
}

.field .checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    cursor: pointer;
}

.field .checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
    cursor: pointer;
}

.button {
    display: inline-block;
    padding: 0.6rem 1.25rem;
    font-size: 0.95rem;
    font-weight: 500;
    font-family: var(--font-body);
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition:
        background-color 0.2s,
        transform 0.1s;
}

.button.is-primary {
    background: var(--color-text);
    color: var(--color-bg);
}

.button.is-primary:hover {
    background: #4c566a;
}

.button.is-primary:active {
    transform: scale(0.98);
}

.button.is-small {
    font-size: 0.85rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
}

.button.is-outline {
    background: transparent;
    border: 1px solid var(--color-border);
    color: var(--color-text);
}

.button.is-outline:hover {
    border-color: var(--color-text);
    background: var(--color-text);
    color: var(--color-bg);
}

.button.is-danger {
    background: #bf616a;
    color: white;
}

.button.is-danger:hover {
    background: #8e3b44;
}

/* ============================================
   Result Box (Mobile Default)
   ============================================ */

.result-box {
    padding: var(--spacing-lg);
    background: var(--color-bg-secondary);
    border-radius: 4px;
    margin-top: var(--spacing-lg);
}

.result-box h2,
.result-box h3,
.result-box h4 {
    margin-bottom: 0.75rem;
    font-family: var(--font-heading);
    font-size: 1rem;
    font-weight: 600;
}

.result-box h2 {
    font-size: 1.4rem;
}

.result-box pre {
    padding: var(--spacing-md);
    border-radius: 4px;
    overflow-x: auto;
    margin: 0.75rem 0 0;
    font-family: var(--font-mono);
    font-size: 0.8rem;
    background: var(--color-bg);
    border: 1px solid var(--color-border);
}

.token-warning {
    font-weight: 500;
    margin: var(--spacing-md) 0;
    color: #bf616a;
}

/* The token note sits directly above its save warning (no gap between them). */
.token-usage {
    margin-bottom: 0;
}

.onboarding-result .token-warning {
    margin-top: 0;
}

.error-message {
    color: #bf616a;
    font-size: 0.9rem;
    margin-top: var(--spacing-sm);
}

.help {
    font-size: 0.8rem;
    color: var(--color-text-light);
    margin-top: 0.25rem;
}

/* ============================================
   Docs Section (Mobile Default)
   ============================================ */

.docs-section {
    padding: 2.5rem 0;
    max-width: 720px;
    margin: 0 auto;
}

.docs-section h1.title {
    font-family: var(--font-heading);
    font-size: 2.5rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin-bottom: var(--spacing-md);
}

.docs-section .intro {
    font-size: 1.1rem;
    color: var(--color-text-light);
    margin-bottom: 2.5rem;
    line-height: 1.6;
}

.docs-section h2.title {
    font-family: var(--font-heading);
    font-size: 1.6rem;
    font-weight: 600;
    margin-bottom: var(--spacing-md);
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border);
}

.docs-section h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 1.5rem 0 0.5rem;
}

.docs-section p {
    margin-bottom: 0.75rem;
    line-height: 1.6;
}

.docs-section ul {
    margin-left: 1.5rem;
    margin-bottom: 0.75rem;
}

.docs-section li {
    margin-bottom: 0.25rem;
    line-height: 1.5;
}

.docs-section pre {
    background: var(--color-bg-secondary);
    padding: var(--spacing-md);
    border-radius: 4px;
    overflow-x: auto;
    margin: 0.75rem 0 1rem;
    font-family: var(--font-mono);
    font-size: 0.85rem;
}

.docs-section code {
    font-family: var(--font-mono);
    font-size: 0.9em;
}

.endpoint {
    margin-bottom: 1.5rem;
}

.method-tag {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: 700;
    font-family: var(--font-mono);
    margin-right: var(--spacing-sm);
    background: var(--color-accent);
    color: white;
}

.method-tag.is-get {
    background: #81a1c1;
}
.method-tag.is-post {
    background: #a3be8c;
}
.method-tag.is-put {
    background: #d08770;
}
.method-tag.is-delete {
    background: #bf616a;
}

.docs-subsection {
    margin-bottom: 2rem;
}

.docs-subsection .title {
    font-family: var(--font-heading);
    font-size: 1.4rem;
    font-weight: 600;
    margin: 1.5rem 0 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border);
}

/* ============================================
   Panel / Admin Layout (Mobile Default)
   ============================================ */

.panel-layout {
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 60px);
}

.panel-nav {
    background: var(--color-text);
    color: var(--color-bg);
    padding: 0 var(--spacing-lg);
}

.panel-nav-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    height: 50px;
    padding: 0;
}

.panel-logo {
    font-family: var(--font-heading);
    font-size: 1.1rem;
    font-weight: 600;
}

.panel-nav-links {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.panel-nav-links a {
    font-size: 0.9rem;
    opacity: 0.8;
    transition: opacity 0.15s;
}

.panel-nav-links a:hover,
.panel-nav-links a.is-active {
    opacity: 1;
}

.panel-nav-links .button-link {
    background: none;
    border: none;
    color: inherit;
    font-size: 0.9rem;
    cursor: pointer;
    opacity: 0.8;
    padding: 0;
    transition: opacity 0.15s;
}

.panel-nav-links .button-link:hover {
    opacity: 1;
}

.panel-content {
    flex: 1;
    max-width: 1100px;
    width: 100%;
    margin: 0 auto;
    padding: var(--spacing-md);
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--spacing-lg);
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.panel-title {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    font-weight: 600;
}

.panel-actions {
    display: flex;
    gap: var(--spacing-sm);
}

.panel-search-form {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-lg);
}

.panel-search-input {
    flex: 1;
    min-width: 0;
    padding: 0.5rem 0.75rem;
    font-size: 0.95rem;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    background: var(--color-bg);
    color: var(--color-text);
    font-family: var(--font-body);
}

.panel-search-input:focus {
    outline: none;
    border-color: var(--color-text-light);
}

.panel-search-button {
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
    font-weight: 500;
    background: var(--color-text);
    color: var(--color-bg);
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-family: var(--font-body);
    transition: opacity 0.15s;
}

.panel-search-button:hover {
    opacity: 0.85;
}

.panel-search-clear {
    font-size: 0.9rem;
    color: var(--color-text-light);
    transition: color 0.15s;
}

.panel-search-clear:hover {
    color: var(--color-text);
}

/* ============================================
   Post Management (Mobile Default)
   ============================================ */

.post-management-section {
    padding: 2.5rem 0;
    max-width: 900px;
    margin: 0 auto;
}

.management-card {
    border: 1px solid var(--color-border);
    border-radius: 4px;
    padding: 1.25rem;
    transition: all 0.15s ease-in-out;
}

.management-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

.management-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: var(--spacing-sm);
    line-height: 1.3;
}

.management-card-excerpt {
    font-size: 0.9rem;
    color: var(--color-text-light);
    margin-bottom: 0.75rem;
    line-height: 1.5;
}

.post-status {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 0.75rem;
}

.post-status.is-published {
    background: #e4eee2;
    color: #5e7a52;
}

.post-status.is-draft {
    background: #e5e9f0;
    color: #4c566a;
}

.card-actions {
    display: flex;
    gap: var(--spacing-sm);
}

/* ============================================
   Posts Table (Mobile Default)
   ============================================ */

.settings-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--spacing-md);
}

.post-list {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: 4px;
}

.empty-list {
    padding: 3rem;
    text-align: center;
    color: var(--color-text-light);
}

.posts-table {
    width: 100%;
    border-collapse: collapse;
}

.posts-table th,
.posts-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.posts-table th {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-light);
    background: var(--color-bg-secondary);
    position: sticky;
    top: 77px;
    z-index: 1;
}

.posts-table tr:last-child td {
    border-bottom: none;
}

.posts-table tr:hover td {
    background: var(--color-bg-secondary);
}

.post-title-cell a {
    font-weight: 500;
    cursor: pointer;
}

.post-title-cell a:hover {
    color: var(--color-text-light);
}

.post-date-cell {
    color: var(--color-text-light);
    font-size: 0.85rem;
}

.posts-table th.actions-cell,
.posts-table td.actions-cell {
    white-space: nowrap;
    text-align: right;
}

.btn-view,
.btn-edit,
.btn-delete {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    cursor: pointer;
    border: 1px solid var(--color-border);
    background: var(--color-bg);
    transition: all 0.15s;
}

.btn-view:hover,
.btn-edit:hover {
    border-color: var(--color-text);
    background: var(--color-text);
    color: var(--color-bg);
}

.btn-delete {
    color: #bf616a;
    border-color: #e8cdd0;
}

.btn-delete:hover {
    background: #bf616a;
    color: white;
    border-color: #bf616a;
}

/* ============================================
   Edit Form (Mobile Default)
   ============================================ */

.edit-form {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: 4px;
    padding: var(--spacing-lg);
}

.form-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--spacing-lg);
}

.form-header h3 {
    font-family: var(--font-heading);
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.btn-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    color: var(--color-text-light);
}

.btn-close:hover {
    color: var(--color-text);
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: var(--spacing-md);
}

#loginForm {
    max-width: 600px;
    margin: 0 auto;
}

/* ============================================
   Utilities (Mobile Default)
   ============================================ */

.has-text-centered {
    text-align: center;
}

.mb-3,
.mb-4,
.mb-5 {
    margin-bottom: 1rem;
}

.mb-5 {
    margin-bottom: 2rem;
}

.mt-2,
.mt-3,
.mt-4 {
    margin-top: 1rem;
}

.mt-4 {
    margin-top: var(--spacing-lg);
}

.ml-2 {
    margin-left: var(--spacing-sm);
}

.box {
    border: 1px solid var(--color-border);
    border-radius: 4px;
    padding: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
}

.box h3 {
    margin: 0 0 var(--spacing-lg) !important;
}

.box.is-fullheight {
    display: flex;
    flex-direction: column;
}

.box.is-fullheight form {
    width: 100%;
    height: 100%;
    display: flex;
    flex: 1;
    flex-direction: column;
    justify-content: space-between;
}

.notification {
    padding: var(--spacing-md);
    border-radius: 4px;
    margin-bottom: var(--spacing-md);
}

.notification.is-danger {
    background: #f2e0e2;
    color: #bf616a;
    border: 1px solid #e8cdd0;
}

.title.is-4 {
    font-family: var(--font-heading);
    font-size: 1.4rem;
    font-weight: 600;
}

.title.is-5 {
    font-family: var(--font-heading);
    font-size: 1.2rem;
    font-weight: 600;
}

.is-fullwidth {
    width: 100%;
}

/* ============================================
   Error Page
   ============================================ */

.error-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: calc(100vh - 200px);
    padding: var(--spacing-xl) 0;
}

.error-page-content {
    text-align: center;
}

.error-code {
    font-family: var(--font-heading);
    font-size: 6rem;
    font-weight: 700;
    color: var(--color-border);
    margin: 0;
    line-height: 1;
}

.error-page-content h2 {
    font-family: var(--font-heading);
    font-size: 1.75rem;
    font-weight: 600;
    margin: var(--spacing-md) 0 var(--spacing-sm);
}

.error-page-content p {
    color: var(--color-text-light);
    margin-bottom: var(--spacing-lg);
}

/* ============================================
   Desktop Enhancements (min-width: 769px)
   ============================================ */

@media (min-width: 769px) {
    /* Layout */
    html {
        font-size: 18px;
    }

    .container {
        max-width: 1100px;
        padding: 0 1.5rem;
    }

    /* Posts List - Two Column Layout */
    .posts-section {
        display: grid;
        grid-template-columns: 1fr 240px;
        align-items: start;
        gap: 2rem;
        min-height: 100vh;
    }

    main {
        min-height: 100vh;
    }

    .posts-grid {
        order: 1;
    }

    .sidebar-column {
        order: 2;
        position: sticky;
        align-self: start;
        width: 100%;
        max-width: 240px;
        height: fit-content;
        top: 76px;
    }

    .sidebar {
        text-align: right;
        border-bottom: none;
        padding-bottom: 0;
        margin-bottom: 0;
    }

    .sidebar-title {
        font-size: 0.7rem;
        text-align: right;
    }

    .topics-box {
        margin-top: 64px;
    }

    .tag-list {
        flex-wrap: wrap;
        justify-content: flex-end;
        overflow: visible;
        position: static;
    }

    .tag-list::after,
    .tag-list-toggle {
        display: none;
    }

    .search-form {
        flex-direction: row;
    }

    .search-input {
        text-align: right;
        padding: 0.5rem 0.75rem;
    }

    .about-box {
        display: block;
        text-align: right;
        border-top: none;
        margin-top: 64px;
        padding-top: 0;
    }

    .about-box-mobile {
        display: none;
    }

    .about-box-text {
        font-size: 0.85rem;
    }

    /* Post Detail */
    .post-detail-title {
        font-size: 2.5rem;
    }

    .content {
        font-size: 1.15rem;
    }

    /* Post Navigation */
    .post-about {
        margin: 64px 0;
        padding: var(--spacing-lg) 0;
        border-top: 1px solid var(--color-border);
        text-align: left;
    }

    .post-about .sidebar-title {
        text-align: left;
    }

    .post-nav {
        flex-direction: row;
    }

    .post-nav a {
        max-width: 45%;
    }

    /* Form Section */
    .form-section {
        padding: 0 0 3rem;
    }

    /* Settings Grid */
    .settings-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .settings-grid .box.is-fullwidth {
        grid-column: span 2;
    }

    /* Panel */
    .panel-nav-inner {
        padding: 0;
    }

    .panel-content {
        padding: var(--spacing-lg);
    }

    /* Posts Table */
    .posts-table {
        display: table;
    }
}

/* ============================================
   Small Desktop Adjustments (min-width: 1100px)
   ============================================ */

@media (min-width: 1100px) {
    .header-inner,
    .panel-nav-inner {
        padding: 0;
    }
}

/* ============================================
   Pagination
   ============================================ */

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-lg) 0;
    margin-top: var(--spacing-lg);
}

.pagination-link {
    color: var(--color-accent);
    font-size: 0.75rem;
    font-weight: 500;
    padding: 2px 6px;
    transition: all 0.15s;
}

.pagination-link:hover {
    text-decoration: underline;
}

.pagination-info {
    color: var(--color-text-light);
    font-size: 0.7rem;
}

/* ============================================
   Modal
   ============================================ */

.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    position: relative;
    background: var(--color-bg);
    padding: 1.5rem;
    border-radius: 4px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.modal-content h3 {
    margin-top: 0;
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
}

.modal-content p {
    margin: 0;
    color: var(--color-text-light);
}

.modal-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    margin-top: 1.25rem;
}

/* ============================================
   Notification
   ============================================ */

.notification {
    padding: 0.75rem 1rem;
    border-radius: 4px;
    margin-bottom: var(--spacing-md);
    font-size: 0.9rem;
    border: 1px solid transparent;
    animation: fadeInSlide 0.2s ease-out;
}

.notification.is-success {
    background: #e2ede0;
    color: #4f6b45;
    border-color: #c3d9b8;
}

.notification.is-danger {
    background: #eed7da;
    color: #7a3b44;
    border-color: #e3b9bf;
}

.notification.is-warning {
    background: #f5eed8;
    color: #8a6d1f;
    border-color: #f0e3bf;
}

.notification-container {
    position: sticky;
    top: 0;
    z-index: 100;
    margin-bottom: var(--spacing-md);
}

.notification-container:empty {
    display: none;
}

@keyframes fadeInSlide {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

pre code.hljs {
  display: block;
  overflow-x: auto;
  padding: 1em
}
code.hljs {
  padding: 3px 5px
}
/*!
  Theme: GitHub
  Description: Light theme as seen on github.com
  Author: github.com
  Maintainer: @Hirse
  Updated: 2021-05-15

  Outdated base version: https://github.com/primer/github-syntax-light
  Current colors taken from GitHub's CSS
*/
.hljs {
  color: #2e3440;
  background: #eceff4
}
.hljs-doctag,
.hljs-keyword,
.hljs-meta .hljs-keyword,
.hljs-template-tag,
.hljs-template-variable,
.hljs-type,
.hljs-variable.language_ {
  /* prettylights-syntax-keyword */
  color: #bf616a
}
.hljs-title,
.hljs-title.class_,
.hljs-title.class_.inherited__,
.hljs-title.function_ {
  /* prettylights-syntax-entity */
  color: #b48ead
}
.hljs-attr,
.hljs-attribute,
.hljs-literal,
.hljs-meta,
.hljs-number,
.hljs-operator,
.hljs-variable,
.hljs-selector-attr,
.hljs-selector-class,
.hljs-selector-id {
  /* prettylights-syntax-constant */
  color: #5e81ac
}
.hljs-regexp,
.hljs-string,
.hljs-meta .hljs-string {
  /* prettylights-syntax-string */
  color: #81a1c1
}
.hljs-built_in,
.hljs-symbol {
  /* prettylights-syntax-variable */
  color: #d08770
}
.hljs-comment,
.hljs-code,
.hljs-formula {
  /* prettylights-syntax-comment */
  color: #4c566a
}
.hljs-name,
.hljs-quote,
.hljs-selector-tag,
.hljs-selector-pseudo {
  /* prettylights-syntax-entity-tag */
  color: #a3be8c
}
.hljs-subst {
  /* prettylights-syntax-storage-modifier-import */
  color: #2e3440
}
.hljs-section {
  /* prettylights-syntax-markup-heading */
  color: #5e81ac;
  font-weight: bold
}
.hljs-bullet {
  /* prettylights-syntax-markup-list */
  color: #8a6d1f
}
.hljs-emphasis {
  /* prettylights-syntax-markup-italic */
  color: #2e3440;
  font-style: italic
}
.hljs-strong {
  /* prettylights-syntax-markup-bold */
  color: #2e3440;
  font-weight: bold
}
.hljs-addition {
  /* prettylights-syntax-markup-inserted */
  color: #a3be8c;
  background-color: #e4eee2
}
.hljs-deletion {
  /* prettylights-syntax-markup-deleted */
  color: #8e3b44;
  background-color: #f2e0e2
}
.hljs-char.escape_,
.hljs-link,
.hljs-params,
.hljs-property,
.hljs-punctuation,
.hljs-tag {
  /* purposely ignored */
  
}
WRITER_CSS;
}

// ============================================================================
// WEB: RENDER
// ============================================================================

/** Format an ISO timestamp like Node's toLocaleDateString('en-US', {...}). */
function formatPostDate(string $iso): string
{
  if ($iso === '') {
    return 'Invalid Date';
  }
  try {
    return (new DateTime($iso))->format('M j, Y');
  } catch (Exception) {
    return 'Invalid Date';
  }
}

/** Settings shaped for templates; falls back to Node's minimal `{title:'Writer'}`. */
function webSettings(): array
{
  $row = settingsFetch();
  if ($row === null) {
    return [
      'title' => 'Writer',
      'author' => '',
      'description' => '',
      'about' => '',
      'custom_scripts' => '',
      'show_docs' => true,
      'onboarding_complete' => false,
    ];
  }
  return settingsToJson($row);
}

function viewHeader(array $settings): string
{
  $out = '<header class="header"><div class="header-inner">'
    . '<a class="logo" href="/">' . h($settings['title'] ?? '') . '</a>';
  if (jsTruthy($settings['author'] ?? '')) {
    $out .= '<span class="header-author">by ' . h($settings['author']) . '</span>';
  }
  return $out . '</div><div class="reading-progress"><div class="reading-progress-bar"></div></div></header>';
}

function viewFooter(array $settings): string
{
  $showDocs = ($settings['show_docs'] ?? null) !== false;
  $out = '<footer class="footer"><div class="container"><div class="block">'
    . '<p>&copy; ' . date('Y') . ' ' . h($settings['title'] ?? '') . '</p>'
    . '<p>Powered by <a href="https://github.com/abtz-Labs/writer" target="_blank" rel="noopener noreferrer" class="brand">Writer</a></p>'
    . '</div><div class="block"><a href="/panel">Panel</a>';
  if ($showDocs) {
    $out .= '<a href="/docs" target="_blank" rel="noopener noreferrer">API Docs</a>';
  }
  return $out
    . '<a href="/feed.xml" target="_blank" rel="noopener noreferrer">RSS Feed</a>'
    . '</div></div></footer>';
}

function viewSearchIcon(): string
{
  return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
}

function viewTopics(array $tags, string $activeTag, bool $hasActiveTag): string
{
  $out = '<div class="topics-box"><h3 class="sidebar-title">Topics</h3><div class="tag-list" id="tag-list">'
    . '<a href="/" class="tag' . ($hasActiveTag && $activeTag === '' ? ' is-active' : '') . '" data-filter="">All</a>';
  foreach ($tags as $tag) {
    $cls = $hasActiveTag && $activeTag === $tag ? ' is-active' : '';
    $out .= '<a href="/?tag=' . rawurlencode($tag) . '" class="tag' . $cls . '" data-filter="' . h($tag) . '">'
      . h(mb_strtoupper(mb_substr($tag, 0, 1)) . mb_substr($tag, 1)) . '</a>';
  }
  $out .= '<button type="button" class="tag tag-list-toggle" aria-controls="tag-list" aria-expanded="false">'
    . '<span class="tag-list-more" aria-hidden="true">&hellip;</span>'
    . '<span class="tag-list-more-text visually-hidden">Show all topics</span>'
    . '<span class="tag-list-less">Show less</span></button></div></div>';
  return $out . <<<'WR_TOPICS_JS'
<script>
    (function () {
        var list = document.getElementById("tag-list");
        var toggle = list && list.querySelector(".tag-list-toggle");
        if (!toggle) return;
        function sync() {
            toggle.hidden =
                !list.classList.contains("is-expanded") &&
                list.scrollWidth <= list.clientWidth;
        }
        toggle.addEventListener("click", function () {
            var expanded = list.classList.toggle("is-expanded");
            toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
            sync();
        });
        sync();
        window.addEventListener("resize", sync);
    })();
</script>
WR_TOPICS_JS;
}

function viewModals(): string
{
  return <<<'WR_MODALS'
<div id="confirmModal" class="modal" style="display: none">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <h3 id="modalTitle">Confirm</h3>
        <p id="modalMessage">Are you sure?</p>
        <div class="modal-actions">
            <button type="button" class="button is-outline" id="modalCancel">Cancel</button>
            <button type="button" class="button is-danger" id="modalConfirm">Confirm</button>
        </div>
    </div>
</div>

<div id="imageEditModal" class="modal" style="display: none">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <h3>Edit Image</h3>
        <form id="imageEditForm">
            <div class="field">
                <label class="label" for="imgSrc">URL</label>
                <input type="url" id="imgSrc" name="src" required>
            </div>
            <div class="field">
                <label class="label" for="imgAlt">Alt Text</label>
                <input type="text" id="imgAlt" name="alt">
            </div>
            <div class="field">
                <label class="label" for="imgTitle">Title</label>
                <input type="text" id="imgTitle" name="title">
            </div>
            <div class="modal-actions">
                <button type="button" class="button is-outline" id="imgModalCancel">Cancel</button>
                <button type="submit" class="button is-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById("confirmModal");
    const modalTitle = document.getElementById("modalTitle");
    const modalMessage = document.getElementById("modalMessage");
    const modalConfirmBtn = document.getElementById("modalConfirm");
    const modalCancelBtn = document.getElementById("modalCancel");
    let modalConfirmCallback = null;

    window.showConfirmModal = function ({
        title = "Confirm",
        message = "Are you sure?",
        confirmText = "Confirm",
        confirmClass = "is-danger",
        onConfirm,
    }) {
        modalTitle.textContent = title;
        modalMessage.textContent = message;
        modalConfirmBtn.textContent = confirmText;
        modalConfirmBtn.className = "button " + confirmClass;
        modalConfirmCallback = onConfirm;
        modal.style.display = "flex";
    };

    function hideModal() {
        modal.style.display = "none";
        modalConfirmCallback = null;
    }

    const imageModal = document.getElementById("imageEditModal");
    const imgSrcInput = document.getElementById("imgSrc");
    const imgAltInput = document.getElementById("imgAlt");
    const imgTitleInput = document.getElementById("imgTitle");
    const imgForm = document.getElementById("imageEditForm");
    let imageEditCallback = null;

    window.showImageEditModal = function ({ src = "", alt = "", title = "", onUpdate }) {
        imgSrcInput.value = src;
        imgAltInput.value = alt;
        imgTitleInput.value = title;
        imageEditCallback = onUpdate;
        imageModal.style.display = "flex";
        imgSrcInput.focus();
    };

    function hideImageModal() {
        imageModal.style.display = "none";
        imageEditCallback = null;
    }

    document.getElementById("imgModalCancel").addEventListener("click", hideImageModal);
    imageModal.querySelector(".modal-backdrop").addEventListener("click", hideImageModal);
    imgForm.addEventListener("submit", function (e) {
        e.preventDefault();
        if (imageEditCallback) {
            imageEditCallback({
                src: imgSrcInput.value,
                alt: imgAltInput.value,
                title: imgTitleInput.value
            });
        }
        hideImageModal();
    });

    modalConfirmBtn.addEventListener("click", function () {
        if (modalConfirmCallback) modalConfirmCallback();
        hideModal();
    });

    modalCancelBtn.addEventListener("click", hideModal);
    modal.querySelector(".modal-backdrop").addEventListener("click", hideModal);

    window.showNotification = function (message, type) {
        const container = document.querySelector(".panel-content .notification-container");
        if (!container) return;
        const el = document.createElement("div");
        el.className = "notification is-" + (type || "success");
        el.textContent = message;
        container.appendChild(el);
        setTimeout(function () {
            el.style.opacity = "0";
            el.style.transition = "opacity 0.3s";
            setTimeout(function () {
                el.remove();
            }, 300);
        }, 4000);
    };

    (function () {
        const flash = sessionStorage.getItem("panelFlash");
        if (flash) {
            try {
                const data = JSON.parse(flash);
                const container = document.querySelector(".panel-content .notification-container");
                if (container) {
                    showNotification(data.message, data.type);
                }
            } catch (e) {}
            sessionStorage.removeItem("panelFlash");
        }
    })();
})();
</script>
WR_MODALS;
}

function viewSearchBox(string $query): string
{
  return '<div class="search-box"><h3 class="sidebar-title">Search</h3>'
    . '<form action="/search" method="GET" class="search-form">'
    . '<input type="text" name="q" value="' . h($query) . '" placeholder="Search posts..." class="search-input" autocomplete="off" />'
    . '<button type="submit" class="search-button" aria-label="Search">' . viewSearchIcon() . '</button>'
    . '</form></div>';
}

function viewSidebar(array $tags, string $activeTag, bool $hasActiveTag, array $settings, string $query): string
{
  $out = '<div class="sidebar-column"><div class="sidebar">' . viewSearchBox($query);
  $out .= viewTopics($tags, $activeTag, $hasActiveTag);
  if (jsTruthy($settings['about'] ?? '')) {
    $out .= '<div class="about-box"><h3 class="sidebar-title">About the Author</h3>'
      . '<p class="about-box-text">' . h($settings['about']) . '</p></div>';
  }
  return $out . '</div></div>';
}

function viewAboutMobile(array $settings): string
{
  if (!jsTruthy($settings['about'] ?? '')) {
    return '';
  }
  return '<div class="about-box-mobile"><h3 class="sidebar-title">About the Author</h3>'
    . '<p class="about-box-text">' . h($settings['about']) . '</p></div>';
}

function viewPostCard(array $post): string
{
  $slug = h($post['slug']);
  $title = h($post['title']);
  $out = '<article class="post-card" data-tags="' . h(implode(',', $post['tags'])) . '">';
  if (jsTruthy($post['firstImage'])) {
    $out .= '<a href="/post/' . $slug . '" class="post-card-image">'
      . '<img src="' . h($post['firstImage']) . '" alt="' . $title . '" loading="lazy" />'
      . '</a>';
  }
  $out .= '<a href="/post/' . $slug . '" class="post-title">' . $title . '</a>'
    . '<p class="post-excerpt">' . h($post['excerpt']) . '</p>'
    . '<div class="post-meta">'
    . '<span class="post-date">' . h(formatPostDate($post['published_at'])) . '</span>'
    . '<span class="separator">&middot;</span>'
    . '<span class="reading-time">' . (int) $post['reading_time'] . ' min read</span>'
    . '</div></article>';
  return $out;
}

function viewPagination(?int $prevPage, ?int $nextPage, int $currentPage, int $totalPages, callable $href, ?string $id = null): string
{
  $out = '<nav' . ($id !== null ? ' id="' . h($id) . '"' : '') . ' class="pagination">';
  if ($prevPage !== null) {
    $out .= '<a href="' . h($href($prevPage)) . '" class="pagination-link">&larr; prev</a>';
  }
  $out .= '<span class="pagination-info">' . $currentPage . ' / ' . $totalPages . '</span>';
  if ($nextPage !== null) {
    $out .= '<a href="' . h($href($nextPage)) . '" class="pagination-link">next &rarr;</a>';
  }
  return $out . '</nav>';
}

function viewIndexEmpty(array $settings): string
{
  $out = <<<'WR_EMPTY'
<div class="empty-state">
    <h2>Welcome to your blog!</h2>
    <p>No posts yet. Create your first post using the API:</p>
    <pre><code>curl -X POST /api/posts \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "Hello World", "body": "My first post content"}'</code></pre>
WR_EMPTY;
  if (!jsTruthy($settings['onboarding_complete'] ?? false)) {
    $out .= '<p class="hint">Visit <a href="/onboarding">/onboarding</a> to set up your blog.</p>';
  }
  return $out . '</div>';
}

function viewIndexScript(): string
{
  return <<<'WR_INDEX_JS'
<script>
    (function () {
        var params = new URLSearchParams(window.location.search);
        var activeTag = params.get("tag") || "";
        document.querySelectorAll(".tag-list a.tag").forEach(function (tagEl) {
            tagEl.addEventListener("click", function (e) {
                if (this.dataset.filter !== activeTag) {
                    window.location.href = this.href;
                }
                e.preventDefault();
            });
        });
        document.querySelectorAll(".post-card").forEach(function (card) {
            var cardTags = card.dataset.tags ? card.dataset.tags.split(",") : [];
            if (!activeTag || cardTags.includes(activeTag)) {
                card.style.display = "";
            } else {
                card.style.display = "none";
            }
        });
    })();
</script>
WR_INDEX_JS;
}

function viewIndex(array $d): string
{
  $settings = $d['settings'];
  $posts = $d['posts'];
  $activeTag = $d['activeTag'];
  $query = $d['query'] ?? '';

  $out = '<div class="container"><div class="posts-section"><div class="posts-grid">';
  if (count($posts) === 0) {
    $out .= viewIndexEmpty($settings);
  } else {
    foreach ($posts as $post) {
      $out .= viewPostCard($post);
    }
    if ($d['totalPages'] > 1) {
      $out .= viewPagination(
        $d['prevPage'],
        $d['nextPage'],
        $d['currentPage'],
        $d['totalPages'],
        fn(int $p): string => '?page=' . $p . ($activeTag !== '' ? '&tag=' . $activeTag : '')
      );
    }
  }
  $out .= '</div>';
  $out .= viewSidebar($d['tags'], $activeTag, true, $settings, $query);
  $out .= viewAboutMobile($settings);
  $out .= '</div></div>';
  return $out . viewIndexScript();
}

function viewSearch(array $d): string
{
  $settings = $d['settings'];
  $posts = $d['posts'];
  $query = $d['query'];

  $out = '<div class="container"><div class="posts-section"><div class="posts-grid">';
  if ($query !== '') {
    $count = count($posts);
    $out .= '<div class="search-header"><h2 class="search-title">Search results for "' . h($query) . '"</h2>'
      . '<span class="search-count">' . $count . ' result' . ($count !== 1 ? 's' : '') . '</span></div>';
  }
  if (count($posts) === 0) {
    $out .= '<div class="empty-state">';
    if ($query !== '') {
      $out .= '<h2>No posts found</h2><p>No posts match your search for "' . h($query) . '". Try different keywords.</p>';
    } else {
      $out .= '<h2>Search</h2><p>Enter a search term to find posts.</p>';
    }
    $out .= '</div>';
  } else {
    foreach ($posts as $post) {
      $out .= viewPostCard($post);
    }
    if ($d['totalPages'] > 1) {
      $out .= viewPagination(
        $d['prevPage'],
        $d['nextPage'],
        $d['currentPage'],
        $d['totalPages'],
        fn(int $p): string => '?q=' . rawurlencode($query) . '&page=' . $p
      );
    }
  }
  $out .= '</div>';
  $out .= viewSidebar($d['tags'], '', false, $settings, $query);
  $out .= viewAboutMobile($settings);
  $out .= '</div></div>';
  return $out . <<<'WR_SEARCH_JS'
<script>
    (function () {
        document.querySelectorAll(".tag-list a.tag").forEach(function (tagEl) {
            tagEl.addEventListener("click", function (e) {
                window.location.href = this.href;
                e.preventDefault();
            });
        });
    })();
</script>
WR_SEARCH_JS;
}

function viewPost(array $d): string
{
  $post = $d['post'];
  $settings = $d['settings'];
  $author = $settings['author'] ?? '';
  $out = '<section class="post-detail-section"><article>';
  if (!empty($d['previewMode'])) {
    $out .= '<div class="preview-banner notification is-warning">Preview mode. Only accessible because you are logged in.</div>';
  }
  if (!empty($d['isAuthenticated'])) {
    $out .= '<a href="/panel?slug=' . h($post['slug']) . '" class="edit-post-link">Edit &rarr;</a>';
  }
  if (jsTruthy($post['cover_image'])) {
    $out .= '<figure class="post-cover-image"><img src="' . h($post['cover_image']) . '" alt="' . h($post['title']) . '" /></figure>';
  }
  $out .= '<header><h1 class="post-detail-title">' . h($post['title']) . '</h1><div class="post-detail-meta"><div class="author-detail">';
  if (jsTruthy($author)) {
    $out .= '<span class="author-avatar">' . h(mb_strtoupper(mb_substr($author, 0, 1))) . '</span>'
      . '<span class="author-name">' . h($author) . '</span>';
  }
  $out .= '</div><span class="separator">&middot;</span>'
    . '<span class="post-date">' . h(formatPostDate($post['published_at'])) . '</span>'
    . '<span class="separator">&middot;</span>'
    . '<span class="reading-time">' . (int) $post['reading_time'] . ' min read</span></div>';
  if ($post['tags']) {
    $out .= '<div class="post-tags">';
    foreach ($post['tags'] as $tag) {
      $out .= '<span class="tag is-medium">' . h($tag) . '</span>';
    }
    $out .= '</div>';
  }
  $out .= '</header>';
  $out .= '<div class="content">' . $post['bodyHtml'] . '</div>';
  if (jsTruthy($settings['about'] ?? '')) {
    $out .= '<div class="post-about"><h3 class="sidebar-title">About the Author</h3>'
      . '<p class="about-box-text">' . h($settings['about']) . '</p></div>';
  }
  if ($d['prevPost'] !== null || $d['nextPost'] !== null) {
    $out .= '<nav class="post-nav">';
    if ($d['prevPost'] !== null) {
      $out .= '<a href="/post/' . h($d['prevPost']['slug']) . '" class="nav-prev"><span class="nav-arrow">&larr;</span>'
        . '<span class="nav-title">' . h($d['prevPost']['title']) . '</span></a>';
    }
    if ($d['nextPost'] !== null) {
      $out .= '<a href="/post/' . h($d['nextPost']['slug']) . '" class="nav-next">'
        . '<span class="nav-title">' . h($d['nextPost']['title']) . '</span><span class="nav-arrow">&rarr;</span></a>';
    }
    $out .= '</nav>';
  }
  $out .= '</article></section>';
  return $out . <<<'WR_POST_JS'
<script>
(function () {
    var bar = document.querySelector('.reading-progress-bar');
    if (!bar) return;
    var wasComplete = false;
    window.addEventListener('scroll', function () {
        var scrollTop = window.scrollY;
        var docHeight = document.documentElement.scrollHeight - window.innerHeight;
        var progress = docHeight > 0 ? (scrollTop / docHeight) * 100 : 0;
        var clamped = Math.min(100, Math.max(0, progress));
        bar.style.width = clamped + '%';
        if (clamped >= 100 && !wasComplete) {
            wasComplete = true;
            bar.classList.remove('pulse');
            void bar.offsetWidth;
            bar.classList.add('pulse');
        } else if (clamped < 100) {
            wasComplete = false;
        }
    }, { passive: true });
})();
</script>
WR_POST_JS;
}

function view404(string $message): string
{
  return '<div class="error-page"><div class="error-page-content"><h1 class="error-code">404</h1>'
    . '<h2>Page Not Found</h2><p>' . h($message) . '</p>'
    . '<a href="/" class="button is-primary">Go Home</a></div></div>';
}

function viewLogin(array $d): string
{
  $out = '<div class="has-text-centered mb-5"><h1 class="title">' . h($d['title']) . '</h1>'
    . '<p class="subtitle">' . h($d['subtitle']) . '</p></div>'
    . '<form id="loginForm" method="POST" action="' . h($d['action']) . '"><div class="box">';
  if (jsTruthy($d['error'] ?? '')) {
    $out .= '<div class="notification is-danger is-light mb-4">' . h($d['error']) . '</div>';
  }
  $out .= '<input type="hidden" name="redirect" value="' . h($d['redirectTo']) . '">'
    . '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">';
  if ($d['showUsername']) {
    $out .= '<div class="field"><label class="label" for="username">Username</label>'
      . '<input type="text" id="username" name="username" autocomplete="username" autofocus placeholder="Enter username"></div>'
      . '<div class="field"><label class="label" for="password">Password</label>'
      . '<input type="password" id="password" name="password" autocomplete="current-password" placeholder="Enter password"></div>'
      . '<div class="field"><label class="label">Or use auth token</label></div>';
  }
  $out .= '<div class="field"><label class="label" for="token">Auth Token</label>'
    . '<input type="password" id="token" name="token" autocomplete="current-password" '
    . ($d['tokenRequired'] ? 'required ' : '') . ($d['showUsername'] ? '' : 'autofocus ')
    . 'placeholder="Enter your auth token"></div>'
    . '<button type="submit" class="button is-primary is-fullwidth">Login</button></div></form>';
  return $out;
}

function viewOnboarding(): string
{
  return <<<'WR_ONBOARDING'
<section class="form-section">
  <div class="has-text-centered mb-5">
    <h1 class="title">Welcome to Writer!</h1>
    <p class="subtitle">In your first access, configure your new blog.</p>
  </div>

  <div class="box">
    <form id="onboardingForm" method="POST" action="/api/onboarding">
      <div class="field">
        <label class="label" for="title">What is your blog title?</label>
        <input type="text" id="title" name="title" required placeholder="My Awesome Blog">
      </div>
      <div class="field">
        <label class="label" for="author">What is your name (the author)?</label>
        <input type="text" id="author" name="author" required placeholder="John Doe">
      </div>
      <div class="field">
        <label class="label" for="description">Write a short description about your blog</label>
        <textarea id="description" name="description" placeholder="A blog about..."></textarea>
      </div>

      <hr>
      <p class="has-text-grey mb-4">Set up credentials for accessing the admin panel.</p>

      <div class="field">
        <label class="label" for="username">Username</label>
        <div class="control">
          <input class="input" type="text" id="username" name="username" required placeholder="admin">
        </div>
      </div>
      <div class="field">
        <label class="label" for="password">Password</label>
        <div class="control">
          <input class="input" type="password" id="password" name="password" required placeholder="••••••••">
        </div>
      </div>
      <div class="field">
        <label class="label" for="confirmPassword">Confirm Password</label>
        <div class="control">
          <input class="input" type="password" id="confirmPassword" name="confirmPassword" required placeholder="••••••••">
        </div>
      </div>

      <button type="submit" class="button is-primary is-fullwidth">Set Up Blog</button>
    </form>
  </div>

  <div class="result-box onboarding-result" id="result" style="display: none">
    <h2>Blog Created!</h2>
    <h4>Your auth token:</h4>
    <pre><code id="tokenDisplay"></code></pre>
    <p class="token-usage">Use the token in the <code>X-Auth-Token</code> header for all protected API operations.</p>
    <p class="token-warning">
      ⚠️ Save this token! You won't be able to see it again, but you'll be able to rotate it, if needed.
    </p>
    <h4>Your recovery key (save it somewhere safe):</h4>
    <pre><code id="recoveryDisplay"></code></pre>
    <p>
      Your recovery key is a backup credential. Use it if you forget your password.
      On the login page, type your username and the recovery key in the password
      field. A recovery key works one time only. After you use it, Writer makes a new
      key and shows it to you one time. Save the new key at once.
    </p>
    <a href="/" class="button is-link is-outline mt-3">Go to Blog</a>
  </div>
</section>

<script>
  document.getElementById("onboardingForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;
    if (password && password !== confirmPassword) {
      alert("Passwords do not match");
      return;
    }
    const formData = {
      title: document.getElementById("title").value,
      author: document.getElementById("author").value,
      description: document.getElementById("description").value,
      username: document.getElementById("username").value,
      password: password,
    };
    try {
      const res = await fetch("/api/onboarding", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(formData),
      });
      const data = await res.json();
      if (data.instructions?.value) {
        document.getElementById("onboardingForm").parentElement.style.display = "none";
        document.getElementById("result").style.display = "block";
        document.getElementById("tokenDisplay").textContent = data.instructions.value;
        document.getElementById("recoveryDisplay").textContent = data.recovery_key || "";
      } else if (data.error) {
        alert(data.message);
      }
    } catch (err) {
      alert("Failed to set up blog");
    }
  });
</script>
WR_ONBOARDING;
}

/** Shared admin top nav; `$separator` mirrors Node's panel-only " | ". */
function viewPanelNav(string $active, bool $separator): string
{
  $links = '<a href="/panel"' . ($active === 'posts' ? ' class="is-active"' : '') . '>Posts</a>'
    . '<a href="/settings"' . ($active === 'settings' ? ' class="is-active"' : '') . '>Settings</a>';
  if ($separator) {
    $links .= '<span> | </span>';
  }
  return '<nav class="panel-nav"><div class="panel-nav-inner">'
    . '<a class="panel-logo" href="/panel">Panel</a>'
    . '<div class="panel-nav-links">' . $links
    . '<form method="POST" action="/logout" style="display: inline;">'
    . '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">'
    . '<button type="submit" class="button-link">Logout</button>'
    . '</form></div></div></nav>';
}

/** Tiptap/Turndown editor module (CDN) driving the panel form. */
function viewPanelScript(string $token): string
{
  $script = <<<'WR_PANEL_JS'
<script type="module">
import { Editor } from 'https://esm.sh/@tiptap/core@2.11.7';
import StarterKit from 'https://esm.sh/@tiptap/starter-kit@2.11.7';
import Link from 'https://esm.sh/@tiptap/extension-link@2.11.7';
import Image from 'https://esm.sh/@tiptap/extension-image@2.11.7';
import Placeholder from 'https://esm.sh/@tiptap/extension-placeholder@2.11.7';
import Table from 'https://esm.sh/@tiptap/extension-table@2.11.7';
import TableRow from 'https://esm.sh/@tiptap/extension-table-row@2.11.7';
import TableCell from 'https://esm.sh/@tiptap/extension-table-cell@2.11.7';
import TableHeader from 'https://esm.sh/@tiptap/extension-table-header@2.11.7';
import TurndownService from 'https://esm.sh/turndown@7.2.4';
import { gfm } from 'https://esm.sh/turndown-plugin-gfm@1.0.2';

const authToken = __AUTH_TOKEN__;
const turndown = new TurndownService({ headingStyle: 'atx', codeBlockStyle: 'fenced' });
turndown.use(gfm);

turndown.addRule('strikethrough', {
  filter: ['del', 's', 'strike'],
  replacement: (content) => '~~' + content + '~~'
});

turndown.addRule('listItemParagraph', {
  filter: (node) => node.nodeName === 'P' && node.parentNode.nodeName === 'LI',
  replacement: (content, node) => node.textContent + '\n'
});

let editor = null;

function initEditor(content = '') {
  if (editor) {
    editor.destroy();
    document.getElementById('tiptap-editor').innerHTML = '';
  }
  editor = new Editor({
    element: document.getElementById('tiptap-editor'),
    extensions: [
      StarterKit,
      Link.configure({ openOnClick: false, HTMLAttributes: { class: 'editor-link' } }),
      Image.configure({ HTMLAttributes: { class: 'editor-image' } }),
      Placeholder.configure({ placeholder: 'Write your post content...' }),
      Table.configure({ resizable: true }),
      TableRow,
      TableHeader,
      TableCell
    ],
    content: content,
    editorProps: {
      attributes: { class: 'tiptap-content' }
    }
  });
  return editor;
}

window.showCreateForm = function() {
  document.querySelector('.post-list').style.display = 'none';
  const pagination = document.getElementById('pagination');
  if (pagination) pagination.style.display = 'none';
  document.getElementById('editForm').style.display = 'block';
  document.getElementById('formTitle').textContent = 'New Post';
  document.getElementById('postForm').action = '/api/posts';
  document.getElementById('postSlug').value = '';
  document.getElementById('title').value = '';
  document.getElementById('cover_image').value = '';
  document.getElementById('body').value = '';
  document.getElementById('status').value = 'draft';
  document.getElementById('published_at').value = '';
  initEditor('');
};

window.editPost = function(slug) {
  fetch('/api/posts/' + slug, {
    credentials: 'same-origin',
    headers: { 'X-Auth-Token': authToken }
  })
  .then(res => res.json())
  .then(post => {
    document.querySelector('.post-list').style.display = 'none';
    const pagination = document.getElementById('pagination');
    if (pagination) pagination.style.display = 'none';
    document.getElementById('editForm').style.display = 'block';
    document.getElementById('formTitle').textContent = 'Edit Post';
    document.getElementById('postForm').action = '/api/posts/' + slug;
    document.getElementById('postSlug').value = slug;
    document.getElementById('title').value = post.title;
    document.getElementById('cover_image').value = post.cover_image || '';
    document.getElementById('status').value = post.status || 'draft';
    if (post.published_at) {
      const date = new Date(post.published_at);
      const localDatetime = date.getFullYear() + '-' +
        String(date.getMonth() + 1).padStart(2, '0') + '-' +
        String(date.getDate()).padStart(2, '0') + 'T' +
        String(date.getHours()).padStart(2, '0') + ':' +
        String(date.getMinutes()).padStart(2, '0');
      document.getElementById('published_at').value = localDatetime;
    } else {
      document.getElementById('published_at').value = '';
    }
    initEditor(post.bodyHtml || '<p></p>');
  })
  .catch(err => {
    window.showNotification('Failed to load post', 'danger');
  });
};

window.cancelEdit = function() {
  document.querySelector('.post-list').style.display = 'block';
  const pagination = document.getElementById('pagination');
  if (pagination) pagination.style.display = '';
  document.getElementById('editForm').style.display = 'none';
  if (editor) { editor.destroy(); editor = null; }
};

window.editImage = function(img) {
  const src = img.src || '';
  const alt = img.alt || '';
  const title = img.title || '';
  const pos = editor.view.posAtDOM(img, 0);
  editor.chain().setNodeSelection(pos).focus();
  window.showImageEditModal({
    src: src,
    alt: alt,
    title: title,
    onUpdate: function(attrs) {
      editor.chain().updateAttributes('image', attrs).focus().run();
    }
  });
};

window.deletePost = function(slug) {
  fetch('/api/posts/' + slug, {
    method: 'DELETE',
    headers: { 'X-Auth-Token': authToken }
  })
  .then(res => res.json())
  .then(data => {
    if (data.confirmation_required && data.confirmation_url) {
      return fetch(data.confirmation_url, {
        method: 'POST',
        headers: { 'X-Auth-Token': authToken }
      }).then(r => r.json());
    }
    return data;
  })
  .then(data => {
    if (data.message) {
      sessionStorage.setItem('panelFlash', JSON.stringify({ message: 'Post deleted successfully', type: 'success' }));
      window.location.reload();
    } else if (data.error) {
      window.showNotification(data.message, 'danger');
    }
  })
  .catch(err => {
    window.showNotification('Failed to delete post', 'danger');
  });
};

document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const editSlug = params.get('slug');
  if (editSlug) window.editPost(editSlug);
});

document.getElementById('tiptap-editor')?.addEventListener('click', (e) => {
  const img = e.target.closest('img');
  if (!img) return;
  e.preventDefault();
  window.editImage(img);
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  const action = btn.dataset.action;
  const slug = btn.dataset.slug;

  if (action === 'create-post') {
    e.preventDefault();
    window.showCreateForm();
  } else if (action === 'edit-post') {
    e.preventDefault();
    window.editPost(slug);
  } else if (action === 'delete-post') {
    e.preventDefault();
    window.showConfirmModal({
      title: 'Delete Post',
      message: 'Are you sure you want to delete this post? This action cannot be undone.',
      confirmText: 'Delete',
      confirmClass: 'is-danger',
      onConfirm: () => window.deletePost(slug)
    });
  } else if (action === 'cancel-edit') {
    cancelEdit();
  }
});

document.getElementById('postForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!editor) return;
  const slug = document.getElementById('postSlug').value;
  const publishedAtValue = document.getElementById('published_at').value;
  const markdownBody = turndown.turndown(editor.getHTML());
  const formData = {
    title: document.getElementById('title').value,
    cover_image: document.getElementById('cover_image').value,
    body: markdownBody,
    status: document.getElementById('status').value,
    published_at: publishedAtValue ? new Date(publishedAtValue).toISOString() : null
  };

  const method = slug ? 'PUT' : 'POST';
  const url = slug ? '/api/posts/' + slug : '/api/posts';

  try {
    const res = await fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Auth-Token': authToken
      },
      body: JSON.stringify(formData)
    });
    const data = await res.json();
    if (data.slug || data.id) {
      const msg = slug ? 'Post updated successfully' : 'Post created successfully';
      sessionStorage.setItem('panelFlash', JSON.stringify({ message: msg, type: 'success' }));
      window.location.reload();
    } else if (data.error) {
      window.showNotification(data.message, 'danger');
    }
  } catch (err) {
    window.showNotification('Failed to save post', 'danger');
  }
});
</script>
WR_PANEL_JS;
  return str_replace('__AUTH_TOKEN__', json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $script);
}

function viewPanel(array $d): string
{
  $posts = $d['posts'];
  $query = $d['query'];

  $out = '<div class="panel-layout">' . viewPanelNav('posts', true)
    . '<div class="panel-content">'
    . '<div class="notification-container"></div>'
    . '<div class="panel-header"><h1 class="panel-title">Posts</h1>'
    . '<div class="panel-actions"><button class="button is-primary is-small" data-action="create-post">New Post</button></div></div>'
    . '<form class="panel-search-form" action="/panel" method="GET">'
    . '<input type="text" name="q" class="panel-search-input" placeholder="Search posts..." value="' . h($query) . '" autocomplete="off">'
    . '<button type="submit" class="panel-search-button">Search</button>';
  if ($query !== '') {
    $out .= '<a href="/panel" class="panel-search-clear">Clear</a>';
  }
  $out .= '</form><div class="post-list">';

  if (count($posts) === 0) {
    $out .= '<div class="empty-list">'
      . ($query !== '' ? '<p>No posts match your search.</p>' : '<p>No posts yet. Create your first post to get started.</p>')
      . '</div>';
  } else {
    $out .= '<table class="posts-table"><thead><tr>'
      . '<th>Title</th><th>Status</th><th>Published</th><th class="actions-cell">Actions</th>'
      . '</tr></thead><tbody>';
    foreach ($posts as $post) {
      $slug = h($post['slug']);
      $status = h($post['status']);
      $out .= '<tr><td class="post-title-cell">'
        . '<a href="#" data-action="edit-post" data-slug="' . $slug . '">' . h($post['title']) . '</a></td>'
        . '<td><span class="post-status is-' . $status . '">' . $status . '</span></td>'
        . '<td class="post-date-cell">' . h(formatPostDate((string) $post['published_at'])) . '</td>'
        . '<td class="actions-cell">'
        . '<a href="/post/' . $slug . '" class="btn-view">' . ($post['status'] === 'draft' ? 'Preview' : 'View') . '</a>'
        . '<button class="btn-edit" data-action="edit-post" data-slug="' . $slug . '">Edit</button>'
        . '<button class="btn-delete" data-action="delete-post" data-slug="' . $slug . '">Delete</button>'
        . '</td></tr>';
    }
    $out .= '</tbody></table>';
  }
  $out .= '</div>';

  if ($d['totalPages'] > 1) {
    $out .= viewPagination(
      $d['prevPage'],
      $d['nextPage'],
      $d['currentPage'],
      $d['totalPages'],
      fn(int $p): string => '?page=' . $p . ($query !== '' ? '&q=' . rawurlencode($query) : ''),
      'pagination'
    );
  }

  $out .= '<div id="editForm" class="edit-form" style="display: none">'
    . '<div class="form-header"><h3 id="formTitle">Edit Post</h3>'
    . '<button class="btn-close" data-action="cancel-edit">&times;</button></div>'
    . '<form id="postForm">'
    . '<input type="hidden" id="postSlug" name="slug" value="">'
    . '<div class="field"><label class="label" for="title">Title</label>'
    . '<input type="text" id="title" name="title" required value=""></div>'
    . '<div class="field"><label class="label" for="cover_image">Cover Image URL</label>'
    . '<input type="url" id="cover_image" name="cover_image" placeholder="https://example.com/image.jpg" value="">'
    . '<p class="help">Optional. Provide an external image URL or leave blank to auto-detect from body.</p></div>'
    . '<div class="field"><label class="label">Content</label>'
    . '<div class="tiptap-editor"><div id="tiptap-editor"></div></div>'
    . '<p class="help">Supports Markdown syntax.</p>'
    . '<input type="hidden" id="body" name="body" value=""></div>'
    . '<div class="field is-grouped">'
    . '<div class="field" style="flex: 1;"><label class="label" for="status">Status</label>'
    . '<select id="status" name="status"><option value="draft">Draft</option><option value="published">Published</option></select></div>'
    . '<div class="field" style="flex: 1;"><label class="label" for="published_at">Published at</label>'
    . '<input type="datetime-local" id="published_at" name="published_at">'
    . '<p class="help">Optional. Defaults to creation date if not set.</p></div></div>'
    . '<div class="form-actions">'
    . '<button type="button" class="button is-outline" data-action="cancel-edit">Cancel</button>'
    . '<button type="submit" class="button is-primary">Save</button></div>'
    . '</form></div></div></div>';

  return $out . viewPanelScript((string) $d['token']);
}

/** Settings module: serializes the six settings forms onto the API. */
function viewSettingsScript(string $token): string
{
  $script = <<<'WR_SETTINGS_JS'
<script>
    document
        .getElementById("settingsForm")
        .addEventListener("submit", async (e) => {
            e.preventDefault();
            const formData = {
                title: document.getElementById("title").value,
                author: document.getElementById("author").value,
                description:
                    document.getElementById("description").value,
                show_docs: document.getElementById("showDocs").checked,
            };
            try {
                const res = await fetch("/api/settings", {
                    method: "PUT",
                    headers: {
                        "Content-Type": "application/json",
                        "X-Auth-Token": __AUTH_TOKEN__,
                    },
                    body: JSON.stringify(formData),
                });
                const data = await res.json();
                if (data.title) {
                    showNotification("Settings saved successfully!");
                } else if (data.error) {
                    showNotification(data.message, "danger");
                }
            } catch (err) {
                showNotification("Failed to save settings", "danger");
            }
        });

    document
        .getElementById("rotateTokenForm")
        .addEventListener("submit", (e) => {
            e.preventDefault();
            showConfirmModal({
                title: "Rotate Auth Token",
                message: "Are you sure you want to rotate your auth token? Any applications using the current token will lose access.",
                confirmText: "Rotate",
                confirmClass: "is-danger",
                onConfirm: async () => {
                    try {
                        const res = await fetch(
                            "/api/settings/rotate-token",
                            {
                                method: "POST",
                                headers: { "X-Auth-Token": __AUTH_TOKEN__ },
                            },
                        );
                        const data = await res.json();
                        if (data.confirmation_required && data.confirmation_url) {
                            const confirmRes = await fetch(data.confirmation_url, {
                                method: "POST",
                                headers: { "X-Auth-Token": __AUTH_TOKEN__ },
                            });
                            const confirmData = await confirmRes.json();
                            if (confirmData.auth_token) {
                                document.querySelector(
                                    ".settings-grid",
                                ).style.display = "none";
                                document.getElementById(
                                    "result",
                                ).style.display = "block";
                                document.getElementById(
                                    "newTokenDisplay",
                                ).textContent = confirmData.auth_token;
                                showNotification("Auth token rotated successfully!");
                            } else if (confirmData.error) {
                                showNotification(confirmData.message, "danger");
                            }
                        } else if (data.auth_token) {
                            document.querySelector(
                                ".settings-grid",
                            ).style.display = "none";
                            document.getElementById(
                                "result",
                            ).style.display = "block";
                            document.getElementById(
                                "newTokenDisplay",
                            ).textContent = data.auth_token;
                            showNotification("Auth token rotated successfully!");
                        } else if (data.error) {
                            showNotification(data.message, "danger");
                        }
                    } catch (err) {
                        showNotification("Failed to rotate token", "danger");
                    }
                },
            });
        });

    document
        .getElementById("credentialsForm")
        .addEventListener("submit", async (e) => {
            e.preventDefault();
            const password =
                document.getElementById("password").value;
            const confirmPassword =
                document.getElementById("confirmPassword").value;
            if (password && password !== confirmPassword) {
                showNotification("Passwords do not match", "warning");
                return;
            }
            const formData = {
                username: document.getElementById("username").value,
                password: password,
            };
            try {
                const res = await fetch(
                    "/api/settings/credentials",
                    {
                        method: "PUT",
                        headers: {
                            "Content-Type": "application/json",
                            "X-Auth-Token": __AUTH_TOKEN__,
                        },
                        body: JSON.stringify(formData),
                    },
                );
                const data = await res.json();
                if (data.username) {
                    showNotification("Credentials saved successfully!");
                    document.getElementById("password").value = "";
                    document.getElementById(
                        "confirmPassword",
                    ).value = "";
                } else if (data.error) {
                    showNotification(data.message, "danger");
                }
            } catch (err) {
                showNotification("Failed to save credentials", "danger");
            }
        });

    document
        .getElementById("customScriptsForm")
        .addEventListener("submit", async (e) => {
            e.preventDefault();
            const formData = {
                custom_scripts:
                    document.getElementById("customScripts").value,
                csp_script_domains:
                    document.getElementById("cspScriptDomains").value,
            };
            try {
                const res = await fetch("/api/settings", {
                    method: "PUT",
                    headers: {
                        "Content-Type": "application/json",
                        "X-Auth-Token": __AUTH_TOKEN__,
                    },
                    body: JSON.stringify(formData),
                });
                const data = await res.json();
                if (data.title !== undefined) {
                    showNotification("Custom scripts saved successfully!");
                } else if (data.error) {
                    showNotification(data.message, "danger");
                }
            } catch (err) {
                showNotification("Failed to save custom scripts", "danger");
            }
        });

    document
        .getElementById("aboutForm")
        .addEventListener("submit", async (e) => {
            e.preventDefault();
            const formData = {
                about: document.getElementById("about").value,
            };
            try {
                const res = await fetch("/api/settings", {
                    method: "PUT",
                    headers: {
                        "Content-Type": "application/json",
                        "X-Auth-Token": __AUTH_TOKEN__,
                    },
                    body: JSON.stringify(formData),
                });
                const data = await res.json();
                if (data.title !== undefined) {
                    showNotification("About the author saved successfully!");
                } else if (data.error) {
                    showNotification(data.message, "danger");
                }
            } catch (err) {
                showNotification("Failed to save about the author", "danger");
            }
        });
</script>
WR_SETTINGS_JS;
  return str_replace('__AUTH_TOKEN__', json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $script);
}

function viewSettings(array $d): string
{
  $settings = $d['settings'];
  $out = '<div class="panel-layout">' . viewPanelNav('settings', false)
    . '<div class="panel-content">'
    . '<div class="notification-container"></div>'
    . '<div class="panel-header"><h1 class="panel-title">Settings</h1><div class="panel-actions"></div></div>';
  if (jsTruthy($d['error'] ?? null)) {
    $out .= '<div class="notification is-danger is-light mb-4">' . h($d['error']) . '</div>';
  }
  $out .= '<section class="form-section"><div class="settings-grid">'
    // General
    . '<div class="box is-fullheight"><h3 class="title is-5">General</h3>'
    . '<form id="settingsForm" method="POST" action="/settings/update">'
    . '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">'
    . '<div><div class="field"><label class="label" for="title">Blog Title</label>'
    . '<input type="text" id="title" name="title" required value="' . h($settings['title'] ?? '') . '"></div>'
    . '<div class="field"><label class="label" for="author">Author Name</label>'
    . '<input type="text" id="author" name="author" value="' . h($settings['author'] ?? '') . '"></div>'
    . '<div class="field"><label class="label" for="description">Blog Description</label>'
    . '<textarea id="description" name="description">' . h($settings['description'] ?? '') . '</textarea></div>'
    . '<div class="field"><label class="checkbox-label">'
    . '<input type="checkbox" id="showDocs" name="show_docs"' . (($settings['show_docs'] ?? true) !== false ? ' checked' : '') . '>'
    . ' Show API Docs link in footer</label></div></div>'
    . '<button type="submit" class="button is-primary is-fullwidth">Save</button>'
    . '</form></div>'
    // Credentials
    . '<div class="box is-fullheight"><h3 class="title is-5">Credentials</h3>'
    . '<form id="credentialsForm" method="POST" action="/settings/update-credentials">'
    . '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">'
    . '<div><div class="field"><label class="label" for="username">Username</label>'
    . '<input type="text" id="username" name="username" value="' . h($settings['username'] ?? '') . '" placeholder="Enter username"></div>'
    . '<div class="field"><label class="label" for="password">New Password</label>'
    . '<input type="password" id="password" name="password" placeholder="Enter new password">';
  if ($settings['hasPassword'] ?? false) {
    $out .= '<p class="help">Leave blank to keep current password</p>';
  }
  $out .= '</div>'
    . '<div class="field"><label class="label" for="confirmPassword">Confirm Password</label>'
    . '<input type="password" id="confirmPassword" name="confirmPassword" placeholder="Confirm new password"></div></div>'
    . '<button type="submit" class="button is-primary is-fullwidth">Save</button>'
    . '</form></div>'
    // About the Author
    . '<div class="box is-fullwidth"><h3 class="title is-5">About the Author</h3>'
    . '<form id="aboutForm"><div class="field">'
    . '<textarea id="about" name="about" rows="4" maxlength="280" placeholder="A brief description about yourself...">' . h($settings['about'] ?? '') . '</textarea>'
    . '<p class="help">Write a brief description about yourself. Max 280 characters.</p></div>'
    . '<button type="submit" class="button is-primary is-fullwidth">Save</button></form></div>'
    // Custom Scripts
    . '<div class="box is-fullwidth"><h3 class="title is-5">Custom Scripts</h3>'
    . '<form id="customScriptsForm"><div class="field">'
    . '<textarea id="customScripts" name="custom_scripts" rows="6" placeholder="&lt;script&gt;...&lt;/script&gt;">' . h($settings['custom_scripts'] ?? '') . '</textarea>'
    . '<p class="help">Add custom script tags (e.g., analytics) to be included in every page.</p></div>'
    . '<div class="field"><label class="label" for="cspScriptDomains">Allowed Script Domains</label>'
    . '<textarea id="cspScriptDomains" name="csp_script_domains" rows="3" placeholder="https://www.googletagmanager.com, https://www.google-analytics.com">' . h($settings['csp_script_domains'] ?? '') . '</textarea>'
    . '<p class="help">Comma-separated list of external domains allowed to load scripts (added to the Content Security Policy).</p></div>'
    . '<button type="submit" class="button is-primary is-fullwidth">Save</button></form></div>'
    // Security
    . '<div class="box is-fullwidth"><h3 class="title is-5">Security</h3>'
    . '<form id="rotateTokenForm" method="POST" action="/settings/rotate-token">'
    . '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">'
    . '<p class="mb-3">Rotate your auth token if you suspect it has been compromised. <br />'
    . '<strong>Make sure to update any applications using the old token.</strong></p>'
    . '<button type="submit" class="button is-outline is-danger">Rotate Auth Token</button>'
    . '</form></div></div>'
    // Result box
    . '<div class="result-box" id="result" style="display: none"><h3>Token Rotated!</h3>'
    . '<p>Your new auth token:</p><pre><code id="newTokenDisplay"></code></pre>'
    . '<p class="token-warning">Save this new token! You won\'t be able to see it again.</p>'
    . '<a href="/settings" class="button is-link is-outlined mt-3">Back to Settings</a></div>';
  return $out . viewSettingsScript((string) $d['token']) . '</section></div></div>';
}

function viewDocs(): string
{
  return <<<'WR_DOCS'
<section class="docs-section">
  <h1 class="title">API Documentation</h1>
  <p class="intro">Complete reference for the Writer API. All endpoints are self-discoverable at <code>/api</code>.</p>

  <div class="docs-subsection">
    <h2 class="title is-4">Authentication</h2>
    <p>Protected endpoints require the <code>X-Auth-Token</code> header:</p>
    <pre><code>X-Auth-Token: your-auth-token-here</code></pre>
    <p>Get your token from the <a href="/onboarding">onboarding</a> page (first time only). The token is not returned by <code>GET /api/settings</code> for security.</p>
  </div>

  <div class="docs-subsection">
    <h2 class="title is-4">Public Endpoints</h2>

    <div class="endpoint">
      <h3><span class="method-tag is-get">GET</span> /api</h3>
      <p>Returns this API documentation in JSON format.</p>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-get">GET</span> /api/posts</h3>
      <p>List all <strong>published</strong> blog posts with pagination. Drafts are never included.</p>
      <p><strong>Query Parameters:</strong></p>
      <ul>
        <li><code>page</code> - Page number (default: 1)</li>
        <li><code>limit</code> - Posts per page (default: 10, max: 100)</li>
      </ul>
      <pre><code>curl http://localhost:8080/api/posts?page=1&limit=5</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-get">GET</span> /api/posts/:slug</h3>
      <p>Get a single published post by its URL slug. Drafts return 404 unless you provide a valid <code>X-Auth-Token</code>.</p>
      <pre><code>curl http://localhost:8080/api/posts/my-post-slug</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-get">GET</span> /api/settings</h3>
      <p>Get blog settings (title, author, description, show_docs). The <code>auth_token</code> field is intentionally omitted.</p>
      <pre><code>curl http://localhost:8080/api/settings</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-get">GET</span> /feed.xml</h3>
      <p>RSS 2.0 feed with up to 20 published posts.</p>
    </div>
  </div>

  <div class="docs-subsection">
    <h2 class="title is-4">Protected Endpoints</h2>
    <p>These require a valid <code>X-Auth-Token</code> header.</p>

    <div class="endpoint">
      <h3><span class="method-tag is-post">POST</span> /api/posts</h3>
      <p>Create a new blog post.</p>
      <p><strong>Required:</strong> <code>title</code>, <code>body</code></p>
      <p><strong>Optional:</strong> <code>tags</code> (array), <code>cover_image</code> (URL), <code>status</code> (<code>draft</code> or <code>published</code>)</p>
      <pre><code>curl -X POST http://localhost:8080/api/posts \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "My Post", "body": "Content here", "tags": ["tech"], "status": "published"}'</code></pre>
      <p><strong>Auto-generated:</strong> <code>slug</code>, <code>keywords</code>, <code>meta_description</code>, <code>reading_time</code>, <code>excerpt</code></p>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-put">PUT</span> /api/posts/:slug</h3>
      <p>Update an existing post. Only include fields you want to change.</p>
      <pre><code>curl -X PUT http://localhost:8080/api/posts/my-post-slug \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "New Title"}'</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-delete">DELETE</span> /api/posts/:slug</h3>
      <p>Delete a post by its slug. <strong>This action requires confirmation.</strong></p>
      <pre><code>curl -X DELETE http://localhost:8080/api/posts/my-post-slug \
  -H "X-Auth-Token: YOUR_TOKEN"</code></pre>
      <p><strong>Response:</strong></p>
      <pre><code>{
  "confirmation_required": true,
  "confirmation_url": "/api/confirm/abc123..."
}</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-put">PUT</span> /api/settings</h3>
      <p>Update blog settings.</p>
      <p><strong>Optional:</strong> <code>title</code>, <code>author</code>, <code>description</code>, <code>custom_scripts</code>, <code>show_docs</code></p>
      <pre><code>curl -X PUT http://localhost:8080/api/settings \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"title": "New Title", "author": "New Author", "show_docs": true}'</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-put">PUT</span> /api/settings/credentials</h3>
      <p>Update the admin username and password. Send an empty <code>password</code> to keep the current one.</p>
      <pre><code>curl -X PUT http://localhost:8080/api/settings/credentials \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: YOUR_TOKEN" \
  -d '{"username": "admin", "password": "newpass"}'</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-post">POST</span> /api/settings/rotate-token</h3>
      <p>Rotate the auth token. <strong>This action requires confirmation.</strong> Any application using the old token will lose access.</p>
      <pre><code>curl -X POST http://localhost:8080/api/settings/rotate-token \
  -H "X-Auth-Token: YOUR_TOKEN"</code></pre>
      <p><strong>Response:</strong></p>
      <pre><code>{
  "confirmation_required": true,
  "confirmation_url": "/api/confirm/abc123..."
}</code></pre>
    </div>

    <div class="endpoint">
      <h3><span class="method-tag is-post">POST</span> /api/confirm/:token</h3>
      <p>Confirm a pending destructive action. Use the <code>confirmation_url</code> returned by the original request. Confirmation tokens expire after 10 minutes.</p>
      <pre><code>curl -X POST http://localhost:8080/api/confirm/abc123... \
  -H "X-Auth-Token: YOUR_TOKEN"</code></pre>
      <p><strong>Expired/invalid token response:</strong> <code>410 Gone</code></p>
    </div>
  </div>

  <div class="docs-subsection">
    <h2 class="title is-4">Confirmation Flow Example</h2>
    <p>Deleting a post is a two-step process:</p>
    <pre><code># Step 1: Request deletion &rarr; get confirmation URL
curl -X DELETE http://localhost:8080/api/posts/my-post \
  -H "X-Auth-Token: YOUR_TOKEN"

# Response:
# {
#   "confirmation_required": true,
#   "confirmation_url": "/api/confirm/abc123..."
# }

# Step 2: POST the confirmation URL
curl -X POST http://localhost:8080/api/confirm/abc123... \
  -H "X-Auth-Token: YOUR_TOKEN"

# Response:
# { "message": "Post deleted successfully", "slug": "my-post" }</code></pre>
  </div>

  <div class="docs-subsection">
    <h2 class="title is-4">Response Examples</h2>

    <div class="endpoint">
      <h3>Post Object</h3>
      <pre><code>{
  "id": "uuid",
  "title": "My Post",
  "slug": "my-post",
  "body": "Content...",
  "bodyHtml": "&lt;p&gt;Content...&lt;/p&gt;",
  "excerpt": "First 200 chars...",
  "cover_image": "",
  "keywords": ["keyword1", "keyword2"],
  "meta_description": "Meta description...",
  "reading_time": 2,
  "status": "published",
  "tags": ["tech"],
  "created_at": "2026-04-08T12:00:00Z",
  "updated_at": "2026-04-08T12:00:00Z"
}</code></pre>
    </div>

    <div class="endpoint">
      <h3>Settings Object</h3>
      <pre><code>{
  "title": "Writer",
  "author": "John Doe",
  "description": "A blog about tech",
  "onboarding_complete": true,
  "show_docs": true,
  "hasPassword": true
}</code></pre>
    </div>
  </div>

  <div class="docs-subsection">
    <h2 class="title is-4">Security Notes</h2>
    <ul>
      <li>All web forms (login, settings, logout) include CSRF tokens. Do not use the web UI endpoints programmatically.</li>
      <li>Draft posts are never returned by public API endpoints.</li>
    </ul>
  </div>
</section>
WR_DOCS;
}

/** Build and emit a full HTML document (layout + partials + body). */
function renderPage(array $d): never
{
  $settings = $d['settings'] ?? null;
  $post = $d['post'] ?? null;
  $base = baseUrl();

  $hasTitle = array_key_exists('title', $d);
  $title = $hasTitle
    ? $d['title']
    : ($post !== null ? $post['title'] : ($settings !== null ? ($settings['title'] ?? 'Writer') : 'Writer'));

  $metaDesc = $post !== null
    ? ($post['meta_description'] ?? '')
    : ($settings !== null ? ($settings['description'] ?? '') : '');
  $metaKeywords = $post !== null ? ($post['keywords'] ?? '') : '';

  $og = '';
  if ($post !== null) {
    $ogDesc = ($post['meta_description'] ?? '') !== '' ? $post['meta_description'] : ($post['excerpt'] ?? '');
    $url = $base . '/post/' . $post['slug'];
    $img = $base . '/og/' . $post['slug'] . '.png';
    $og = '<meta property="og:type" content="article" />'
      . '<meta property="og:title" content="' . h($post['title']) . '" />'
      . '<meta property="og:description" content="' . h($ogDesc) . '" />'
      . '<meta property="og:url" content="' . h($url) . '" />'
      . '<meta property="og:image" content="' . h($img) . '" />'
      . '<meta name="twitter:card" content="summary_large_image" />'
      . '<meta name="twitter:title" content="' . h($post['title']) . '" />'
      . '<meta name="twitter:description" content="' . h($ogDesc) . '" />'
      . '<meta name="twitter:image" content="' . h($img) . '" />';
  } else {
    $ogTitle = $hasTitle ? $d['title'] : 'Writer';
    $ogDesc = $settings !== null ? ($settings['description'] ?? '') : '';
    $og = '<meta property="og:type" content="website" />'
      . '<meta property="og:title" content="' . h($ogTitle) . '" />'
      . '<meta property="og:description" content="' . h($ogDesc) . '" />'
      . '<meta property="og:url" content="' . h($base . '/') . '" />'
      . '<meta property="og:image" content="' . h($base . '/og/site.png') . '" />'
      . '<meta name="twitter:card" content="summary_large_image" />'
      . '<meta name="twitter:title" content="' . h($ogTitle) . '" />'
      . '<meta name="twitter:description" content="' . h($ogDesc) . '" />'
      . '<meta name="twitter:image" content="' . h($base . '/og/site.png') . '" />';
  }

  $customScripts = ($settings !== null && ($settings['custom_scripts'] ?? '') !== '') ? $settings['custom_scripts'] : '';

  // A recovery key is rotated on use; show the new one once, right after login.
  $flash = '';
  if (!empty($_SESSION['flash_recovery_key'])) {
    $flash = '<div class="notification is-warning">You used your recovery key, and it was '
      . 'rotated. Save your new recovery key now — it is shown one time only: '
      . '<code>' . h((string) $_SESSION['flash_recovery_key']) . '</code></div>';
    unset($_SESSION['flash_recovery_key']);
  }

  http_response_code($d['status'] ?? 200);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html lang="en"><head>'
    . '<meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />'
    . '<title>' . h($title) . '</title>'
    . '<meta name="description" content="' . h($metaDesc) . '" />'
    . '<meta name="keywords" content="' . h($metaKeywords) . '" />'
    . $og
    . '<style>' . cssInline() . '</style>'
    . '<link rel="alternate" type="application/rss+xml" title="RSS Feed" href="/feed.xml" />'
    . $customScripts
    . '</head><body>'
    . viewHeader($settings ?? [])
    . '<main class="container">' . $flash . ($d['body'] ?? '') . '</main>'
    . viewFooter($settings ?? [])
    . viewModals()
    . '<script src="' . HLJS_ORIGIN . '/gh/highlightjs/cdn-release@11.9.0/build/highlight.min.js"></script>'
    . '<script>if(window.hljs){hljs.highlightAll();}</script>'
    . '</body></html>';
  exit;
}

// ============================================================================
// WEB: PAGES
// ============================================================================

/** Authenticate the web session; redirects to onboarding/login on failure. */
function requireWebAuth(): array
{
  $settings = settingsFetch();
  if ($settings === null || (int) $settings['onboarding_complete'] !== 1) {
    redirect('/onboarding');
  }
  $token = $_SESSION['authToken'] ?? '';
  if ($token === '' || !hash_equals((string) $settings['auth_token'], $token)) {
    redirect('/login?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
  }
  return $settings;
}

function isWebAuthenticated(?array $settings = null): bool
{
  $settings ??= settingsFetch();
  $token = $_SESSION['authToken'] ?? '';
  return $settings !== null && $token !== '' && hash_equals((string) $settings['auth_token'], $token);
}

/** FTS5 MATCH expression: AND of quoted per-word terms (trigram substring match). */
function ftsMatchExpr(string $q): ?string
{
  $terms = preg_split('/\s+/u', jsTrim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $terms = array_values(array_filter($terms, fn(string $t): bool => mb_strlen($t) >= 3));
  if (count($terms) === 0) {
    return null;
  }
  return implode(' ', array_map(fn(string $t): string => '"' . str_replace('"', '""', $t) . '"', $terms));
}

/**
 * Published posts ranked by FTS5 bm25 (title, slug, tags, keywords, excerpt, body).
 * Queries shorter than 3 chars cannot match a trigram index — fall back to a
 * title LIKE.
 */
function searchPublishedPosts(string $q): array
{
  $q = jsTrim($q);
  if ($q === '') {
    return [];
  }
  $db = getDb();
  $expr = ftsMatchExpr($q);
  if (mb_strlen($q) < 3 || $expr === null) {
    $stmt = $db->prepare(
      "SELECT * FROM posts WHERE status = 'published' AND title LIKE ?
             ORDER BY COALESCE(NULLIF(published_at, ''), created_at) DESC"
    );
    $stmt->execute(['%' . $q . '%']);
    return array_map('postToView', array_map('rowToPost', $stmt->fetchAll()));
  }
  $stmt = $db->prepare(
    "SELECT p.* FROM posts_fts JOIN posts p ON p.rowid = posts_fts.rowid
         WHERE posts_fts MATCH ? AND p.status = 'published'
         ORDER BY bm25(posts_fts, 10.0, 6.0, 8.0, 7.0, 4.0, 1.0)"
  );
  $stmt->execute([$expr]);
  return array_map('postToView', array_map('rowToPost', $stmt->fetchAll()));
}

function postToView(array $p): array
{
  $json = postToJson($p);
  $json['excerpt'] = generateExcerpt($p['body'], 200);
  $json['bodyHtml'] = renderBodyHtml($p['body']);
  $json['isPublished'] = $p['status'] === 'published';
  $json['firstImage'] = firstImageFromBody($p['body'], $p['cover_image']);
  return $json;
}

/** Published posts sorted newest first, plus the sorted tag list. */
function publishedPostsAndTags(): array
{
  $published = array_values(array_filter(
    array_map('postToView', dbAllPosts()),
    fn(array $p): bool => $p['status'] === 'published'
  ));
  usort($published, fn($a, $b) => strtotime($b['published_at']) <=> strtotime($a['published_at']));
  $tags = [];
  foreach ($published as $p) {
    foreach ($p['tags'] as $t) {
      $tags[$t] = true;
    }
  }
  $tags = array_keys($tags);
  sort($tags);
  return [$published, $tags];
}

function serveHome(array $m): never
{
  $tag = is_string($_GET['tag'] ?? null) ? $_GET['tag'] : '';
  $pageNum = max(1, (int) ($_GET['page'] ?? 0) ?: 1);
  $limit = 10;
  $skip = ($pageNum - 1) * $limit;

  [$published, $tags] = publishedPostsAndTags();
  $filtered = $tag !== ''
    ? array_values(array_filter($published, fn(array $p): bool => in_array($tag, $p['tags'], true)))
    : $published;

  $totalPages = (int) ceil(count($filtered) / $limit);
  $posts = array_slice($filtered, $skip, $limit);
  $settings = webSettings();

  renderPage([
    'settings' => $settings,
    'body' => viewIndex([
      'posts' => $posts,
      'settings' => $settings,
      'tags' => $tags,
      'activeTag' => $tag,
      'query' => '',
      'currentPage' => $pageNum,
      'totalPages' => $totalPages,
      'prevPage' => $pageNum > 1 ? $pageNum - 1 : null,
      'nextPage' => $pageNum < $totalPages ? $pageNum + 1 : null,
    ]),
  ]);
}

function serveSearch(array $m): never
{
  $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
  $pageNum = max(1, (int) ($_GET['page'] ?? 0) ?: 1);
  $limit = 10;
  $skip = ($pageNum - 1) * $limit;

  [, $tags] = publishedPostsAndTags();
  $results = searchPublishedPosts($q);

  $totalPages = (int) ceil(count($results) / $limit);
  $posts = array_slice($results, $skip, $limit);
  $settings = webSettings();

  renderPage([
    'settings' => $settings,
    'body' => viewSearch([
      'posts' => $posts,
      'settings' => $settings,
      'tags' => $tags,
      'query' => $q,
      'currentPage' => $pageNum,
      'totalPages' => $totalPages,
      'prevPage' => $pageNum > 1 ? $pageNum - 1 : null,
      'nextPage' => $pageNum < $totalPages ? $pageNum + 1 : null,
    ]),
  ]);
}

function servePost(array $m): never
{
  $slug = urldecode($m[1]);
  $settingsRow = settingsFetch();
  $isAuthenticated = isWebAuthenticated($settingsRow);
  $all = dbAllPosts();

  $post = null;
  foreach ($all as $p) {
    if ($p['slug'] !== $slug) {
      continue;
    }
    if ($p['status'] === 'published' || ($p['status'] === 'draft' && $isAuthenticated)) {
      $post = $p;
      break;
    }
  }

  $settings = webSettings();
  if ($post === null) {
    renderPage([
      'status' => 404,
      'settings' => $settings,
      'body' => view404('This post may be a draft or has been removed.'),
    ]);
  }

  $previewMode = $post['status'] === 'draft' && $isAuthenticated;

  $sorted = array_values(array_filter(
    array_map('postToView', $all),
    fn(array $p): bool => $p['status'] === 'published'
  ));
  usort($sorted, fn($a, $b) => strtotime($b['published_at']) <=> strtotime($a['published_at']));

  $index = null;
  foreach ($sorted as $i => $p) {
    if ($p['slug'] === $post['slug']) {
      $index = $i;
      break;
    }
  }
  $prevPost = ($index !== null && $index > 0) ? $sorted[$index - 1] : null;
  $nextPost = ($index !== null && $index < count($sorted) - 1) ? $sorted[$index + 1] : null;

  $postView = postToView($post);
  $postView['bodyHtml'] = renderGistsInHtml($postView['bodyHtml']);

  renderPage([
    'post' => $postView,
    'settings' => $settings,
    'body' => viewPost([
      'post' => $postView,
      'prevPost' => $prevPost,
      'nextPost' => $nextPost,
      'settings' => $settings,
      'previewMode' => $previewMode,
      'isAuthenticated' => $isAuthenticated,
    ]),
  ]);
}

function serveLoginGet(array $m): never
{
  $redirectTo = trim((string) ($_GET['redirect'] ?? '/'));
  if ($redirectTo === '' || $redirectTo[0] !== '/' || str_starts_with($redirectTo, '//')) {
    $redirectTo = '/';
  }
  $settingsRow = settingsFetch();
  if ($settingsRow === null || (int) $settingsRow['onboarding_complete'] !== 1) {
    redirect('/onboarding');
  }
  if (isWebAuthenticated($settingsRow)) {
    redirect($redirectTo);
  }
  $showUsername = jsTruthy($settingsRow['username']);
  renderPage([
    'title' => $settingsRow['title'] !== '' ? $settingsRow['title'] : 'Writer',
    'settings' => ['title' => $settingsRow['title'], 'author' => $settingsRow['author']],
    'body' => viewLogin([
      'title' => $settingsRow['title'] !== '' ? $settingsRow['title'] : 'Writer',
      'subtitle' => 'Enter your credentials to continue',
      'action' => '/login',
      'showUsername' => $showUsername,
      'tokenRequired' => !$showUsername,
      'error' => null,
      'redirectTo' => $redirectTo,
    ]),
  ]);
}

function serveLoginPost(array $m): never
{
  if (rateLimitExceeded('login', clientIp(), 5, 900)) {
    jsonResponse(['error' => 'Too many requests', 'message' => 'Too many login attempts. Please try again later.'], 429);
  }
  verifyCsrf();

  $rawToken = isset($_POST['token']) ? trim((string) $_POST['token']) : '';
  $rawUsername = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
  $rawPassword = isset($_POST['password']) ? (string) $_POST['password'] : '';

  $redirectTo = trim((string) ($_POST['redirect'] ?? '/'));
  if ($redirectTo === '' || $redirectTo[0] !== '/' || str_starts_with($redirectTo, '//')) {
    $redirectTo = '/';
  }

  $settingsRow = settingsFetch();
  if ($settingsRow === null) {
    redirect('/onboarding');
  }

  $isValid = false;
  if ($rawToken !== '') {
    $isValid = hash_equals((string) $settingsRow['auth_token'], $rawToken);
  } elseif ($rawUsername !== '' && $rawPassword !== '') {
    $passwordOk = verifyPasswordSecret($rawPassword, (string) $settingsRow['password_hash']);
    $recoveryOk = verifyRecoveryKey($rawPassword, (string) $settingsRow['recovery_key_hash']);
    $isValid = hash_equals((string) $settingsRow['username'], $rawUsername) && ($passwordOk || $recoveryOk);
    if ($isValid && $recoveryOk && !$passwordOk) {
      $newKey = generateRecoveryKey();
      $settingsRow['recovery_key_hash'] = hashRecoveryKey($newKey);
      $settingsRow['updated_at'] = isoNow();
      settingsSave($settingsRow);
      $_SESSION['flash_recovery_key'] = $newKey;
    } elseif ($isValid && $passwordOk && passwordNeedsRehash((string) $settingsRow['password_hash'])) {
      $settingsRow['password_hash'] = hashPasswordSecret($rawPassword);
      $settingsRow['updated_at'] = isoNow();
      settingsSave($settingsRow);
    }
  }

  if ($isValid) {
    rateLimitClear('login', clientIp());
    session_regenerate_id(true);
    $_SESSION['authToken'] = $settingsRow['auth_token'];
    redirect($redirectTo);
  }

  $showUsername = jsTruthy($settingsRow['username']);
  renderPage([
    'title' => $settingsRow['title'] !== '' ? $settingsRow['title'] : 'Writer',
    'settings' => ['title' => $settingsRow['title'], 'author' => $settingsRow['author']],
    'body' => viewLogin([
      'title' => $settingsRow['title'] !== '' ? $settingsRow['title'] : 'Writer',
      'subtitle' => 'Enter your credentials to continue',
      'action' => '/login',
      'showUsername' => $showUsername,
      'tokenRequired' => !$showUsername,
      'error' => 'Invalid credentials',
      'redirectTo' => $redirectTo,
    ]),
  ]);
}

function serveLogout(array $m): never
{
  verifyCsrf();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie('session', '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  redirect('/');
}

function serveOnboardingGet(array $m): never
{
  $settingsRow = settingsFetch();
  if ($settingsRow !== null && (int) $settingsRow['onboarding_complete'] === 1) {
    redirect('/');
  }
  renderPage([
    'settings' => ['title' => 'Setup Your Blog'],
    'body' => viewOnboarding(),
  ]);
}

function serveDocs(array $m): never
{
  renderPage([
    'settings' => webSettings(),
    'body' => viewDocs(),
  ]);
}

function serveSettingsUpdate(array $m): never
{
  requireWebAuth();
  verifyCsrf();
  applySettingsUpdate(getInput(), $_SESSION['authToken'] ?? '');
  redirect('/settings');
}

/** Panel-side search over ALL posts (drafts included), FTS5-ranked like /search. */
function searchAllPosts(string $q): array
{
  $q = jsTrim($q);
  if ($q === '') {
    return [];
  }
  $db = getDb();
  $expr = ftsMatchExpr($q);
  if (mb_strlen($q) < 3 || $expr === null) {
    $stmt = $db->prepare(
      "SELECT * FROM posts WHERE title LIKE ?
             ORDER BY COALESCE(NULLIF(published_at, ''), created_at) DESC"
    );
    $stmt->execute(['%' . $q . '%']);
    return array_map('rowToPost', $stmt->fetchAll());
  }
  $stmt = $db->prepare(
    'SELECT p.* FROM posts_fts JOIN posts p ON p.rowid = posts_fts.rowid
         WHERE posts_fts MATCH ?
         ORDER BY bm25(posts_fts, 10.0, 6.0, 8.0, 7.0, 4.0, 1.0)'
  );
  $stmt->execute([$expr]);
  return array_map('rowToPost', $stmt->fetchAll());
}

function servePanel(array $m): never
{
  $settingsRow = requireWebAuth();
  $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
  $pageNum = max(1, (int) ($_GET['page'] ?? 0) ?: 1);
  $limit = 15;
  $skip = ($pageNum - 1) * $limit;

  $all = array_map('postToJson', dbAllPosts());
  if (jsTrim($q) !== '') {
    $all = array_map('postToJson', searchAllPosts($q));
  }
  usort($all, fn($a, $b) => strtotime($b['published_at']) <=> strtotime($a['published_at']));

  $totalPages = (int) ceil(count($all) / $limit);
  $posts = array_slice($all, $skip, $limit);
  $settings = settingsToJson($settingsRow);

  renderPage([
    'settings' => $settings,
    'body' => viewPanel([
      'posts' => $posts,
      'query' => $q,
      'token' => $_SESSION['authToken'] ?? '',
      'currentPage' => $pageNum,
      'totalPages' => $totalPages,
      'prevPage' => $pageNum > 1 ? $pageNum - 1 : null,
      'nextPage' => $pageNum < $totalPages ? $pageNum + 1 : null,
    ]),
  ]);
}

function serveSettings(array $m): never
{
  $settingsRow = requireWebAuth();
  $settings = settingsToJson($settingsRow);
  renderPage([
    'settings' => $settings,
    'body' => viewSettings([
      'settings' => $settings,
      'token' => $_SESSION['authToken'] ?? '',
      'error' => null,
    ]),
  ]);
}

function serveSettingsUpdateCredentials(array $m): never
{
  $settingsRow = requireWebAuth();
  verifyCsrf();
  $input = getInput();
  $settings = settingsToJson($settingsRow);

  $password = $input['password'] ?? '';
  if (jsTruthy($password) && $password !== ($input['confirmPassword'] ?? '')) {
    renderPage([
      'settings' => $settings,
      'body' => viewSettings([
        'settings' => $settings,
        'token' => $_SESSION['authToken'] ?? '',
        'error' => 'Passwords do not match',
      ]),
    ]);
  }

  applyCredentialsUpdate($input, $_SESSION['authToken'] ?? '');
  redirect('/settings');
}

// ============================================================================
// ROUTER
// ============================================================================

function route(string $method, string $pattern, callable $handler): bool
{
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
    return false;
  }
  if (preg_match($pattern, requestPath(), $m)) {
    $handler($m);
    return true;
  }
  return false;
}

function notFound(): never
{
  renderPage([
    'status' => 404,
    'settings' => ['title' => 'Page Not Found - Writer'],
    'body' => view404('The page "' . requestPath() . '" could not be found.'),
  ]);
}

// ============================================================================
// API: HEALTH
// ============================================================================

function apiHealth(array $m): void
{
  $ms = (int) round((microtime(true) - floor(microtime(true))) * 1000);
  jsonResponse([
    'status' => 'ok',
    'timestamp' => gmdate('Y-m-d\TH:i:s', floor(microtime(true))) . sprintf('.%03d', $ms) . 'Z',
  ]);
}

// ============================================================================
// API: DOCS
// ============================================================================

function apiGetDocs(array $m): never
{
  $base = baseUrl();
  jsonResponse([
    'name' => 'Writer API',
    'version' => '1.0.0',
    'description' => 'Self-discoverable RESTful API for blog management',
    'authentication' => [
      'header' => 'X-Auth-Token',
      'description' => 'All protected endpoints require this header',
      'example' => 'X-Auth-Token: your-auth-token-here',
      'get_token' => 'POST /api/onboarding',
    ],
    'endpoints' => [
      'public' => [
        ['method' => 'GET', 'path' => '/api', 'description' => 'API documentation (this endpoint)', 'response' => 'JSON API specification'],
        ['method' => 'GET', 'path' => '/api/posts', 'description' => 'List all posts with pagination', 'query' => ['page' => 'Page number (default: 1)', 'limit' => 'Posts per page (default: 10)'], 'response' => '{ posts: [], pagination: {} }'],
        ['method' => 'GET', 'path' => '/api/posts/:slug', 'description' => 'Get single post by slug', 'response' => 'Post object'],
        ['method' => 'GET', 'path' => '/api/settings', 'description' => 'Get blog settings', 'response' => 'Settings object'],
        ['method' => 'POST', 'path' => '/api/onboarding', 'description' => 'Complete blog setup (first time only)', 'payload' => ['title' => 'Blog title (required)', 'author' => 'Author name (optional)', 'description' => 'Blog description (optional)'], 'response' => 'Settings with auth token'],
      ],
      'protected' => [
        ['method' => 'POST', 'path' => '/api/posts', 'description' => 'Create a new post', 'header' => 'X-Auth-Token (required)', 'payload' => ['title' => 'Post title (required)', 'body' => 'Post content (required)', 'tags' => 'Array of tags (optional)'], 'inference' => ['slug' => 'Generated from title (lowercase, hyphens)', 'keywords' => 'Extracted from title and body', 'meta_description' => 'First 160 chars of body', 'reading_time' => 'Calculated from word count'], 'response' => 'Created post object'],
        ['method' => 'PUT', 'path' => '/api/posts/:slug', 'description' => 'Update an existing post', 'header' => 'X-Auth-Token (required)', 'payload' => ['title' => 'Post title (optional)', 'body' => 'Post content (optional)', 'tags' => 'Array of tags (optional)'], 'response' => 'Updated post object'],
        ['method' => 'DELETE', 'path' => '/api/posts/:slug', 'description' => 'Delete a post (requires confirmation)', 'header' => 'X-Auth-Token (required)', 'response' => '{ confirmation_required: true, confirmation_url: "/api/confirm/..." }'],
        ['method' => 'PUT', 'path' => '/api/settings', 'description' => 'Update blog settings', 'header' => 'X-Auth-Token (required)', 'payload' => ['title' => 'Blog title (optional)', 'author' => 'Author name (optional)', 'description' => 'Blog description (optional)', 'custom_scripts' => 'Custom HTML/scripts (optional)', 'csp_script_domains' => 'Comma-separated allowed script domains for CSP (optional)'], 'response' => 'Updated settings object'],
        ['method' => 'POST', 'path' => '/api/settings/rotate-token', 'description' => 'Rotate the auth token (requires confirmation)', 'header' => 'X-Auth-Token (required)', 'response' => '{ confirmation_required: true, confirmation_url: "/api/confirm/..." }'],
        ['method' => 'POST', 'path' => '/api/confirm/:token', 'description' => 'Confirm a pending destructive action', 'header' => 'X-Auth-Token (required)', 'response' => 'Result of the confirmed action'],
      ],
    ],
    'example_usage' => [
      'create_post' => [
        'curl' => "curl -X POST {$base}/api/posts \\\n  -H \"Content-Type: application/json\" \\\n  -H \"X-Auth-Token: your-token-here\" \\\n  -d '{\"title\": \"My First Post\", \"body\": \"Hello World!\", \"tags\": [\"intro\"]}'",
      ],
      'list_posts' => ['curl' => "curl {$base}/api/posts"],
    ],
    'links' => [
      'home' => $base . '/',
      'onboarding' => $base . '/onboarding',
    ],
  ]);
}

// ============================================================================
// API: AUTH
// ============================================================================

function optionalAuthRow(): ?array
{
  $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;
  if (!is_string($token) || $token === '') {
    return null;
  }
  $settings = settingsFetch();
  if ($settings === null || !hash_equals((string) $settings['auth_token'], $token)) {
    return null;
  }
  return $settings;
}

/**
 * Authenticate an explicit token (not the request header). Used by the
 * header-based route wrapper and by web-form handlers passing the session
 * token — no HTTP self-fetch.
 */
function requireAuthRow(string $token): array
{
  $settings = settingsFetch();
  if ($settings === null) {
    jsonResponse([
      'error' => 'Unauthorized',
      'message' => 'Blog not configured',
      'hint' => 'Visit /onboarding to set up your blog',
    ], 401);
  }
  if ($token === '' || !hash_equals((string) $settings['auth_token'], $token)) {
    jsonResponse(['error' => 'Unauthorized', 'message' => 'Invalid auth token'], 401);
  }
  return $settings;
}

function requireAuth(): array
{
  $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;
  if (!is_string($token) || $token === '') {
    jsonResponse([
      'error' => 'Unauthorized',
      'message' => 'X-Auth-Token header is required',
      'hint' => 'Visit /onboarding to generate an auth token',
    ], 401);
  }
  return requireAuthRow($token);
}

// ============================================================================
// API: SETTINGS
// ============================================================================

function settingsFetch(): ?array
{
  $row = getDb()->query("SELECT * FROM settings WHERE id = 'settings'")->fetch();
  return $row === false ? null : $row;
}

function settingsSave(array $s): void
{
  $stmt = getDb()->prepare(
    'INSERT OR REPLACE INTO settings
        (id, title, author, description, username, password_hash, recovery_key_hash,
         auth_token, about, custom_scripts, csp_script_domains, onboarding_complete,
         show_docs, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    'settings',
    $s['title'] ?? '',
    $s['author'] ?? '',
    $s['description'] ?? '',
    $s['username'] ?? '',
    $s['password_hash'] ?? '',
    $s['recovery_key_hash'] ?? '',
    $s['auth_token'] ?? '',
    $s['about'] ?? '',
    $s['custom_scripts'] ?? '',
    $s['csp_script_domains'] ?? '',
    (int) ($s['onboarding_complete'] ?? 0),
    (int) ($s['show_docs'] ?? 1),
    $s['created_at'] ?? '',
    $s['updated_at'] ?? '',
  ]);
}

function settingsToJson(array $s): array
{
  return [
    'id' => 'settings',
    'title' => $s['title'],
    'author' => $s['author'],
    'description' => $s['description'],
    'username' => $s['username'],
    'hasPassword' => ($s['password_hash'] ?? '') !== '',
    'auth_token' => $s['auth_token'],
    'about' => $s['about'],
    'custom_scripts' => $s['custom_scripts'],
    'csp_script_domains' => $s['csp_script_domains'],
    'onboarding_complete' => (bool) $s['onboarding_complete'],
    'show_docs' => (bool) $s['show_docs'],
    'created_at' => $s['created_at'],
    'updated_at' => $s['updated_at'],
  ];
}

function apiGetSettings(array $m): never
{
  $settings = settingsFetch();
  if ($settings === null) {
    jsonResponse([
      'onboarding_complete' => false,
      'message' => 'Blog not configured. Visit /onboarding to set up.',
    ]);
  }
  $json = settingsToJson($settings);
  unset($json['auth_token'], $json['username']);
  jsonResponse($json);
}

function apiOnboarding(array $input): never
{
  $title = $input['title'] ?? null;
  if (!jsTruthy($title)) {
    jsonResponse(['error' => 'Validation Error', 'message' => 'Blog title is required'], 400);
  }
  $existing = settingsFetch();
  if ($existing !== null && (int) $existing['onboarding_complete'] === 1) {
    jsonResponse(['error' => 'Conflict', 'message' => 'Blog is already configured. Use PUT to update.'], 400);
  }
  $password = $input['password'] ?? '';
  $authToken = generateToken(32);
  $recoveryKey = generateRecoveryKey();
  $data = [
    'id' => 'settings',
    'title' => $title,
    'author' => $input['author'] ?? '',
    'description' => $input['description'] ?? '',
    'custom_scripts' => $input['custom_scripts'] ?? '',
    'username' => $input['username'] ?? '',
    'password_hash' => jsTruthy($password) ? hashPasswordSecret((string) $password) : '',
    'recovery_key_hash' => hashRecoveryKey($recoveryKey),
    'auth_token' => $authToken,
    'about' => $existing['about'] ?? '',
    'csp_script_domains' => $existing['csp_script_domains'] ?? '',
    'onboarding_complete' => 1,
    'show_docs' => $existing['show_docs'] ?? 1,
    'created_at' => $existing['created_at'] ?? isoNow(),
    'updated_at' => isoNow(),
  ];
  settingsSave($data);
  clearOGCache('site');
  jsonResponse(array_merge(settingsToJson($data), [
    'message' => 'Blog configured successfully',
    'recovery_key' => $recoveryKey,
    'instructions' => [
      'header' => 'X-Auth-Token',
      'value' => $authToken,
      'usage' => 'Include this token in the X-Auth-Token header for all protected API requests',
    ],
  ]), 201);
}

function executeRotateToken(): array
{
  $settings = settingsFetch();
  if ($settings === null) {
    jsonResponse(['error' => 'Not Found', 'message' => 'Blog not configured.'], 404);
  }
  $settings['auth_token'] = generateToken(32);
  $settings['updated_at'] = isoNow();
  settingsSave($settings);
  return ['message' => 'Auth token rotated successfully', 'auth_token' => $settings['auth_token']];
}

function apiRotateTokenRequest(array $m): never
{
  if (settingsFetch() === null) {
    jsonResponse(['error' => 'Not Found', 'message' => 'Blog not configured.'], 404);
  }
  $token = confirmationCreate('rotate-token', []);
  jsonResponse([
    'confirmation_required' => true,
    'message' => 'This action requires confirmation. Please send a POST request to the confirmation_url to proceed.',
    'confirmation_url' => '/api/confirm/' . $token,
  ], 202);
}

/** Apply a settings update; returns the saved row (shared by API + web form). */
function applySettingsUpdate(array $input, string $authToken): array
{
  $settings = requireAuthRow($authToken);
  $has = fn(string $k): bool => array_key_exists($k, $input);
  $updated = $settings;
  foreach (['title', 'author', 'description', 'about', 'custom_scripts', 'csp_script_domains', 'show_docs'] as $field) {
    if ($has($field)) {
      $updated[$field] = $input[$field];
    }
  }
  if ($has('show_docs')) {
    // JSON sends a real boolean; the HTML form fallback sends "on".
    $updated['show_docs'] = is_bool($input['show_docs']) ? $input['show_docs'] : jsTruthy($input['show_docs']);
  }
  $updated['updated_at'] = isoNow();
  settingsSave($updated);
  clearOGCache('site');
  return $updated;
}

function apiUpdateSettings(array $input, string $authToken): never
{
  jsonResponse(settingsToJson(applySettingsUpdate($input, $authToken)));
}

function applyCredentialsUpdate(array $input, string $authToken): array
{
  $settings = requireAuthRow($authToken);
  $username = $input['username'] ?? null;
  $updated = $settings;
  $updated['username'] = jsTruthy($username) ? $username : '';
  if (jsTruthy($input['password'] ?? null)) {
    $updated['password_hash'] = hashPasswordSecret((string) $input['password']);
  }
  $updated['updated_at'] = isoNow();
  settingsSave($updated);
  return $updated;
}

function apiUpdateCredentials(array $input, string $authToken): never
{
  $updated = applyCredentialsUpdate($input, $authToken);
  jsonResponse([
    'username' => $updated['username'],
    'hasPassword' => $updated['password_hash'] !== '',
    'message' => 'Credentials updated successfully',
  ]);
}

// ============================================================================
// API: CONFIRMATIONS
// ============================================================================

function confirmationCreate(string $action, array $data, int $ttlMs = 300000): string
{
  $token = bin2hex(random_bytes(16));
  $stmt = getDb()->prepare('INSERT INTO confirmations (token, action, data, expires_at) VALUES (?, ?, ?, ?)');
  $stmt->execute([$token, $action, json_encode($data), (int) round(microtime(true) * 1000) + $ttlMs]);
  return $token;
}

function confirmationGet(string $token): ?array
{
  $stmt = getDb()->prepare('SELECT * FROM confirmations WHERE token = ? LIMIT 1');
  $stmt->execute([$token]);
  $row = $stmt->fetch();
  if ($row === false) {
    return null;
  }
  if ((int) round(microtime(true) * 1000) > (int) $row['expires_at']) {
    confirmationDelete($token);
    return null;
  }
  $row['data'] = json_decode($row['data'], true) ?? [];
  return $row;
}

function confirmationDelete(string $token): void
{
  $stmt = getDb()->prepare('DELETE FROM confirmations WHERE token = ?');
  $stmt->execute([$token]);
}

function apiConfirm(string $token): never
{
  $pending = confirmationGet($token);
  if ($pending === null) {
    jsonResponse(['error' => 'Gone', 'message' => 'Confirmation token expired or invalid'], 410);
  }
  switch ($pending['action']) {
    case 'delete-post':
      $result = executeDeletePost((string) ($pending['data']['slug'] ?? ''));
      break;
    case 'rotate-token':
      $result = executeRotateToken();
      break;
    default:
      jsonResponse(['error' => 'Bad Request', 'message' => 'Unknown confirmation action'], 400);
  }
  confirmationDelete($token);
  jsonResponse($result);
}

// ============================================================================
// API: POSTS
// ============================================================================

function rowToPost(array $row): array
{
  $row['reading_time'] = (int) $row['reading_time'];
  $tags = json_decode($row['tags'], true);
  $row['tags'] = is_array($tags) ? $tags : [];
  // Node's Post constructor: created_at || now, then published_at || created_at.
  $row['created_at'] = jsTruthy($row['created_at']) ? $row['created_at'] : isoNow();
  $row['published_at'] = jsTruthy($row['published_at']) ? $row['published_at'] : $row['created_at'];
  return $row;
}

function dbAllPosts(): array
{
  $rows = getDb()->query('SELECT * FROM posts ORDER BY rowid')->fetchAll();
  return array_map('rowToPost', $rows);
}

function dbFindPostBySlug(string $slug): ?array
{
  $stmt = getDb()->prepare('SELECT * FROM posts WHERE slug = ? LIMIT 1');
  $stmt->execute([$slug]);
  $row = $stmt->fetch();
  return $row === false ? null : rowToPost($row);
}

function dbInsertPost(array $p): void
{
  $stmt = getDb()->prepare(
    'INSERT INTO posts
        (id, title, slug, body, excerpt, cover_image, keywords, meta_description,
         reading_time, status, tags, created_at, updated_at, published_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $p['id'],
    $p['title'],
    $p['slug'],
    $p['body'],
    $p['excerpt'],
    $p['cover_image'],
    $p['keywords'],
    $p['meta_description'],
    $p['reading_time'],
    $p['status'],
    json_encode($p['tags']),
    $p['created_at'],
    $p['updated_at'],
    $p['published_at'],
  ]);
}

function dbUpdatePost(array $p): void
{
  $stmt = getDb()->prepare(
    'UPDATE posts SET title = ?, slug = ?, body = ?, excerpt = ?, cover_image = ?,
         keywords = ?, meta_description = ?, reading_time = ?, status = ?, tags = ?,
         created_at = ?, updated_at = ?, published_at = ? WHERE id = ?'
  );
  $stmt->execute([
    $p['title'],
    $p['slug'],
    $p['body'],
    $p['excerpt'],
    $p['cover_image'],
    $p['keywords'],
    $p['meta_description'],
    $p['reading_time'],
    $p['status'],
    json_encode($p['tags']),
    $p['created_at'],
    $p['updated_at'],
    $p['published_at'],
    $p['id'],
  ]);
}

function dbDeletePostById(string $id): void
{
  $stmt = getDb()->prepare('DELETE FROM posts WHERE id = ?');
  $stmt->execute([$id]);
}

function uniqueSlugFromDb(string $base, ?string $excludeId = null): string
{
  $rows = getDb()->query('SELECT id, slug FROM posts')->fetchAll();
  return generateUniqueSlug($rows, $base, $excludeId);
}

function postToJson(array $p): array
{
  return [
    'id' => $p['id'],
    'title' => $p['title'],
    'slug' => $p['slug'],
    'body' => $p['body'],
    'excerpt' => $p['excerpt'],
    'cover_image' => $p['cover_image'],
    'keywords' => $p['keywords'],
    'meta_description' => $p['meta_description'],
    'reading_time' => (int) $p['reading_time'],
    'status' => $p['status'],
    'tags' => $p['tags'],
    'created_at' => $p['created_at'],
    'updated_at' => $p['updated_at'],
    'published_at' => $p['published_at'],
  ];
}

function postToApiJson(array $p): array
{
  $json = postToJson($p);
  $json['bodyHtml'] = renderBodyHtml($p['body']);
  return $json;
}

function validatePostInput(array $data, bool $isUpdate = false): array
{
  $errors = [];
  $has = fn(string $k): bool => array_key_exists($k, $data);
  $title = $data['title'] ?? null;
  $body = $data['body'] ?? null;

  if (!$isUpdate || $has('title')) {
    if (!$isUpdate && (!jsTruthy($title) || !is_string($title) || jsTrim($title) === '')) {
      $errors[] = 'Title is required and must be a non-empty string';
    }
    if ($has('title') && !is_string($title)) {
      $errors[] = 'Title must be a string';
    }
    if (is_string($title) && jsTruthy($title) && mb_strlen($title) > 200) {
      $errors[] = 'Title must not exceed 200 characters';
    }
  }

  if (!$isUpdate || $has('body')) {
    if (!$isUpdate && (!jsTruthy($body) || !is_string($body) || jsTrim($body) === '')) {
      $errors[] = 'Body is required and must be a non-empty string';
    }
    if ($has('body') && !is_string($body)) {
      $errors[] = 'Body must be a string';
    }
    if (is_string($body) && jsTruthy($body) && mb_strlen($body) > 50000) {
      $errors[] = 'Body must not exceed 50000 characters';
    }
  }

  if ($has('tags')) {
    $tags = $data['tags'];
    $ok = is_array($tags) && array_is_list($tags);
    if ($ok) {
      foreach ($tags as $t) {
        if (!is_string($t) || mb_strlen($t) > 50) {
          $ok = false;
          break;
        }
      }
    }
    if (!$ok) {
      $errors[] = 'Tags must be an array of strings, each not exceeding 50 characters';
    }
  }

  if ($has('status') && !in_array($data['status'], ['draft', 'published'], true)) {
    $errors[] = 'Status must be either "draft" or "published"';
  }

  if ($has('published_at') && $data['published_at'] !== null) {
    $v = $data['published_at'];
    if (!is_string($v) || strtotime($v) === false) {
      $errors[] = 'published_at must be a valid ISO 8601 date string';
    }
  }

  return $errors;
}

function apiListPosts(array $m): never
{
  $posts = [];
  foreach (dbAllPosts() as $post) {
    if ($post['status'] === 'published') {
      $posts[] = postToApiJson($post);
    }
  }
  usort($posts, fn($a, $b) => strtotime($b['published_at']) <=> strtotime($a['published_at']));

  $page = max(1, (int) ($_GET['page'] ?? 0) ?: 1);
  $limit = min(max(1, (int) ($_GET['limit'] ?? 0) ?: PAGE_SIZE), 100);
  $start = ($page - 1) * $limit;
  $total = count($posts);

  jsonResponse([
    'posts' => array_slice($posts, $start, $limit),
    'pagination' => [
      'page' => $page,
      'limit' => $limit,
      'total' => $total,
      'pages' => (int) ceil($total / $limit),
    ],
  ]);
}

function apiGetPost(array $m): never
{
  $post = dbFindPostBySlug(urldecode($m[1]));
  $authed = optionalAuthRow() !== null;
  if ($post === null || ($post['status'] !== 'published' && !$authed)) {
    jsonResponse(['error' => 'Not Found', 'message' => 'Post not found'], 404);
  }
  jsonResponse(postToApiJson($post));
}

function apiCreatePost(array $input): never
{
  $errors = validatePostInput($input);
  if ($errors) {
    jsonResponse(['error' => 'Validation Error', 'message' => implode('; ', $errors)], 400);
  }

  $title = $input['title'];
  $body = $input['body'];
  $inferred = inferMetadata($title, $body);
  $now = isoNow();

  $post = [
    'id' => genUuid(),
    'title' => $title,
    'slug' => uniqueSlugFromDb($inferred['slug']),
    'body' => $body,
    'cover_image' => jsTruthy($input['cover_image'] ?? null) ? (string) $input['cover_image'] : '',
    'excerpt' => $inferred['excerpt'],
    'keywords' => $inferred['keywords'],
    'meta_description' => $inferred['meta_description'],
    'reading_time' => $inferred['reading_time'],
    'status' => jsTruthy($input['status'] ?? null) ? $input['status'] : 'draft',
    'tags' => $input['tags'] ?? [],
    'created_at' => $now,
    'updated_at' => $now,
    'published_at' => jsTruthy($input['published_at'] ?? null) ? $input['published_at'] : $now,
  ];

  dbInsertPost($post);
  jsonResponse(postToApiJson($post), 201);
}

function apiUpdatePost(string $slug, array $input): never
{
  $post = dbFindPostBySlug($slug);
  if ($post === null) {
    jsonResponse(['error' => 'Not Found', 'message' => 'Post not found'], 404);
  }

  $errors = validatePostInput($input, true);
  if ($errors) {
    jsonResponse(['error' => 'Validation Error', 'message' => implode('; ', $errors)], 400);
  }

  $has = fn(string $k): bool => array_key_exists($k, $input);
  $bodyInput = $input['body'] ?? null;
  $titleInput = $input['title'] ?? null;
  $processedBody = is_string($bodyInput) ? $bodyInput : $post['body'];

  $newSlug = $post['slug'];
  if (is_string($bodyInput) && jsTruthy($titleInput) && $titleInput !== $post['title']) {
    $inferred = inferMetadata($titleInput, $processedBody);
    $newSlug = uniqueSlugFromDb($inferred['slug'], $post['id']);
  }

  $updated = $post;
  $updated['title'] = jsTruthy($titleInput) ? $titleInput : $post['title'];
  $updated['slug'] = $newSlug;
  $updated['body'] = $processedBody;
  if ($has('cover_image')) {
    $updated['cover_image'] = jsTruthy($input['cover_image']) ? (string) $input['cover_image'] : '';
  }
  $regenerate = jsTruthy($bodyInput);
  $updated['excerpt'] = $regenerate ? generateExcerpt($processedBody) : $post['excerpt'];
  $updated['keywords'] = $regenerate
    ? extractKeywords(jsTruthy($titleInput) ? $titleInput : $post['title'], $processedBody)
    : $post['keywords'];
  $updated['meta_description'] = $regenerate ? generateMetaDescription($processedBody) : $post['meta_description'];
  $updated['reading_time'] = $regenerate ? calculateReadingTime($processedBody) : $post['reading_time'];
  $updated['status'] = jsTruthy($input['status'] ?? null)
    ? $input['status']
    : (jsTruthy($post['status']) ? $post['status'] : 'draft');
  $updated['tags'] = $has('tags') ? $input['tags'] : $post['tags'];
  $updated['published_at'] = jsTruthy($input['published_at'] ?? null) ? $input['published_at'] : $post['published_at'];
  $updated['updated_at'] = isoNow();

  dbUpdatePost($updated);
  clearOGCache($slug);
  if ($newSlug !== $slug) {
    clearOGCache($newSlug);
  }
  jsonResponse(postToApiJson($updated));
}

function executeDeletePost(string $slug): array
{
  $post = dbFindPostBySlug($slug);
  if ($post === null) {
    jsonResponse(['error' => 'Not Found', 'message' => 'Post not found'], 404);
  }
  dbDeletePostById($post['id']);
  clearOGCache($slug);
  return ['message' => 'Post deleted successfully', 'slug' => $slug];
}

function apiDeletePost(string $slug): never
{
  if (dbFindPostBySlug($slug) === null) {
    jsonResponse(['error' => 'Not Found', 'message' => 'Post not found'], 404);
  }
  $token = confirmationCreate('delete-post', ['slug' => $slug]);
  jsonResponse([
    'confirmation_required' => true,
    'message' => 'This action requires confirmation. Please send a POST request to the confirmation_url to proceed.',
    'confirmation_url' => '/api/confirm/' . $token,
  ], 202);
}

// ============================================================================
// BOOTSTRAP
// ============================================================================

if (defined('WRITER_TESTING')) {
  return; // library mode for tests.php: no session, no dispatch
}

bootSession();
initDatabase();

$settingsRow = settingsFetch();
sendSecurityHeaders($settingsRow['csp_script_domains'] ?? null);

dispatch();

function dispatch(): never
{
  // Before setup, send every human-facing page to the onboarding form.
  // API, feed and OG routes stay reachable (they answer to machines).
  $settings = settingsFetch();
  if ($settings === null || (int) $settings['onboarding_complete'] !== 1) {
    $path = requestPath();
    $machine = str_starts_with($path, '/api')
      || $path === '/feed.xml'
      || str_starts_with($path, '/og/');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !$machine && $path !== '/onboarding') {
      redirect('/onboarding');
    }
  }

  route('GET', '#^/api/health$#', 'apiHealth');
  route('GET', '#^/api$#', 'apiGetDocs');
  route('GET', '#^/api/posts$#', 'apiListPosts');
  route('GET', '#^/api/posts/([^/]+)$#', 'apiGetPost');
  route('POST', '#^/api/posts$#', function (array $m) {
    requireAuth();
    apiCreatePost(getInput());
  });
  route('PUT', '#^/api/posts/([^/]+)$#', function (array $m) {
    requireAuth();
    apiUpdatePost(urldecode($m[1]), getInput());
  });
  route('DELETE', '#^/api/posts/([^/]+)$#', function (array $m) {
    requireAuth();
    apiDeletePost(urldecode($m[1]));
  });
  route('GET', '#^/api/settings$#', 'apiGetSettings');
  route('POST', '#^/api/onboarding$#', function (array $m) {
    requireJsonBody();
    if (rateLimitExceeded('onboarding', clientIp(), 5, 3600)) {
      jsonResponse([
        'error' => 'Too many requests',
        'message' => 'Too many onboarding attempts. Please try again later.',
      ], 429);
    }
    apiOnboarding(getInput());
  });
  route('PUT', '#^/api/settings$#', function (array $m) {
    apiUpdateSettings(getInput(), (string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
  });
  route('PUT', '#^/api/settings/credentials$#', function (array $m) {
    apiUpdateCredentials(getInput(), (string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
  });
  route('POST', '#^/api/settings/rotate-token$#', function (array $m) {
    requireAuth();
    apiRotateTokenRequest($m);
  });
  route('POST', '#^/api/confirm/([^/]+)$#', function (array $m) {
    requireAuth();
    apiConfirm(urldecode($m[1]));
  });

  // Web pages
  route('GET', '#^/feed\.xml$#', 'serveFeed');
  route('GET', '#^/og/site\.png$#', 'serveOgSite');
  route('GET', '#^/og/([^/]+)\.png$#', 'serveOgPost');
  route('GET', '#^/$#', 'serveHome');
  route('GET', '#^/search$#', 'serveSearch');
  route('GET', '#^/post/([^/]+)$#', 'servePost');
  route('GET', '#^/login$#', 'serveLoginGet');
  route('POST', '#^/login$#', 'serveLoginPost');
  route('POST', '#^/logout$#', 'serveLogout');
  route('GET', '#^/onboarding$#', 'serveOnboardingGet');
  route('GET', '#^/docs$#', 'serveDocs');
  route('GET', '#^/posts$#', fn(array $m) => redirect('/panel'));
  route('GET', '#^/panel$#', 'servePanel');
  route('GET', '#^/settings$#', 'serveSettings');
  route('POST', '#^/settings/update$#', 'serveSettingsUpdate');
  route('POST', '#^/settings/update-credentials$#', 'serveSettingsUpdateCredentials');

  notFound();
}
