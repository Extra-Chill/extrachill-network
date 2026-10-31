<?php
// A WordPress-free PHP endpoint: Playground's per-request overhead alone.
file_put_contents( ABSPATH . 'rig-ping.php', "<?php header('Content-Type: text/plain'); echo 'ok';" );
@unlink( WP_CONTENT_DIR . '/rig-ttfb.jsonl' );
@unlink( WP_CONTENT_DIR . '/rig-ttfb-client.jsonl' );
echo "ttfb-profile seeded\n";
