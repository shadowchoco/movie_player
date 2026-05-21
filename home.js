document.querySelectorAll('.icon-action.restart').forEach((link) => {
    link.addEventListener('click', () => {
        const name = link.dataset.videoName;
        if (name) {
            localStorage.removeItem(`video-time:${name}`);
        }
    });
});
