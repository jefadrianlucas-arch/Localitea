<style>
/* =========================================================
   SITE FOOTER — Local Milktea House
   Palette: espresso #2C221E · roast #4A3525 · mocha #6F4E37
            oat #F3EADF · foam #FBF7F1 · line #E6DACB
========================================================= */

.site-footer {
    margin-top: 2rem;
    background: #F1E6D8;
    border-top: 1px solid #E0D2BF;
    color: #6D5B4C;
    font-size: 0.82rem;
    line-height: 1.55;
}

.site-footer-main {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) repeat(3, minmax(0, 1fr));
    gap: 28px;
    align-items: start;
    padding: 26px 0 20px;
}

/* Brand */
.site-footer-brand {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

/* Logo picture (replace the file in the <img> src to change it) */
.site-footer-logo {
    flex: 0 0 auto;
    display: block;
    width: 56px;
    height: 56px;
    object-fit: contain;
}

.site-footer-name {
    margin: 0 0 3px;
    color: #2C221E;
    font-size: 0.95rem;
    font-weight: 600;
    line-height: 1.25;
}

.site-footer-tagline {
    margin: 0;
    max-width: 300px;
    color: #6D5B4C;
}

/* Columns */
.site-footer-title {
    margin: 0 0 8px;
    color: #2C221E;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.4px;
}

.site-footer-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.site-footer-list li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-weight: 400;
}

.site-footer-list i {
    flex: 0 0 auto;
    margin-top: 3px;
    color: #8A6A4F;
    font-size: 0.85rem;
}

.site-footer-social {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid #DCCDBD;
    border-radius: 50%;
    background: #ffffff;
    color: #4A3525;
    font-size: 1rem;
    text-decoration: none;
}

.site-footer-social:hover,
.site-footer-social:focus-visible {
    background: #332317;
    border-color: #332317;
    color: #ffffff;
}

.site-footer-social:focus-visible {
    outline: 3px solid rgba(111, 78, 55, 0.35);
    outline-offset: 2px;
}

/* Copyright */
.site-footer-bottom {
    padding: 12px 0 16px;
    border-top: 1px solid #DDCEBB;
    color: #75665A;
    font-size: 0.74rem;
    text-align: center;
}

@media (prefers-reduced-motion: no-preference) {
    .site-footer-social {
        transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
}

@media (max-width: 991.98px) {
    .site-footer-main {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 22px 20px;
    }

    .site-footer-brand {
        grid-column: 1 / -1;
    }

    .site-footer-tagline {
        max-width: none;
    }
}

@media (max-width: 575.98px) {
    .site-footer-main {
        grid-template-columns: minmax(0, 1fr);
        gap: 18px;
        padding: 22px 0 16px;
    }

    .site-footer-brand {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .site-footer-col {
        text-align: center;
    }

    .site-footer-list {
        align-items: center;
    }

    .site-footer-list li {
        justify-content: center;
        text-align: left;
    }
}

/* =========================================================
   BACK TO TOP BUTTON on every page
========================================================= */

.back-to-top {
    position: fixed;
    right: 25px;
    bottom: 25px;

    width: 44px;
    height: 44px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: #332317;
    color: #ffffff;

    border: none;
    border-radius: 50%;

    font-size: 1.05rem;

    box-shadow: 0 6px 16px rgba(51, 35, 23, 0.25);

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
    background: #24170F;
}

.back-to-top:focus-visible {
    outline: 3px solid rgba(111, 78, 55, 0.4);
    outline-offset: 2px;
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

<footer class="site-footer">

    <div class="container">

        <div class="site-footer-main">

            <div class="site-footer-brand">

                <!-- LOGO: palitan lang ang filename sa src para sa picture mo -->
                <img
                    src="../assets/images/logo.png"
                    alt="Local Milktea House logo"
                    class="site-footer-logo"
                >

                <div>
                    <p class="site-footer-name">Local Milktea House</p>
                    <p class="site-footer-tagline">
                        From coffee cravings to milktea moments, we've got your cup covered.
                    </p>
                </div>

            </div>


            <div class="site-footer-col">

                <h6 class="site-footer-title">Contact</h6>

                <ul class="site-footer-list">
                    <li>
                        <i class="bi bi-envelope"></i>
                        <span>localmilkteahouse@gmail.com</span>
                    </li>
                    <li>
                        <i class="bi bi-telephone"></i>
                        <span>0908 555 6644</span>
                    </li>
                </ul>

            </div>


            <div class="site-footer-col">

                <h6 class="site-footer-title">Social Media</h6>

                <a
                    href="https://www.facebook.com/share/1PDfmQysuD/"
                    class="site-footer-social"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Local Milktea House on Facebook"
                    title="Visit our Facebook page"
                >
                    <i class="bi bi-facebook"></i>
                </a>

            </div>


            <div class="site-footer-col">

                <h6 class="site-footer-title">Location</h6>

                <ul class="site-footer-list">
                    <li>
                        <i class="bi bi-geo-alt"></i>
                        <span>Blk 5 Lot 2 Block C2a st. Nicolasa Virata General Mariano Alvarez Caviite, General Mariano Alvarez, Philippines, 4117</span>
                    </li>
                </ul>

            </div>

        </div>


        <div class="site-footer-bottom">
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