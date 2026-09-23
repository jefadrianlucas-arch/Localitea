<style>
/* =========================================================
   BACK TO TOP BUTTON on every page
========================================================= */

.back-to-top {
    position: fixed;
    right: 25px;
    bottom: 25px;

    width: 46px;
    height: 46px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: #6f4e37;
    color: #ffffff;

    border: none;
    border-radius: 50%;

    font-size: 1.1rem;

    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);

    cursor: pointer;

    opacity: 0;
    visibility: hidden;
    transform: translateY(15px);

    transition:
        opacity 0.25s ease,
        visibility 0.25s ease,
        transform 0.25s ease,
        background 0.2s ease;

    z-index: 9999;
}

.back-to-top.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.back-to-top:hover {
    background: #5b3d2e;
}

.back-to-top:active {
    transform: translateY(1px);
}

@media (max-width: 768px) {
    .back-to-top {
        right: 18px;
        bottom: 18px;
        width: 42px;
        height: 42px;
        font-size: 1rem;
    }
}
</style>

<footer class="py-3 mt-4 border-top bg-light">

    <div class="container">

        <div class="row align-items-center text-center text-md-start">

            <div class="col-md-2 mb-2 mb-md-0 text-center">

                <div
                    class="border rounded-circle d-inline-flex p-2 align-items-center justify-content-center bg-white shadow-sm"
                    style="width: 45px; height: 45px;"
                >
                    <i class="bi bi-cup-hot-fill fs-5 text-dark"></i>
                </div>

            </div>


            <div
                class="col-md-3 mb-2 mb-md-0 small text-dark"
                style="font-size: 0.75rem;"
            >
                From coffee cravings to milktea moments, we've got your cup covered.
            </div>


            <div
                class="col-md-2 mb-2 mb-md-0 small"
                style="font-size: 0.75rem;"
            >

                <strong class="d-block text-dark mb-0">
                    Contact
                </strong>

                <span class="d-block text-muted">
                    example@gmail.com
                </span>

                <span class="d-block text-muted">
                    0918 888 6866
                </span>

            </div>


            <div
                class="col-md-2 mb-2 mb-md-0 small text-center"
                style="font-size: 0.75rem;"
            >

                <strong class="d-block text-dark mb-0">
                    Social Media
                </strong>

                <a
                    href="#"
                    class="text-dark fs-6"
                >
                    <i class="bi bi-facebook"></i>
                </a>

            </div>


            <div
                class="col-md-3 small text-center text-md-end"
                style="font-size: 0.75rem;"
            >

                <strong class="d-block text-dark mb-0">
                    Location
                </strong>

                <span class="text-muted">
                    Blk C3A, Nicolas Virata, GMA, Cavite
                </span>

            </div>

        </div>


        <hr class="my-2 text-muted">


        <div
            class="text-center text-muted"
            style="font-size: 0.7rem;"
        >
            &copy; 2026 Local Milktea House. All rights reserved.
        </div>

    </div>

</footer>

<!-- Bootstrap 5.3.3 JavaScript -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<!-- CUSTOMER ORDERING AJAX -->
<script src="../assets/js/customer-ordering.js"></script>

<!-- BACK TO TOP BUTTON -->
<button
    type="button"
    id="backToTop"
    class="back-to-top"
    aria-label="Back to top"
    title="Back to top"
>
    <i class="bi bi-arrow-up"></i>
</button>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const backToTop = document.getElementById('backToTop');

    if (!backToTop) {
        return;
    }

    function toggleBackToTop() {

        if (window.scrollY > 300) {
            backToTop.classList.add('show');
        } else {
            backToTop.classList.remove('show');
        }

    }

    window.addEventListener('scroll', toggleBackToTop, {
        passive: true
    });

    toggleBackToTop();

    backToTop.addEventListener('click', function () {

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });

    });

});
</script>
</body>
</html>