<?php
/*
 Plugin Name: MemberPress HubSpot Integration
 Author: Nathan Smith
 Description: HubSpot autoresponder integration for MemberPress
 Version: 1.0
 Author URI: https://nathan.services
 */
class MP_Hubspot {
	const ID = 'mp_hubspot';
	const SETTING_GROUP = 'mp_hubspot_settings_group';
	const SETTING = 'mp_hubspot_settings';
	const ENDPOINT_BASE = 'https://api.hubapi.com';
	protected $options,$workflows;

	public function __construct() {
		add_action('admin_menu',[$this,'menuList']);
		add_action('mepr-signup',[$this,'processSignup']);
		//add_action('mepr-txn-store',[$this,'processChange']);
		//add_action('mepr-subscr-store',[$this,'processChange']);
		add_action('admin_init',[$this,'registerSettings']);
	}
	public function menuList() {
		add_submenu_page('options-general.php','MemberPress HubSpot Settings', 'MemberPress HubSpot Settings', 'administrator', self::ID, [$this,'settingsPage']);
	}

	public function registerSettings() {
		global $wpcwdb,$wpdb;
		$posts = get_posts([
			'post_type' => 'memberpressproduct',
			'order' => 'ASC',
			'posts_per_page' => 100
		]);

		register_setting(self::SETTING_GROUP,self::SETTING,[$this,'sanitize']);
		add_settings_section(self::ID . '_key','API Key',null,self::ID);
		if($this->isAuth()) {
			add_settings_section(self::ID . '_main','Workflow Settings',function(){echo 'Please specify the workflow id you would like the user to be enrolled in upon subscription to a membership.';},self::ID);
		}
		add_settings_field('apiKey','API Key',function() {
			?>
		<input type="text" name="<?=self::SETTING . '[apiKey]'?>" value="<?=(isset($this->options['apiKey']) ? esc_attr($this->options['apiKey']) : null)?>"/>
		<?php
		},self::ID,self::ID . '_key');

		if($this->isAuth()) {
			foreach($posts as $row) {
				add_settings_field('course' . $row->ID,$row->post_title,function() use($row) {
				?>
				<select name="<?=self::SETTING . '[course' . $row->ID . ']'?>">
					<option value="">None</option>
				<?php foreach($this->getWorkflows() as $id => $workflow) { ?>
					<option value="<?=$id?>"<?=(isset($this->options['course' . $row->ID]) && $this->options['course' . $row->ID] == $id ? ' selected' : null)?>><?=$workflow?></option>
				<?php } ?>
				</select>
				<?php
				},self::ID,self::ID . '_main');
			}
		}
	}

	public function processSignup($transaction) {
		$this->options = get_option(self::SETTING);
		$product = $transaction->product();
		$user = $transaction->user(true);

		if(!$this->isAuth() || !isset($this->options['course' . $product->ID])) {
			return;
		}

		$workflow_id = $this->options['course' . $product->ID];
		$email = $user->user_email;

		$result = $this->apiCall('POST',self::ENDPOINT_BASE . '/automation/v2/workflows/' . $workflow_id . '/enrollments/contacts/' . rawurlencode($email));
	}

	public function sanitize($options) {
		$new = [];
		foreach($options as $key => $value) {
			$value = (int)$value;
			if($value) {
				$new[$key] = $value;
			}
		}
		$new['apiKey'] = $options['apiKey'];
		return $new;
	}

	public function settingsPage() {
		$this->options = get_option(self::SETTING);
?>
<div class="wrap">
	<h1>Hubspot Settings</h1>
	<form method="post" action="options.php">
	<?php
		settings_fields(self::SETTING_GROUP);
		do_settings_sections(self::ID);
		submit_button();
	?>
	</form>
</div>
<?php
	}

	public function getWorkflows() {
		if($this->workflows) {
			return $this->workflows;
		}
		$result = $this->apiCall('GET', self::ENDPOINT_BASE . '/automation/v3/workflows/');
		$workflows = [];
		foreach($result['workflows'] as $workflow) {
			$workflows[$workflow['id']] = $workflow['name'];
		}
		$this->workflows = $workflows;
		return $this->workflows;
	}

	public function apiCall($method,$endpoint,$data=null) {
		$this->options = get_option(self::SETTING);
		if(empty($data)) $data = array();

		$http = new WP_Http();
		$args = [
			'method' => $method,
		];

		if(is_array($data)) {
			$args['body'] = json_encode($data);
		}
		else {
			$args['body'] = $data;
		}

		$args['headers'] = [
			'Content-Type' => 'application/json',
		];

		// Sign the request
		$pieces = parse_url($endpoint);
		$query = '';
		if(isset($pieces['query'])) {
			$query = $pieces['query'];
			// Can't do this. HubSpot uses duplicate property names that won't hash. e.g. property=firstname&property=lastname
			// parse_str($pieces['query'],$query);
		}
		$query .= '&hapikey=' . $this->options['apiKey'];

		$url = $pieces['scheme'] . '://' . $pieces['host'] . $pieces['path'] . '?' . ltrim($query,'&');

		$result = $http->request($url,$args);
		$code = $result['http_response']->get_status();

		if($code == 200 || $code == 204) {
			$data = json_decode($result['http_response']->get_data(),true);
			return $data;
		}
		return null;
	}

	public function isAuth() {
		$this->options = get_option(self::SETTING);
		return !empty($this->options['apiKey']);
	}
}
$mp_hubspot = new MP_Hubspot();
