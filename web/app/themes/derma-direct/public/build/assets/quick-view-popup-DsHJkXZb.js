jQuery(document).ready(function(i){function l(){i("#quick-view-modal").length||i("body").append(`
            <div id="quick-view-modal" class="fixed min-h-full inset-0 bg-black/50 z-40 flex md:items-center justify-center p-4 hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="quick-view-name" tabindex="-1">
                <div class="bg-white h-fit rounded-2xl shadow-2xl w-full max-w-4xl relative p-5 sm:p-10">
                    <button class="quick-view-close max-sm:p-[5px] max-sm:bg-red-500 rounded-full absolute -top-2 sm:top-8 -right-2 sm:right-8 text-gray-400 hover:text-gray-600 transition z-10 hover:cursor-pointer" aria-label="Close">
                        ${quick_view_popup_vars.close_svg}
                    </button>
                    <div class="flex flex-col sm:flex-row items-start gap-10">
                        <div class="relative bg-gray-100 flex items-center justify-center w-full sm:w-64 sm:h-64 shrink-0 rounded-xl overflow-hidden">
                            <span id="quick-view-sale-badge" class="absolute top-4 left-4 bg-red-500 text-white text-xs font-bold px-3 py-1 rounded hidden">SALE</span>
                            <img id="quick-view-image" class="size-full object-cover" src="" alt="">
                        </div>
                        <div class="flex-1">
                        <p id="quick-view-brand" class="uppercase text-xs tracking-wide text-gray-400 font-semibold mb-1"></p>
                            <p id="quick-view-name" class="text-2xl font-bold text-gray-900 leading-snug mb-3"></p>
                            <p id="quick-view-package" class="text-sm text-gray-500 mb-3"></p>
                            <p id="quick-view-description" class="text-base text-gray-600 leading-relaxed mb-5"></p>
                            <hr class="border-gray-100 mb-5" />
                            <div id="quick-view-stock" class="mb-4"></div>
                            <div id="quick-view-price" class="mb-4">
                                <div class="flex items-baseline gap-3 flex-wrap">
                                    <span id="quick-view-regular-price" class="text-gray-400 line-through text-base"></span>
                                    <span id="quick-view-ex-vat-price" class="text-[#e05050] text-3xl font-bold"></span>
                                    <span class="text-gray-500 text-sm">Ex VAT</span>
                                </div>
                                <p id="quick-view-inc-vat-price" class="text-gray-500 text-base mt-1"></p>
                                <div id="quick-view-discount-wrapper" class="flex items-center gap-2 mt-2 flex-wrap">
                                    <span id="quick-view-saving-amount" class="bg-red-50 text-red-500 text-xs font-semibold px-3 py-1 rounded-full border border-red-100"></span>
                                    <span id="quick-view-saving-percent" class="bg-red-50 text-red-500 text-xs font-semibold px-3 py-1 rounded-full border border-red-100"></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mb-4">
                                <button type="button" class="quick-view-qty-minus border border-gray-200 rounded-lg w-10 h-10"> - </button>
                                <input id="quick-view-quantity" type="number" value="1" min="1" class="w-16 h-10 text-center border border-gray-200 rounded-lg">
                                <button type="button" class="quick-view-qty-plus border border-gray-200 rounded-lg w-10 h-10"> + </button>
                            </div>
                            <div id="quick-view-cart" class="w-full mb-3"></div>
                            <a href="#" id="quick-view-product-detail" class="block text-center text-sm text-gray-500 hover:text-gray-800 transition">View full product details →</a>
                        </div>
                    </div>
                </div>
            </div>
        `)}function n(){i("#quick-view-modal").removeClass("hidden").addClass("overflow-auto").attr("aria-hidden","false").focus()}function c(){i("#quick-view-modal").addClass("hidden").removeClass("overflow-auto").attr("aria-hidden","true"),i("#quick-view-image").attr("src","").attr("alt",""),i("#quick-view-name, #quick-view-description, #quick-view-ex-vat-price, #quick-view-inc-vat-price, #quick-view-regular-price, #quick-view-saving-amount, #quick-view-saving-percent, #quick-view-cart").html(""),i("#quick-view-product-detail").attr("href","#"),i("#quick-view-sale-badge").addClass("hidden"),i("#quick-view-discount-wrapper").hide()}function d(){i("#quick-view-cart").html('<button disabled class="w-full bg-black text-white font-semibold rounded-xl flex items-center justify-center gap-2 py-3.5 opacity-50">Loading...</button>')}function s(){let e=parseInt(i("#quick-view-quantity").val());(!e||e<1)&&(e=1,i("#quick-view-quantity").val(e)),i("#quick-view-cart .ajax_add_to_cart").attr("data-quantity",e).data("quantity",e)}i(document).on("click",".quick-view-qty-plus",function(){let e=parseInt(i("#quick-view-quantity").val());i("#quick-view-quantity").val(e+1),s()}),i(document).on("click",".quick-view-qty-minus",function(){let e=parseInt(i("#quick-view-quantity").val());e>1&&(i("#quick-view-quantity").val(e-1),s())});function o(e){const t=e.price;i("#quick-view-quantity").val(1),i("#quick-view-image").attr("src",e.image).attr("alt",e.name),i("#quick-view-name").html(e.name),i("#quick-view-brand").html(e.brand),i("#quick-view-description").html(e.description),i("#quick-view-ex-vat-price").html(t.purchase_ex_vat),i("#quick-view-inc-vat-price").html(`${t.purchase_inc_vat} Inc VAT`),i("#quick-view-product-detail").attr("href",e.permalink),i("#quick-view-cart").html(e.cart),s(),i("#quick-view-stock").html(e.stock_status),t.is_sale?(i("#quick-view-regular-price").html(t.regular_ex_vat).show(),i("#quick-view-discount-wrapper").show(),i("#quick-view-sale-badge").removeClass("hidden"),i("#quick-view-saving-amount").html(`Save ${t.saving_amount}`),i("#quick-view-saving-percent").html(`${t.saving_percent}% off`)):(i("#quick-view-regular-price").hide(),i("#quick-view-discount-wrapper").hide(),i("#quick-view-sale-badge").addClass("hidden"));let a="";e.package_includes&&e.package_includes.length&&(a='<ul class="space-y-1">',e.package_includes.forEach(function(r){a+=`
                    <li class="text-sm text-gray-500">
                        ${r.item}
                    </li>
                `}),a+="</ul>"),i("#quick-view-package").html(a)}i(document).on("click",".quick-view-trigger",function(){const e=i(this).data("product-id");l(),n(),d(),i.ajax({url:quick_view_popup_vars.ajax_url,type:"POST",data:{action:"get_quick_view_product",nonce:quick_view_popup_vars.nonce,product_id:e},success:function(t){t.success&&o(t.data)},error:function(t){console.error("Quick view error:",t),i("#quick-view-cart").html('<p class="text-red-500 text-sm">Failed to load product. Please try again.</p>')}})}),i(document).on("click",".quick-view-close",function(){c()}),i(document).on("keydown",function(e){e.key==="Escape"&&c()}),i(document).on("click","#quick-view-modal",function(e){i(e.target).is("#quick-view-modal")&&c()})});
