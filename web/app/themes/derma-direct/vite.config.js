import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin'
import { wordpressPlugin, wordpressThemeJson } from '@roots/vite-plugin';

export default defineConfig({
  base: '/app/themes/derma-direct/public/build/',
  plugins: [
    tailwindcss(),
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/app.js',

        'resources/css/editor.css',
        'resources/js/editor.js',

        'resources/js/header.js',
        'resources/js/lightbox.js',

        'resources/css/mini-cart.css',
        'resources/js/mini-cart.js',

        'resources/css/woocommerce-common.css',
        'resources/js/woocommerce-common.js',

        'resources/js/product-archive.js',
        'resources/js/product-single.js',
        'resources/css/next-day-delivery-timer.css',
        'resources/js/next-day-delivery-timer.js',
        'resources/js/write-review.js',
        'resources/js/wishlist.js',
        'resources/js/popup.js',
        'resources/js/quick-view-popup.js',
        'resources/js/search-results.js',
        'resources/js/cart-page.js',
        'resources/js/account-dashboard.js',
        'resources/js/training-partner.js',
        'resources/js/checkout-page.js',

        'resources/css/account-page.css',
        'resources/css/blog-page.css',
        'resources/css/cart-page.css',
        'resources/css/checkout-page.css',
        'resources/css/search.css',
        'resources/css/training-partner.css',

        // Blocks.
        'resources/js/blocks/video-section.js',

        'resources/js/blocks/shop-by-category.js',

        'resources/css/blocks/reviews-slider.css',
        'resources/js/blocks/reviews-slider.js',

        'resources/css/blocks/hero-banner-slider.css',
        'resources/js/blocks/hero-banner-slider.js',

        'resources/css/blocks/brands-slider.css',
        'resources/js/blocks/brands-slider.js',

        'resources/css/blocks/testimonials.css',
        'resources/js/blocks/testimonials.js',

        'resources/css/blocks/newsletter-subscribe.css',
        'resources/js/blocks/newsletter-subscribe.js',

        'resources/css/blocks/heading-and-content.css',

        'resources/css/blocks/product-description.css',
        'resources/js/blocks/product-description.js',

        'resources/js/blocks/video-banner.js',

        // Admin pages.
        'resources/css/admin/next-day-delivery-timer.css',
        'resources/js/admin/next-day-delivery-timer.js',
        'resources/css/admin/order-page.css',
        'resources/js/admin/order-page.js',
      ],
      refresh: true,
    }),

    wordpressPlugin(),

    // Generate the theme.json file in the public/build/assets directory
    // based on the Tailwind config and the theme.json file from base theme folder
    wordpressThemeJson({
      disableTailwindColors: false,
      disableTailwindFonts: false,
      disableTailwindFontSizes: false,
    }),
  ],
  resolve: {
    alias: {
      '@scripts': '/resources/js',
      '@styles': '/resources/css',
      '@fonts': '/resources/fonts',
      '@images': '/resources/images',
    },
  },
})
