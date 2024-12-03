(function($) {
  "use strict";

  // Vérification de la disponibilité de jQuery
  if (typeof $ === 'undefined') {
      throw new Error('jQuery is not loaded. Please load jQuery before this script.');
  }

  // Toggle the side navigation
  $("#sidebarToggle, #sidebarToggleTop").on('click', function() {
      $("body").toggleClass("sidebar-toggled");
      $(".sidebar").toggleClass("toggled");
      if ($(".sidebar").hasClass("toggled")) {
          $('.sidebar .collapse').collapse('hide');
      }
  });

  // Close menu accordions on resize
  $(window).resize(function() {
      if ($(window).width() < 768) {
          $('.sidebar .collapse').collapse('hide');
      }

      if ($(window).width() < 480 && !$(".sidebar").hasClass("toggled")) {
          $("body").addClass("sidebar-toggled");
          $(".sidebar").addClass("toggled");
          $('.sidebar .collapse').collapse('hide');
      }
  });

  // Prevent scrolling when hovering over fixed sidebar
  $('body.fixed-nav .sidebar').on('mousewheel DOMMouseScroll wheel', function(e) {
      if ($(window).width() > 768) {
          var e0 = e.originalEvent,
              delta = e0.wheelDelta || -e0.detail;
          this.scrollTop += (delta < 0 ? 1 : -1) * 30;
          e.preventDefault();
      }
  });

  // Show/hide the scroll-to-top button
  $(document).on('scroll', function() {
      var scrollDistance = $(this).scrollTop();
      if (scrollDistance > 100) {
          $('.scroll-to-top').fadeIn();
      } else {
          $('.scroll-to-top').fadeOut();
      }
  });

  // Smooth scrolling for scroll-to-top button
  $(document).on('click', 'a.scroll-to-top', function(e) {
      var $anchor = $(this);
      $('html, body').stop().animate({
          scrollTop: ($($anchor.attr('href')).offset().top)
      }, 1000, 'easeInOutExpo');
      e.preventDefault();
  });

})(jQuery);