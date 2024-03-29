<?php

namespace Licenser;

/**
 * Licenser License Checker
 *
 * This class will check, active and deactive license
 */
class License {

    /**
     * Licenser\Client
     *
     * @var object
     */
    protected $client;

    /**
     * Arguments of create menu
     *
     * @var array
     */
    protected $menu_args;

    /**
     * `option_name` of `wp_options` table
     *
     * @var string
     */
    protected $option_key;

    /**
     * Error message of HTTP request
     *
     * @var string
     */
    public $error;

    /**
     * Success message on form submit
     *
     * @var string
     */
    public $success;

    /**
     * Corn schedule hook name
     *
     * @var string
     */
    protected $schedule_hook;

    /**
     * Set value for valid license
     *
     * @var bool
     */
    private $is_valid_license = null;

    /**
     * Initialize the class
     *
     * @param Client $client
     */
    public function __construct( Client $client ) {
        $this->client = $client;

        $this->option_key = 'licenser_' . md5( $this->client->slug ) . '_manage_license';

        $this->schedule_hook = $this->client->slug . '_license_check_event';

        // Form Submit
        add_action( 'wp_ajax_licenser-validate_' . $this->client->hash, [ $this, 'license_form_submit' ] );

        // Creating WP Ajax Endpoint to refresh license remotely
        add_action( 'wp_ajax_licenser_refresh_license_' . $this->client->hash, [ $this, 'refresh_license_api' ] );

        // License Fields Anywhere
        add_action( 'licenser_activation_fields-' . $this->client->hash, [ $this, 'license_fields' ] );

        // Run hook to check license status daily
        add_action( $this->schedule_hook, [ $this, 'check_license_status' ] );

        // Active/Deactive corn schedule
        $this->run_schedule();
    }

    /**
     * Set the license option key.
     *
     * If someone wants to override the default generated key.
     *
     * @param string $key
     *
     * @since 1.3.0
     *
     * @return License
     */
    public function set_option_key( $key ) {
        $this->option_key = $key;

        return $this;
    }

    /**
     * Get the license key
     *
     * @since 1.3.0
     *
     * @return string|null
     */
    public function get_license() {
        return get_option( $this->option_key, null );
    }

    /**
     * Check license
     *
     * @return array
     */
    public function check( $license_key ) {
        $route = 'license/' . $this->client->hash . '/check';

        return $this->send_request( $license_key, $route );
    }

    /**
     * Active a license
     *
     * @return array
     */
    public function activate( $license_key ) {
        $route = 'license/' . $this->client->hash . '/activate';

        return $this->send_request( $license_key, $route );
    }

    /**
     * Deactivate a license
     *
     * @return array
     */
    public function deactivate( $license_key ) {
        $route = 'license/' . $this->client->hash . '/deactivate';

        return $this->send_request( $license_key, $route );
    }

    /**
     * Send common request
     *
     * @return array
     */
    protected function send_request( $license_key, $route ) {
        $params = [
            'license_key' => $license_key,
            'url'         => esc_url( home_url() ),
            'is_local'    => $this->client->is_local_server(),
            'version'     => $this->client->project_version,
        ];

        $response = $this->client->send_request( $params, $route, true );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $response = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $response ) || isset( $response['exception'] ) ) {
            return [
                'success' => false,
                'error'   => $this->client->__trans( 'Unknown error occurred, Please try again.' ),
            ];
        }

        if ( isset( $response['errors'] ) && isset( $response['errors']['license_key'] ) ) {
            $response = [
                'success' => false,
                'error'   => $response['errors']['license_key'][0],
            ];
        }

        return $response;
    }

    /**
     * License Refresh Endpoint
     */
    public function refresh_license_api() {
        $this->check_license_status();

        wp_send_json_success(
            [
                'message' => 'License refreshed successfully.',
            ],
            200
        );
    }

    /**
     * Add settings page for license
     *
     * @param array $args
     *
     * @return void
     */
    public function add_settings_page( $args = [] ) {
        $defaults = [
            'type'        => 'menu', // Can be: menu, options, submenu
            'page_title'  => 'Manage License',
            'menu_title'  => 'Manage License',
            'capability'  => 'manage_options',
            'menu_slug'   => $this->client->slug . '-manage-license',
            'icon_url'    => '',
            'position'    => null,
            'parent_slug' => '',
        ];

        $this->menu_args = wp_parse_args( $args, $defaults );

        add_action( 'admin_menu', [ $this, 'admin_menu' ], 99 );
    }

    /**
     * Admin Menu hook
     *
     * @return void
     */
    public function admin_menu() {
        switch ( $this->menu_args['type'] ) {
            case 'menu':
                $this->create_menu_page();
                break;

            case 'submenu':
                $this->create_submenu_page();
                break;

            case 'options':
                $this->create_options_page();
                break;
        }
    }
    
    /**
     * License Fields
     */
    public function license_fields() {
        $license = $this->get_license();

        $action  = ( $license && isset( $license['status'] ) && 'activate' === $license['status'] ) ? 'deactive' : 'active';

        ?>
        <div class="licenser-activation-form <?php echo esc_attr('licenser-license-' . $action); ?>" id="<?php echo esc_attr( $this->client->hash ); ?>">
            <div class="loader"></div>
            <input type="hidden" class="licenser-client-nonce" value="<?php echo wp_create_nonce( $this->client->name ); ?>">
            <div class="license-input-fields">
                <div class="license-input-key">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M336 352c97.2 0 176-78.8 176-176S433.2 0 336 0S160 78.8 160 176c0 18.7 2.9 36.8 8.3 53.7L7 391c-4.5 4.5-7 10.6-7 17v80c0 13.3 10.7 24 24 24h80c13.3 0 24-10.7 24-24V448h40c13.3 0 24-10.7 24-24V384h40c6.4 0 12.5-2.5 17-7l33.3-33.3c16.9 5.4 35 8.3 53.7 8.3zM376 96a40 40 0 1 1 0 80 40 40 0 1 1 0-80z"/></svg>
                    <input type="text" value="<?php echo $this->get_input_license_value( $action, $license ); ?>"
                        placeholder="<?php echo esc_attr( $this->client->__trans( 'Enter your license key to activate' ) ); ?>" 
                        class="licenser-client-license-key"
                        onkeydown="if(event.keyCode == 13) { licenser_activation_form_submit( event, 'active' ); return false;}"
                        <?php echo ( 'deactive' === $action ) ? 'readonly="readonly"' : ''; ?>
                    />
                    <?php if( $action == 'deactive') : ?>
                        <button type="button" data-action="refresh" class="button licenser-refresh-button" onclick="licenser_activation_form_submit( event, 'refresh' );">
                            <span class="dashicons dashicons-update"></span>
                            <span class="screen-reader-text"><?php echo $this->client->__trans( 'Refresh License' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <button data-action="<?php echo esc_attr( $action ); ?>" type="button" name="submit" class="button  <?php echo 'deactive' === $action ? ' deactive-button' : 'button-primary'; ?>" onclick="licenser_activation_form_submit( event );">
                    <?php echo $action === 'active' ? $this->client->__trans( 'Activate License' ) : $this->client->__trans( 'Deactivate License' ); ?>
                </button>
            </div>
            <div class="licenser-activation-response">
                    <?php
                    if ( 'deactive' === $action && isset( $license['remaining'] ) ) {
                        echo $this->show_active_license_info( $license );
                    }
                    ?>
            </div>
        </div>
        <?php $this->scripts(); ?>
        <?php
    }

    /**
     * License menu output
     */
    public function menu_output() {

        $license = $this->get_license();

        $action  = ( $license && isset( $license['status'] ) && 'activate' === $license['status'] ) ? 'deactive' : 'active';
        ?>

        <div class="wrap licenser-license-settings-wrapper">
            <h1><?php echo esc_html( $this->client->__trans( 'License Activation' ) ); ?></h1>

            <?php
                $this->show_license_page_notices();
                do_action( 'before_licenser_license_section' );
            ?>

            <div class="licenser-license-settings licenser-license-section">
                <?php $this->show_license_page_card_header( $license ); ?>

                <div class="licenser-license-details">
                    <p>
                        <?php printf( $this->client->__trans( 'Activate <strong>%s</strong> by your license key to get professional support and automatic update from your WordPress dashboard.' ), $this->client->name ); ?>
                    </p>
                    <?php $this->license_fields(); ?>
                </div>
            </div> <!-- /.licenser-license-settings -->

            <?php do_action( 'after_licenser_license_section' ); ?>
        </div>
        <?php
    }

    /**
     * License form submit action
     */
    public function license_form_submit() {
        $this->handle_submit( $_POST );
     
        // Get license
        $license = $this->get_license();

        // Action
        $action = isset( $_POST['_action'] ) ? sanitize_text_field( $_POST['_action'] ) : '';

        // Notices
        $notices = $this->show_license_page_notices();

        // Active license info
        if( in_array( $action, ['active', 'refresh'] ) ) {
            $notices .= $this->show_active_license_info( $license );
        }

        // Send response
        wp_send_json_success(
            [
                // 'message' => $this->error ? $this->error : $this->success,
                'message' => $notices,
                'license' => $license,
            ],
            200
        );

    }

    /**
     * License form submit
     */
    public function handle_submit( $form_data = [] ) {
      
        if ( ! isset( $form_data['_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $form_data['_nonce'] ) ), $this->client->name ) ) {
            $this->error = $this->client->__trans( 'Nonce vefification failed.' );

            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            $this->error = $this->client->__trans( 'You don\'t have permission to manage license.' );

            return;
        }

        $license_key = ! empty( $form_data['license_key'] ) ? sanitize_text_field( wp_unslash( $form_data['license_key'] ) ) : '';
        $action      = ! empty( $form_data['_action'] ) ? sanitize_text_field( wp_unslash( $form_data['_action'] ) ) : '';

        switch ( $action ) {
            case 'active':
                $this->active_client_license( $license_key );
                break;

            case 'deactive':
                $this->deactive_client_license();
                break;

            case 'refresh':
                $this->refresh_client_license();
                break;
        }

    }

    /**
     * Check license status on schedule
     */
    public function check_license_status() {
        $license = $this->get_license();

        if ( isset( $license['key'] ) && ! empty( $license['key'] ) ) {
            $response = $this->check( $license['key'] );

            if ( isset( $response['success'] ) && $response['success'] ) {
                $license['status']           = 'activate';
                $license['remaining']        = $response['remaining'];
                $license['activation_count'] = $response['activation_count'];
                $license['activation_limit'] = $response['activation_limit'];
                $license['expiry_days']      = $response['expiry_days'];
                $license['expiry_date']      = $response['expiry_date'];
                $license['title']            = $response['title'];
            } else {
                $license['status']      = 'deactivate';
                $license['expiry_days'] = 0;
            }

            update_option( $this->option_key, $license, false );
        }
    }

    /**
     * Check this is a valid license
     */
    public function is_valid() {
        if ( null !== $this->is_valid_license ) {
            return $this->is_valid_license;
        }

        $license = $this->get_license();

        if ( ! empty( $license['key'] ) && isset( $license['status'] ) && $license['status'] === 'activate' ) {
            $this->is_valid_license = true;
        } else {
            $this->is_valid_license = false;
        }

        return $this->is_valid_license;
    }

    /**
     * Check this is a valid license
     */
    public function is_valid_by( $option, $value ) {
        $license = $this->get_license();

        if ( ! empty( $license['key'] ) && isset( $license['status'] ) && $license['status'] === 'activate' ) {
            if ( isset( $license[ $option ] ) && $license[ $option ] === $value ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Styles and Scripts for licenses page
     */
    private function scripts() {
        // Don't load more than once
        if ( isset( $GLOBALS[ 'licenser_client_scripts_loaded' ] ) ) {
            return;
        }
        ?>
        <script>
            // Ajax Function
            function licenser_activation_form_submit( e, action = '' ) {
                
                let $this = jQuery(e.target).closest('.licenser-activation-form');
                let $thisBtn = jQuery(e.target);
                let productId = $this.attr('id');
                let licenseInput = jQuery('.licenser-client-license-key', $this);
                if ( !action ) {
                    action = $thisBtn.attr('data-action');
                }

                if ( $this.hasClass('loading') ) {
                    return;
                }

                // Deactive action
                if( action == 'deactive' ){
                    if( !confirm('<?php echo $this->client->__trans( 'Are you sure you want to deactivate this license?' ); ?>') ){
                        return;
                    }
                } else {
                    if( licenseInput.val() == '' ){
                        licenseInput.focus();
                        return;
                    }
                }

                var data = {
                    'action': 'licenser-validate_' + productId,
                    '_action': action,
                    'product_id': productId,
                    '_nonce': jQuery('.licenser-client-nonce', $this).val(),
                    'license_key': licenseInput.val(),
                }

                $this.addClass('loading');

                // Send ajax
                jQuery.post(ajaxurl, data, function(res) {

                    $this.removeClass('loading');

                    let notice = res.data.message;
 
                    jQuery('.licenser-activation-response', $this).html(notice);
                    jQuery(document).trigger('wp-updates-notice-added');
                });

            };
        </script>
        <style type="text/css">
            .licenser-license-section {
                width: 100%;
                max-width: 1100px;
                min-height: 1px;
                box-sizing: border-box;
            }
            .licenser-license-settings {
                background-color: #fff;
                box-shadow: 0px 3px 10px rgba(16, 16, 16, 0.05);
            }
            .licenser-license-settings * {
                box-sizing: border-box;
            }
            .licenser-license-title {
                background-color: #F8FAFB;
                border-bottom: 2px solid #EAEAEA;
                display: flex;
                align-items: center;
                padding: 10px 20px;
            }
            .licenser-license-title svg {
                width: 30px;
                height: 30px;
                fill: #0082BF;
            }
            .licenser-license-title span {
                font-size: 17px;
                color: #444444;
                margin-left: 10px;
            }
            .licenser-license-details {
                padding: 20px;
            }
            .licenser-license-details>p {
                font-size: 15px;
                margin: 0 0 20px 0;
            }
            .single-license-info {
                min-width: 220px;
                flex: 0 0 30%;
            }
            .single-license-info h3 {
                font-size: 18px;
                margin: 0 0 12px 0;
            }
            .single-license-info p {
                margin: 0;
                color: #00C000;
            }
            .single-license-info p.occupied {
                color: #E40055;
            }
            .licenser-license-right-form {
                margin-left: auto;
            }
            .licenser-activation-form {
                position: relative;
                max-width: 850px;
            }
            .licenser-activation-form.loading .license-input-fields:before {
                content: "";
                position: absolute;
                height: 100%;
                top: 0;
                left: 0;
                bottom: 0;
                right: 0;
                background: rgb(208, 208, 208, 0.5);
                z-index: 1;
            }

            .license-input-key {
                position: relative;
                flex: 0 0 72%;
                max-width: 72%;
            }
            .license-input-key input {
                background-color: #F9F9F9;
                padding: 10px 15px 10px 48px;
                border: 1px solid #E8E5E5;
                border-radius: 3px;
                height: 40px;
                font-size: 14px;
                color: #71777D;
                width: 100%;
                box-shadow: 0 0 0 transparent;
            }
            .license-input-key input:focus {
                outline: 0 none;
                border: 1px solid #E8E5E5;
                box-shadow: 0 0 0 transparent;
            }
            .license-input-key svg {
                width: 20px;
                height: 20px;
                fill: #0082BF;
                position: absolute;
                left: 14px;
                top: 10px;
            }
            .license-input-fields {
                position: relative;
                display: flex;
                justify-content: space-between;
                margin-bottom: 15px;
                max-width: 850px;
                width: 100%;
            }
            .wp-core-ui .license-input-fields button,
            .license-input-fields button {
                font-size: 15px;
                padding: 8px;
                height: 40px;
                border-radius: 3px;
                cursor: pointer;
                flex: 0 0 25%;
                max-width: 25%;
                line-height: 1;
            }
            .license-input-fields button.deactive-button {
                background-color: #E40055;
                border-color: #E40055;
                color: #fff;
            }
            .license-input-fields button[disabled] {
                color: #a7aaad !important;
                background: #f6f7f7 !important;
                border-color: #dcdcde !important;
                box-shadow: none !important;
                text-shadow: none !important;
                cursor: default;
            }
            .license-input-fields button:focus {
                outline: 0 none;
            }
            .wp-core-ui .license-input-key .licenser-refresh-button,
            .license-input-key .licenser-refresh-button {
                position: absolute;
                right: 0;
                top: 50%;
                transform: translateY(-50%);
                border: 0;
                border-top-left-radius: 0px;
                border-bottom-left-radius: 0px;
                height: calc(100% - 2px);
            }
            .loading .licenser-refresh-button span.dashicons {
                -webkit-animation: .75s linear infinite spinner-border;
                animation: .75s linear infinite spinner-border;
            }
            .active-license-info .occupied {
                color: #ffc107;
            }
            @keyframes spinner-border {
                to { transform: rotate(360deg) }
            }
        </style>
        <?php
        // Set global
        $GLOBALS[ 'licenser_client_scripts_loaded' ] = true;
    }

    /**
     * Show active license information
     */
    private function show_active_license_info( $license ) {
        // return if error
        if ( ! empty( $this->error ) ) {
            return;
        }
        ob_start();
        ?>
        <div class="active-license-info">
          
            <?php $this->client->_etrans( 'Activations Remaining:' ); ?>
            <?php if ( empty( $license['activation_limit'] ) ) { ?>
                <strong><?php $this->client->_etrans( 'Unlimited' ); ?></strong>
            <?php } else { ?>
                <strong class="<?php echo $license['remaining'] ? '' : 'occupied'; ?>">
                    <?php printf( $this->client->__trans( '%1$d out of %2$d' ), $license['remaining'], $license['activation_limit'] ); ?>
                </strong>
            <?php } ?>
        
            <?php $this->client->_etrans( 'Expiry Date:' ); ?>
            <?php
            if ( false !== $license['expiry_days'] ) {
                $occupied = $license['expiry_days'] > 21 ? '' : 'occupied';
                echo '<strong class="' . $occupied . '">' . gmdate("M d, Y", strtotime($license['expiry_date']));
                if ( $license['expiry_days'] > 0 ) {
                    echo ' (' . $license['expiry_days'] . ' ' . $this->client->__trans( 'days left' ) . ')';
                } else {
                    echo ' <strong class="expired" style=" color: red; ">(' . $this->client->__trans( 'Expired' ) . ')</strong>';
                }
                echo '</strong>';
                
            } else {
                echo '<strong>' . $this->client->__trans( 'Never' ) . '</strong>';
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Show license settings page notices
     */
    private function show_license_page_notices() {
        ob_start();
        if ( ! empty( $this->error ) ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo $this->error; ?></p>
            </div>
			<?php
        }

        if ( ! empty( $this->success ) ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo $this->success; ?></p>
            </div>
			<?php
        }
        return ob_get_clean();
    }

    /**
     * Card header
     */
    private function show_license_page_card_header( $license ) {
        ?>
        <div class="licenser-license-title">
            <svg enable-background="new 0 0 299.995 299.995" version="1.1" viewBox="0 0 300 300" xml:space="preserve" xmlns="http://www.w3.org/2000/svg">
                <path d="m150 161.48c-8.613 0-15.598 6.982-15.598 15.598 0 5.776 3.149 10.807 7.817 13.505v17.341h15.562v-17.341c4.668-2.697 7.817-7.729 7.817-13.505 0-8.616-6.984-15.598-15.598-15.598z"/>
                <path d="m150 85.849c-13.111 0-23.775 10.665-23.775 23.775v25.319h47.548v-25.319c-1e-3 -13.108-10.665-23.775-23.773-23.775z"/>
                <path d="m150 1e-3c-82.839 0-150 67.158-150 150 0 82.837 67.156 150 150 150s150-67.161 150-150c0-82.839-67.161-150-150-150zm46.09 227.12h-92.173c-9.734 0-17.626-7.892-17.626-17.629v-56.919c0-8.491 6.007-15.582 14.003-17.25v-25.697c0-27.409 22.3-49.711 49.711-49.711 27.409 0 49.709 22.3 49.709 49.711v25.697c7.993 1.673 14 8.759 14 17.25v56.919h2e-3c0 9.736-7.892 17.629-17.626 17.629z"/>
            </svg>
            <span><?php echo $this->client->__trans( 'Activate License' ); ?></span>
        </div>
        <?php
    }

    /**
     * Active client license
     */
    private function active_client_license( $license_key ) {
        if ( empty( $license_key ) ) {
            $this->error = $this->client->__trans( 'The license key field is required.' );
            return;
        }

        $response = $this->activate( $license_key );

        if ( ! $response['success'] ) {
            $this->error = $response['error'] ? $response['error'] : $this->client->__trans( 'Unknown error occurred.' );

            return;
        }

        $data = [
            'key'              => $license_key,
            'status'           => 'activate',
            'remaining'        => $response['remaining'],
            'activation_limit' => $response['activation_limit'],
            'expiry_days'      => $response['expiry_days'],
            'expiry_date'      => $response['expiry_date'],
            'title'            => $response['title'],
        ];

        update_option( $this->option_key, $data, false );

        $this->success = $this->client->__trans( 'License activated successfully.' );
    }

    /**
     * Deactive client license
     */
    private function deactive_client_license() {
        $license = $this->get_license();

        if ( empty( $license['key'] ) ) {
            $this->error = $this->client->__trans( 'License key not found.' );

            return;
        }

        $response = $this->deactivate( $license['key'] );

        $data = [
            'key'    => '',
            'status' => 'deactivate',
        ];

        update_option( $this->option_key, $data, false );

        if ( ! $response['success'] ) {
            $this->error = $response['error'] ? $response['error'] : $this->client->__trans( 'Unknown error occurred.' );

            return;
        }

        $this->success = $this->client->__trans( 'License deactivated successfully.' );

    }

    /**
     * Refresh Client License
     */
    private function refresh_client_license() {
        $license = $this->get_license();

        if ( ! $license || ! isset( $license['key'] ) || empty( $license['key'] ) ) {
            $this->error = $this->client->__trans( 'License key not found' );

            return;
        }

        $this->check_license_status();

        $this->success = $this->client->__trans( 'License refreshed successfully.' );
    }

    /**
     * Add license menu page
     */
    private function create_menu_page() {
        call_user_func(
            'add_menu_page',
            $this->menu_args['page_title'],
            $this->menu_args['menu_title'],
            $this->menu_args['capability'],
            $this->menu_args['menu_slug'],
            [ $this, 'menu_output' ],
            $this->menu_args['icon_url'],
            $this->menu_args['position']
        );
    }

    /**
     * Add submenu page
     */
    private function create_submenu_page() {
        call_user_func(
            'add_submenu_page',
            $this->menu_args['parent_slug'],
            $this->menu_args['page_title'],
            $this->menu_args['menu_title'],
            $this->menu_args['capability'],
            $this->menu_args['menu_slug'],
            [ $this, 'menu_output' ],
            $this->menu_args['position']
        );
    }

    /**
     * Add submenu page
     */
    private function create_options_page() {
        call_user_func(
            'add_options_page',
            $this->menu_args['page_title'],
            $this->menu_args['menu_title'],
            $this->menu_args['capability'],
            $this->menu_args['menu_slug'],
            [ $this, 'menu_output' ],
            $this->menu_args['position']
        );
    }

    /**
     * Schedule daily sicense checker event
     */
    public function schedule_cron_event() {
        if ( ! wp_next_scheduled( $this->schedule_hook ) ) {
            wp_schedule_event( time(), 'daily', $this->schedule_hook );

            wp_schedule_single_event( time() + 20, $this->schedule_hook );
        }
    }

    /**
     * Clear any scheduled hook
     */
    public function clear_scheduler() {
        wp_clear_scheduled_hook( $this->schedule_hook );
    }

    /**
     * Enable/Disable schedule
     */
    private function run_schedule() {
        switch ( $this->client->type ) {
            case 'plugin':
                register_activation_hook( $this->client->file, [ $this, 'schedule_cron_event' ] );
                register_deactivation_hook( $this->client->file, [ $this, 'clear_scheduler' ] );
                break;

            case 'theme':
                add_action( 'after_switch_theme', [ $this, 'schedule_cron_event' ] );
                add_action( 'switch_theme', [ $this, 'clear_scheduler' ] );
                break;
        }
    }

    /**
     * Get input license key
     *
     * @return $license
     */
    private function get_input_license_value( $action, $license ) {
        if ( 'active' === $action ) {
            return isset( $license['key'] ) ? $license['key'] : '';
        }

        if ( 'deactive' === $action ) {
            $key_length = strlen( $license['key'] );

            return str_pad(
                substr( $license['key'], 0, $key_length / 2 ),
                $key_length,
                '*'
            );
        }

        return '';
    }
}