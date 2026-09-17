document.addEventListener("DOMContentLoaded", () => {

    // const play_button__el = document.querySelector(".video-play-button");
    const video_popup__el = document.getElementById("derma_video_popup");
    const close_popup__el = document.getElementById("derma_close_popup");

    const video_frame__el = document.getElementById("derma_video_frame");

    if (!video_popup__el || !close_popup__el || !video_frame__el) return;

    const video_src = video_frame__el.getAttribute("src");

    // Closing popup on clicking close button.
    close_popup__el.addEventListener("click", () => {
        video_popup__el.classList.add("hidden");
        video_frame__el.setAttribute("src", ""); // stop video
        video_frame__el.setAttribute("src", video_src); // reset video
    });

    // Closing popup if user clicks outside the video box
    video_popup__el.addEventListener("click", (e) => {
        if (e.target !== video_popup__el) return;

        video_popup__el.classList.add("hidden");
        video_frame__el.setAttribute("src", ""); // stop video
        video_frame__el.setAttribute("src", video_src); // reset video
    });
});

