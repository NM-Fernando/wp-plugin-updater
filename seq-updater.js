jQuery(document).ready(function($) {
    let queue = [];
    let current = 0;

    // Append log with color and optional timestamp
    function appendLog(plugin, status, timestamp = null) {
        let color = status.includes("✅") ? "green" : status.includes("❌") ? "red" : "orange";
        let timeText = timestamp ? " (Last Updated: " + timestamp + ")" : "";
        $('#update-log').append('<li style="color:' + color + '">' + plugin + ': ' + status + timeText + '</li>');
        $('#update-log').scrollTop($('#update-log')[0].scrollHeight);
    }

    // Run updates (no email)
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
            } else alert("No saved plugins found.");
        });
    });

    // Run updates + email
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
                    let timestamp = new Date().toLocaleString();
                    appendLog(plugin, status, timestamp);
                });
            } else {
                $('#update-log').append('<li style="color:red">❌ ' + res.data + '</li>');
            }
        });
    });

    // Run next plugin in queue
    function runNext() {
        if (current >= queue.length) {
            $('#update-log').append('<li style="color:green">✅ All updates finished.</li>');
            return;
        }

        let plugin = queue[current];
        $('#update-log').append('<li style="color:orange">⏳ Updating ' + plugin + '...</li>');

        $.post(SeqUpdater.ajaxurl, {
            action: 'seq_update_plugin',
            nonce: SeqUpdater.nonce,
            plugin: plugin
        }, function(res) {
            let timestamp = new Date().toLocaleString();
            if (res.success) appendLog(plugin, res.data.status, timestamp);
            else appendLog(plugin, "❌ Failed", timestamp);

            current++;
            runNext();
        });
    }
});