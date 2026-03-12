<?php
/**
 * GMS Comments API — Server-side storage
 * Stores threaded comments in a JSON file (no database needed)
 *
 * GET  comments-api.php          → returns all comments as JSON
 * POST comments-api.php          → add top-level comment  {name, text}
 * POST comments-api.php?reply_to=ID → add reply to comment {name, text}
 */

header('Content-Type: application/json');

// CORS — restrict to same origin or known domains
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['http://localhost', 'https://khushbushah.online'];
$matched = false;
foreach ($allowed as $a) {
    if (str_starts_with($origin, $a)) { $matched = true; break; }
}
header('Access-Control-Allow-Origin: ' . ($matched ? $origin : $allowed[0]));
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Data file path (same directory, not web-accessible name)
define('DATA_FILE', __DIR__ . '/gms-comments-data.json');

// Read comments from file
function readComments(): array {
    if (!file_exists(DATA_FILE)) {
        return [];
    }
    $json = file_get_contents(DATA_FILE);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

// Write comments to file
function writeComments(array $comments): bool {
    return file_put_contents(DATA_FILE, json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

// Generate unique ID
function genId(): string {
    return base_convert(time(), 10, 36) . bin2hex(random_bytes(4));
}

// Sanitize input
function clean(string $input, int $maxLen = 2000): string {
    $s = trim($input);
    $s = strip_tags($s);
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    return $s;
}

// Validate name: letters, spaces, hyphens, apostrophes, dots only — max 20 chars
function isValidName(string $name): bool {
    return mb_strlen($name, 'UTF-8') >= 2
        && mb_strlen($name, 'UTF-8') <= 20
        && preg_match('/^[a-zA-Z\s\'\-\.]+$/u', $name);
}

// Check for suspicious content (script injection, HTML tags, etc.)
function isSuspiciousContent(string $text): bool {
    $patterns = [
        '/<script/i', '/javascript:/i', '/on\w+\s*=/i',
        '/eval\s*\(/i', '/document\./i', '/window\./i',
        '/\balert\s*\(/i', '/\bfetch\s*\(/i',
        '/<iframe/i', '/<object/i', '/<embed/i', '/<form/i',
        '/<img[^>]+onerror/i', '/data:\s*text\/html/i',
        '/base64/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// Hourly rate limit (stricter, per IP)
function checkHourlyLimit(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockFile = sys_get_temp_dir() . '/gms_hour_' . md5($ip) . '.json';
    $now = time();
    $window = 3600;
    $maxPosts = 20;
    $data = [];
    if (file_exists($lockFile)) {
        $data = json_decode(file_get_contents($lockFile), true) ?: [];
    }
    $data = array_filter($data, fn($t) => ($now - $t) < $window);
    if (count($data) >= $maxPosts) return false;
    $data[] = $now;
    file_put_contents($lockFile, json_encode(array_values($data)), LOCK_EX);
    return true;
}

// Count total comments recursively
function countTotal(array $comments): int {
    $n = count($comments);
    foreach ($comments as $c) {
        if (!empty($c['replies'])) $n += countTotal($c['replies']);
    }
    return $n;
}

// Max nesting depth check
function getDepth(array &$comments, string $parentId, int $depth = 0): int {
    foreach ($comments as &$c) {
        if ($c['id'] === $parentId) return $depth;
        if (!empty($c['replies'])) {
            $found = getDepth($c['replies'], $parentId, $depth + 1);
            if ($found >= 0) return $found;
        }
    }
    return -1;
}

// Recursively find a comment by ID and add a reply
function addReplyTo(array &$comments, string $parentId, array $reply): bool {
    foreach ($comments as &$c) {
        if ($c['id'] === $parentId) {
            if (!isset($c['replies'])) {
                $c['replies'] = [];
            }
            $c['replies'][] = $reply;
            return true;
        }
        if (!empty($c['replies']) && addReplyTo($c['replies'], $parentId, $reply)) {
            return true;
        }
    }
    return false;
}

// Recursively find a comment by ID and apply a reaction
function applyReaction(array &$comments, string $id, string $emoji, string $action): bool {
    foreach ($comments as &$c) {
        if ($c['id'] === $id) {
            if (!isset($c['reactions'])) {
                $c['reactions'] = [];
            }
            $current = $c['reactions'][$emoji] ?? 0;
            if ($action === 'add') {
                $c['reactions'][$emoji] = $current + 1;
            } else {
                $c['reactions'][$emoji] = max(0, $current - 1);
                if ($c['reactions'][$emoji] === 0) {
                    unset($c['reactions'][$emoji]);
                }
            }
            return true;
        }
        if (!empty($c['replies']) && applyReaction($c['replies'], $id, $emoji, $action)) {
            return true;
        }
    }
    return false;
}

// Rate limiting: max 5 posts per minute per IP
function checkRateLimit(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockFile = sys_get_temp_dir() . '/gms_rate_' . md5($ip) . '.json';

    $now = time();
    $window = 60; // seconds
    $maxPosts = 5;

    $data = [];
    if (file_exists($lockFile)) {
        $data = json_decode(file_get_contents($lockFile), true) ?: [];
    }

    // Remove old entries
    $data = array_filter($data, fn($t) => ($now - $t) < $window);

    if (count($data) >= $maxPosts) {
        return false;
    }

    $data[] = $now;
    file_put_contents($lockFile, json_encode(array_values($data)), LOCK_EX);
    return true;
}

// === HANDLE REQUEST ===

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return all comments
    echo json_encode(readComments());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject oversized payloads (max 8KB)
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 8192) {
        http_response_code(413);
        echo json_encode(['error' => 'Payload too large.']);
        exit;
    }

    // Rate limit checks (per-minute + per-hour)
    if (!checkRateLimit()) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many comments. Please wait a moment.']);
        exit;
    }
    if (!checkHourlyLimit()) {
        http_response_code(429);
        echo json_encode(['error' => 'Hourly limit reached. Please try again later.']);
        exit;
    }

    // Parse input
    $input = json_decode($raw, true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input.']);
        exit;
    }

    // Honeypot — bots fill hidden fields
    if (!empty($input['website']) || !empty($input['url']) || !empty($input['email'])) {
        // Silently accept but don't save (fool the bot)
        http_response_code(201);
        echo json_encode(['success' => true, 'comment' => ['id' => genId()]]);
        exit;
    }

    $rawName = trim($input['name'] ?? '');
    $rawText = trim($input['text'] ?? '');

    // Validate name format BEFORE sanitization (letters, spaces, hyphens, apostrophes, dots)
    if (!isValidName($rawName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name must be 2–20 characters, letters only (spaces, hyphens, apostrophes allowed).']);
        exit;
    }

    // Check for script injection in raw inputs
    if (isSuspiciousContent($rawName) || isSuspiciousContent($rawText)) {
        http_response_code(400);
        echo json_encode(['error' => 'Your message contains disallowed content.']);
        exit;
    }

    $name = clean($rawName, 20);
    $text = clean($rawText, 2000);
    $category = clean($input['category'] ?? '', 50);

    // Validate category if provided
    $allowedCategories = ['login','superadmin','manager','staff','client','reports','security','general'];
    if ($category !== '' && !in_array($category, $allowedCategories, true)) {
        $category = 'general';
    }

    if ($name === '' || $text === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Name and comment are required.']);
        exit;
    }

    // Minimum text length
    if (mb_strlen($rawText, 'UTF-8') < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'Comment must be at least 3 characters.']);
        exit;
    }

    $comments = readComments();

    // Cap total comments at 500 to prevent storage abuse
    if (countTotal($comments) >= 500) {
        http_response_code(429);
        echo json_encode(['error' => 'Maximum comment limit reached.']);
        exit;
    }

    $replyTo = $_GET['reply_to'] ?? null;

    // Limit reply nesting depth to 5
    if ($replyTo) {
        $depth = getDepth($comments, $replyTo);
        if ($depth < 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Parent comment not found.']);
            exit;
        }
        if ($depth >= 10) {
            http_response_code(400);
            echo json_encode(['error' => 'This thread is too deep. Please start a new discussion instead.']);
            exit;
        }
    }

    $comment = [
        'id'      => genId(),
        'name'    => $name,
        'text'    => $text,
        'time'    => date('c'), // ISO 8601
        'ip_hash' => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'gms_salt_2026'),
        'replies' => [],
    ];

    if ($category !== '') {
        $comment['category'] = $category;
    }

    if ($replyTo) {
        if (!addReplyTo($comments, $replyTo, $comment)) {
            http_response_code(404);
            echo json_encode(['error' => 'Parent comment not found.']);
            exit;
        }
    } else {
        $comments[] = $comment;
    }

    if (!writeComments($comments)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save comment. Check server permissions.']);
        exit;
    }

    http_response_code(201);
    echo json_encode(['success' => true, 'comment' => $comment]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    // Rate limit reactions too
    if (!checkRateLimit()) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please wait.']);
        exit;
    }

    $reactId = $_GET['react'] ?? null;
    if (!$reactId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing react parameter.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $emoji = $input['emoji'] ?? '';
    $action = $input['action'] ?? 'add';

    $allowed = ['👍','❤️','🎯','💡','👏','🔥'];
    if (!in_array($emoji, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid emoji.']);
        exit;
    }

    $comments = readComments();
    if (!applyReaction($comments, $reactId, $emoji, $action)) {
        http_response_code(404);
        echo json_encode(['error' => 'Comment not found.']);
        exit;
    }

    if (!writeComments($comments)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save reaction.']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

// Unknown method
http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
