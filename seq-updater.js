jQuery(document).ready(function($) {
    let queue = [];
    let current = 0;

    /**
     * Manual Run: Step-by-step, no email
     */
    $('#run-updates').on('click', function(e) {
        e.preventDefault();
        $.post(SeqUpdater.ajaxurl, {
            action: 'get_saved_plugins',
            nonce: SeqUpdater.nonce
        }, function(res) {
            if (res.success && res.data.length > 0) {
                queue = res.data;
                current = 0;
                $('#update-log').empty();
                runNext();
            } else {
                alert("No saved plugins found.");
            }
        });
    });

    /**
     * Manual Run: All at once, then send email
     */
    $('#run-updates-email').on('click', function(e) {
        e.preventDefault();
        $('#update-log').empty().append('<li>⏳ Running updates + sending email...</li>');

        $.post(SeqUpdater.ajaxurl, {
            action: 'run_updates_with_email',
            nonce: SeqUpdater.nonce
        }, function(res) {
            if (res.success) {
                $('#update-log').append('<li>✅ Updates complete. Email sent.</li>');
                $.each(res.data, function(plugin, status) {
                    $('#update-log').append('<li>' + plugin + ': ' + status + '</li>');
                });
            } else {
                $('#update-log').append('<li>❌ ' + res.data + '</li>');
            }
        });
    });

    /**
     * Helper - update plugins sequentially (step-by-step mode)
     */
    function runNext() {
        if (current >= queue.length) {
            $('#update-log').append('<li>✅ All updates finished.</li>');
            return;
        }
        let plugin = queue[current];
        $('#update-log').append('<li>⏳ Updating ' + plugin + '...</li>');

        $.post(SeqUpdater.ajaxurl, {
            action: 'seq_update_plugin',
            nonce: SeqUpdater.nonce,
            plugin: plugin
        }, function(res) {
            if (res.success) {
                $('#update-log').append('<li>' + plugin + ': ' + res.data.status + '</li>');
            } else {
                $('#update-log').append('<li>' + plugin + ': ❌ Failed</li>');
            }
            current++;
            runNext();
        });
    }
});