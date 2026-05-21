const player = document.querySelector('#player');
const currentName = document.querySelector('#currentName');
const detailName = document.querySelector('#detailName');
const detailSize = document.querySelector('#detailSize');
const detailDate = document.querySelector('#detailDate');
const items = document.querySelectorAll('.video-item');
const uploadForm = document.querySelector('.upload');
const fileInput = document.querySelector('.upload input[type="file"]');
const chooseLabel = document.querySelector('.upload-button span');
const uploadButton = document.querySelector('.upload button');
const playToggle = document.querySelector('.play-toggle');
const skipButtons = document.querySelectorAll('[data-skip]');
const deleteForms = document.querySelectorAll('.delete-form');

function getUploadStatus() {
    if (!uploadForm) {
        return null;
    }

    let status = uploadForm.querySelector('.upload-status');
    if (status) {
        return status;
    }

    status = document.createElement('div');
    status.className = 'upload-status';
    status.hidden = true;
    status.setAttribute('aria-live', 'polite');
    status.innerHTML = `
        <div class="spinner" aria-hidden="true"></div>
        <div class="upload-copy">
            <strong>Uploading...</strong>
            <small>0%</small>
        </div>
        <div class="progress-track">
            <div class="progress-bar"></div>
        </div>
    `;
    uploadForm.append(status);
    return status;
}

function setUploadProgress(percent, text) {
    const status = getUploadStatus();
    if (!status) {
        return;
    }

    const safePercent = Math.max(0, Math.min(100, percent));
    status.hidden = false;
    status.querySelector('.progress-bar').style.width = `${safePercent}%`;
    status.querySelector('.upload-copy small').textContent = text || `${Math.round(safePercent)}%`;
}

function updatePlayButton() {
    if (!player || !playToggle) {
        return;
    }

    playToggle.textContent = player.paused ? 'Play' : 'Pause';
}

function storageKey(name) {
    return `video-time:${name}`;
}

function getActiveVideoName() {
    return currentName?.textContent || document.querySelector('.video-item.active')?.dataset.name || '';
}

function saveCurrentTime() {
    if (!player) {
        return;
    }

    const name = getActiveVideoName();
    if (!name || !Number.isFinite(player.currentTime)) {
        return;
    }

    localStorage.setItem(storageKey(name), String(player.currentTime));
}

function applyStartPosition() {
    if (!player) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const name = getActiveVideoName();
    if (!name) {
        return;
    }

    if (params.has('restart')) {
        localStorage.removeItem(storageKey(name));
        player.currentTime = 0;
        return;
    }

    if (params.has('resume')) {
        const savedTime = Number(localStorage.getItem(storageKey(name)) || 0);
        if (Number.isFinite(savedTime) && savedTime > 0) {
            player.currentTime = savedTime;
        }
    }
}

function seekVideo(seconds) {
    if (!player) {
        return;
    }

    const doSeek = () => {
        const duration = Number.isFinite(player.duration) ? player.duration : null;
        const current = Number.isFinite(player.currentTime) ? player.currentTime : 0;
        const target = duration === null
            ? Math.max(0, current + seconds)
            : Math.max(0, Math.min(duration, current + seconds));

        if (typeof player.fastSeek === 'function') {
            player.fastSeek(target);
        } else {
            player.currentTime = target;
        }
    };

    if (player.readyState < 1) {
        player.addEventListener('loadedmetadata', doSeek, { once: true });
        player.load();
        return;
    }

    doSeek();
}

items.forEach((item) => {
    item.addEventListener('click', () => {
        if (!player) {
            return;
        }

        items.forEach((button) => button.classList.remove('active'));
        item.classList.add('active');
        player.src = item.dataset.src;
        player.addEventListener('loadedmetadata', () => {
            const savedTime = Number(localStorage.getItem(storageKey(item.dataset.name)) || 0);
            if (Number.isFinite(savedTime) && savedTime > 0) {
                player.currentTime = savedTime;
            }
        }, { once: true });
        player.play().catch(() => {});
        updatePlayButton();

        if (currentName) {
            currentName.textContent = item.dataset.name;
        }
        if (detailName) {
            detailName.textContent = item.dataset.name;
        }
        if (detailSize) {
            detailSize.textContent = item.dataset.size;
        }
        if (detailDate) {
            detailDate.textContent = item.dataset.date;
        }
    });
});

if (player && playToggle) {
    playToggle.addEventListener('click', () => {
        if (player.paused) {
            player.play().catch(() => {});
        } else {
            player.pause();
        }
        updatePlayButton();
    });

    player.addEventListener('play', updatePlayButton);
    player.addEventListener('pause', updatePlayButton);
    player.addEventListener('ended', updatePlayButton);
    player.addEventListener('timeupdate', saveCurrentTime);
    player.addEventListener('pause', saveCurrentTime);
    player.addEventListener('loadedmetadata', applyStartPosition, { once: true });
    player.addEventListener('ended', () => {
        const name = getActiveVideoName();
        if (name) {
            localStorage.removeItem(storageKey(name));
        }
    });
    if (player.readyState >= 1) {
        applyStartPosition();
    }
    updatePlayButton();
}

skipButtons.forEach((button) => {
    button.addEventListener('click', () => {
        seekVideo(Number(button.dataset.skip || 0));
    });
});

deleteForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
        const name = form.querySelector('input[name="video_name"]')?.value || 'this video';
        if (!window.confirm(`Delete ${name}?`)) {
            event.preventDefault();
            return;
        }

        const activeName = document.querySelector('.video-item.active')?.dataset.name;
        if (player && activeName === name) {
            event.preventDefault();
            player.pause();
            player.removeAttribute('src');
            player.querySelectorAll('source').forEach((source) => source.removeAttribute('src'));
            player.load();
            window.setTimeout(() => form.submit(), 250);
        }
    });
});

if (fileInput && chooseLabel) {
    fileInput.addEventListener('change', () => {
        chooseLabel.textContent = fileInput.files[0]?.name || 'Choose Video';
    });
}

if (uploadForm && fileInput) {
    uploadForm.addEventListener('submit', (event) => {
        event.preventDefault();

        if (!fileInput.files.length) {
            fileInput.click();
            return;
        }

        const formData = new FormData(uploadForm);
        const request = new XMLHttpRequest();
        const status = getUploadStatus();
        const statusTitle = status?.querySelector('.upload-copy strong');

        uploadForm.classList.add('is-uploading');
        if (uploadButton) {
            uploadButton.disabled = true;
        }
        if (statusTitle) {
            statusTitle.textContent = `Uploading ${fileInput.files[0].name}`;
        }
        setUploadProgress(0, 'Starting upload...');

        request.upload.addEventListener('progress', (progress) => {
            if (!progress.lengthComputable) {
                setUploadProgress(8, 'Uploading...');
                return;
            }

            const percent = (progress.loaded / progress.total) * 100;
            setUploadProgress(percent, `${Math.round(percent)}% uploaded`);
        });

        request.addEventListener('load', () => {
            if (request.status >= 200 && request.status < 300) {
                setUploadProgress(100, 'Processing video...');
                document.open();
                document.write(request.responseText);
                document.close();
                return;
            }

            if (statusTitle) {
                statusTitle.textContent = 'Upload failed';
            }
            setUploadProgress(0, 'Server rejected the upload.');
            uploadForm.classList.remove('is-uploading');
            if (uploadButton) {
                uploadButton.disabled = false;
            }
        });

        request.addEventListener('error', () => {
            if (statusTitle) {
                statusTitle.textContent = 'Upload failed';
            }
            setUploadProgress(0, 'Network error. Please try again.');
            uploadForm.classList.remove('is-uploading');
            if (uploadButton) {
                uploadButton.disabled = false;
            }
        });

        request.open('POST', uploadForm.action || window.location.href);
        request.send(formData);
    });
}
