// Only flips the button to "Added ✓" once WooCommerce actually confirms the add succeeded (its
// own `added_to_cart` event) — not optimistically on click, which used to show "Added ✓" even
// when the request was rejected server-side (e.g. the 50-per-product cap) and the page was about
// to redirect to the product page with an error instead.
export function handleAddToCartButtonTextChange() {
    jQuery(document.body)
        .off('added_to_cart.ddAddedText')
        .on('added_to_cart.ddAddedText', function (event, fragments, cart_hash, $button) {
            if (!$button || !$button.length) return;

            const button = $button.get(0);
            const text = button.textContent.trim().toLowerCase();
            if (text.includes('out of stock')) return;
            if (button.classList.contains('added-temp')) return;

            button.classList.add('added-temp');
            // Defer the innerHTML swap to the next tick. Doing it synchronously here
            // detaches the real event.target (the icon/text node the user actually clicked,
            // since this button has child nodes) from the DOM while the click is still
            // bubbling. WooCommerce's own delegated add-to-cart handler on document.body
            // then can't match the now-orphaned target against `.add_to_cart_button`, so its
            // preventDefault() never runs and the browser falls through to a full navigation
            // instead of the AJAX add. Confirmed live: capture-phase target was `connected:
            // true`, but by the final bubble listener it was `connected: false, prevented:
            // false`. Pushing the mutation past the current dispatch fixes that race.
            setTimeout(() => {
                const originalContent = button.innerHTML;
                button.innerHTML = 'Added ✓';
                setTimeout(() => {
                    button.innerHTML = originalContent;
                    button.classList.remove('added-temp');
                }, 3000);
            }, 0);
        });
}

document.addEventListener('DOMContentLoaded', () => {
    handleAddToCartButtonTextChange();
});