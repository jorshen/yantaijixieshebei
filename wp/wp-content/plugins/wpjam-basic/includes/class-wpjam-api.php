<?php
trait WPJAM_Call_Trait{
	public function call($name, ...$args){
		[$action, $name]	= in_array($name, ['try', 'catch', 'ob_get'], true) ? [$name, array_shift($args)] : ['', $name];

		if(is_closure($name)){
			$cb		= $name;
		}else{
			$type	= array_find(['model', 'prop'], fn($k)=> str_ends_with($name, '_by_'.$k));
			$name	= $type ? explode_last('_by_', $name)[0] : $name;
			$cb 	= $type == 'prop' ? $this->$name : [$type == 'model' ? $this->model : $this, $name];
		}

		return wpjam_call($action, is_closure($cb) ? $cb->bindTo($this, static::class) : $cb, ...$args);
	}

	public function ob_get($name, ...$args){
		return $this->call('ob_get', $name, ...$args);
	}

	public function try($name, ...$args){
		return $this->call('try', $name, ...$args);
	}

	public function catch($name, ...$args){
		return $this->call('catch', $name, ...$args);
	}

	protected function call_dynamic_method($name, ...$args){
		return $this->call(wpjam_dynamic_method(static::class, $name), ...$args);
	}

	public static function add_dynamic_method($name, $closure){
		wpjam_dynamic_method(static::class, $name, $closure);
	}

	public static function get_called(){
		return strtolower(static::class);
	}
}

trait WPJAM_Items_Trait{
	use WPJAM_Call_Trait;

	public function get_items($field=''){
		$field	= $field ?: $this->get_items_field();

		return $this->$field ?: [];
	}

	public function update_items($items, $field=''){
		$field	= $field ?: $this->get_items_field();

		$this->$field	= $items;

		return $this;
	}

	protected function get_items_field(){
		return wpjam_get_annotation(static::class, 'items_field') ?: '_items';
	}

	public function process_items($cb, $field=''){
		$items	= $this->catch($cb, $this->get_items($field));

		return is_wp_error($items) ? $items : $this->update_items($items, $field);
	}

	public function item_exists($key, $field=''){
		return wpjam_exists($this->get_items($field), $key);
	}

	public function has_item($item, $field=''){
		return in_array($item, $this->get_items($field));
	}

	public function get_item($key, $field=''){
		return wpjam_get($this->get_items($field), $key);
	}

	public function get_item_arg($key, $arg, $field=''){
		return $this->get_item($key.'.'.$arg, $field);
	}

	public function add_item($key, ...$args){
		[$item, $key]	= (!$args || is_bool($key) || (!is_scalar($key) && !is_null($key))) ? [$key, null] : [array_shift($args), $key];

		return $this->process_items(fn($items)=> wpjam_add_at($items, count($items), $key, $this->prepare_item($item, $key, 'add', ...$args)), ...$args);
	}

	public function remove_item($item, $field=''){
		return $this->process_items(fn($items)=> array_diff($items, [$item]), $field);
	}

	public function edit_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field);
	}

	public function update_item($key, $item, $field='', $action='update'){
		return $this->process_items(fn($items)=> array_replace($items, [$key=> $this->prepare_item($item, $key, $action, $field)]), $field);
	}

	public function set_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field, 'set');
	}

	public function delete_item($key, $field=''){
		return wpjam_tap($this->process_items(fn($items)=> wpjam_except($items, $this->prepare_item(null, $key, 'delete', $field) ?? $key), $field), fn($res)=> !is_wp_error($res) && method_exists($this, 'after_delete_item') && $this->after_delete_item($key, $field));
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		if(wpjam_is_assoc_array($orders)){
			[$orders, $field]	= array_values(wpjam_pull($orders, ['item', '_field']));
		}

		return $this->process_items(fn($items)=> array_merge(wpjam_pull($items, $orders), $items), $field);
	}

	protected function prepare_item($item, $key, $action, $field=''){
		$field	= $this->get_items_field();
		$items	= $this->get_items($field);
		$add	= $action == 'add';

		if(isset($item)){
			method_exists($this, 'validate_item') && wpjam_if_error($this->validate_item($item, $key, $action, $field), 'throw');

			$add	&& ($max = wpjam_get_annotation(static::class, 'max_items')) && count($items) >= $max && wpjam_throw('quota_exceeded', '最多'.$max.'个');
			$item	= method_exists($this, 'sanitize_item') ? $this->sanitize_item($item, $key, $action, $field) : $item;
		}

		if(isset($key)){
			$label	= ['add'=>'添加', 'update'=>'编辑', 'delete'=>'删除'][$action] ?? '';
			$label	&& (wpjam_exists($items, $key) === $add) && wpjam_throw('invalid_item_key', '「'.$key.'」'.($add ? '已' : '不').'存在，无法'.$label);
		}else{
			$add || wpjam_throw('invalid_item_key', 'key不能为空');
		}

		return $item;
	}

	public static function get_item_actions(){
		$args	= [
			'row_action'	=> false,
			'data_callback'	=> fn($id)=> wpjam_try([static::class, 'get_item'], $id, ...array_values(wpjam_get_data_parameter(['i', '_field']))),
			'value_callback'=> fn()=> '',
			'callback'		=> function($id, $data, $action){
				$args	= array_values(wpjam_get_data_parameter(['i', '_field']));
				$args	= $action == 'del_item' ? $args : wpjam_add_at($args, 1, null, $data);

				return wpjam_try([static::class, $action], $id, ...$args);
			}
		];

		return [
			'add_item'	=>['page_title'=>'新增项目',	'title'=>'新增',	'dismiss'=>true]+array_merge($args, ['data_callback'=> fn()=> []]),
			'edit_item'	=>['page_title'=>'修改项目',	'dashicon'=>'edit']+$args,
			'del_item'	=>['page_title'=>'删除项目',	'dashicon'=>'no-alt',	'class'=>'del-icon',	'direct'=>true,	'confirm'=>true]+$args,
			'move_item'	=>['page_title'=>'移动项目',	'dashicon'=>'move',		'class'=>'move-item',	'direct'=>true]+wpjam_except($args, 'callback'),
		];
	}
}

class WPJAM_API{
	private $data	= ['query'=>[]];

	private function __construct(){
		add_filter('query_vars',	fn($vars)=> array_merge($vars, ['module', 'action', 'term_id']), 11);
		add_filter('request',		fn($vars)=> wpjam_parse_query_vars($vars), 11);
		add_action('parse_request',	fn($wp)=> empty($wp->query_vars['module']) || $this->dispatch($wp->query_vars), 1);
		add_action('loop_start',	fn($query)=> array_push($this->data['query'], $query), 1);
		add_action('loop_end',		fn($query)=> array_pop($this->data['query']), 999);
	}

	public function add($field, $key, ...$args){
		[$key, $item]	= $args ? [$key, $args[0]] : [null, $key];

		if($field == 'route'){
			$item	= wpjam_is_assoc_array($item) ? array_filter($item) : ['callback'=>$item];
			$model	= $item['model'] ?? '';
			$item	= $item+['callback'=>($model ? $model.'::redirect': '')];

			foreach(['rewrite_rule', 'menu_page', 'admin_load'] as $k){
				if($k == 'rewrite_rule' || is_admin()){
					$v	= $item[$k] ?? wpjam_call('parse', [$model, 'get_'.$k]);
					$v && ('wpjam_add_'.$k)($k == 'rewrite_rule' ? $v : maybe_callback($v));
				}
			}

			if(!empty($item['query_var'])){
				$action	= wpjam_get_parameter($key, ['method'=> wp_doing_ajax() ? 'DATA' : 'GET']);
				$action	&& add_action((wp_doing_ajax() ? 'admin_init' : 'parse_request'), fn()=> $this->dispatch($key, $action), 0);
			}
		}elseif($field == 'map_meta_cap'){
			$this->get($field) || add_filter($field, [$this, $field], 10, 4);
		}

		if(isset($key) && !str_ends_with($key, '[]') && $this->get($field, $key) !== null){
			return new WP_Error('invalid_key', '「'.$key.'」已存在，无法添加');
		}

		return $this->set($field, $key ?? '[]', $item);
	}

	public function set($field, $key, ...$args){
		$this->data[$field]	= is_array($key) ? array_merge(($args && $args[0]) ? $this->get($field) : [], $key) : wpjam_set($this->get($field), $key, ...$args);

		return is_array($key) ? $key : $args[0];
	}

	public function delete($field, ...$args){
		if($args){
			return $this->data[$field] = wpjam_except($this->get($field), $args[0]);
		}

		unset($this->data[$field]);
	}

	public function get($field, ...$args){
		return $args ? wpjam_get($this->get($field), ...$args) : ($this->data[$field] ?? []);
	}

	public function map_meta_cap($caps, $cap, $user_id, $args){
		if(!in_array('do_not_allow', $caps) && $user_id){
			foreach($this->get('map_meta_cap', $cap) ?: [] as $item){
				$item	= maybe_callback($item, $user_id, $args, $cap);
				$caps	= (is_array($item) || $item) ? (array)$item : $caps;
			}
		}

		return $caps;
	}

	public function dispatch($module, $action=''){
		if(is_array($module)){
			[$module, $action]	= [$module['module'], $module['action'] ?? ''];

			remove_action('template_redirect', 'redirect_canonical');
		}

		if($item = $this->get('route', $module)){
			!empty($item['query_var']) && $GLOBALS['wp']->set_query_var($module, $action);
			!empty($item['callback']) && wpjam_call($item['callback'], $action, $module);
		}

		if(!is_admin()){
			if($item && !empty($item['file'])){
				$file	= $item['file'];
			}else{
				$file	= apply_filters('wpjam_template', STYLESHEETPATH.'/template/'.$module.'/'.($action ?: 'index').'.php', $module, $action);
			}

			is_file($file) && add_filter('template_include', fn()=> $file);
		}
	}

	public function get_parameter($name, $method){
		if(is_array($method)){
			$args	= $method;
			$method	= strtoupper(wpjam_pull($args, 'method') ?: 'GET');
			$value	= $this->get_parameter($name, $method);

			if($name){
				$fallback	= wpjam_pull($args, 'fallback');
				$default	= wpjam_pull($args, 'default', $this->get('defaults', $name));
				$send		= wpjam_pull($args, 'send', true);
				$value		??= ($fallback ? $this->get_parameter($fallback, $method) : null) ?? $default;

				if($args){
					$type	= $args['type'] ??= '';
					$args	= ['type'=>$type == 'int' ? 'number' : $type]+$args;	// 兼容
					$field	= wpjam_field(['key'=>$name]+$args);
					$value	= wpjam_catch([($type ? $field : $field->schema(false)), 'validate'], $value, 'parameter');

					$send && wpjam_if_error($value, 'send');
				}
			}

			return $value;
		}elseif(in_array($method, ['DATA', 'DEFAULTS'])){
			if($method == 'DATA' && $name && isset($_GET[$name])){
				return wp_unslash($_GET[$name]);
			}

			$types	= ['defaults', ...($method == 'DATA' ? ['data'] : [])];
			$data	= $this->data['parameter'][$method] ??= array_reduce($types, fn($c, $t)=> wpjam_merge($c, ($v = $this->get_parameter($t, 'REQUEST') ?? []) && is_string($v) && str_starts_with($v, '{') ? wpjam_json_decode($v) : wp_parse_args($v)), []);
			;
		}else{
			$data	= ['POST'=>$_POST, 'REQUEST'=>$_REQUEST][$method] ?? $_GET;

			if($name){
				if(isset($data[$name])){
					return wp_unslash($data[$name]);
				}

				if($_POST || !in_array($method, ['POST', 'REQUEST'])){
					return null;
				}
			}else{
				if($data || in_array($method, ['GET', 'REQUEST'])){
					return wp_unslash($data);
				}
			}

			$data	= $this->data['parameter']['INPUT'] ??= (function(){
				$v	= file_get_contents('php://input');
				$v	= $v && is_string($v) ? @wpjam_json_decode($v) : $v;

				return is_array($v) ? $v : [];
			})();
		}

		return wpjam_get($data, $name ?: null);
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}

	public static function __callStatic($method, $args){
		$function	= 'wpjam_'.$method;

		if(function_exists($function)){
			return $function(...$args);
		}
	}
}

class WPJAM_Args implements ArrayAccess, IteratorAggregate, JsonSerializable{
	use WPJAM_Call_Trait;

	protected $args;

	public function __construct($args=[]){
		$this->args	= $args;
	}

	public function __get($key){
		$args	= $this->get_args();

		return wpjam_exists($args, $key) ? $args[$key] : ($key == 'args' ? $args : null);
	}

	public function __set($key, $value){
		$this->filter_args();

		$this->args[$key]	= $value;
	}

	public function __isset($key){
		return wpjam_exists($this->get_args(), $key) ?: ($this->$key !== null);
	}

	public function __unset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->get_args()[$key] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->filter_args();

		if(is_null($key)){
			$this->args[]		= $value;
		}else{
			$this->args[$key]	= $value;
		}
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return wpjam_exists($this->get_args(), $key);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_args());
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize(){
		return $this->get_args();
	}

	protected function filter_args(){
		return $this->args	= $this->args ?: [];
	}

	public function get_args(){
		return $this->filter_args();
	}

	public function update_args($args, $replace=true){
		$this->args	= ($replace ? 'array_replace' : 'wp_parse_args')($this->get_args(), $args);

		return $this;
	}

	public function process_arg($key, $cb){
		if(!is_closure($cb)){
			return $this;
		}

		$value	= $this->call($cb, $this->get_arg($key));

		return is_null($value) ? $this->delete_arg($key) : $this->update_arg($key, $value);
	}

	public function get_arg($key, $default=null, $action=false){
		$value	= wpjam_get($this->get_args(), $key);

		if($action){
			$value	= is_closure($value) ? $value->bindTo($this, static::class) : $value;
			$value	??= is_string($key) ? $this->parse_method('get_'.$key, 'model') : null;
			$value	= $action === 'callback' ? maybe_callback($value, $this->name) : $value;
		}

		return $value ?? $default;
	}

	public function update_arg($key, $value=null){
		$this->args	= wpjam_set($this->get_args(), $key, $value);

		return $this;
	}

	public function delete_arg($key, ...$args){
		if($args && is_string($key) && str_ends_with($key, '[]')){
			return $this->process_arg(substr($key, 0, -2), fn($value)=> is_array($value) ? array_diff($value, $args) : $value);
		}

		$this->args	= wpjam_except($this->get_args(), $key);

		return $this;
	}

	public function pull($key, ...$args){
		$this->filter_args();

		return wpjam_pull($this->args, $key, ...$args);
	}

	public function pick($keys){
		return wpjam_pick($this->get_args(), $keys);
	}

	public function call_field($key, ...$args){
		if(is_array($key)){
			return wpjam_reduce($key, fn($c, $v, $k)=> $c->call_field($k, $v), $this);
		}

		if(!$args && is_callable($key)){
			[$args, $key]	= [[$key], ''];
		}

		return [$this, $args ? 'update_arg' : 'delete_arg']('_fields['.$key.']', ...$args);
	}

	public function parse_fields(...$args){
		$fields	= [];

		foreach($this->get_arg('_fields[]') as $key => $field){
			if(is_callable($field)){
				$result	= wpjam_try($field, ...$args);

				if(is_numeric($key)){
					$fields	= array_merge($fields, $result);
				}else{
					$fields[$key]	= $result;
				}
			}elseif(wpjam_is_assoc_array($field)){
				$fields[$key]	= $field;
			}
		}

		return $fields;
	}

	public function to_array(){
		return $this->get_args();
	}

	public function sandbox($cb, ...$args){
		try{
			$archive	= $this->get_args();

			return is_closure($cb) ? $this->call($cb, ...$args) : null;
		}finally{
			$this->args	= $archive;
		}
	}

	protected function parse_method($name, $type=null){
		if((!$type || $type == 'model') && ($cb	= [$this->model, $name])[0] && method_exists(...$cb)){
			return $cb;
		}

		if((!$type || $type == 'prop') && ($cb = $this->$name) && is_callable($cb)){
			return is_closure($cb) ? $cb->bindTo($this, static::class) : $cb;
		}
	}

	public function call_method($name, ...$args){
		return ($called	= $this->parse_method($name)) ? $called(...$args) : (str_starts_with($name, 'filter_') ? array_shift($args) : null);
	}

	protected function error($code, $msg){
		return new WP_Error($code, $msg);
	}
}

class WPJAM_Register extends WPJAM_Args{
	use WPJAM_Items_Trait;

	public function __construct($name, $args=[]){
		$this->args	= array_merge($args, ['name'=>$name]);
		$this->args	= $this->preprocess_args($this->args);
	}

	protected function preprocess_args($args){
		if(!$this->is_active() && empty($args['active'])){
			return $args;
		}

		$config	= get_class($this) == self::class ? [] : static::call_group('get_config');
		$model	= empty($config['model']) ? null : ($args['model'] ?? '');

		if($model || !empty($args['hooks']) || !empty($args['init'])){
			$file	= wpjam_pull($args, 'file');

			$file && is_file($file) && include_once $file;
		}

		if($model){
			is_subclass_of($model, self::class) && trigger_error('「'.(is_object($model) ? get_class($model) : $model).'」是 WPJAM_Register 子类');

			if($config['model'] === 'object' && !is_object($model)){
				if(class_exists($model, true)){
					$model = $args['model']	= new $model(array_merge($args, ['object'=>$this]));
				}else{
					trigger_error('model 无效');
				}
			}

			foreach(['hooks'=>'add_hooks', 'init'=>'init'] as $k => $m){
				($args[$k] ?? ($k == 'hooks' || ($config[$k] ?? false))) === true && method_exists($model, $m) && ($args[$k] = [$model, $m]);
			}
		}

		return $args;
	}

	protected function filter_args(){
		if(get_class($this) != self::class && !in_array(($name	= $this->args['name']), static::call_group('get_arg', 'filtered[]'))){
			static::call_group('update_arg', 'filtered[]', $name);

			$this->args	= apply_filters(static::call_group('get_arg', 'name').'_args', $this->args, $name);
		}

		return $this->args;
	}

	public function get_arg($key, $default=null, $should_callback=true){
		return parent::get_arg($key, $default, $should_callback ? 'callback' : 'parse');
	}

	public function get_parent(){
		return $this->sub_name ? self::get($this->name) : null;
	}

	public function get_sub($name){
		return self::get($this->name.':'.$name);
	}

	public function get_subs(){
		return wpjam_array(self::get_by(['name'=>$this->name]), fn($k, $v)=> $v->sub_name);
	}

	public function register_sub($name, $args){
		return self::register($this->name.':'.$name, new static($this->name, array_merge($args, ['sub_name'=>$name])));
	}

	public function unregister_sub($name){
		return self::unregister($this->name.':'.$name);
	}

	public function is_active(){
		return true;
	}

	public static function validate_name($name){
		if(empty($name)){
			$e	= '为空';
		}elseif(is_numeric($name)){
			$e	= '「'.$name.'」'.'为纯数字';
		}elseif(!is_string($name)){
			$e	= '「'.var_export($name, true).'」不为字符串';
		}

		return empty($e) ? true : trigger_error(self::class.'的注册 name'.$e) && false;
	}

	public static function get_group($args){
		return WPJAM_Register_Group::instance($args);
	}

	public static function call_group($method, ...$args){
		if(static::class != self::class){
			$group	= static::get_group(['called'=>static::class, 'name'=>strtolower(static::class)]);

			$group->defaults	??= method_exists(static::class, 'get_defaults') ? static::get_defaults() : [];

			return $group->catch($method, ...$args);
		}
	}

	public static function register($name, $args=[]){
		return static::call_group('add_object', $name, $args);
	}

	public static function unregister($name, $args=[]){
		static::call_group('remove_object', $name, $args);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		$objects	= static::call_group('get_objects', $args, $operator);

		return $output == 'names' ? array_keys($objects) : $objects;
	}

	public static function get_by(...$args){
		return self::get_registereds(...((!$args || is_array($args[0])) ? $args : [[$args[0]=> $args[1]]]));
	}

	public static function get($name, $by='', $top=''){
		return static::call_group('get_object', $name, $by, $top);
	}

	public static function exists($name){
		return (bool)self::get($name);
	}

	public static function get_setting_fields($args=[]){
		return static::call_group('get_fields', $args);
	}

	public static function get_active($key=null){
		return static::call_group('get_active', $key);
	}

	public static function call_active($method, ...$args){
		return static::call_group('call_active', $method, ...$args);
	}

	public static function by_active(...$args){
		$name	= current_filter();
		$method = (did_action($name) ? 'on_' : 'filter_').substr($name, str_starts_with($name, 'wpjam_') ? 6 : 0);

		return self::call_active($method, ...$args);
	}
}

class WPJAM_Register_Group extends WPJAM_Args{
	public function get_objects($args=[], $operator='AND'){
		$this->defaults && array_map(fn($name)=> $this->by_default($name), array_keys($this->defaults));

		$objects	= wpjam_filter($this->get_arg('objects[]'), $args, $operator);
		$orderby	= $this->get_config('orderby');

		return $orderby ? wpjam_sort($objects, ($orderby === true ? 'order' : $orderby), ($this->get_config('order') ?? 'DESC'), 10) : $objects;
	}

	public function get_object($name, $by='', $top=''){
		if($name){
			if(!$by){
				return $this->get_arg('objects['.$name.']') ?: $this->by_default($name);
			}

			if($by == 'model' && strcasecmp($name, $top) !== 0){
				return array_find($this->get_objects(), fn($v)=> is_string($v->model) && strcasecmp($name, $v->model) === 0) ?: $this->get_object(get_parent_class($name), $by, $top);
			}
		}
	}

	public function by_default($name){
		$args = $this->pull('defaults['.$name.']');

		return is_null($args) ? null : $this->add_object($name, $args);
	}

	public function add_object($name, $object){
		$called	= $this->called ?: 'WPJAM_Register';
		$count	= count($this->get_arg('objects[]'));

		if(is_object($name)){
			$object	= $name;
			$name	= $object->name ?? null;
		}elseif(is_array($name)){
			[$object, $name]	= [$name, $object];

			$name	= wpjam_pull($object, 'name') ?: ($name ?: '__'.$count);
		}

		if(!$called::validate_name($name)){
			return;
		}

		$this->get_arg('objects['.$name.']') && trigger_error($this->name.'「'.$name.'」已经注册。');

		if(is_array($object)){
			if(!empty($object['admin']) && !is_admin()){
				return;
			}

			$object	= new $called($name, $object);
		}

		$this->update_arg('objects['.$name.']', $object);

		if($object->is_active() || $object->active){
			wpjam_hooks(maybe_callback($object->pull('hooks')));
			wpjam_init($object->pull('init'));

			method_exists($object, 'registered') && $object->registered();

			$count == 0 && wpjam_hooks(wpjam_call($called.'::add_hooks'));
		}

		return $object;
	}

	public function remove_object($name){
		return $this->delete_arg('objects['.$name.']');
	}

	public function get_config($key=''){
		$this->config	??= $this->called ? wpjam_get_annotation($this->called, 'config')+['model'=>true] : [];

		return $this->get_arg('config['.($key ?: '').']');
	}

	public function get_active($key=''){
		return wpjam_array($this->get_objects(), fn($k, $v)=> ($v->active ?? $v->is_active()) ? [$k, $key ? $v->get_arg($key) : $v] : null, true);
	}

	public function call_active($method, ...$args){
		$type	= array_find(['filter', 'get'], fn($t)=> str_starts_with($method, $t.'_'));

		foreach($this->get_active() as $object){
			$result	= wpjam_try([$object, 'call_method'], $method, ...$args);

			if($type == 'filter'){
				$args[0]	= $result;
			}elseif($type == 'get'){
				$return		= array_merge($return ?? [], is_array($result) ? $result : []);
			}
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return ?? [];
		}
	}

	public function get_fields($args=[]){
		$objects	= array_filter($this->get_objects(wpjam_pull($args, 'filter_args')), fn($v)=> !isset($v->active));
		$options	= wpjam_parse_options($objects, $args);

		if(wpjam_get($args, 'type') == 'select'){
			$name	= wpjam_pull($args, 'name');
			$args	+= ['options'=>$options];

			return $name ? [$name => $args] : $args;
		}

		return $options;
	}

	public static function __callStatic($method, $args){
		foreach(self::instance() as $group){
			if($method == 'register_json'){
				$group->get_config($method) && $group->catch('call_active', $method, $args[0]);
			}elseif($method == 'on_admin_init'){
				foreach(['menu_page', 'admin_load'] as $key){
					$group->get_config($key) && array_map('wpjam_add_'.$key, $group->get_active($key));
				}
			}
		}
	}

	public static function instance($args=[]){
		static $groups	= [];

		if(!$groups){
			add_action('wpjam_api',			[self::class, 'register_json']);
			add_action('wpjam_admin_init',	[self::class, 'on_admin_init']);
		}

		return $args ? (!empty($args['name']) ? ($groups[$args['name']] ??= new self($args)) : null) : $groups;
	}
}

/**
* @config menu_page, admin_load, register_json, init, orderby
**/
#[config('menu_page', 'admin_load', 'register_json', 'init', 'orderby')]
class WPJAM_Option_Setting extends WPJAM_Register{
	public function __invoke(){
		flush_rewrite_rules();

		$submit	= wpjam_get_post_parameter('submit_name');
		$values	= $this->validate_by_fields(wpjam_get_data_parameter()) ?: [];
		$fix	= is_network_admin() ? 'site_option' : 'option';

		if($this->option_type == 'array'){
			$cb		= $this->update_callback ?: 'wpjam_update_'.$fix;
			$cb		= is_callable($cb) ? $cb : wp_die('无效的回调函数');
			$exist	= $this->value_callback();
			$values	= $submit == 'reset' ? wpjam_diff($exist, $values, 'key') : wpjam_filter(array_merge($exist, $values), fn($v)=> !is_null($v), true);
			$values	= $submit == 'reset' ? $values : $this->try('sanitize_callback', $values);

			$cb($this->name, $values);
		}else{
			wpjam_map($values, fn($v, $k)=> $submit == 'reset' ? ('delete_'.$fix)($k) : ('update_'.$fix)($k, $v));
		}

		$errors	= array_filter(get_settings_errors(), fn($e)=> !in_array($e['type'], ['updated', 'success', 'info']));
		$errors	&& wp_die(implode('&emsp;', array_column($errors, 'message')));

		return [
			'type'		=> (!$this->ajax || $submit == 'reset') ? 'redirect' : $submit,
			'errmsg'	=> $submit == 'reset' ? '设置已重置。' : '设置已保存。'
		];
	}

	public function __call($method, $args){
		if(try_remove_suffix($method, '_fields')){
			if($method == 'render'){
				$fields	= array_shift($args);
			}elseif($method == 'get' || try_remove_suffix($method, '_by')){
				$fields	= array_merge(...array_column($this->get_sections($method !== 'validate'), 'fields'));

				if($method == 'get'){
					return $fields;
				}
			}

			return isset($fields) ? [WPJAM_Fields::create($fields, ['value_callback'=>[$this, 'value_callback']]), $method](...$args) : null;
		}elseif(try_remove_suffix($method, '_option')){
			$type	= try_remove_suffix($method, '_site') ? 'site_option' : ($this->blog_id && is_multisite() ? 'blog_option' : 'option');
			$args	= $method == 'update' ? [$this->sanitize_option($args[0])] : [];
			$result	= wpjam_call($method.'_'.$type, ...[...($type == 'blog_option' ? [$this->blog_id] : []), $this->name, ...$args]);

			return $method == 'get' ? $this->sanitize_option($result) : $result;
		}elseif(try_remove_suffix($method, '_setting')){
			$site	= try_remove_suffix($method, '_site');
			$type	= ($site ? 'site_' : '').'option';
			$data	= [$this, 'get_'.$type]() ?: [];
			$name	= $args[0] ?? null;

			if($method == 'get'){
				$site_default	= $type == 'option' && is_multisite() && $this->site_default;

				if(!$name){
					return array_merge($site_default ? $this->get_site_setting() : [], $data);
				}

				if(is_array($name)){
					return wpjam_fill(array_filter($name), fn($n)=> [$this, 'get_'.($site ? 'site_' : '').'setting']($n));
				}

				$value	= is_array($data) ? wpjam_if_error(wpjam_get($data, $name), null) : null;

				if(is_null($value)){
					if($site_default){
						return $this->get_site_setting(...$args);
					}

					if(count($args) >= 2){
						return $args[1];
					}

					if($this->field_default){
						return wpjam_get(($this->_defaults ??= $this->get_defaults_by_fields()), $name);
					}
				}

				return is_string($value) ? str_replace("\r\n", "\n", trim($value)) : $value;
			}

			return [$this, 'update_'.$type]($method == 'update' ? wpjam_reduce(is_array($name) ? $name : [$name=>$args[1]], fn($c, $v, $n)=> wpjam_set($c, $n, $v), $data) : wpjam_except($data, $name));
		}
	}

	public function sanitize_callback($values){
		return $this->call_method('sanitize_callback', $values, $this->name) ?? $values;
	}

	protected function filter_args(){
		return $this->args;
	}

	public function get_arg($key, $default=null, $do_callback=true){
		$value	= parent::get_arg($key, $default, $do_callback);

		if($key == 'menu_page'){
			if(!$this->name || (is_network_admin() && !$this->site_default)){
				return;
			}

			if(!$value){
				if(!$this->post_type || !$this->title){
					return $value;
				}

				$value	= ['parent'=>wpjam_get_post_type_setting($this->post_type, 'plural'), 'order'=>1];
			}

			if(wp_is_numeric_array($value)){
				return wpjam_array($value, function($k, $v){
					if(!empty($v['tab_slug']) && !empty($v['plugin_page'])){
						return [$k, $v];
					}elseif(!empty($v['menu_slug'])){
						return [$k, $v+($v['menu_slug'] == $this->name ? ['menu_title'=>$this->title] : [])];
					}
				});
			}

			$value	+= ($value['function'] ??= 'option') == 'option' ? ['option_name'=>$this->name] : [];

			if(!empty($value['tab_slug'])){
				return ($value['plugin_page'] ??= $this->plugin_page) ? $value+['title'=>$this->title] : null;
			}

			$value	+= ['menu_slug'=>$this->plugin_page ?: $this->name, 'menu_title'=>$this->title];
		}elseif($key == 'admin_load'){
			$value	= wp_is_numeric_array($value) ? $value : ($value ? [$value] : []);
			$value	= array_map(fn($v) => ($this->model && !isset($v['callback']) && !isset($v['model'])) ? $v+['model'=>$this->model] : $v, $value);
		}elseif($key == 'sections'){
			if(!$value || !is_array($value)){
				$id		= $this->type == 'section' ? $this->section_id : ($this->current_tab ?: $this->sub_name ?: $this->name);
				$value	= [$id=>array_filter(['fields'=>$this->get_arg('fields', null, false)]) ?: $this->get_arg('section') ?: []];
			}

			$value	= wpjam_array($value, fn($k, $v)=> is_array($v) && isset($v['fields']) ? [$k, $this->parse_section($v, $k)] : null);
		}

		return $value;
	}

	public function get_current(){
		return $this->get_sub(wpjam_join(':', self::parse_sub())) ?: $this;
	}

	protected function parse_section($section, $id){
		return wpjam_set($section, 'fields', maybe_callback($section['fields'] ?? [], $id, $this->name));
	}

	protected function get_sections($all=false, $filter=true){
		$sections	= $this->get_arg('sections');
		$sections	= count($sections) == 1 ? array_map(fn($s)=> $s+['title'=>$this->title ?: ''], $sections) : $sections;
		$sections	= array_reduce($all ? $this->get_subs() : [], fn($c, $v)=> array_merge($c, $v->get_sections(false, false)), $sections);

		if(!$filter){
			return $sections;
		}

		$args		= ['type'=>'section', 'name'=>$this->name]+($all ? [] : wpjam_map(self::parse_sub(), fn($v)=> ['value'=>$v, 'if_null'=>true]));
		$objects	= wpjam_sort(self::get_by($args), 'order', 'desc', 10);

		foreach(array_reverse(array_filter($objects, fn($v)=> $v->order > 10))+$objects as $object){
			foreach(($object->get_arg('sections') ?: []) as $id => $section){
				$section	= $this->parse_section($section, $id);
				$id			= $id ?: array_key_first($sections);
				$exist		= isset($sections[$id]) ? ($object->order > 10 ? wpjam_merge($section, $sections[$id]) : $sections[$id]) : [];	// 字段靠前
				$sections	= wpjam_set($sections, $id, wpjam_merge($exist, $section));
			}
		}

		return apply_filters('wpjam_option_setting_sections', array_filter($sections, fn($v)=> isset($v['title'], $v['fields'])), $this->name);
	}

	public function add_section(...$args){
		$keys	= ['model', 'fields', 'section'];
		$args	= is_array($args[0]) ? $args[0] : ['section_id'=>$args[0]]+(array_any($keys, fn($k)=> isset($args[1][$k])) ? $args[1] : ['fields'=>$args[1]]);
		$args	= array_any([...$keys, 'sections'], fn($k)=>isset($args[$k])) ? $args : ['sections'=>$args];
		$name	= md5(maybe_serialize(wpjam_map($args, fn($v)=> is_closure($v) ? spl_object_hash($v) : $v, true)));

		return self::register($name, new static($this->name, $args+['type'=>'section']));
	}

	public function value_callback($name=''){
		return $this->option_type == 'array' ? (is_network_admin() ? $this->get_site_setting($name) : $this->get_setting($name)) : get_option($name, null);
	}

	public function render($page){
		$sections	= $this->get_sections();
		$multi		= count($sections) > 1;
		$nav		= $multi && !$page->tab_slug ? wpjam_tag('ul') : '';
		$form		= wpjam_tag('form', ['method'=>'POST', 'id'=>'wpjam_option', 'novalidate']);

		foreach($sections as $id => $section){
			$tab	= wpjam_tag(...($nav ? ['div', ['id'=>'tab_'.$id]] : []));
			$multi	&& $tab->append($page->tab_slug ? 'h3' : 'h2', [], $section['title']);
			$nav	&& $nav->append('li', ['data'=>wpjam_pick($section, ['show_if'])], ['a', ['class'=>'nav-tab', 'href'=>'#tab_'.$id], $section['title']]);

			$form->append($tab->append([
				wpjam_ob_get_contents($section['callback'] ?? '', $section),
				wpautop($section['summary'] ?? ''),
				$this->render_fields($section['fields'])
			]));
		}

		$form->data('nonce', wp_create_nonce($this->option_group))->append(wpjam_tag('p', ['submit'])->append([
			get_submit_button('', 'primary', 'save', false),
			$this->reset ? get_submit_button('重置选项', 'secondary', 'reset', false) : ''
		]));

		return $nav ? $form->before($nav->wrap('h2', ['nav-tab-wrapper', 'wp-clearfix']))->wrap('div', ['tabs']) : $form;
	}

	public function page_load(){
		wpjam_add_admin_ajax('wpjam-option-action',	[
			'callback'		=> $this,
			'nonce_action'	=> fn()=> $this->option_group,
			'allow'			=> fn()=> current_user_can($this->capability)
		]);
	}

	public static function parse_sub($args=null){
		return wpjam_pick($args ?? $GLOBALS, ['plugin_page', 'current_tab']);
	}

	public static function parse_json_module($args){
		if($option	= $args['option_name'] ?? ''){
			$name	= $args['setting_name'] ?? ($args['setting'] ?? null);
			$output	= ($args['output'] ?? '') ?: ($name ?: $option);
			$object	= self::get($option);
			$names	= $object && $object->option_type != 'array' ? [$option, $name] : [$name];

			return [$output => wpjam_get($object ? $object->prepare_by_fields() : wpjam_get_option($option), array_filter($names) ?: null)];
		}
	}

	public static function create($name, $args){
		$args	= maybe_callback($args, $name);
		$sub	= self::parse_sub($args);
		$rest	= $sub ? wpjam_except($args, ['model', 'menu_page', 'admin_load', 'plugin_page', 'current_tab']) : [];
		$args	= ($sub ? [] : ['primary'=>true])+$args+[
			'option_group'	=> $name,
			'option_page'	=> $name,
			'option_type'	=> 'array',
			'capability'	=> 'manage_options',
			'ajax'			=> true,
		];

		if($object	= self::get($name)){
			if($sub || is_null($object->primary)){
				$object->update_args($sub ? wpjam_except($rest, 'title') : $args);
			}else{
				trigger_error('option_setting'.'「'.$name.'」已经注册。'.var_export($args, true));
			}
		}else{
			$args['option_type'] == 'array' && !doing_filter('sanitize_option_'.$name) && is_null(get_option($name, null)) && add_option($name, []);

			$object	= self::register($name, $sub ? $rest : $args);
		}

		return $sub ? $object->register_sub(wpjam_join(':', $sub), $args) : $object;
	}

	public static function get_instance($name, $blog_id=0){
		if(!is_multisite() || !$blog_id){
			if($object	= self::get($name)){
				return $object;
			}
		}

		if(is_multisite()){
			$blog_id	= $blog_id ?: get_current_blog_id();
			$blog_id && !is_numeric($blog_id) && trigger_error($name.':'.$blog_id);
		}

		return wpjam_var('setting:'.wpjam_join('-', $name, $blog_id), fn()=> new static($name, ['blog_id'=>$blog_id]));
	}

	public static function sanitize_option($value){
		return wpjam_if_error($value, null) ? $value : [];
	}
}

class WPJAM_Option_Model{
	protected static function get_object(){
		return WPJAM_Option_Setting::get(...(array_filter([wpjam_get_annotation(static::class, 'option')]) ?: [static::class, 'model', self::class]));
	}

	protected static function call_setting($action, ...$args){
		return ($object	= self::get_object()) ? [$object, $action.'_setting'](...$args) : null;
	}

	public static function get_setting($name='', ...$args){
		return self::call_setting('get', $name, ...$args);
	}

	public static function update_setting(...$args){
		return self::call_setting('update', ...$args);
	}

	public static function delete_setting($name){
		return self::call_setting('delete', $name);
	}
}

class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(try_remove_suffix($method, '_option')){
			$name	= $method == 'get' && is_array($args[0]) ? '' : $args[0];
			$key	= 'options['.$name.']';

			if($method == 'register'){
				$args	= $args[1];

				if($this->name == 'post'){
					$args	+= ['fields'=>[], 'priority'=>'default'];

					$args['post_type']	??= wpjam_pull($args, 'post_types') ?: null;
				}elseif($this->name == 'term'){
					$args['taxonomy']	??= wpjam_pull($args, 'taxonomies') ?: null;

					if(!isset($args['fields'])){
						$args['fields']		= [$name => wpjam_except($args, 'taxonomy')];
						$args['from_field']	= true;
					}
				}

				return $this->update_arg($key, new WPJAM_Meta_Option(['name'=>$name, 'meta_type'=>$this->name]+$args));
			}elseif($method == 'unregister'){
				return $this->delete_arg($key);
			}

			if($name){
				return $this->get_arg($key);
			}

			$args	= $args[0];
			$keys	= [];

			if($this->name == 'post'){
				if(isset($args['post_type'])){
					$object = wpjam_get_post_type_object($args['post_type']);
					$object	&& $object->register_option();

					$keys[]	= 'post_type';
				}
			}elseif($this->name == 'term'){
				if(isset($args['taxonomy'])){
					$object = wpjam_get_taxonomy_object($args['taxonomy']);
					$object	&& $object->register_option();

					$keys[]	= 'taxonomy';
				}

				if(isset($args['action'])){
					$keys[]	= 'action';
				}
			}

			foreach($keys as $k){
				$args[$k]	= ['value'=>$args[$k], 'if_null'=>true, 'callable'=>true];
			}

			if(isset($args['list_table'])){
				$args['title']		= true;
				$args['list_table']	= $args['list_table'] ? true : ['compare'=>'!==', 'value'=>'only'];
			}

			return wpjam_sort(wpjam_filter($this->get_arg($key), $args), 'order', 'DESC', 10);
		}elseif(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
			$args	= [$this->name, ...$args];
			$cb		= str_replace('data', 'metadata', $method);
		}elseif(try_remove_suffix($method, '_by_mid')){
			$args	= [$this->name, ...$args];
			$cb		= $method.'_metadata_by_mid';
		}elseif(try_remove_suffix($method, '_meta')){
			$cb		= [$this, $method.'_data'];
		}elseif(str_contains($method, '_meta')){
			$cb		= [$this, str_replace('_meta', '', $method)];
		}

		return $cb(...$args);
	}

	protected function preprocess_args($args){
		$wpdb	= $GLOBALS['wpdb'];
		$global	= $args['global'] ?? false;
		$table	= $args['table_name'] ?? $this->name.'meta';

		$wpdb->$table ??= $args['table'] ?? ($global ? $wpdb->base_prefix : $wpdb->prefix).$this->name.'meta';

		$global && wp_cache_add_global_groups($this->name.'_meta');

		return parent::preprocess_args($args);
	}

	public function lazyload_data($ids){
		wpjam_lazyload($this->name.'_meta', $ids);
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object'){
		if(in_array($name, ['object', 'object_id'])){
			return $this->name.'_id';
		}elseif($name == 'id'){
			return 'user' == $this->name ? 'umeta_id' : 'meta_id';
		}
	}

	public function register_actions($args=[]){
		foreach($this->get_option(['list_table'=>true]+$args) as $v){
			wpjam_register_list_table_action(($v->action_name ?: 'set_'.$v->name), $v->get_args()+[
				'meta_type'		=> $this->name,
				'page_title'	=> '设置'.$v->title,
				'submit_text'	=> '设置'
			]);
		}
	}

	protected function parse_value($value){
		if(wp_is_numeric_array($value)){
			return maybe_unserialize($value[0]);
		}else{
			return array_merge($value, ['meta_value'=>maybe_unserialize($value['meta_value'])]);
		}
	}

	public function get_data_with_default($id, ...$args){
		if(!$args){
			return $this->get_data($id);
		}

		if($id && $args[0]){
			if(is_array($args[0])){
				return wpjam_array($args[0], fn($k, $v)=> [is_numeric($k) ? $v : $k, $this->get_data_with_default($id, ...(is_numeric($k) ? [$v, null] : [$k, $v]))]);
			}

			if($args[0] == 'meta_input'){
				trigger_error('meta_input');
				return array_map([$this, 'parse_value'], $this->get_data($id));
			}

			if($this->data_exists($id, $args[0])){
				return $this->get_data($id, $args[0], true);
			}
		}

		return is_array($args[0]) ? [] : ($args[1] ?? null);
	}

	public function get_by_key(...$args){
		global $wpdb;

		if(!$args){
			return [];
		}

		if(is_array($args[0])){
			$key	= $args[0]['meta_key'] ?? ($args[0]['key'] ?? '');
			$value	= $args[0]['meta_value'] ?? ($args[0]['value'] ?? '');
			$column	= $args[1] ?? '';
		}else{
			$key	= $args[0];
			$value	= $args[1] ?? null;
			$column	= $args[2] ?? '';
		}

		$where	= array_filter([
			$key ? $wpdb->prepare('meta_key=%s', $key) : '',
			!is_null($value) ? $wpdb->prepare('meta_value=%s', maybe_serialize($value)) : ''
		]);

		if($where){
			$where	= implode(' AND ', $where);
			$table	= $this->get_table();
			$data	= $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A) ?: [];

			if($data){
				$data	= array_map([$this, 'parse_value'], $data);

				return $column ? reset($data)[$this->get_column($column)] : $data;
			}
		}

		return $column ? null : [];
	}

	public function update_data_with_default($id, $key, ...$args){
		if(is_array($key)){
			if(wpjam_is_assoc_array($key)){
				$defaults	= (isset($args[0]) && is_array($args[0])) ? $args[0] : [];

				if(isset($key['meta_input']) && wpjam_is_assoc_array($key['meta_input'])){
					$this->update_data_with_default($id, wpjam_pull($key, 'meta_input'), wpjam_pull($defaults, 'meta_input'));
				}

				wpjam_map($key, fn($v, $k)=> $this->update_data_with_default($id, $k, $v, wpjam_pull($defaults, $k)));
			}

			return true;
		}else{
			$value		= $args[0];
			$default	= $args[1] ?? null;

			if(is_closure($value)){
				$cb		= $value;
				$value	= $cb($this->get_data_with_default($id, $key, $default), $key, $id);
			}

			if(is_array($value)){
				if($value && (!is_array($default) || array_diff_assoc($default, $value))){
					return $this->update_data($id, $key, $value);
				}
			}else{
				if(isset($value) && ((is_null($default) && ($value || is_numeric($value))) || (!is_null($default) && $value != $default))){
					return $this->update_data($id, $key, $value);
				}
			}

			return $this->delete_data($id, $key);
		}
	}

	public function delete_empty_data(){
		$wpdb	= $GLOBALS['wpdb'];
		$mids	= $wpdb->get_col("SELECT ".$this->get_column('id')." FROM ".$this->get_table()." WHERE meta_value = ''") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function delete_by_key($key, $value=''){
		return delete_metadata($this->name, null, $key, $value, true);
	}

	public function delete_by_id($id){
		$wpdb	= $GLOBALS['wpdb'];
		$table	= $this->get_table();
		$column	= $this->get_column();
		$mids	= $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$table} WHERE {$column} = %d ", $id)) ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function update_cache($ids){
		update_meta_cache($this->name, $ids);
	}

	public function cleanup(){
		$wpdb	= $GLOBALS['wpdb'];
		$key	= $this->object_key;
		$table	= $key ? $wpdb->{$this->name.'s'} : '';

		if(!$key){
			$model	= $this->object_model;

			if(!$model || !is_callable([$model, 'get_table'])){
				return;
			}

			$table	= $model::get_table();
			$key	= $model::get_primary_key();
		}

		if(is_multisite() && !str_starts_with($this->get_table(), $wpdb->prefix) && wpjam_lock($this->name.':meta_type:cleanup', DAY_IN_SECONDS, true)){
			return;
		}

		$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$table." t ON t.".$key." = m.".$this->get_column('object')." WHERE t.".$key." IS NULL") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function create_table(){
		if(($table	= $this->get_table()) != $GLOBALS['wpdb']->get_var("show tables like '{$table}'")){
			$column	= $this->name.'_id';

			$GLOBALS['wpdb']->query("CREATE TABLE {$table} (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				{$column} bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id),
				KEY {$column} ({$column}),
				KEY meta_key (meta_key(191))
			)");
		}
	}

	public static function get_defaults(){
		return array_merge([
			'post'	=> ['object_model'=>'WPJAM_Post',	'object_column'=>'title',	'object_key'=>'ID'],
			'term'	=> ['object_model'=>'WPJAM_Term',	'object_column'=>'name',	'object_key'=>'term_id'],
			'user'	=> ['object_model'=>'WPJAM_User',	'object_column'=>'display_name','object_key'=>'ID'],
		], (is_multisite() ? [
			'blog'	=> ['object_key'=>'blog_id'],
			'site'	=> [],
		] : []));
	}
}

class WPJAM_Meta_Option extends WPJAM_Args{
	public function __get($key){
		$value	= parent::__get($key);

		if(isset($value)){
			return $value;
		}elseif($key == 'list_table'){
			return did_action('current_screen') && !empty($GLOBALS['plugin_page']);
		}elseif($key == 'show_in_rest'){
			return true;
		}elseif($key == 'show_in_posts_rest'){
			return $this->show_in_rest;
		}
	}

	public function __call($method, $args){
		if($method == 'prepare' && ($this->callback || $this->update_callback)){
			return [];
		}

		$id		= array_shift($args);
		$fields	= maybe_callback($this->fields, $id, $this->name);

		if($method == 'get_fields'){
			return $fields;
		}

		$object	= WPJAM_Fields::create($fields, array_merge($this->get_args(), ['id'=>$id]));

		if($method == 'callback'){
			$data	= $object->catch('validate', ...$args);

			if(is_wp_error($data) || !$data){
				return $data ?: true;
			}

			if($callback = $this->callback ?: $this->update_callback){
				$result	= is_callable($callback) ? call_user_func($callback, $id, $data, $fields) : false;

				return $result === false ? new WP_Error('invalid_callback') : $result;
			}

			return wpjam_update_metadata($this->meta_type, $id, $data, $object->get_defaults());
		}elseif($method == 'render'){
			echo wpautop($this->summary ?: '').$object->render(...$args);
		}else{
			return $object->$method(...$args);
		}
	}
}

class WPJAM_JSON extends WPJAM_Register{
	public function __invoke(){
		$method		= $this->method ?: $_SERVER['REQUEST_METHOD'];
		$attr		= $method != 'POST' && !str_ends_with($this->name, '.config') ? ['page_title', 'share_title', 'share_image'] : [];
		$response	= wpjam_try('apply_filters', 'wpjam_pre_json', [], $this, $this->name);
		$response	+= ['errcode'=>0, 'current_user'=>wpjam_try('wpjam_get_current_user', $this->pull('auth'))]+$this->pick($attr);

		if($this->modules){
			$modules	= maybe_callback($this->modules, $this->name, $this->args);
			$results	= array_map(fn($module)=> self::parse_module($module), wp_is_numeric_array($modules) ? $modules : [$modules]);
		}elseif($this->callback){
			$fields		= wpjam_try('maybe_callback', $this->fields ?: [], $this->name);
			$data		= $this->fields ? ($fields ? wpjam_fields($fields)->get_parameter($method) : []) : $this->args;
			$results[]	= wpjam_try($this->pull('callback'), $data, $this->name);
		}elseif($this->template){
			$results[]	= is_file($this->template) ? include $this->template : '';
		}else{
			$results[]	= wpjam_except($this->args, 'name');
		}

		$response	= array_reduce($results, fn($c, $v)=> array_merge($c, is_array($v) ? array_diff_key($v, wpjam_pick($c, $attr)) : []), $response);
		$response	= apply_filters('wpjam_json', $response, $this->args, $this->name);

		foreach($attr as $k){
			if(($v	= $response[$k] ?? '') || $k != 'share_image'){
				$response[$k]	= $k == 'share_image' ? wpjam_get_thumbnail($v, '500x400') : html_entity_decode($v ?: wp_get_document_title());
			}
		}

		return $response;
	}

	public static function parse_module($module){
		$args	= $module['args'] ?? [];
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');
		$parser	= $module['callback'] ?? '';

		if(!$parser && ($type = $module['type'] ?? '')){
			$parser	= $type == 'config' ? fn($args)=> wpjam_get_config($args['group'] ?? '') : (($model = [
				'post_type'	=> 'WPJAM_Posts',
				'taxonomy'	=> 'WPJAM_Terms',
				'setting'	=> 'WPJAM_Option_Setting',
				'data_type'	=> 'WPJAM_Data_Type',
			][$type] ?? '') ?  $model.'::parse_json_module' : '');
		}

		return $parser ? wpjam_try($parser, $args) : $args;
	}

	public static function die_handler($msg, $title='', $args=[]){
		wpjam_if_error($msg, 'send');

		$code	= $args['code'] ?? '';
		$data	= $code && $title ? ['modal'=>['title'=>$title, 'content'=>$msg]] : [];
		$code	= $code ?: $title;
		$item	= !$code && is_string($msg) ? wpjam_get_error_setting($msg) : [];
		$item	= $item ?: ['errcode'=>($code ?: 'error'), 'errmsg'=>$msg]+$data;

		wpjam_send_json($item);
	}

	public static function redirect($name){
		header('X-Content-Type-Options: nosniff');

		rest_send_cors_headers(false);

		if('OPTIONS' === $_SERVER['REQUEST_METHOD']){
			status_header(403);
			exit;
		}

		add_filter('wp_die_'.(array_find(['jsonp_', 'json_'], fn($v)=> call_user_func('wp_is_'.$v.'request')) ?: '').'handler', fn()=> [self::class, 'die_handler']);

		if(!try_remove_prefix($name, 'mag.')){
			return;
		}

		$name	= substr($name, str_starts_with($name, '.mag') ? 4 : 0);	// 兼容
		$name	= str_replace('/', '.', $name);
		$name	= wpjam_var('json', apply_filters('wpjam_json_name', $name));
		$user	= wpjam_get_current_user();

		$user && !empty($user['user_id']) && wp_set_current_user($user['user_id']);

		do_action('wpjam_api', $name);

		wpjam_send_json(wpjam_catch(self::get($name) ?: wp_die('接口未定义', 'invalid_api')));
	}

	public static function get_defaults(){
		return [
			'post.list'		=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'post.calendar'	=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'post.get'		=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'media.upload'	=> ['modules'=>['callback'=>['WPJAM_Posts', 'parse_media_json_module']]],
			'site.config'	=> ['modules'=>['type'=>'config']],
		];
	}

	public static function get_current(){
		return wpjam_var('json');
	}

	public static function get_rewrite_rule(){
		return [
			['api/([^/]+)/(.*?)\.json?$',	['module'=>'json', 'action'=>'mag.$matches[1].$matches[2]'], 'top'],
			['api/([^/]+)\.json?$', 		'index.php?module=json&action=$matches[1]', 'top'],
		];
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['parse_post_list_module', 'parse_post_get_module'])){
			return wpjam_catch([static::class, 'parse_module'], [
				'type'	=> 'post_type',
				'args'	=> ['action'=>str_replace(['parse_post_', '_module'], '', $method)]+($args[0] ?? [])
			]);
		}
	}
}

class WPJAM_AJAX extends WPJAM_Args{
	public function __invoke(){
		add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_JSON', 'die_handler']);

		$cb	= $this->callback;

		(!$cb || !is_callable($cb)) && wp_die('invalid_callback');

		if($this->admin){
			$data	= $this->fields ? wpjam_fields($this->fields)->get_parameter('POST') : wpjam_get_post_parameter();
			$verify	= wpjam_get($data, 'action_type') !== 'form';
		}else{
			$data	= array_merge(wpjam_get_data_parameter(), wpjam_except(wpjam_get_post_parameter(), ['action', 'defaults', 'data', '_ajax_nonce']));
			$data	= array_merge($data, wpjam_fields($this->fields)->validate($data, 'parameter'));
			$verify	= $this->verify !== false;
		}

		$action	= $verify ? $this->get_attr($this->name, $data, 'nonce_action') : '';
		$action && !check_ajax_referer($action, false, false) && wpjam_send_json(['errcode'=>'invalid_nonce', 'errmsg'=>'验证失败，请刷新重试。']);

		$this->allow && !wpjam_call($this->allow, $data) && wp_die('access_denied');

		return $cb($data, $this->name);
	}

	public static function get_attr($name, $data=[], $output=''){
		if($ajax = wpjam('ajax', $name)){
			$cb		= $ajax['nonce_action'] ?? '';
			$action	= $cb ?  $cb($data) : (empty($ajax['admin']) ? $name.wpjam_join(':', wpjam_pick($data, $ajax['nonce_keys'] ?? [])) : '');

			return $output == 'nonce_action' ? $action : ['action'=>$name, 'data'=>$data]+($action ? ['nonce'=>wp_create_nonce($action)] : []);
		}
	}

	public static function create($name, $args){
		if(!is_admin() && !wpjam('ajax')){
			wpjam_script('wpjam-ajax', [
				'for'		=> 'wp, login',
				'src'		=> wpjam_url(dirname(__DIR__).'/static/ajax.js'),
				'deps'		=> ['jquery'],
				'data'		=> 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";',
				'position'	=> 'before',
				'priority'	=> 1
			]);

			if(!is_login()){
				add_filter('script_loader_tag', fn($tag, $handle)=> $handle == 'wpjam-ajax' && current_theme_supports('script', $handle) ? '' : $tag, 10, 2);
			}
		}

		if(wp_doing_ajax() && wpjam_get($_REQUEST, 'action') == $name && (is_user_logged_in() || !empty($args['nopriv']))){
			add_action('wp_ajax_'.(is_user_logged_in() ? '' : 'nopriv_').$name, fn()=> wpjam_send_json(wpjam_catch(new static(['name'=>$name]+$args))));
		}

		return wpjam('ajax', $name, $args);
	}
}

class WPJAM_Cache extends WPJAM_Args{
	public function __call($method, $args){
		$method	= substr($method, str_starts_with($method, 'cache_') ? 6 : 0);
		$multi	= str_contains($method, '_multiple');
		$gnd	= array_any(['get', 'delete'], fn($k)=> str_contains($method, $k));
		$i		= $method == 'cas' ? 1 : 0;
		$g		= $i+(($gnd || $multi) ? 1 : 2);

		if(count($args) >= $g){
			$key		= $args[$i];
			$args[$i]	= $multi ? ($gnd ? 'wpjam_map' : 'wpjam_array')($key, fn($k)=> $this->key($k)) : $this->key($key);

			$gnd ||	($args[$g]	= ($args[$g] ?? 0) ?: ($this->time ?: DAY_IN_SECONDS));

			$result	= ('wp_cache_'.$method)(...wpjam_add_at($args, $g, $this->group));

			if($result && $method == 'get_multiple'){
				return wpjam_array($key, fn($i, $k) => (($v = $result[$args[0][$i]]) !== false) ? [$k, $v] : null);
			}

			return $result;
		}
	}

	protected function key($key){
		return wpjam_join(':', $this->prefix, $key);
	}

	public function get_with_cas($key, &$token){
		return wp_cache_get_with_cas($this->key($key), $this->group, $token);
	}

	public function is_over($key, $max, $time){
		$times	= $this->get($key) ?: 0;

		return $times > $max || ($this->set($key, $times+1, ($max == $times && $time > 60) ? $time : 60) && false);
	}

	public function generate($key){
		$this->failed($key);

		if($this->interval){
			$this->get($key.':time') ? wpjam_throw('error', '验证码'.$this->interval.'分钟前已发送了。') : $this->set($key.':time', time(), $this->interval*60);
		}

		return wpjam_tap(rand(100000, 999999), fn($v)=> $this->set($key.':code', $v, $this->cache_time));
	}

	public function verify($key, $code){
		$this->failed($key);

		return ($code && (int)$code === (int)$this->get($key.':code')) ? true : $this->failed($key, true);
	}

	protected function failed($key, $invalid=false){
		if($this->failed_times){
			$times	= (int)$this->get($key.':failed_times');

			if($invalid){
				$this->set($key.':failed_times', $times+1, $this->cache_time/2);

				wpjam_throw('invalid_code');
			}elseif($times > $this->failed_times){
				wpjam_throw('failed_times_exceeded', '尝试的失败次数超过上限，请15分钟后重试。');
			}
		}
	}

	public static function get_verification($args){
		[$name, $args]	= is_array($args) ? [wpjam_pull($args, 'group'), $args] : [$args, []];

		return self::get_instance([
			'group'		=> 'verification_code',
			'prefix'	=> $name ?: 'default',
			'global'	=> true,
		]+$args+[
			'failed_times'	=> 5,
			'interval'		=> 1,
			'cache_time'	=> MINUTE_IN_SECONDS*30
		]);
	}

	public static function get_instance($group, $args=[]){
		$args	= is_array($group) ? $group : ['group'=>$group]+$args;
		$name	= wpjam_join(':', $args['group'] ?? '', $args['prefix'] ?? '');

		return $name ? wpjam_var('cache:'.$name, fn()=> self::create($args)) : null;
	}

	public static function create($args=[]){
		if(is_object($args)){
			if(!$args->cache_object && $args->cache_group){
				$group	= $args->cache_group;
				$group	= is_array($group) ? ['group'=>$group[0], 'global'=>$group[1] ?? false] : ['group'=>$group];

				$args->cache_object	= self::create($group+['prefix'=>$args->cache_prefix, 'time'=>$args->cache_time]);
			}

			return $args->cache_object;
		}

		if(!empty($args['group'])){
			if(wpjam_pull($args, 'global')){
				wp_cache_add_global_groups($args['group']);
			}

			if(empty($args['time']) && !empty($args['cache_time'])){
				$args['time']	= $args['cache_time'];
			}

			return new self($args);
		}
	}
}

class WPJAM_Verify_TXT{
	public static function get_rewrite_rule(){
		add_filter('root_rewrite_rules', fn($rewrite)=> $GLOBALS['wp_rewrite']->root ? $rewrite : array_merge(['([^/]+)\.txt?$'=>'index.php?module=txt&action=$matches[1]'], $rewrite));
	}

	public static function redirect($action){
		if($txt	= self::get(str_replace('.txt', '', $action).'.txt', 'value')){
			header('Content-Type: text/plain');
			echo $txt; exit;
		}
	}

	public static function get($name, $key=null){
		$data	= wpjam_get_option('wpjam_verify_txts') ?: [];
		$data	= str_ends_with($name, '.txt') ? array_find($data, fn($v)=> $v['name'] == $name) : ($data[$name] ?? []);

		return $key == 'fields' ? [
			'name'	=> ['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$data['name'] ?? '',	'class'=>'all-options'],
			'value'	=> ['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$data['value'] ?? '']
		] : ($key ? ($data[$key] ?? '') : $data);
	}

	public static function set($name, $data){
		return wpjam_update_setting('wpjam_verify_txts', $name, $data);
	}
}

class WPJAM_Exception extends Exception{
	private $error;

	public function __construct($msg, $code=null, ?Throwable $previous=null){
		$error	= $this->error	= is_wp_error($msg) ? $msg : new WP_Error($code ?: 'error', $msg);
		$code	= $error->get_error_code();
		$msg	= $error->get_error_message();

		if(is_array($msg)){
			var_dump($msg);
		}

		parent::__construct($msg, (is_numeric($code) ? (int)$code : 1), $previous);
	}

	public function __call($method, $args){
		if(in_array($method, ['get_wp_error', 'get_error'])){
			return $this->error;
		}

		return [$this->error, $method](...$args);
	}
}