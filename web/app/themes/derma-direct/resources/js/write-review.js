document.addEventListener('DOMContentLoaded', () => {
    let selectedFiles = [];
    const write_review_button__el = document.getElementById('write_review_button');
    const popupOverlay = document.querySelector(".review-popup");
    const popupBox = document.querySelector(".popup-box");
    const closeBtns = document.querySelectorAll(".close-review-popup");

    const stars = document.querySelectorAll(".rating-stars .star");
    const ratingInput = document.querySelector(".rating-input");
    const ratingText = document.querySelector(".rating-text");
    const labels = ["Poor", "Fair", "Good", "Very Good", "Excellent"];
    let defaultRating = 5;

    const fileList = document.getElementById("fileList");
    fileList.innerHTML = "";
    const fileInput = document.getElementById("fileUpload");
    selectedFiles = [];

    const form = document.getElementById("reviewForm");
    const nameError = document.querySelector(".name-field-error");
    const emailError = document.querySelector(".email-field-error");
    const reviewError = document.querySelector(".review-text-error");

    function handlePopupOpenClose() {
        // Opening of review form popup.
        write_review_button__el.addEventListener('click', () => {
            popupOverlay.classList.remove("opacity-0", "invisible");
            popupBox.classList.remove("translate-y-8");
        });

        // Closing of review form popup via close button.
        closeBtns.forEach((btn) => {
            btn.addEventListener("click", () => {
                _closePopup()
            })
        });

        // Closing of review form popup via clicking outside the popupOverlay.
        popupOverlay.addEventListener("click", e => {
            if (!e.target.classList.contains("review-popup")) return;

            _closePopup();
        });
    }

    function _closePopup() {
        popupOverlay.classList.add("opacity-0", "invisible");
        popupBox.classList.add("translate-y-8");

        _resetForm();
    }

    function _updateStars(stars__el, value) {
        stars__el.forEach(star => {
            star.classList.toggle("bg-[#000000]", parseInt(star.dataset.value) <= value);
            star.classList.toggle("bg-gray-300", parseInt(star.dataset.value) > value);
        });
    }

    function updateStarRating() {
        // Initialize default rating
        ratingInput.value = defaultRating;
        ratingText.textContent = labels[defaultRating - 1];
        _updateStars(stars, defaultRating);

        // Handle star click
        stars.forEach(star => {
            star.addEventListener("click", () => {
                const value = parseInt(star.dataset.value);
                ratingInput.value = value;
                ratingText.textContent = labels[value - 1];
                _updateStars(stars, value);
            });
        });
    }

    function _renderFiles(selectedFiles=[]) {
        selectedFiles.forEach((file, index) => {
            const li = document.createElement("li");
            li.className = "flex justify-between items-center bg-gray-100 px-3 py-2 rounded";

            const name = document.createElement("span");
            name.textContent = file.name;

            const button = document.createElement("button");
            button.textContent = "Remove";
            button.className = "text-red-600 cursor-pointer";
            button.addEventListener("click", () => {
                selectedFiles.splice(index, 1);
                _renderFiles(selectedFiles);
            });

            li.appendChild(name);
            li.appendChild(button);
            fileList.appendChild(li);
        });
    }

    function handleUploadedFilesRender() {
        fileInput.addEventListener("change", () => {
            Array.from(fileInput.files).forEach(file => {
                if (!["image/jpeg", "image/png"].includes(file.type)) return alert("Only JPG/PNG allowed");
                if (file.size > 10 * 1024 * 1024) return alert("File max 10MB");
                selectedFiles.push(file);
            });
            _renderFiles(selectedFiles);
        });
    }

    function handleFormSubmission() {
        function _isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function validateForm(data) {
            // Clearing previous errors.
            let valid = true;
            [nameError, emailError, reviewError].forEach(el => el.textContent = "");

            if (!data.name) {
                nameError.textContent = "Name is required"; valid = false;
            }
            if (!data.email) {
                emailError.textContent = "Email is required"; valid = false;
            }
            else if (!_isValidEmail(data.email)) {
                emailError.textContent = "Invalid email"; valid = false;
            }
            if (!data.review) {
                reviewError.textContent = "Review is required"; valid = false;
            }

            return valid;
        }

        // - - - - - - - - - - - - - - - - - - - - -
        form.addEventListener("submit", e => {
            e.preventDefault();

            const formData = {
                action: "save_product_review",
                nonce: pr_localized_data.nonce,
                product_id: form.getAttribute("data-product-id"),
                name: form.name.value.trim(),
                email: form.email.value.trim(),
                review: form.review.value.trim(),
                rating: ratingInput.value,
                images: selectedFiles.map(f => f.name) // optional
            };

            if (!validateForm(formData)) return;

            console.log("Submitting review...", formData);

            fetch(pr_localized_data.ajax_url, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams(formData).toString()
            })
            .then(res => res.json())
            .then(response => {
                const form_content__el = document.querySelector('#reviewForm .form-content');

                if (response.success) {
                    form_content__el.innerHTML = response.message;
                    _resetForm();
                } else {
                    form_content__el.innerHTML = response.message || 'Error submitting review.';
                }

                document.querySelector('.review-popup .footer-section').style.display = 'none';
            });
        });
    }

    function _resetForm() {
        ratingInput.value = 5;
        ratingText.textContent = labels[defaultRating - 1];
        _updateStars(stars, 5);

        selectedFiles = [];
        _renderFiles(selectedFiles);

        form.reset();
    }
    /**
     * ======================================================================
     * Main.
    */
    handlePopupOpenClose();
    updateStarRating();
    handleUploadedFilesRender();
    handleFormSubmission();
});
