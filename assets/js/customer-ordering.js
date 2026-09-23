/* =========================================================
   LOCALITEA CUSTOMER ORDERING AJAX
   Vanilla JS + Fetch API
========================================================= */
(function () {
    'use strict';

    function toastContainer() {
        let el = document.getElementById('customerAjaxToastContainer');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'customerAjaxToastContainer';
        el.style.cssText = 'position:fixed;top:84px;right:18px;z-index:12000;width:min(360px,calc(100vw - 36px));display:flex;flex-direction:column;gap:10px;pointer-events:none;';
        document.body.appendChild(el);
        return el;
    }

    function showToast(message, type) {
        const box = document.createElement('div');
        const danger = type === 'danger';
        box.style.cssText = 'pointer-events:auto;background:#fff;border:1px solid ' + (danger ? '#e3a0a0' : '#d9c8b8') + ';border-radius:12px;padding:12px 14px;box-shadow:0 10px 24px rgba(0,0,0,.12);display:flex;align-items:flex-start;gap:9px;font-size:.84rem;font-weight:600;line-height:1.4;color:' + (danger ? '#842029' : '#4a3525') + ';';

        const icon = document.createElement('i');
        icon.className = 'bi ' + (danger ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill');
        icon.style.color = danger ? '#dc3545' : '#6f4e37';

        const text = document.createElement('span');
        text.textContent = message || (danger ? 'Something went wrong.' : 'Done.');
        box.append(icon, text);
        toastContainer().appendChild(box);

        setTimeout(function () {
            box.style.opacity = '0';
            box.style.transform = 'translateY(-6px)';
            box.style.transition = 'opacity .2s ease,transform .2s ease';
            setTimeout(function () { box.remove(); }, 220);
        }, 2800);
    }

    function loading(active, message) {
        let overlay = document.getElementById('customerAjaxLoading');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'customerAjaxLoading';
            overlay.innerHTML = '<div class="customer-ajax-loading-box"><span class="spinner-border spinner-border-sm me-2"></span><span></span></div>';
            overlay.style.cssText = 'position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,.52);backdrop-filter:blur(1px);z-index:11999;';
            document.body.appendChild(overlay);
        }
        overlay.style.display = active ? 'flex' : 'none';
        const label = overlay.querySelector('span:last-child');
        if (label) label.textContent = message || 'Processing...';
    }

    async function parseResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) return await response.json();
        return { success: response.ok, redirect: response.redirected ? response.url : '' };
    }

    async function submitAjaxForm(form) {
        if (!form || form.dataset.ajaxBusy === '1') return;
        form.dataset.ajaxBusy = '1';
        const data = new FormData(form);
        data.set('ajax', '1');
        const buttons = form.querySelectorAll('button[type="submit"],input[type="submit"]');
        buttons.forEach(function (button) { button.disabled = true; });
        loading(true, form.dataset.ajaxLoadingText || 'Updating...');

        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: (form.getAttribute('method') || 'POST').toUpperCase(),
                body: data,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const result = await parseResponse(response);

            if (result.redirect) {
                window.location.href = result.redirect;
                return;
            }

            if (!response.ok || result.success === false) {
                showToast(result.message || 'Unable to complete the request.', 'danger');
                buttons.forEach(function (button) { button.disabled = false; });
                return;
            }

            showToast(result.message || 'Updated successfully.', 'success');
            setTimeout(function () { window.location.reload(); }, 300);
        } catch (error) {
            showToast('Please check your connection and try again.', 'danger');
            buttons.forEach(function (button) { button.disabled = false; });
        } finally {
            loading(false);
            form.dataset.ajaxBusy = '0';
        }
    }

    async function removeCartItem(link) {
        if (!link || link.dataset.ajaxBusy === '1') return;
        link.dataset.ajaxBusy = '1';
        loading(true, 'Removing item...');
        try {
            const joiner = link.href.includes('?') ? '&' : '?';
            const response = await fetch(link.href + joiner + 'ajax=1', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const result = await parseResponse(response);

            if (result.redirect) {
                window.location.href = result.redirect;
                return;
            }
            if (!response.ok || result.success === false) {
                showToast(result.message || 'Unable to remove the item.', 'danger');
                return;
            }
            showToast(result.message || 'Item removed.', 'success');
            setTimeout(function () { window.location.reload(); }, 250);
        } catch (error) {
            showToast('Please check your connection and try again.', 'danger');
        } finally {
            loading(false);
            link.dataset.ajaxBusy = '0';
        }
    }

    window.submitCheckoutAjax = async function (form) {
        if (!form || form.dataset.ajaxBusy === '1') return false;
        form.dataset.ajaxBusy = '1';

        const data = new FormData(form);
        data.set('ajax', '1');
        const button = document.getElementById('checkoutButton');

        if (button) {
            button.disabled = true;
            button.dataset.originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        }

        loading(true, 'Placing your order...');

        try {
            const response = await fetch(form.getAttribute('action') || 'checkout.php', {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const result = await parseResponse(response);

            if (!response.ok || result.success === false) {
                showToast(result.message || 'Unable to place your order.', 'danger');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = button.dataset.originalText || 'Place Order';
                }
                return false;
            }

            if (result.redirect) {
                window.location.href = result.redirect;
                return false;
            }

            showToast(result.message || 'Order placed successfully.', 'success');
        } catch (error) {
            showToast('Please check your connection and try again.', 'danger');
            if (button) {
                button.disabled = false;
                button.innerHTML = button.dataset.originalText || 'Place Order';
            }
        } finally {
            loading(false);
            form.dataset.ajaxBusy = '0';
        }

        return false;
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-ajax-form="true"]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                submitAjaxForm(form);
            });
        });

        document.querySelectorAll('a[data-ajax-remove="true"]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                removeCartItem(link);
            });
        });
    });
})();
