/**
 * Shop Builder — touch support for drag & drop reordering.
 *
 * jQuery UI Sortable only listens to mouse events, so on phones and tablets
 * the drag handles did nothing. This translates touches that START on a
 * drag handle (.sb-drag-handle) into the mouse events Sortable expects.
 * Touches anywhere else are left alone, so the page still scrolls normally;
 * while dragging, Sortable's own edge auto-scroll moves the page.
 */
(function () {
    'use strict';

    const HANDLE = '.sb-drag-handle';
    let active = false;

    function dispatch(type, touch, target) {
        const event = new MouseEvent(type, {
            bubbles: true,
            cancelable: true,
            view: window,
            clientX: touch.clientX,
            clientY: touch.clientY,
            screenX: touch.screenX,
            screenY: touch.screenY,
            button: 0,
            buttons: type === 'mouseup' ? 0 : 1,
        });
        (target || document).dispatchEvent(event);
    }

    document.addEventListener('touchstart', function (e) {
        if (e.touches.length !== 1) return;
        const handle = e.target.closest && e.target.closest(HANDLE);
        if (!handle) return;
        active = true;
        // Stops the page from scrolling and the browser's emulated mouse events.
        e.preventDefault();
        dispatch('mouseover', e.touches[0], handle);
        dispatch('mousedown', e.touches[0], handle);
    }, { passive: false });

    document.addEventListener('touchmove', function (e) {
        if (!active) return;
        e.preventDefault();
        dispatch('mousemove', e.touches[0]);
    }, { passive: false });

    function end(e) {
        if (!active) return;
        active = false;
        dispatch('mouseup', e.changedTouches[0]);
    }
    document.addEventListener('touchend', end);
    document.addEventListener('touchcancel', end);
})();
