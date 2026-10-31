<?php
// Print the collected profile (raw JSONL) for offline analysis, plus a
// minimal journey result so the workflow summary treats the run as judged.
foreach ( array( 'rig-ttfb.jsonl', 'rig-ttfb-client.jsonl' ) as $file ) {
	$path = WP_CONTENT_DIR . '/' . $file;
	$data = is_file( $path ) ? file_get_contents( $path ) : '';
	echo 'RIG_TTFB_FILE:' . $file . ':' . base64_encode( (string) gzcompress( $data, 9 ) ) . "\n";
	echo $file . ' lines: ' . substr_count( $data, "\n" ) . "\n";
}
$result = array(
	'schema'  => 'extrachill-network/journey-result/ttfb-profile/v1',
	'results' => array( array( 'id' => 'profile-collected', 'outcome' => is_file( WP_CONTENT_DIR . '/rig-ttfb.jsonl' ) ? 'pass' : 'finding' ) ),
);
echo 'EXTRACHILL_JOURNEY_RESULT:' . base64_encode( wp_json_encode( $result ) ) . "\n";
