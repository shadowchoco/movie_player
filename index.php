<?php
declare(strict_types=1);

ini_set('upload_max_filesize', '1024M');
ini_set('post_max_size', '1024M');
ini_set('max_execution_time', '3600');
ini_set('max_input_time', '3600');
ini_set('memory_limit', '512M');

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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    $file = $_FILES['video'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'The video is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The video is larger than the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a video file.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'The server stopped the upload.',
        ];
        $message = $uploadErrors[$file['error']] ?? 'Upload failed. Please check the file size allowed by your hosting plan.';
        $messageType = 'error';
    } else {
        $originalName = clean_name((string) $file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            $message = 'Please upload MP4, WEBM, OGG, MOV, or M4V videos only.';
            $messageType = 'error';
        } else {
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            $targetName = $originalName;
            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
            $counter = 1;

            while (file_exists($targetPath)) {
                $targetName = $baseName . '-' . $counter . '.' . $extension;
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
                $counter++;
            }

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $message = 'Video uploaded successfully.';
                $messageType = 'success';
            } else {
                $message = 'Could not save the video. Make sure the videos folder is writable.';
                $messageType = 'error';
            }
        }
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
$currentVideo = $videos[0] ?? null;
if (isset($_GET['video'])) {
    $requestedVideo = clean_name((string) $_GET['video']);
    foreach ($videos as $video) {
        if ($video['name'] === $requestedVideo) {
            $currentVideo = $video;
            break;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Player</title>
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

        <form class="upload" method="post" enctype="multipart/form-data">
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

    <main class="layout">
        <?php if ($message !== ''): ?>
            <p class="message <?php echo htmlspecialchars($messageType, ENT_QUOTES); ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES); ?>
            </p>
        <?php endif; ?>

        <section class="player-panel">
            <?php if ($currentVideo): ?>
                <video id="player" controls autoplay playsinline preload="metadata">
                    <source src="<?php echo htmlspecialchars($currentVideo['url'], ENT_QUOTES); ?>">
                    Your browser does not support the video tag.
                </video>
                <div class="player-controls" aria-label="Video controls">
                    <button type="button" data-skip="-60" title="Back 1 minute" aria-label="Back 1 minute">-1m</button>
                    <button type="button" data-skip="-10" title="Back 10 seconds" aria-label="Back 10 seconds">-10s</button>
                    <button class="play-toggle" type="button" title="Play or pause" aria-label="Play or pause">Pause</button>
                    <button type="button" data-skip="10" title="Forward 10 seconds" aria-label="Forward 10 seconds">+10s</button>
                    <button type="button" data-skip="60" title="Forward 1 minute" aria-label="Forward 1 minute">+1m</button>
                </div>
                <div class="now-playing">
                    <span>Now Playing</span>
                    <strong id="currentName"><?php echo htmlspecialchars($currentVideo['name'], ENT_QUOTES); ?></strong>
                    <dl class="file-details">
                        <div>
                            <dt>File</dt>
                            <dd id="detailName"><?php echo htmlspecialchars($currentVideo['name'], ENT_QUOTES); ?></dd>
                        </div>
                        <div>
                            <dt>Size</dt>
                            <dd id="detailSize"><?php echo htmlspecialchars($currentVideo['size'], ENT_QUOTES); ?></dd>
                        </div>
                        <div>
                            <dt>Uploaded</dt>
                            <dd id="detailDate"><?php echo htmlspecialchars($currentVideo['date'], ENT_QUOTES); ?></dd>
                        </div>
                    </dl>
                </div>
            <?php else: ?>
                <div class="empty">
                    <h2>No videos yet</h2>
                    <p>Upload a video from the top-right button to start playing.</p>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($videos): ?>
            <aside class="playlist" aria-label="Uploaded videos">
                <h2>Uploaded Videos</h2>
                <?php foreach ($videos as $index => $video): ?>
                    <div class="playlist-row">
                        <button
                            class="video-item<?php echo $currentVideo && $video['name'] === $currentVideo['name'] ? ' active' : ''; ?>"
                            type="button"
                            data-src="<?php echo htmlspecialchars($video['url'], ENT_QUOTES); ?>"
                            data-name="<?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>"
                            data-size="<?php echo htmlspecialchars($video['size'], ENT_QUOTES); ?>"
                            data-date="<?php echo htmlspecialchars($video['date'], ENT_QUOTES); ?>"
                        >
                            <span><?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?></span>
                            <small><?php echo htmlspecialchars($video['size'], ENT_QUOTES); ?></small>
                        </button>
                        <form class="delete-form" method="post">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="video_name" value="<?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>">
                            <button type="submit" title="Delete video" aria-label="Delete <?php echo htmlspecialchars($video['name'], ENT_QUOTES); ?>">
                                <span aria-hidden="true">x</span>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </aside>
        <?php endif; ?>
    </main>

    <script src="script.js"></script>
</body>
</html>
