(function($) {
    var Ajax_Thumbnail_Rebuild = {
        regenerate_attachment_thumbnails: function(attachment_id, nonce, callback) {
            $.post(
                ajaxurl,
                {
                    attachment_id: attachment_id,
                    _ajax_nonce: nonce,
                    action: 'ajax_thumbnail_rebuild'
                },
                function(response) {
                    // Check for response
                    if (response.success === true) {
                        if (typeof callback === 'function') {
                            callback(response.data);
                        }
                    }
                },
                'JSON'
            );
        },
    };

    $(document)
        .on('click', '.thumbnail-rebuild__generate-button--single', function(event) {
            event.preventDefault();

            // Set up variables
            var $button       = $(this);
            var attachment_id = $button.data('attachment-id');
            var nonce         = $button.data('nonce');

            // Disable button, add loading animation
            $button.prop('disabled', true);
            $button.addClass('');

            Ajax_Thumbnail_Rebuild.regenerate_attachment_thumbnails(attachment_id, nonce, function(data) {
                $button.prop('disabled', false);
            });
        })
})(jQuery);