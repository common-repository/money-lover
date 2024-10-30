<div class="wrap">
	<h3><?php echo get_admin_page_title() ?></h3>
		<table class="form-table" id="ml_form">
			<tr>
				<th><?php _e( 'Shop Name', 'moneylover' ) ?></th>
				<td><?php echo get_bloginfo( 'name' ); ?></td>
			</tr>
			<?php if ( $this->get( 'email', false ) ) : ?>
				<tr>
					<th><?php _e( 'Login with:', 'moneylover' ) ?></th>
					<td><?php echo $this->get( 'email' ) ?></td>
				</tr>
			<?php endif; ?>
			<?php $wallet = $this->get( 'wallet', false ); ?>
			<?php if ( $wallet && isset( $wallet->id ) ) : ?>
				<tr>
					<th><?php _e( 'Wallet ID', 'moneylover' ) ?></th>
					<td><?php echo esc_attr( $wallet->id ) ?></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php if ( !$this->get( 'access_token', false ) ) : ?>
			<button class="button button-primary" onclick="loginNow();"><?php _e( 'Login', 'moneylover' ) ?></button>
		<?php else : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'ml-logout' => 'true' ) ) ) ?>" class="button button-primary" type="submit" name="ml-logout"><?php _e( 'Logout', 'moneylover' ) ?></a>
		<?php endif; ?>
	<script type="text/javascript">
		window.ML_AsyncInit = function () {
		    ML.init({
		        app_id: '<?php echo $this->app_id ?>',
		        end_point: '<?php echo $this->get_endpoint( self::$hashed ) ?>',
		        app_name: '<?php echo get_bloginfo( 'name' ) ?>',
		        currency: '<?php echo $this->get_currency() ?>',
		        limit: 10
		    });
		};

		(function (d, s, id) {
		    if (d.getElementById(id)) {
		        return;
		    }
		    var mljs = d.createElement(s);
		    mljs.id = id;
		    mljs.async = true;
		    mljs.defer = true;
		    var useSSL = 'https:' == d.location.protocol;
		    mljs.src = (useSSL ? 'https:' : 'http:') + '//connect.moneylover.me/js/sdk.js';
		//        mljs.src = (useSSL ? 'https:' : 'http:') + '//connect.moneylover.me/js/sdk.js';
		    var node = d.getElementsByTagName('script')[0];
		    node.parentNode.insertBefore(mljs, node);
		})(document, 'script', 'moneylover-sdk');

		function loginNow() {
		    ML.login(function (result) {
		        jQuery.ajax({
		        	url: ajaxurl,
		        	type: 'POST',
		        	dataType: 'json',
		        	data: {
		        		action: 'moneylover_login_sections',
		        		data: result,
		        		private_key: '<?php echo self::$hashed ?>'
		        	},
		        	success: function(data) {
	        			if ( data.data.message ) {
	        				setTimeout(function(){ window.location.reload() }, 1000);
	        			}
		        	}
		        });
		    },
		    {
		    	app_name: '<?php echo get_bloginfo( 'name' ) ?>',
		    	secret: '<?php echo self::$hashed ?>'
		    });
		}
	</script>
</div>