<?php
/**
 *  Plugin Name: Money Lover
 *  Description: A plugin which helps you to manage your online transactions via Money Lover app.
 *  Author: DesignWall
 *  Author URI: http://www.designwall.com
 *  Version: 1.0.0
 *	Require at least: 4.0
 *	Tested up to 4.4
 *	
 *  Text Domain: moneylover
 *	Domain Path: /languages/
 *
 *	@package Money Lover
 *	@category Core
 *	@author DesignWall
 */

if ( !defined( 'ABSPATH' ) ) {
	exit; // exit
}

if ( !class_exists( "MoneyLover" ) ) :

/**
* Main core class.
*
* @class MoneyLover
* @version 1.0.0
*/
class MoneyLover {

	/**
	* Class instance
	*
	* @var object
	*/
	protected static $_instance = null;

	/**
	* Money Lover Transactions API URL.
	*
	* @var string
	*/
	private $url = 'https://connect.moneylover.me/api/transaction';

	/**
	* Money Lover Refresh Token API URL.
	*
	* @var string
	*/
	private $url_refresh_token = 'https://connect.moneylover.me/api/auth/refresh-token';

	/**
	* Money Lover endpoint.
	*
	* @var string
	*/
	private $default_endpoint = 'money-lover';

	/**
	* Money Lover App ID.
	*
	* @var string
	*/
	private $app_id = 'NJxHe5yhg';

	/**
	* Money Lover App Secret.
	*
	* @var string
	*/
	private $app_secret = 'b94ffb41-5851-47e2-8c84-c176e13279d3';

	/**
	* Private key.
	*
	* @var string
	*/
	protected static $hashed = null;

	/**
	* Money Lover Constructor.
	*/
	public function __construct() {
		$this->define_constants();
		self::generate_private_key();
		// edd complete order
		add_action( 'edd_update_payment_status', array( $this, 'notification' ), 10, 3 );
		// add menu
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// enqueue scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// endpoint
		add_action( 'init', array( $this, 'load_api_endpoint' ) );
		// login with moneylover
		add_action( 'wp_ajax_moneylover_login_sections', array( $this, 'save_callback' ) );
		// save options
		add_action( 'admin_init', array( $this, 'logout' ) );
		// load text domain
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
	}

	public function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	* Load Localisation files.
	*/
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'moneylover', false,  plugin_basename( dirname( __FILE__ ) )  . '/languages' );
	}

	/**
	* Define Constants.
	*/
	public function define_constants() {
		$defines = array(
			'ML_DIR' => trailingslashit( plugin_dir_path( __FILE__ ) ),
			'ML_URI' => trailingslashit( plugin_dir_url( __FILE__ ) )
		);

		foreach( $defines as $k => $v ) {
			if ( !defined( $k ) ) {
				define( $k, $v );
			}
		}
	}

	/**
	* Register tools menu.
	*/
	public function register_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Money Lover', 'moneylover' ),
			__( 'Money Lover', 'moneylover' ),
			'manage_options',
			'money-lover',
			array( $this, 'import_layout' )
		);
	}

	/**
	* Print tools layout.
	*/
	public function import_layout() {
		include ML_DIR . 'views/admin.php';
	}

	/**
	* Get endpoint url.
	*/
	public function get_endpoint( $secret = '' ) {
		$url = home_url( '/' . $this->default_endpoint );
		if ( $secret ) {
			$url .= '?secret=' . $secret;
		}

		return $url;
	}

	/**
	* Save data after login done.
	*/
	public function save_callback() {
		$data = $_POST['data'];

		if ( !$data['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Login Fail!!', 'moneylover' ) ) );
		}

		foreach( $data as $k => $v ) {
			if ( $k == 'status' ) {
				unset( $data[$k] );
			}

			if ( !is_array( $v ) ) {
				$v = esc_html( $v );
			} else {
				$v = array_map( 'sanitize_text_field', $v );
			}

			update_option( '_money_lover_' . $k, $v );
		}
		update_option( '_money_lover_private_key', sanitize_text_field( $_POST['private_key'] ) );

		wp_send_json_success( array( 'message' => __( 'Done!!', 'moneylover' ) ) );
	}

	/**
	* Get options.
	*
	* @param string $key Options key
	* @param string|array|int|bool $default (default: '')
	* @return mixed
	*/
	private function get( $key, $default = '' ) {
		return get_option( '_money_lover_' . $key, $default );
	}

	/**
	* Logout action.
	*/
	public function logout() {
		global $wpdb;
		if ( isset( $_GET['ml-logout'] ) ) {
			if ( !current_user_can( 'manage_options' ) ) {
				return;
			}

			$query = "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_money_lover_%'";
			$delete_old_transactions = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_money_lover_sync', '_money_lover_transaction_id' )";

			$wpdb->query( $query );
			$wpdb->query( $delete_old_transactions );

			wp_safe_redirect( admin_url( 'tools.php?page=money-lover' ) );
		}
	}

	/**
	* Enqueue jquery.
	*/
	public function enqueue() {
		global $plugin_page;

		if ( $plugin_page == 'money-lover' ) {
			wp_enqueue_script('jquery');
		}
	}

	/**
	* Prepare data when have api request.
	*
	* @param array $payment_ids
	* @return array
	*/
	protected function prepare_data( $payment_ids ) {
		$data = array();
		if ( !empty( $payment_ids ) ) {
			foreach( $payment_ids as $k => $payment_id ) {
				$payment = new EDD_Payment( $payment_id );

				if ( !isset( $payment->transaction_id ) || empty( $payment->transaction_id ) ) {
					unset( $payment_ids[ $k ] );
					continue;
				}

				$user = get_user_by( 'id', $payment->user_id );

				if ( !$user ) {
					if ( $payment->first_name ) {
						$display_name = $payment->first_name;
					} else {
						$display_name = __( 'Anonymous', 'moneylover' );
					}
				} else {
					$display_name = $user->display_name;
				}

				$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );

				$products = '';
				if ( isset( $payment_meta['downloads'] ) ) {
					foreach( $payment_meta['downloads'] as $k => $v ) {
						if ( isset( $v['id'] ) ) {
							$products = get_post_field( 'post_title', $v['id'] ) . ' ';
						}
					}
				}

				$amount = $payment->total;
				if ( 'refunded' == $payment->status ) {
					$amount = '-' . $amount;
				}

				$description = sprintf( __( 'Order ID: #%1$s. Transactions ID: %2$s. Products: %3$s. Status: Completed', 'moneylover' ), $payment->ID, $payment->transaction_id, $products );

				$date = $payment->completed_date;
				if ( !$date ) {
					$date = $payment->date;
				}

				if ( 'refunded' == $payment->status ) {
					$description = sprintf( __( 'Order ID: #%1$s. Transactions ID: %2$s. Products: %3$s. Status: Refunded', 'moneylover' ), $payment->ID, $payment->transaction_id, $products );
				}

				$transaction_id = self::generate_transaction( $payment );

				$data[] = array(
					'transaction_id' 	=> 'DW-' . $transaction_id,
					'category'			=> 'sales',
					'amount'			=> intval( $amount ),
					'currency_code'		=> strtoupper( $payment->currency ),
					'description'		=> $description,
					'with'				=> array( $display_name ),
					'made_on'			=> date( 'Y-m-d', strtotime( $date ) )
				);

				update_post_meta( $payment_id, '_money_lover_sync', true );
				update_post_meta( $payment_id, '_money_lover_transaction_id', $transaction_id );
			}
		}

		return $data;
	}

	/**
	* Send notify to moneylover when have new transaction.
	*
	* @param int $payment_id
	*/
	public function notification( $payment_id, $new_status, $old_status ) {

		if ( ( 'publish' == $new_status && 'pending' == $old_status  ) || ( 'publish' == $old_status && 'refunded' == $new_status ) ) {
			$this->refresh_token();
			
			$result = self::send( $this->url, $this->get( 'access_token' ) );

			if ( is_wp_error( $result ) ) {
				update_post_meta( $payment_id, '_money_lover_ping', false );
			}
			
			if ( !is_wp_error( $result ) ) {
				$body = wp_remote_retrieve_body( $result );
				$body = json_decode( $body );
				if ( !isset( $body->status ) ) {
					update_post_meta( $payment_id, '_money_lover_ping', false );
				}
			}

			if ( 'refunded' == $new_status ) {
				delete_post_meta( $payment_id, '_money_lover_sync' );
				delete_post_meta( $payment_id, '_money_lover_transaction_id' );
			}

			print_r( $result ); die;
		}

		return $payment_id;
	}

	/**
	* Refresh Token
	*
	* @return json
	*/
	public function refresh_token() {
		if ( time() >= $this->get( 'expire' ) ) {
			
			$result = self::send( $this->url_refresh_token, $this->get( 'refresh_token' ) );

			if ( !is_wp_error( $result ) ) {
				$body = wp_remote_retrieve_body( $result );
				$body = json_decode( $body );
				foreach( $body as $k => $v ) {
					update_option( '_money_lover_' . $k, $v );
				}
			}
			
			return $result;
		}	
	}

	/**
	* Print data when have endpoint request
	*
	* @param array $data
	* @return json
	*/
	public function print_data( $args ) {
		global $wpdb;

		extract( wp_parse_args( $args, array(
			'from'				=> false,
			'to'				=> false,
			'skip'				=> intval(1),
			'limit'				=> 50
		) ) );

		//AND pm.meta_key <> '_money_lover_sync'
		$query = "SELECT p.ID FROM {$wpdb->posts} AS p JOIN {$wpdb->postmeta} as pm ON p.ID = pm.post_id WHERE p.post_status IN ( 'publish', 'refunded' ) AND pm.meta_key = '_edd_payment_transaction_id' GROUP BY p.ID ORDER BY p.ID DESC";

		if ( $from || $to ) {
			$query .= " AND pm.meta_key = '_edd_completed_date'";
		}

		if ( $from ) {
			$from = date( 'Y-m-d h:i:s', strtotime( $from ) );
			$query .= " AND pm.meta_value > '{$from}'";
		}

		if ( $to ) {
			$to = $from = date( 'Y-m-d h:i:s', strtotime( $to ) );
			$query .= " AND pm.meta_value < '{$to}'";
		}

		if ( $limit ) {
			$query .= " LIMIT {$limit}";

			if ( $skip ) {
				$offset = (int) ( $skip - 1 ) * $limit;
				$query .= " OFFSET {$offset}";
			}
		}

		$result = $wpdb->get_col( $query );

		$data = $this->prepare_data( $result );

		echo $this->prepare( $data );
	}

	/**
	* Send data
	*
	* @param array $args
	* @return json
	*/
	public function prepare( $args ) {
		$data = array(
			'accountNumber' => $this->get('email'),
			'currency'		=> $this->get_currency(),
			'transactions'	=> $args
		);

		$data = json_encode( $data );
		return $data;
	}

	/**
	* Get Currency Code
	*
	* @return string (default: 'USD')
	*/
	public function get_currency() {
		$settings = get_option( 'edd_settings_general' );

		return isset( $settings['currency'] ) ? strtoupper( $settings['currency'] ) : 'USD';
	}

	/**
	* Check is api question
	*/
	public function is_api_request() {
		$is_active = stristr( $_SERVER['REQUEST_URI'], $this->default_endpoint ) !== false;

		if ( $is_active ) {
			return true;
		}

		return false;
	}

	/**
	* Print data
	*/
	public function load_api_endpoint() {
		if ( !is_admin() && $this->is_api_request() && !defined( "MONEY_LOVER_API_REQUEST" ) ) {
			header( "Content-Type: application/json" );
			if ( !isset( $_GET['secret'] ) ) {
				die;
			}

			if ( $_GET['secret'] ) {
				if ( $this->get('private_key') !== esc_html( $_GET['secret'] ) ) {
					die;
				}
			}

			$data = array(
				'from'		=> isset( $_GET['from'] ) ? esc_html( $_GET['from'] ) : false,
				'to'		=> isset( $_GET['to'] ) ? esc_html( $_GET['to'] ) : false,
				'limit'		=> isset( $_GET['limit'] ) ? intval( $_GET['limit'] ) : intval( 50 ),
				'skip'		=> isset( $_GET['to'] ) ? intval( $_GET['skip'] ) : intval( 1 ),
			);

			$this->print_data( $data );
			die;
		}
	}

	/**
	* Send curl
	*
	* @param string $url
	* @param string $access_token
	* @return array
	*/
	private function send( $url, $access_token ) {
		$appid = $this->app_id;
		$secret = $this->app_secret;

		$args = array(
			'method' 			=> 'POST',
			'timeout' 			=> 45,
			'redirection' 		=> 5,
			'httpverion' 		=> '1.0',
			'blocking' 			=> true,
			'headers' 			=> array(
				'Authorization' => 'Basic '. base64_encode( $appid . ':' . $secret ),
				'Content-Type' 	=> 'application/json'
			),
			'body' 				=> json_encode( array(
				'app_id'		=> $this->get( 'app_id' ),
				'access_token'	=> $access_token
			) ),
			'cookies' 			=> array()
		);

		$result = wp_remote_post( $url, $args );

		return $result;
	}

	/**
	* Generate private key
	*/
	private function generate_private_key() {
		$app_name = get_bloginfo( 'name' );
		$time = current_time( 'timestamp' );
		$hashed = md5( $app_name . ':' . $time );

		if ( is_null( self::$hashed ) ) {
			self::$hashed = $hashed;
		}
	}

	/**
	* Generate transaction id
	*
	* @param int|EDD_Payment $payment
	* @return string
	*/
	private function generate_transaction( $payment ) {
		$payment_id = intval(0);
		if ( is_object( $payment ) ) {
			$payment_id = $payment->ID;
		} else if ( is_int( $payment ) ) {
			$payment_id = $payment;
		}

		$hashed = get_post_meta( $payment_id, '_money_lover_transaction_id', true );

		if ( empty( $hashed ) ) {
			$hashed = md5( $payment_id . ':' . time() );
		}
		
		return $hashed;
	}
}

function moneylover() {
	return MoneyLover::instance();
}

$GLOBALS['moneylover'] = moneylover();

endif;