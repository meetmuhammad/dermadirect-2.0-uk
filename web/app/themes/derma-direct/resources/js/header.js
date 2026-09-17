/**
 * This file has JS code logic needed for the header section.
*/

document.addEventListener('DOMContentLoaded', () => {
    const handleDropdownClick = () => {
        /**
         * This function controls the top header dropdowns for language and currency
         * option selections.
        */
        const dropdowns = document.querySelectorAll(".top-header .dropdown, .bottom-header .dropdown");
        dropdowns.forEach((dropdown) => {
            const btn = dropdown.querySelector(".dropdown-btn");
            const label = dropdown.querySelector(".dropdown-label");
            const menu = dropdown.querySelector(".dropdown-menu");
            const items = dropdown.querySelectorAll(".dropdown-item");

            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                menu.classList.toggle("hidden");
                dropdowns.forEach((other) => {
                    if (other !== dropdown) other.querySelector(".dropdown-menu").classList.add("hidden");
                });
            });

            items.forEach((item) => {
                item.addEventListener("click", (e) => {
                    e.preventDefault();
                    label.textContent = item.textContent;
                    menu.classList.add("hidden");
                });
            });
        });

        window.addEventListener("click", () => {
            dropdowns.forEach((dropdown) =>
                dropdown.querySelector(".dropdown-menu").classList.add("hidden")
            );
        });
    }

    const handleMainNavMenu = () => {
        /**
         * This function is used to control the main header menu dropdowns.
        */
        // const menu = document.querySelector("header #main_nav_menu");
        const submenu_btn__els = document.querySelectorAll("header .submenu-btn");
        if ((submenu_btn__els.length ?? 0) === 0) return;

        submenu_btn__els.forEach((btn) => {
            const submenu = btn.nextElementSibling;

            btn.addEventListener("click", (e) => {
                e.stopPropagation();

                document.querySelectorAll(".submenu").forEach((menu) => {
                    if (menu !== submenu) menu.classList.add("hidden");
                });

                submenu.classList.toggle("hidden");
            });
        });

        // Hiding submenus if a new submenu is opened.
        document.addEventListener("click", () => {
            document.querySelectorAll(".submenu").forEach((submenu) => submenu.classList.add("hidden"));
        });
    }

    const toggleMobileMenu = () => {
        const mobile_menu_btn__el = document.querySelector('#mobile_menu_btn');
        const menu__el = document.getElementById("main_nav_menu");

        if (!mobile_menu_btn__el || !menu__el) return;

        mobile_menu_btn__el.addEventListener("click", () => {
            menu__el.classList.toggle("hidden");
        });

        // Close when clicking outside
        document.addEventListener("click", (e) => {
            const clickedInsideMenu = e.target.closest("#main_nav_menu");
            const clickedToggleBtn = e.target.closest("#mobile_menu_btn");

            if (!clickedInsideMenu && !clickedToggleBtn) {
                menu__el.classList.add("hidden");
            }
        });
    }

    jQuery(document).on('click', '.currency-switcher-dropdown .dropdown-item', function (e) {
        e.preventDefault();

        const currency = jQuery(this).text().trim();
        const dropdown = jQuery(this).closest('.currency-switcher-dropdown');

        jQuery.ajax({
            url: minicart_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'set_currency_preference',
                currency: currency,
                nonce: minicart_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update UI instantly (No lag)
                    dropdown.find('.dropdown-label').text(currency);

                    // Refresh page so WooCommerce prices update
                    location.reload();
                }
            }
        });
    });

    // mega menu open and page refreshed

    document.querySelectorAll('.has-dropdown-toggle > a').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault(); // stop refresh
            this.parentElement.classList.toggle('open'); // dropdown toggle
        });
    });

    // -------------------- Main -------------------------.
    handleDropdownClick();
    handleMainNavMenu();
    toggleMobileMenu();
});
