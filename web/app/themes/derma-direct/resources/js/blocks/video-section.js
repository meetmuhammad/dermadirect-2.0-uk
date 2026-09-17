document.addEventListener('DOMContentLoaded', () => {

    const play_button__el = document.querySelector(".video-play-button");
    const video_popup__el = document.getElementById("derma_video_popup");
    const video_frame__el = document.getElementById("derma_video_frame");

    if (!play_button__el || !video_popup__el || !video_frame__el) return;

    play_button__el.addEventListener("click", () => {
        const video_src = play_button__el.getAttribute("data-video-url");

        video_popup__el.classList.remove("hidden");
        video_frame__el.setAttribute("src", video_src + "?autoplay=1");
    });
});
