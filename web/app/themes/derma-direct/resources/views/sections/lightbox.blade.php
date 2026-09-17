<div id="derma_video_popup" class="fixed inset-0 bg-black/80 hidden flex items-center justify-center z-50 p-4">
    <div class="relative rounded-md w-[95%] max-w-[1000px] overflow-hidden">
        <button id="derma_close_popup" class="absolute top-0 right-3 md:right-5 text-black text-2xl md:text-3xl font-bold cursor-pointer">&times;</button>

        <div class="aspect-video p-3 md:p-5 pt-8 md:pt-9 bg-white rounded-xl">

            <video
                id="derma_video_frame"
                class="w-full h-full rounded-xl object-cover"
                src=""
                autoplay
                muted
                playsinline
                loop
                controls
            ></video>

        </div>
    </div>
</div>
