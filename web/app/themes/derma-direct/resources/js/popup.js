document.addEventListener("DOMContentLoaded", () => {

    const popup   = document.getElementById("customPopup");
    const closeBtn = document.getElementById("popupClose");

    if (!popup || !closeBtn) return;

    const sessionEnabled = popup.dataset.session === "true";
    const delay          = parseInt(popup.dataset.delay || 0, 10) * 1000;
    const STORAGE_KEY    = "derma_popup_dismissed";
    const CHANNEL_NAME   = "derma_popup";

    // ─── Session guard ───────────────────────────────────────────────────────
    if (sessionEnabled && sessionStorage.getItem(STORAGE_KEY) === "1") {
        return;
    }

    // ─── Cross-tab sync via BroadcastChannel ────────────────────────────────
    // When the popup is dismissed in another tab that is already open,
    // this tab will receive the message and dismiss its own popup silently.
    let channel = null;

    if (sessionEnabled && typeof BroadcastChannel !== "undefined") {
        channel = new BroadcastChannel(CHANNEL_NAME);

        channel.addEventListener("message", (e) => {
            if (e.data === STORAGE_KEY) {
                sessionStorage.setItem(STORAGE_KEY, "1");
                hide(/* broadcast */ false);
            }
        });
    }

    // ─── Show ────────────────────────────────────────────────────────────────
    const show = () => {
        popup.removeAttribute("inert");
        popup.classList.remove("opacity-0", "invisible");
        closeBtn.focus();
    };

    popup.setAttribute("inert", "");
    setTimeout(show, delay);

    // ─── Hide ────────────────────────────────────────────────────────────────
    const hide = (broadcast = true) => {
        popup.classList.add("opacity-0", "invisible");
        popup.setAttribute("inert", "");

        if (sessionEnabled) {
            sessionStorage.setItem(STORAGE_KEY, "1");

            if (broadcast && channel) {
                channel.postMessage(STORAGE_KEY);
            }
        }
    };

    // ─── Listeners ───────────────────────────────────────────────────────────
    closeBtn.addEventListener("click", () => hide());

    popup.addEventListener("click", (e) => {
        if (e.target === popup) hide();
    });

    // Close on Escape key for accessibility
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !popup.classList.contains("invisible")) {
            hide();
        }
    });

});
