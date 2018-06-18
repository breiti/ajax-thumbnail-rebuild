(function($) {
    var Ajax_Thumbnail_Rebuild = {
        regenerate_attachment_thumbnails: function(attachment_id, nonce, callback) {
            $.ajax({
                url: window.ajaxurl,
                data: {
                    attachment_id: attachment_id,
                    _ajax_nonce: nonce,
                    action: 'ajax_thumbnail_rebuild__generate_thumbnail'
                },
                dataType: 'JSON',
                success: function(response) {
                    // Check for response
                    if (response.success === true) {
                        if (typeof callback === 'function') {
                            callback(response.data);
                        }
                    }
                },
                error: function() {
                    // Error
                }
            });
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
        });

    if (window.adminpage == 'tools_page_ajax-thumbnail-rebuild') {
        $.get(
            window.ajaxurl,
            {
                only_featured: '1',
                action: 'ajax_thumbnail_rebuild__get_attachments_list_count'
            }
        );
    }
})(jQuery);