<?php
/**
 * The "Teatatu Data" admin screen. On the Master Site it is where data
 * sets are maintained; on every other site it is read-only. For now it
 * shows the data dictionary of registered data sets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'teatatu_data_register_admin_page' );

/**
 * Adds the top-level Teatatu Data menu.
 */
function teatatu_data_register_admin_page() {
	add_menu_page(
		__( 'Teatatu Data', 'teatatu-data' ),
		__( 'Teatatu Data', 'teatatu-data' ),
		'read_teatatu_data',
		'teatatu-data',
		'teatatu_data_render_admin_page',
		'dashicons-database',
		30
	);
}

/**
 * Renders the admin screen.
 */
function teatatu_data_render_admin_page() {
	if ( ! current_user_can( 'read_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-data' ) );
	}
	$datasets = teatatu_data_get_datasets();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Teatatu Data', 'teatatu-data' ); ?></h1>
		<?php if ( ! teatatu_data_is_master_site() ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'Master data is maintained on the Master Site. This site reads it from there.', 'teatatu-data' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Data dictionary', 'teatatu-data' ); ?></h2>
		<?php if ( ! $datasets ) : ?>
			<p><?php esc_html_e( 'No data sets are registered yet.', 'teatatu-data' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $datasets as $key => $dataset ) : ?>
			<h3><?php echo esc_html( $dataset['label'] ); ?> <code><?php echo esc_html( $key ); ?></code></h3>
			<p><?php echo esc_html( $dataset['description'] ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Field', 'teatatu-data' ); ?></th>
						<th><?php esc_html_e( 'Type', 'teatatu-data' ); ?></th>
						<th><?php esc_html_e( 'Required', 'teatatu-data' ); ?></th>
						<th><?php esc_html_e( 'Meaning', 'teatatu-data' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( (array) $dataset['fields'] as $field_key => $field ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $field['label'] ); ?></strong><br /><code><?php echo esc_html( $field_key ); ?></code></td>
							<td><?php echo esc_html( $field['type'] ); ?></td>
							<td><?php echo ! empty( $field['required'] ) ? esc_html__( 'Yes', 'teatatu-data' ) : esc_html__( 'No', 'teatatu-data' ); ?></td>
							<td><?php echo esc_html( isset( $field['description'] ) ? $field['description'] : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	</div>
	<?php
}
