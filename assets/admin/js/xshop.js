!(function ($, window, document, _undefined) {
  $(function () {
    $(document).ready(function () {
      $("#xshop_sync").click(function (e) {
        var $button = $(e.target).prop("disabled", true);
        var $spin = $button.next(".spinner").addClass("is-active");
        $.ajax({
          type: "POST",
          url: ajaxurl,
          dataType: "json",
          data: {
            action: "xshop_sync_product",
          },
          success: function (response) {
            console.log(response);
            $button.prop("disabled", false);
            $spin.removeClass("is-active");
          },
          error: function () {
            $button.prop("disabled", false);
            $spin.removeClass("is-active");
          },
        });
      });
    });
    // Add buttons to product screen.
    var $product_screen = $(".edit-php.post-type-product");
    var $title_action = $product_screen.find(".page-title-action:first");

    if ($title_action.length) {
      $title_action.after(
        '<button id="xshop_sync" class="page-title-action">xShop Sync</button><span class="spinner spinner-xshop"></span>'
      );
    }

  });
})(jQuery, window, document);
