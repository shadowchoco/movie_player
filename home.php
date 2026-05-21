<?php
declare(strict_types=1);

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'videos';
$uploadUrl = 'videos/';
$allowedExtensions = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];
$message = '';
$messageType = 'info';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

function clean_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = trim((string) $name, '._-');
    return $name !== '' ? $name : 'video';
}

function format_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    return round($size, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
}

function hidden_file_path(string $uploadDir): string
{
    return $uploadDir . DIRECTORY_SEPARATOR . '.hidden-videos.json';
}

function hidden_videos(string $uploadDir): array
{
    $path = hidden_file_path($uploadDir);
    if (!is_file($path)) {
        return [];
    }

    $items = json_decode((string) file_get_contents($path), true);
    return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
}

function hide_video(string $uploadDir, string $name): bool
{
    $hidden = hidden_videos($uploadDir);
    if (!in_array($name, $hidden, true)) {
        $hidden[] = $name;
    }

    return file_put_contents(hidden_file_path($uploadDir), json_encode($hidden, JSON_PRETTY_PRINT)) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $deleteName = clean_name((string) ($_POST['video_name'] ?? ''));
    $deleteExtension = strtolower(pathinfo($deleteName, PATHINFO_EXTENSION));
    $deletePath = $uploadDir . DIRECTORY_SEPARATOR . basename($deleteName);

    if (!in_array($deleteExtension, $allowedExtensions, true) || !is_file($deletePath)) {
        $message = 'Video not found.';
        $messageType = 'error';
    } elseif (@unlink($deletePath)) {
        $message = 'Video deleted successfully.';
        $messageType = 'success';
    } elseif (hide_video($uploadDir, basename($deleteName))) {
        $message = 'Video removed from the website list.';
        $messageType = 'success';
    } else {
        $message = 'Could not delete the video. Please check folder permissions.';
        $messageType = 'error';
    }
}

$videos = [];
$hiddenVideos = hidden_videos($uploadDir);
foreach (glob($uploadDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
    if (!is_file($path)) {
        continue;
    }

    if (in_array(basename($path), $hiddenVideos, true)) {
        continue;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        continue;
    }

    $videos[] = [
        'name' => basename($path),
        'url' => $uploadUrl . rawurlencode(basename($path)),
        'size' => format_size((int) filesize($path)),
        'date' => date('d M Y, h:i A', filemtime($path) ?: time()),
        'time' => filemtime($path) ?: 0,
    ];
}

usort($videos, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home - Video Player</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="topbar">
        <div class="brand">
            <a class="home-button" href="home.php" title="Home" aria-label="Home">Home</a>
            <div>
                <h1>Video Player</h1>
                <p><?php echo count($videos); ?> uploaded video<?php echo count($videos) === 1 ? '' : 's'; ?></p>
            </div>
        </div>

        <form class="upload" method="post" action="index.php" enctype="multipart/form-data">
            <label class="upload-button">
                <input type="file" name="video" accept="video/mp4,video/webm,video/ogg,video/quicktime,video/x-m4v" required>
                <span>Choose Video</span>
            </label>
            <button type="submit">Upload</button>
            <div class="upload-status" aria-live="polite" hidden>
                <div class="spinner" aria-hidden="true"></div>
                <div class="upload-copy">
                    <strong>Uploading...</strong>
                    <small>0%</small>
                </div>
                <div class="progress-track">
                    <div class="progress-bar"></div>
                </div>
            </div>
        </form>
    </header>

    <main class="home-layout">
        <?php if ($message !== ''): ?>
            <p class="message <?php echo htmlspecialchars($messageType, ENT_QUOTES); ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES); ?>
            </p>
        <?php endif; ?>

        <?php if ($videos): ?>
            <section class="library" aria-label="Uploaded videos">
                <?php foreach ($videos as $video): ?>
                    <article class="library-row">
                        <div class="library-info">
                            <strong><?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?></strong>
                            <small><?php echo htmlspecialchars($video['size'], ENT_QUOTES); ?> | <?php echo htmlspecialchars($video['date'], ENT_QUOTES); ?></small>
                        </div>
                        <div class="library-actions">
                            <a class="icon-action play" href="index.php?video=<?php echo rawurlencode($video['name']); ?>&resume=1" title="Play from paused time" aria-label="Play <?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>" data-video-name="<?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>">Play</a>
                            <a class="icon-action restart" href="index.php?video=<?php echo rawurlencode($video['name']); ?>&restart=1" title="Restart video" aria-label="Restart <?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>" data-video-name="<?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>">Restart</a>
                            <form class="delete-form library-delete" method="post">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="video_name" value="<?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>">
                                <button type="submit" title="Delete video" aria-label="Delete <?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>">
                                    <span aria-hidden="true">x</span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="empty home-empty">
                <h2>No videos yet</h2>
                <p>Upload a video from the top-right button to start.</p>
            </section>
        <?php endif; ?>
    </main>

    <script src="script.js"></script>
    <script src="home.js"></script>
</body>
</html>
