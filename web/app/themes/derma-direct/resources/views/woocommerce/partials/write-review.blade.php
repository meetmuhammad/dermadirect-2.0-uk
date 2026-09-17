<div class="review-popup fixed left-0 right-0 top-0 h-full bg-[#000000ab] backdrop-blur-sm flex items-center justify-center px-4 py-6 opacity-0 invisible transition-all duration-300 z-[99] font-quicksand">
    <div class="popup-box w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden max-h-[calc(100vh-6rem)]
        overflow-y-auto translate-y-8 transition-all duration-300">

        {{-- Review Header --}}
        <div class="header-section bg-gray-100 px-6 py-4 border-b border-gray-300 flex gap-5 items-center justify-between sticky top-0 font-poppins">
            <div>
                <h3 class="text-2xl font-bold text-gray-900">{!! __('Write a Review', 'sage') !!}</h3>
                <p class="text-sm text-gray-600">{!! __('Share your experience with this product', 'sage') !!}</p>
            </div>
            <button class="close-review-popup min-w-10 min-h-10 bg-white rounded-full shadow flex items-center justify-center hover:bg-gray-200 transition cursor-pointer">
                @svg('images.close', 'size-6')
            </button>
        </div>

        {{-- Review Form --}}
        <form id="reviewForm" data-product-id="{!! esc_attr(get_the_ID() ?? '') !!}">
            <div class="form-content p-6 space-y-6">
                {{-- Rating --}}
                <div class="rating-wrapper">
                    <label class="block text-sm font-medium text-gray-900 mb-2">{!! __('Your Rating', 'sage') !!} <span class="text-red-700">*</span></label>
                    <div class="border border-grey-outline rounded-lg p-4 md:p-6">
                        <div class="flex justify-center gap-2 rating-stars">
                            @for($i=1; $i<=5; $i++)
                                <span data-value="{{ $i }}" class="star size-8 rounded-xl bg-gray-300 flex items-center justify-center cursor-pointer">
                                    @svg('images.rating-star')
                                </span>
                            @endfor
                        </div>
                        <div class="text-sm text-gray-500 text-center mt-2">{!! __('Click on a star to rate', 'sage') !!}</div>
                        <div class="rating-text text-center mt-2 font-medium text-gray-700">{!! __('Excellent', 'sage') !!}</div>
                        <input type="hidden" class="rating-input" name="rating" value="5">
                    </div>
                </div>

                <!-- Name + Email -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-900">{!! __('Your Name', 'sage') !!} <span class="text-red-700">*</span></label>
                        <input type="text" class="w-full mt-1 px-4 py-3 border border-secondary-black outline-none rounded-lg" placeholder="Enter your name" name="name">
                        <span class="text-xs text-red-700 name-field-error"></span>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-900">{!! __('Email', 'sage') !!} <span class="text-red-700">*</span></label>
                        <input type="email" class="w-full mt-1 px-4 py-3 border border-secondary-black outline-none rounded-lg" placeholder="example@email.com" name="email">
                        <span class="text-xs text-red-700 email-field-error"></span>
                    </div>
                </div>
                <!-- Review Text -->
                <div>
                    <label class="text-sm font-medium text-gray-900">{!! __('Your Review', 'sage') !!} <span class="text-red-700">*</span></label>
                    <textarea rows="3" class="w-full px-4 py-3 border border-secondary-black outline-none rounded-lg mt-1" placeholder="{!! __('Write your review...', 'sage') !!}" name="review"></textarea>
                    <span class="text-xs text-red-700 review-text-error"></span>
                </div>
                {{-- Upload Images --}}
                <div class="files-upload-wrapper">
                    <label class="text-sm font-medium text-gray-900 mb-2 block">{!! __('Upload Images (optional)', 'sage') !!}</label>
                    <div
                        class="upload-area border-2 border-dashed border-grey-outline hover:border-primary rounded-lg p-6 text-center cursor-pointer"
                        onclick="document.getElementById('fileUpload').click()"
                    >
                        @svg('images.close', 'size-15 rotate-45 mx-auto [&_path]:fill-secondary-grey/70')
                        <p class="text-sm text-gray-500"><span class="text-primary font-bold">Click to upload</span> or drag & drop</p>
                        <p class="text-xs text-gray-500 hidden md:block mt-1">PNG, JPG up to 10MB each</p>
                        <input type="file" class="hidden" id="fileUpload" multiple accept="image/png, image/jpeg">
                    </div>
                    <ul id="fileList" class="mt-4 space-y-1 md:space-y-2"></ul>
                </div>
            </div>

            {{-- Review Footer --}}
            <div class="footer-section bg-gray-100 px-6 py-4 border-t border-gray-300 flex justify-end gap-3">
                <button type="button" class="close-review-popup px-5 py-2 rounded-lg border cursor-pointer">{!! __('Cancel', 'sage') !!}</button>
                <button type="submit" class="submit-review-btn bg-primary text-white px-6 py-2 rounded-lg cursor-pointer">{!! __('Submit Review') !!}</button>
            </div>
        </form>

    </div>
</div>
