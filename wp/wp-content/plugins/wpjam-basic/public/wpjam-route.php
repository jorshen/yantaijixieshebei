<?php
function wpjam_load($hook, $cb, $priority=10){
	if(!$cb || !is_callable($cb)){
		return;
	}

	$hook	= array_filter((array)$hook, fn($h)=> !did_action($h));

	if(!$hook){
		$cb();
	}elseif(count($hook) == 1){
		add_action(reset($hook), $cb, $priority);
	}else{
		array_walk($hook, fn($h)=> add_action($h, fn()=> array_all($hook, 'did_action') && $cb(), $priority));
	}
}

function wpjam_init($cb){
	wpjam_load('init', $cb);
}

function wpjam_include($hook, $file, $priority=10){
	wpjam_load($hook, fn()=> array_map(fn($f)=> include $f, (array)$file), $priority);
}

function wpjam_hooks($name, ...$args){
	[$type, $name]	= is_string($name) && in_array($name, ['add', 'remove']) ? [$name, array_shift($args)] : ['add', $name];

	$name	= is_string($name) && str_contains($name, ',') ? wp_parse_list($name) : $name;

	if(is_array($name)){
		return wpjam_map($name, fn($n)=> wpjam_hooks($type, ...(is_array($n) ? $n : [$n, ...$args])));
	}

	if($name && $args){
		return is_array($args[0]) && !is_callable($args[0]) ? wpjam_map(array_shift($args), fn($cb)=> wpjam_hooks($type, $name, $cb, ...$args)) : (($type == 'add' ? '' : 'wpjam_').$type.'_filter')($name, ...$args);
	}
}

function wpjam_add_filter($name, $args=[], $priority=10, $accepted_args=1){
	if(is_callable($args['callback'] ?? '')){
		$cb	= function(...$params) use($name, $args, $priority, &$cb){
			if(!empty($args['check']) && !$args['check'](...$params)){
				return array_shift($params);
			}

			empty($args['once']) || remove_filter($name, $cb, $priority);

			return $args['callback'](...$params);
		};

		return add_filter($name, $cb, $priority, $accepted_args);
	}
}

function wpjam_add_once_filter($name, $args=[], $priority=10, $accepted_args=1){
	return wpjam_add_filter($name, ['once'=>true]+(is_callable($args) ? ['callback'=>$args] : $args), $priority, $accepted_args);
}

function wpjam_remove_filter($name, $cb, ...$args){
	return ($priority = $args ? $args[0] : has_filter($name, $cb)) !== false ? remove_filter($name, $cb, $priority) : false;
}

function wpjam_add_action($name, $args=[], $priority=10, $accepted_args=1){
	return wpjam_add_filter($name, $args, $priority, $accepted_args);
}

function wpjam_add_once_action($name, $args=[], $priority=10, $accepted_args=1){
	return wpjam_add_once_filter($name, $args, $priority, $accepted_args);
}

function wpjam_remove_action($name, $cb, ...$args){
	return wpjam_remove_filter($name, $cb, ...$args);
}

function wpjam_call($cb, ...$args){
	[$action, $cb]	= in_array($cb, ['', 'parse', 'try', 'catch', 'ob_get'], true) ? [$cb, array_shift($args)] : ['', $cb];

	if(!$cb){
		return;
	}

	try{
		if(is_string($cb) && ($sep	= array_find(['::', '->'], fn($v)=> str_contains($cb, $v)))){
			$static	= $sep == '::';
			$cb		= explode($sep, $cb, 2);
		}

		if(is_array($cb)){
			(!$cb[0] || (is_string($cb[0]) && !class_exists($cb[0]))) && wpjam_throw('invalid_model', $cb[0]);

			$exists	= method_exists(...$cb);
		}else{
			$exists	= $cb && is_callable($cb);
		}

		if($action == 'parse'){
			return $exists ? $cb : null;
		}

		if(is_array($cb) && is_string($cb[0])){
			$cb[1] || wpjam_throw('invalid_callback');

			if(!isset($static)){
				[$public, $static]	= $exists ? array_map(fn($k)=> wpjam_get_reflection($cb, $k), ['isPublic', 'isStatic']) : [true, method_exists($cb[0], '__callStatic')];
			}

			$exists || method_exists($cb[0], '__call'.($static ? 'Static' : '')) || wpjam_throw('undefined_method', [implode($sep ?? '::', $cb)]);

			if(!$static){
				$args	= ($cb[1] == 'value_callback' && count($args) == 2) ? array_reverse($args) : $args;
				$inst	= [$cb[0], 'get_instance'];
				$num	= wpjam_get_reflection($inst, 'NumberOfRequiredParameters') ?? wpjam_throw('undefined_method', [implode('::', $inst)]);
				$num	= count($args) >= $num ? ($num ?: 1) : wpjam_throw('instance_required', '实例方法对象才能调用');
				$cb[0]	= $inst(...array_splice($args, 0, $num)) ?: wpjam_throw('invalid_id', [$cb[0]]);
			}

			$cb = ($public ?? true) ? $cb : wpjam_get_reflection($cb, 'Closure')($static ? null : $cb[0]);
		}

		if(in_array($action, ['try', 'catch'])){
			$result	= $cb(...$args);

			return $action == 'try' ? wpjam_if_error($result, 'throw') : $result;
		}
	}catch(Exception $e){
		if($action == 'try'){
			throw $e;
		}

		return $action == 'catch' ? wpjam_catch($e) : null;
	}

	if(is_callable($cb)){
		$action == 'ob_get' && ob_start();

		$result	= $cb(...$args);

		return $action == 'ob_get' ? ob_get_clean() : $result;
	}
}

function wpjam_call_multiple($cb, $args){
	return array_map(fn($arg)=> wpjam_call($cb, ...$arg), $args);
}

function wpjam_try($cb, ...$args){
	return wpjam_call('try', wpjam_if_error($cb, 'throw'), ...$args);
}

function wpjam_catch($cb, ...$args){
	if($cb instanceof WPJAM_Exception){
		return $cb->get_error();
	}elseif($cb instanceof Exception){
		return new WP_Error($cb->getCode(), $cb->getMessage());
	}

	return wpjam_call('catch', $cb, ...$args);
}

function wpjam_ob_get_contents($cb, ...$args){
	return wpjam_call('ob_get', $cb, ...$args);
}

function wpjam_retry($times, $cb, ...$args){
	do{
		$times	-= 1;
		$result	= wpjam_catch($cb, ...$args);
	}while($result === false && $times > 0);

	return $result;
}

function wpjam_value_callback($args, $name, $id=null){
	$key	= 'value_callback';
	$names	= (array)$name;
	$args	= is_callable($args) ? [$key=>$args] : $args;
	$value	= wpjam_get($args, ['data', ...$names]);

	if(isset($value)){
		return $value;
	}

	if(empty($args[$key])){
		$model	= $args['model'] ?? '';

		if($id && count($names) >= 2 && $names[0] == 'meta_input' && ($meta_type = ($args['meta_type'] ?? '') ?: wpjam_call($model.'::get_meta_type'))){
			$args['meta_type']	= $meta_type;

			array_shift($names);
		}elseif($model && method_exists($model, $key)){
			$args[$key] = [$model, $key];
		}
	}

	foreach(wpjam_pick($args, [$key, 'meta_type']) as $k => $v){
		$value	= $k == $key ? wpjam_trap($v, $names[0], $id, null) : ($id ? wpjam_get_metadata($v, $id, $names[0]) : null);
		$value	= wpjam_get([$names[0]=>$value], $names);

		if(isset($value)){
			return $value;
		}
	}
}

function wpjam_call_for_blog($blog_id, $cb, ...$args){
	try{
		$switched	= (is_multisite() && $blog_id && $blog_id != get_current_blog_id()) ? switch_to_blog($blog_id) : false;

		return $cb(...$args);
	}finally{
		$switched && restore_current_blog();
	}
}

function wpjam_call_with_suppress($cb, $filters){
	$suppressed	= array_filter($filters, fn($args)=> remove_filter(...$args));

	try{
		return $cb();
	}finally{
		array_map(fn($args)=> add_filter(...$args), $suppressed);
	}
}

function wpjam_dynamic_method($class, $name, ...$args){
	static $cache	= [];

	if($class && $name){
		$key	= $class.'['.$name.']';

		if($args){
			$value	= is_closure($args[0]) ? $args[0] : null;
			$cache	= $args[0] ? ($value ? wpjam_set($cache, $key, $value) : null) : wpjam_except($cache, $key);

			return $value;
		}

		return wpjam_get($cache, $key) ?? wpjam_dynamic_method(get_parent_class($class), $name);
	}
}

function wpjam_build_callback_unique_id($cb){
	return _wp_filter_build_unique_id(null, $cb, null);
}

function wpjam_get_reflection($cb, $key='', ...$args){
	static $cache = [];

	if(is_array($cb) && !is_string($cb[0])){
		$cb[0]	= get_class($cb[0]);
	}

	if(is_array($cb) && empty($cb[1])){
		$ref	= class_exists($cb[0]) ? ($cache['class:'.strtolower($cb[0])] ??= new ReflectionClass($cb[0])) : '';
	}else{
		$cb 	= wpjam_call('parse', $cb);
		$ref	= $cb ? ($cache[wpjam_build_callback_unique_id($cb)] ??= is_array($cb) ? new ReflectionMethod(...$cb) : new ReflectionFunction($cb)) : '';
	}

	if($ref){
		return $key ? [$ref, (array_find(['get', 'is', 'has', 'in'], fn($v)=> str_starts_with($key, $v)) ? '' : 'get').$key](...$args) : $ref;
	}
}

function wpjam_get_annotation($class, $key=''){
	static $cache	= [];

	$name	= strtolower($class);

	if(!isset($cache[$name])){
		$data	= [];
		$ref	= wpjam_get_reflection([$class]);

		if(method_exists($ref, 'getAttributes')){
			foreach($ref->getAttributes() as $attr){
				$k	= $attr->getName();
				$v	= $attr->getArguments();
				$v	= ($v && wp_is_numeric_array($v) && ($k == 'config' ? is_array($v[0]) : count($v) == 1)) ? $v[0] : $v;

				$data[$k]	= $v ?: null;
			}
		}elseif(preg_match_all('/@([a-z0-9_]+)\s+([^\r\n]*)/i', ($ref->getDocComment() ?: ''), $matches, PREG_SET_ORDER)){
			foreach($matches as $m){
				$k	= $m[1];
				$v	= trim($m[2]) ?: null;

				$data[$k]	= ($v && $k == 'config') ? wp_parse_list($v) : $v;
			}
		}

		$cache[$name]	= wpjam_set($data, 'config', wpjam_array($data['config'] ?? [], fn($k, $v)=> is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v, 2) : [$v, true]) : [$k, $v]));
	}

	return wpjam_get($cache[$name], $key ?: null);
}

if(!function_exists('maybe_callback')){
	function maybe_callback($value, ...$args){
		return $value && is_callable($value) ? $value(...$args) : $value;
	}
}

if(!function_exists('maybe_closure')){
	function maybe_closure($value, ...$args){
		return $value && is_closure($value) ? $value(...$args) : $value;
	}
}

if(!function_exists('is_closure')){
	function is_closure($object){
		return $object instanceof Closure;
	}
}

function wpjam_if_error($value, ...$args){
	if($args && is_wp_error($value)){
		if(is_closure($args[0])){
			return array_shift($args)($value, ...$args);
		}elseif(in_array($args[0], [null, false, [], ''], true)){
			return $args[0];
		}elseif($args[0] === 'die'){
			wp_die($value);
		}elseif($args[0] === 'throw'){
			wpjam_throw($value);
		}elseif($args[0] === 'send'){
			wpjam_send_json($value);
		}
	}

	return $value;
}

function wpjam_trap($cb, ...$args){
	$if	= array_pop($args);

	return wpjam_if_error(is_wp_error($cb) ? $cb : wpjam_catch($cb, ...$args), $if);
}

function wpjam_throw($code, $msg='', $data=[]){
	throw new WPJAM_Exception(is_wp_error($code) ? $code : new WP_Error($code, $msg, $data));
}

function wpjam_timer($cb, ...$args){
	try{
		$timestart	= microtime(true);

		return $cb(...$args);
	}finally{
		$log[]	= "Callback: ".var_export($cb, true);
		$log[]	= "Time: ".number_format(microtime(true)-$timestart, 5);

		if(is_closure($cb)){
			$log[]	= "File: ".wpjam_get_reflection($cb, 'FileName');
			$log[]	= "Line: ".wpjam_get_reflection($cb, 'StartLine');
		}

		trigger_error(implode("\n", $log)."\n\n");
	}
}

function wpjam_timer_hook($value){
	$name	= current_filter();
	$object	= $GLOBALS['wp_filter'][$name] ?? null;

	if($object){
		foreach($object->callbacks as &$hooks){
			foreach($hooks as &$hook){
				$hook['function']	= fn(...$args)=> wpjam_timer($hook['function'], ...$args);
			}
		}
	}

	return $value;
}

function wpjam_cache($key, ...$args){
	if(count($args) > 1 || ($args && (is_string($args[0]) || is_bool($args[0])))){
		[$group, $cb, $arg]	= array_pad($args, 3, null);

		$fix	= is_bool($group) ? ($group ? 'site_' : '').'transient' : '';
		$group	= $fix ? '' : ($group ?: 'default');
		$args	= is_numeric($arg) ? ['expire'=>$arg] : (array)$arg;
		$expire	= ($args['expire'] ?? '') ?: 86400;

		if($expire === -1 || $cb === false){
			return $fix ? ('delete_'.$fix)($key) : wp_cache_delete($key, $group);
		}

		$force	= $args['force'] ?? false;
		$value	= $fix ? ('get_'.$fix)($key) : wp_cache_get($key, $group, ($force === 'get' || $force === true));

		if($cb && ($value === false || $force === 'set' || $force === true)){
			$value	= $cb($value, $key, $group);

			if(!is_wp_error($value) && $value !== false){
				$result	= $fix ? ('set_'.$fix)($key, $value, $expire) : wp_cache_set($key, $value, $group, $expire);
			}
		}

		return $value;
	}

	return WPJAM_Cache::get_instance($key, ...$args);
}

function wpjam_counts($name, $cb){
	return wpjam_cache($name, 'counts', $cb);
}

function wpjam_transient($name, $cb, $args=[], $global=false){
	return wpjam_cache($name, (bool)$global, $cb, $args);
}

function wpjam_increment($name, $max=0, $expire=86400, $global=false){
	return wpjam_transient($name, fn($v)=> ($max && (int)$v >= $max) ? 1 : (int)$v+1, ['expire'=>$expire, 'force'=>'set'], $global);
}

function wpjam_lock($name, $expire=10, $group=false){
	$group	= is_bool($group) ? ($group ? 'site-' : '').'transient' : ($group ?: 'default');

	return $expire == -1 ? wp_cache_delete($name, $group) : (wp_cache_get($name, $group, true) || !wp_cache_add($name, 1, $group, $expire));
}

function wpjam_is_over($name, $max, $time, $group=false, $action='increment'){
	$times	= wp_cache_get($name, $group) ?: 0;

	return ($times > $max) || ($action == 'increment' && wp_cache_set($name, $times+1, $group, ($max == $times && $time > 60) ? $time : 60) && false);
}

function wpjam_db_transaction($cb, ...$args){
	$GLOBALS['wpdb']->query("START TRANSACTION;");

	try{
		$result	= $cb(...$args);
		$error	= $GLOBALS['wpdb']->last_error;
		$error && wpjam_throw('error', $error);

		$GLOBALS['wpdb']->query("COMMIT;");

		return $result;
	}catch(Exception $e){
		$GLOBALS['wpdb']->query("ROLLBACK;");

		return false;
	}
}

// WPJAM
function wpjam(...$args){
	$object	= WPJAM_API::get_instance();

	if(!$args){
		return $object;
	}

	$field	= array_shift($args);

	if(str_ends_with($field, '[]')){
		$field	= substr($field, 0, -2);
		$method	= $args ? (count($args) <= 2 && is_null(wpjam_at($args, -1)) ? 'delete' : 'add') : 'get';
	}else{
		$method	= $args && (count($args) > 1 || is_array($args[0])) ? 'set' : 'get';
	}

	return $object->$method($field, ...$args);
}

function wpjam_parse_options($field, $args=[]){
	$type	= $args['type'] ?? (is_array($field) ? '' : 'select');
	$title	= ($args['title_field'] ?? '') ?: 'title';
	$name	= ($args['name_field'] ?? '') ?: 'name';
	$items	= wpjam_filter(is_array($field) ? $field : (wpjam($field) ?: []), $args['filter'] ?? []);

	return wpjam_reduce($items, function($carry, $item, $opt) use($type, $title, $name){
		if(!is_array($item) && !is_object($item)){
			$carry[$opt]	= $item;
		}elseif(!isset($item['options'])){
			$opt	= ($item[$name] ?? '') ?: $opt;
			$carry	= wpjam_set($carry, $opt, wpjam_pick($item, ['label', 'image', 'description', 'alias', 'fields', 'show_if'])+($type == 'select' ? wpjam_pick($item, [$title]) : (($item['field'] ?? '') ?: [])+['label'=>($item[$title] ?? '')]));
		}

		return $carry;
	}, ($type == 'select' ? [''=>__('&mdash; Select &mdash;')] : []), 'options');
}

function wpjam_get_current_query(){
	return wpjam_at(wpjam('query'), -1);
}

function wpjam_is(...$args){
	$query	= ($args && is_object($args[0])) ? array_shift($args) : wpjam_get_current_query();

	if(!$query || !($query instanceof WP_Query) || !$query->is_main_query()){
		return false;
	}

	return $args ? array_any(wp_parse_list(array_shift($args)), fn($type)=> method_exists($query, 'is_'.$type) && [$query, 'is_'.$type](...$args)) : true;
}

// Var
function wpjam_var($name, ...$args){
	[$group, $name]	= str_contains($name, ':') ? explode(':', $name, 2) : ['vars', $name];

	$value	= wpjam($group, $name);

	if($args && ($value === null || !is_closure($args[0]))){
		$value	= maybe_closure($args[0], $name, $group);

		wpjam($group, $name, is_wp_error($value) ? null : $value);
	}

	return $value;
}

// LazyLoader
function wpjam_lazyloader($name, ...$args){
	return wpjam('lazyloader', $name, ...$args);
}

function wpjam_lazyload($name, $ids){
	if(!$name || !($ids	= array_filter($ids))){
		return;
	}

	if(is_array($name)){
		return array_walk($name, fn($n, $k)=> wpjam_lazyload($n, is_numeric($k) ? $ids : array_column($ids, $k)));
	}

	$ids	= array_unique($ids);

	if($name == 'post'){
		_prime_post_caches($ids, false, false);

		return wpjam_lazyload('post_meta', $ids);
	}elseif(in_array($name, ['blog', 'site', 'term', 'comment'])){
		return ('_prime_'.($name == 'blog' ? 'site' : $name).'_caches')($ids);
	}elseif(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
		return wp_metadata_lazyloader()->queue_objects(substr($name, 0, -5), $ids);
	}

	$pending	= wpjam('pending', $name) ?: [];

	if(!$pending){
		$loader	= wpjam_lazyloader($name) ?: (str_ends_with($name, '_meta') ? [
			'filter'	=> 'get_'.$name.'data',
			'callback'	=> fn($pending)=> update_meta_cache(substr($name, 0, -5), $pending)
		] : []);

		$loader && wpjam_add_once_filter($loader['filter'], fn($pre)=> [$pre, wpjam_load_pending($name, $loader['callback'])][0]);
	}

	wpjam('pending', $name, array_merge($pending, $ids));
}

function wpjam_load_pending($name, $cb){
	if($pending	= wpjam('pending', $name)){
		wpjam_call($cb, array_unique($pending));

		wpjam('pending', $name, []);
	}
}

function wpjam_pattern($key, ...$args){
	return wpjam('pattern', $key, ...($args ? [array_combine(['pattern', 'custom_validity'], $args)] : []));
}

function wpjam_default(...$args){
	return wpjam('defaults', ...$args);
}

function wpjam_get_current_user($required=false){
	$value	= wpjam_var('user', fn()=> apply_filters('wpjam_current_user', null));

	return $required ? (is_null($value) ? new WP_Error('bad_authentication') : $value) : wpjam_if_error($value, null);
}

// Parameter
function wpjam_get_parameter($name='', $args=[], $method=''){
	$args	= array_merge($args, $method ? compact('method') : []);

	if(is_array($name)){
		return $name ? wpjam_map((wp_is_numeric_array($name) ? array_fill_keys($name, $args) : $name), fn($v, $n)=> wpjam_get_parameter($n, $v)) : [];
	}

	return wpjam()->get_parameter($name, $args);
}

function wpjam_get_post_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'POST');
}

function wpjam_get_request_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'REQUEST');
}

function wpjam_get_data_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'data');
}

function wpjam_method_allow($method){
	return ($m = $_SERVER['REQUEST_METHOD']) == strtoupper($method) ? true : wp_die('method_not_allow', '接口不支持 '.$m.' 方法，请使用 '.$method.' 方法！');
}

// Request
function wpjam_remote_request($url, $args=[], $err=[]){
	$throw	= wpjam_pull($args, 'throw');
	$field	= wpjam_pull($args, 'field') ?? 'body';
	$args	+= ['body'=>[], 'headers'=>[], 'sslverify'=>false, 'stream'=>false];
	$method	= strtoupper(wpjam_pull($args, 'method', '')) ?: ($args['body'] ? 'POST' : 'GET');

	if($method == 'FILE'){
		wpjam_add_once_filter('pre_http_request', fn($pre, $args, $url)=> (new WP_Http_Curl())->request($url, $args), 1, 3);

		$method = $args['body'] ? 'POST' : 'GET';
	}elseif($method != 'GET'){
		$key	= 'content-type';
		$type	= 'application/json';

		$args['headers']	= array_change_key_case($args['headers']);

		if(wpjam_at(wpjam_pull($args, ['json_encode', 'need_json_encode']), 0)){
			$args['headers'][$key]	= $type;
		}

		if(str_contains($args['headers'][$key] ?? '', $type) && is_array($args['body'])){
			$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
		}
	}

	try{
		$result	= wpjam_try('wp_remote_request', $url, $args+compact('method'));
		$res	= $result['response'];
		$code	= $res['code'];
		$body	= &$result['body'];

		$code && !wpjam_between($code, 200, 299) && wpjam_throw($code, '远程服务器错误：'.$code.' - '.$res['message'].'-'.var_export($body, true));

		if($body && !$args['stream']){
			if(str_contains(wp_remote_retrieve_header($result, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}elseif(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
				$decode	= wpjam_json_decode($body);

				if(!is_wp_error($decode)){
					$body	= $decode;
					$err	+= ['success'=>'0']+wpjam_fill(['errcode', 'errmsg', 'detail'], fn($v)=> $v);

					($code	= wpjam_pull($body, $err['errcode'])) && $code != $err['success'] && wpjam_throw($code, wpjam_pull($body, $err['errmsg']), wpjam_pull($body, $err['detail']) ?? array_filter($body));
				}
			}
		}

		return $field ? wpjam_get($result, $field) : $result;
	}catch(Exception $e){
		$error	= wpjam_fill(['code', 'message', 'data'], fn($k)=> [$e, 'get_error_'.$k]());

		if(apply_filters('wpjam_http_response_error_debug', true, $error['code'], $error['message'])){
			trigger_error(var_export(array_filter(['url'=>$url, 'error'=>array_filter($error), 'body'=>$args['body']]), true));
		}

		if($throw){
			throw $e;
		}

		return wpjam_catch($e);
	}
}

// Error
function wpjam_parse_error($data){
	if($data === true || $data === []){
		return ['errcode'=>0];
	}elseif($data === false || is_null($data)){
		return ['errcode'=>'-1', 'errmsg'=>'系统数据错误或者回调函数返回错误'];
	}

	if(is_wp_error($data)){
		$err	= $data->get_error_data();
		$data	= ['errcode'=>$data->get_error_code(), 'errmsg'=>$data->get_error_message()]+array_filter(is_array($err) ? $err : ['errdata'=>$err]);
	}

	if(wpjam_is_assoc_array($data)){
		$data	+= ['errcode'=>0];
		$data	= array_merge($data, $data['errcode'] ? wpjam_get_error_setting($data['errcode'], $data['errmsg'] ?? []) : []);
	}

	return $data;
}

function wpjam_add_error_setting($code, $msg, $modal=[]){
	wpjam('error') || add_action('wp_error_added', function($code, $msg, $data, $error){
		if($code && count($error->get_error_messages($code)) <= 1 && ($item = wpjam_get_error_setting($code, $msg))){
			$error->remove($code);
			$error->add($code, $item['errmsg'], !empty($item['modal']) ? array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]) : $data);
		}
	}, 10, 4);

	return wpjam('error', $code, ['errmsg'=>$msg, 'modal'=>$modal]);
}

function wpjam_get_error_setting($code, $args=[]){
	if($args && !is_array($args)){
		return [];
	}

	$args	= $args ?: [];
	$item	= wpjam('error', $code) ?: [];

	if($item){
		$msg	= maybe_closure($item['errmsg'], $args);
	}else{
		$error	= $code;

		if(try_remove_suffix($code, '_required')){
			$msg	= $args ? ($code == 'parameter' ? '参数%s' : '%s的值').'为空或无效。' : '参数或者值无效';
		}elseif(try_remove_suffix($code, '_occupied')){
			$msg	= __($code, 'wpjam-basic').'已被其他账号使用。';
		}elseif(try_remove_prefix($code, 'invalid_')){
			if($code == 'parameter'){
				$msg	= $args ? '无效的参数：%s。' : '参数错误。';
			}elseif($code == 'callback'){
				$msg	= '无效的回调函数'.($args ? '：%s' : '').'。';
			}elseif($code == 'name'){
				$msg	= $args ? '%s不能为纯数字。' : '无效的名称';
			}elseif(in_array($code, ['code', 'password'])){
				$msg	= $code == 'code' ? '验证码错误。' : '两次输入的密码不一致。';
			}else{
				$prefix	= '无效的';
				$map	= [
					'id'			=> ' ID',
					'post_type'		=> '文章类型',
					'taxonomy'		=> '分类模式',
					'post'			=> '文章',
					'term'			=> '分类',
					'user'			=> '用户',
					'comment_type'	=> '评论类型',
					'comment_id'	=> '评论 ID',
					'comment'		=> '评论',
					'type'			=> '类型',
					'signup_type'	=> '登录方式',
					'email'			=> '邮箱地址',
					'data_type'		=> '数据类型',
					'qrcode'		=> '二维码',
				];
			}
		}elseif(try_remove_prefix($code, 'illegal_')){
			$suffix	= '无效或已过期。';
			$map	= ['verify_code'	=> '验证码'];
		}

		$msg	??= isset($map) ? ($prefix ?? '').($map[$code] ?? ucwords(str_replace('_', ' ', $code))).($suffix ?? '') : '';
		$code	= $error;
	}

	return $msg ? ['errcode'=>$code, 'errmsg'=>($args && str_contains($msg, '%') ? sprintf($msg, ...$args) : $msg)]+$item : [];
}

// Route
function wpjam_route($name, $model, $query_var=false){
	$name && $model && wpjam('route[]', $name, [(is_string($model) && class_exists($model) ? 'model' : 'callback')=>$model, 'query_var'=>$query_var]);
}

function wpjam_get_query_var($key, $wp=null){
	return ($wp ?: $GLOBALS['wp'])->query_vars[$key] ?? null;
}

// JSON
function wpjam_register_json($name, $args=[]){
	return WPJAM_JSON::register($name, $args);
}

function wpjam_register_api($name, $args=[]){
	return wpjam_register_json($name, $args);
}

function wpjam_get_json_object($name){
	return WPJAM_JSON::get($name);
}

function wpjam_add_json_module_parser($type, $cb){
	return WPJAM_JSON::module_parser($type, $cb);
}

function wpjam_parse_json_module($module){
	return wpjam_catch(['WPJAM_JSON', 'parse_module'], $module);
}

function wpjam_get_current_json($output='name'){
	$name	= WPJAM_JSON::get_current();

	return $output == 'object' ? WPJAM_JSON::get($name) : $name;
}

function wpjam_is_json_request(){
	return get_option('permalink_structure') ? (bool)preg_match("/\/api\/.*\.json/", $_SERVER['REQUEST_URI']) : wpjam_get_parameter('module') == 'json';
}

function wpjam_json_source($name, $cb, $query_args=['source_id']){
	($name == wpjam_get_parameter('source')) && add_filter('wpjam_pre_json', fn($pre)=> is_array($result = $cb(wpjam_get_parameter($query_args))) ? $result : $pre);
}

function wpjam_activation(...$args){
	$args	= $args ? array_reverse(array_slice($args+['', 'wp_loaded'], 0, 2)) : [];
	$result = [wpjam_get_handler(['items_type'=>'transient', 'transient'=>'wpjam-actives']), ($args ? 'add' : 'empty')](...$args);

	return $args ? $result : wpjam_map($result, fn($active)=> $active && count($active) >= 2 && add_action(...$active));
}

function wpjam_updater($type, $hostname, ...$args){
	if(!in_array($type, ['plugin', 'theme']) || !$args){
		return;
	}

	$url	= wpjam($type.'_updater', $hostname);

	if(!$url){
		wpjam($type.'_updater', $hostname, ...$args);

		return add_filter('update_'.$type.'s_'.$hostname, fn($update, $data, $file, $locales)=> ($item = wpjam_updater($type, $hostname, $file)) ? $item+['id'=>$data['UpdateURI'], 'version'=>$data['Version']] : $update, 10, 4);
	}

	static $result;
	$result	??= wpjam_remote_request($url);
	$data	= is_array($result) ? ($result['template']['table'] ?? $result[$type.'s']) : [];
	$file	= $args[0];

	if(isset($data['fields']) && isset($data['content'])){
		$fields	= array_column($data['fields'], 'index', 'title');
		$item	= array_find($data['content'], fn($item)=> $item['i'.$fields[$type == 'plugin' ? '插件' : '主题']] == $file);
		$item	= $item ? array_map(fn($i)=> $item['i'.$i] ?? '', $fields) : [];

		return $item ? [$type=>$file, 'icons'=>[], 'banners'=>[], 'banners_rtl'=>[]]+array_map(fn($v)=> $item[$v], ['url'=>'更新地址', 'package'=>'下载地址', 'new_version'=>'版本', 'requires_php'=>'PHP最低版本', 'requires'=>'最低要求版本', 'tested'=>'最新测试版本']) : [];
	}

	return array_find($data, fn($item)=> $item[$type] == $file) ?: [];
}

// $name, $value
// $name, $args
// $args
// $name, $cb
// $cb
function wpjam_register_config(...$args){
	$group	= count($args) >= 3 ? array_shift($args) : '';
	$args	= array_filter($args, fn($v)=> isset($v));

	if($args){
		if(is_array($args[0]) || count($args) == 1){
			$args	= is_callable($args[0]) ? ['callback'=>$args[0]] : (is_array($args[0]) ? $args[0] : [$args[0]=> null]);
		}else{
			$args	= is_callable($args[1]) ? ['name'=>$args[0], 'callback'=>$args[1]] : [$args[0]=>$args[1]];
		}

		return wpjam(wpjam_join(':', 'config', $group).'[]', $args);
	}
}

function wpjam_get_config($group=''){
	return array_reduce(wpjam(wpjam_join(':', 'config', $group)), function($carry, $item){
		if(!empty($item['callback'])){
			$name	= $item['name'] ?? '';
			$value	= $item['callback'](...(($item['args'] ?? []) ?: ($name ? [$name] : [])));
			$item	= $name ? [$name=> $value] : (is_array($value) ? $value : []);
		}

		return array_merge($carry, $item);
	}, []);
}

// Extend
function wpjam_load_extends($dir, ...$args){
	$args	= is_array($dir) ? $dir : array_merge(($args[0] ?? []), compact('dir'));
	$hook	= wpjam_pull($args, 'hook');

	if($hook){
		return wpjam_load($hook, fn()=> wpjam_load_extends($args), $args['priority'] ?? 10);
	}

	$dir	= maybe_callback($args['dir'] ?? '');

	if(!is_dir($dir)){
		return;
	}

	if($option	= wpjam_pull($args, 'option')){
		$sitewide	= wpjam_pull($args, 'sitewide');
		$object		= wpjam_register_option($option, ['dir'=>$dir]+$args+[
			'ajax'				=> false,
			'site_default'		=> $sitewide,
			'sanitize_callback'	=> fn($data)=> wpjam_array($data, fn($k, $v)=> $v ? wpjam_parse_extend($dir, $k) : null),
			'fields'			=> function(){
				$site	= $this->get_arg('values[site]');
				$data	= isset($site) && is_network_admin() ? $site : $this->get_arg('values[data]');

				foreach(scandir($this->dir) as $v){
					$field	= wpjam_parse_extend($this->dir, $v, 'field');
					$v		= $field['key'] ?? '';

					if($v && (!isset($site) || is_network_admin() || empty($site[$v]))){
						$fields[]	= ['value'=>!empty($data[$v])]+$field;
					}
				}

				return wpjam_sort($fields ?? [], ['value'=>'DESC']);
			}
		]);

		$keys		= $sitewide && is_multisite() ? ['data', 'site'] : ['data'];
		$values		= wpjam_fill($keys, fn($k)=> $object->sanitize_callback([$object, 'get_'.($k == 'site' ? 'site_' : '').'option']()));
		$extends	= array_keys(array_merge(...array_values($values)));

		$object->update_arg('values', $values);
	}else{
		$plugins	= get_option('active_plugins') ?: [];
		$extends	= array_filter(scandir($dir), fn($v)=> !in_array($v.(is_dir($dir.'/'.$v) ? '/'.$v : '').'.php', $plugins));
	}

	array_walk($extends, fn($v)=> wpjam_parse_extend($dir, $v, 'include'));
}

function wpjam_parse_extend($dir, $name, $output=''){
	$name	= str_ends_with($name, '.php') ? substr($name, 0, -4) : $name;

	if(in_array($name, ['.', '..', 'extends'])){
		return;
	}

	$file	= $dir.'/'.$name;
	$file	.= is_dir($file) ? '/'.$name.'.php' : '.php';

	if(!is_file($file)){
		return;
	}

	if($output == 'include'){
		if(is_admin() || !str_ends_with($file, '-admin.php')){
			include_once $file;
		}
	}elseif($output == 'field'){
		$data	= wpjam_get_file_data($file);

		return $data && $data['Name'] ? [
			'key'	=> $name,
			'title'	=> $data['URI'] ? '<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>' : $data['Name'],
			'label'	=> $data['Description']
		] : [];
	}else{
		return $name;
	}
}

function wpjam_get_file_data($file){
	return $file ? array_reduce(['URI', 'Name'], fn($c, $k)=> wpjam_set($c, $k, ($c[$k] ?? '') ?: ($c['Plugin'.$k] ?? '')), get_file_data($file, [
		'Name'			=> 'Name',
		'URI'			=> 'URI',
		'PluginName'	=> 'Plugin Name',
		'PluginURI'		=> 'Plugin URI',
		'Version'		=> 'Version',
		'Description'	=> 'Description'
	])) : [];
}

function wpjam_get_file_summary($file){
	$data	= wpjam_get_file_data($file);

	return str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。';
}

// Asset
function wpjam_asset($type, $handle, $args, $load=false){
	$args	= is_array($args) ? $args : ['src'=>$args];

	if($load || array_any(['wp', 'admin', 'login'], fn($part)=> doing_action($part.'_enqueue_scripts'))){
		$method	= wpjam_pull($args, 'method') ?: 'enqueue';

		if(empty($args[$method.'_if']) || $args[$method.'_if']($handle, $type)){
			$args	= wp_parse_args($args, ['src'=>'', 'deps'=>[], 'ver'=>false, 'media'=>'all', 'position'=>'after']);
			$src	= maybe_closure($args['src'], $handle);
			$data	= $args['data'] ?? '';

			($src || !$data) && wpjam_call('wp_'.$method.'_'.$type, $handle, $src, $args['deps'], $args['ver'], ($type == 'script' ? wpjam_pick($args, ['in_footer', 'strategy']) : $args['media']));

			$data && wpjam_call('wp_add_inline_'.$type, $handle, $data, $args['position']);
		}
	}else{
		$parts	= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
		$parts	= isset($args['for']) ? array_intersect($parts, wp_parse_list($args['for'] ?: 'wp')) : $parts;

		array_walk($parts, fn($part)=> wpjam_load($part.'_enqueue_scripts', fn()=> wpjam_asset($type, $handle, $args, true), ($args['priority'] ?? 10)));
	}
}

function wpjam_script($handle, $args=[]){
	wpjam_asset('script', $handle, $args);
}

function wpjam_style($handle, $args=[]){
	wpjam_asset('style', $handle, $args);
}

// Video
function wpjam_add_video_parser($pattern, $cb){
	wpjam('video_parser[]', [$pattern, $cb]);
}

// Capability
function wpjam_map_meta_cap($cap, $map){
	$cap && $map && (is_callable($map) || wp_is_numeric_array($map)) && wpjam_map(wp_parse_list($cap), fn($c)=> $c && wpjam('map_meta_cap[]', $c.'[]', $map));
}

function wpjam_current_user_can($capability, ...$args){
	return ($capability = maybe_closure($capability, ...$args)) ? current_user_can($capability, ...$args) : true;
}

// Rewrite Rule
function wpjam_add_rewrite_rule($args){
	if(did_action('init')){
		$args	= maybe_callback($args);

		if($args && is_array($args)){
			if(is_array($args[0])){
				array_walk($args, 'wpjam_add_rewrite_rule');
			}else{
				add_rewrite_rule(...[$GLOBALS['wp_rewrite']->root.array_shift($args), ...$args]);
			}
		}
	}else{
		add_action('init', fn()=> wpjam_add_rewrite_rule($args));
	}
}

// Menu Page
function wpjam_add_menu_page(...$args){
	if(is_array($args[0])){
		$args	= $args[0];

		if(wp_is_numeric_array($args)){
			return array_walk($args, 'wpjam_add_menu_page');
		}
	}else{
		$key	= empty($args[1]['plugin_page']) ? 'menu_slug' : 'tab_slug';
		$args	= wpjam_set($args[1], $key, $args[0]);

		if(!is_admin() && ($args['function'] ?? '') == 'option' && (!empty($args['sections']) || !empty($args['fields']))){
			wpjam_register_option(($args['option_name'] ?? $args[$key]), $args);
		}
	}

	$type	= array_find(['tab_slug'=>'tabs', 'menu_slug'=>'pages'], fn($v, $k)=> !empty($args[$k]) && !is_numeric($args[$k]));

	if(!$type){
		return;
	}

	$model	= $args['model'] ?? '';
	$cap	= $args['capability'] ?? '';

	$cap && wpjam_map_meta_cap($cap, wpjam_pull($args, 'map_meta_cap'));

	if($model){
		wpjam_hooks(wpjam_call($model.'::add_hooks'));
		wpjam_init([$model, 'init']);

		$cap && method_exists($model, 'map_meta_cap') && wpjam_map_meta_cap($cap, [$model, 'map_meta_cap']);
	}

	if(is_admin()){
		if($type == 'pages'){
			$parent	= wpjam_pull($args, 'parent');
			$key	= $type.($parent ? '['.$parent.'][subs]' : '').'['.wpjam_pull($args, 'menu_slug').']';
			$args	= $parent ? $args : array_merge(wpjam_admin($key.'[]'), $args, ['subs'=>array_merge(wpjam_admin($key.'[subs][]'), $args['subs'] ?? [])]);
		}else{
			$key	= $type.'[]';
		}

		wpjam_admin($key, $args);
	}
}

if(is_admin()){
	if(!function_exists('get_screen_option')){
		function get_screen_option($option, $key=false){
			if(did_action('current_screen')){
				$screen	= get_current_screen();
				$value	= in_array($option, ['post_type', 'taxonomy']) ? $screen->$option : $screen->get_option($option);

				return $key ? ($value ? wpjam_get($value, $key) : null) : $value;
			}
		}
	}

	function wpjam_admin($key='', ...$args){
		$object	= WPJAM_Admin::get_instance();

		if(!$key){
			return $object;
		}

		if(method_exists($object, $key)){
			return $object->$key(...$args);
		}

		$value	= $object->get_arg($key);

		if(!$args){
			return $value ?? $object->get_arg('vars['.$key.']');
		}

		if(is_object($value)){
			return count($args) >= 2 ? ($value->{$args[0]} = $args[1]) : $value->{$args[0]};
		}

		$value	= $args[0];

		if($key == 'query_data'){
			return wpjam_map($value, fn($v, $k)=> is_array($v) ? wp_die('query_data 不能为数组') : wpjam_admin($key.'['.$k.']', (is_null($v) ? $v : sanitize_textarea_field($v))));
		}

		if(in_array($key, ['script', 'style'])){
			$key	.= '[]';
			$value	= implode("\n", (array)$value);
		}

		$object->process_arg($key, fn()=> $value);

		return $value;
	}

	function wpjam_add_admin_ajax($action, $args=[]){
		wp_doing_ajax() && wpjam_register_ajax($action, ['admin'=>true]+$args);
	}

	function wpjam_add_admin_error($msg='', $type='success'){
		if(is_wp_error($msg)){
			$msg	= $msg->get_error_message();
			$type	= 'error';
		}

		$msg && $type && wpjam_admin('error[]', compact('msg', 'type'));
	}

	function wpjam_add_admin_load($args){
		if(wp_is_numeric_array($args)){
			array_walk($args, 'wpjam_add_admin_load');
		}else{
			$type	= wpjam_pull($args, 'type') ?: array_find(['base'=>'builtin_page', 'plugin_page'=>'plugin_page'], fn($v, $k)=> isset($args[$k])) ?: '';

			in_array($type, ['builtin_page', 'plugin_page']) && wpjam_admin($type.'_load[]', $args);
		}
	}

	function wpjam_admin_tooltip($text, $tooltip){
		return $text ? '<span class="tooltip" data-tooltip="'.esc_attr($tooltip).'">'.$text.'</span>' : '<span class="dashicons dashicons-editor-help tooltip" data-tooltip="'.esc_attr($tooltip).'"></span>';
	}

	function wpjam_get_referer(){
		return remove_query_arg([...wp_removable_query_args(), '_wp_http_referer', 'action', 'action2', '_wpnonce'], wp_get_original_referer() ?: wp_get_referer());
	}

	function wpjam_get_admin_post_id(){
		return (int)($_GET['post'] ?? ($_POST['post_ID'] ?? 0));
	}

	function wpjam_register_page_action($name, $args){
		return WPJAM_Page_Action::create($name, $args);
	}

	function wpjam_get_page_button($name, $args=[]){
		return ($object = WPJAM_Page_Action::get($name)) ? $object->get_button($args) : '';
	}

	function wpjam_register_list_table_action($name, $args){
		return WPJAM_List_Table_Action::register($name, $args);
	}

	function wpjam_unregister_list_table_action($name, $args=[]){
		return WPJAM_List_Table_Action::unregister($name, $args);
	}

	function wpjam_register_list_table_column($name, $field){
		return WPJAM_List_Table_Column::register($name, $field);
	}

	function wpjam_unregister_list_table_column($name, $field=[]){
		return WPJAM_List_Table_Column::unregister($name, $field);
	}

	function wpjam_register_list_table_view($name, $view=[]){
		return WPJAM_List_Table_View::register($name, $view);
	}

	function wpjam_register_dashboard_widget($name, $args){
		WPJAM_Dashboard::add_widget($name, $args);
	}

	function wpjam_chart($type, $data, $args){
	}

	function wpjam_line_chart($data, $labels, $args=[]){
		echo WPJAM_Chart::line(array_merge($args, ['labels'=>$labels, 'data'=>$data]));
	}

	function wpjam_bar_chart($data, $labels, $args=[]){
		echo WPJAM_Chart::line(array_merge($args, ['labels'=>$labels, 'data'=>$data]), 'Bar');
	}

	function wpjam_donut_chart($data, ...$args){
		$args	= count($args) >= 2 ? array_merge($args[1], ['labels'=> $args[0]]) : ($args[0] ?? []);

		echo WPJAM_Chart::donut(array_merge($args, ['data'=>$data]));
	}

	function wpjam_get_chart_parameter($key){
		return (WPJAM_Chart::get_instance())->get_parameter($key);
	}

	function wpjam_render_callback($cb){
		if(is_array($cb)){
			$cb	= (is_object($cb[0]) ? get_class($cb[0]).'->' : $cb[0].'::').(string)$cb[1];
		}elseif(is_object($cb)){
			$cb	= get_class($cb);
		}

		return wpautop($cb);
	}
}

wpjam_pattern('key', '^[a-zA-Z][a-zA-Z0-9_\-]*$', '请输入英文字母、数字和 _ -，并以字母开头！');
wpjam_pattern('slug', '[a-z0-9_\\-]+', '请输入小写英文字母、数字和 _ -！');

wpjam_map([
	['bad_authentication',	'无权限'],
	['access_denied',		'操作受限'],
	['incorrect_password',	'密码错误'],
	['undefined_method',	fn($args)=> '「%s」'.(count($args) >= 2 ? '%s' : '').'未定义'],
], fn($args)=> wpjam_add_error_setting(...$args));

wpjam_register_bind('phone', '',['domain'=>'@phone.sms']);

wpjam_route('json', 'WPJAM_JSON');
wpjam_route('txt', 'WPJAM_Verify_TXT');

wpjam_load_extends(dirname(__DIR__).'/components');
wpjam_load_extends(dirname(__DIR__).'/extends', [
	'option'	=> 'wpjam-extends',
	'sitewide'	=> true,
	'title'		=> '扩展管理',
	'hook'		=> 'plugins_loaded',
	'priority'	=> 1,
	'menu_page'	=> ['parent'=>'wpjam-basic', 'order'=>3, 'function'=>'tab', 'tabs'=>['extends'=>['order'=>20, 'title'=>'扩展管理', 'function'=>'option']]]
]);

wpjam_load_extends([
	'dir'		=> fn()=> get_template_directory().'/extends',
	'hook'		=> 'plugins_loaded',
	'priority'	=> 0,
]);

wpjam_style('remixicon', [
	'src'		=> fn()=> wpjam_get_static_cdn().'/remixicon/4.2.0/remixicon.min.css',
	'method'	=> is_admin() ? 'enqueue' : 'register',
	'data'		=> is_admin() ? "\n".'.wp-menu-image[class*=" ri-"]:before{display:inline-block; line-height:1; font-size:20px;}' : '',
	'priority'	=> 1
]);

wpjam_map(['post_type'=>2, 'taxonomy'=>3], fn($v, $k)=> wpjam_add_filter('register_'.$k.'_args', [
	'check'		=> fn($args)=> did_action('init') || empty($args['_builtin']),
	'callback'	=> fn($args, $name, ...$more)=> (['WPJAM_'.$k, 'get_instance']($name, ($more ? ['object_type'=>$more[0]] : [])+$args))->to_array()
], 999, $v));

add_action('plugins_loaded',	fn()=> wpjam(), 0);
add_action('plugins_loaded',	fn()=> wpjam_activation(), 0);
add_action('plugins_loaded',	fn()=> is_admin() && wpjam_admin(), 0);

add_action('init',	fn()=> get_locale() == 'zh_CN' && $GLOBALS['wp_textdomain_registry']->set('wpjam-basic', 'zh_CN', dirname(__DIR__).'/languages'));

if(wpjam_is_json_request()){
	ini_set('display_errors', 0);

	remove_filter('the_title', 'convert_chars');

	remove_action('init', 'wp_widgets_init', 1);
	remove_action('init', 'maybe_add_existing_user_to_blog');
	// remove_action('init', 'check_theme_switched', 99);

	remove_action('plugins_loaded', 'wp_maybe_load_widgets', 0);
	remove_action('plugins_loaded', 'wp_maybe_load_embeds', 0);
	remove_action('plugins_loaded', '_wp_customize_include');
	remove_action('plugins_loaded', '_wp_theme_json_webfonts_handler');

	remove_action('wp_loaded', '_custom_header_background_just_in_time');
	remove_action('wp_loaded', '_add_template_loader_filters');
}
