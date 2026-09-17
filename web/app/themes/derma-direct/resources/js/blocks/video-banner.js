document.addEventListener('DOMContentLoaded', function () {
    const video = document.getElementById('bannerVideo');
    const btn = document.getElementById('soundToggleBtn');

    if (!video || !btn) return;

    // Ensure autoplay attempt
    video.play().catch(() => {});

    let isMuted = true;

    btn.addEventListener('click', function () {
        isMuted = !isMuted;

        video.muted = isMuted;
        video.volume = isMuted ? 0 : 1;

        btn.textContent = isMuted
            ? 'Click for sound 🔊'
            : 'Click to mute 🔇';
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const video = document.getElementById('bannerVideo');
    if (!video) return;

    const trackEl = video.querySelector('track[kind="captions"]');
    if (!trackEl) return;

    // Hide captions until repositioned, so the first cue doesn't flash in the wrong spot
    trackEl.track.mode = 'hidden';

    function repositionCues() {
        const cues = trackEl.track.cues;
        if (!cues) return;
        for (const cue of cues) {
            cue.snapToLines = false;
            cue.line = 95; // percentage from top; increase to push further down
        }
        trackEl.track.mode = 'showing';
    }

    // 'load' fires on the <track> ELEMENT once the vtt file is parsed
    trackEl.addEventListener('load', repositionCues);

    // Fallback in case cues are already available (cached/fast load)
    if (trackEl.track.cues && trackEl.track.cues.length) {
        repositionCues();
    }
});