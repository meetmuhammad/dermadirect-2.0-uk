const decodeJsonString = (value) => {
  if (!value) {
    return '';
  }

  try {
    return JSON.parse(value);
  } catch {
    return '';
  }
};

const formatCountdown = (secondsRemaining) => {
  const hours = Math.floor(secondsRemaining / 3600);
  const minutes = Math.floor((secondsRemaining % 3600) / 60);
  const seconds = secondsRemaining % 60;

  const pad = (value) => String(value).padStart(2, '0');

  return `${pad(hours)}hrs ${pad(minutes)}mins ${pad(seconds)}sec`;
};

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-next-day-delivery-timer').forEach((banner) => {
    const countdown = banner.querySelector('[data-countdown]');
    if (!countdown) {
      return;
    }

    const deadline = Number(banner.dataset.deadline || 0) * 1000;
    const expiryAction = banner.dataset.expiryAction || 'fallback';
    const fallbackHtml = decodeJsonString(banner.dataset.fallbackHtml || '""');
    const content = banner.querySelector('[data-banner-content]');

    const tick = () => {
      const diff = Math.floor((deadline - Date.now()) / 1000);

      if (diff <= 0) {
        if (expiryAction === 'hide') {
          banner.hidden = true;
          return;
        }

        if (content) {
          content.innerHTML = `<div class="nddt-banner__fallback">${fallbackHtml}</div>`;
        }

        return;
      }

      countdown.textContent = formatCountdown(diff);
      window.setTimeout(tick, 1000);
    };

    tick();
  });
});
