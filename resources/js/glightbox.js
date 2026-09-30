import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';

// Initializes the lightbox for gallery links ([data-glightbox]); photos of one album share a
// data-gallery value, so prev/next navigation stays inside the album. Descriptions come from the
// hidden `.photo-desc-{id}` elements referenced by data-description.
document.addEventListener('DOMContentLoaded', () => {
    GLightbox({
        selector: '[data-glightbox]',
        loop: true,
        touchNavigation: true,
        zoomable: false,
        preload: true,
        openEffect: 'fade',
        closeEffect: 'fade',
        descPosition: 'bottom',
        moreLength: 0,
    });
});
