<?php
class WPJAM_Post extends WPJAM_Instance{
	public function __get($key){
		if(in_array($key, ['id', 'post_id'])){
			return $this->id;
		}elseif($key == 'views'){
			return (int)get_post_meta($this->id, 'views', true);
		}elseif($key == 'viewable'){
			return is_post_publicly_viewable($this->id);
		}elseif($key == 'type_object'){
			return wpjam_get_post_type_object($this->post_type);
		}elseif($key == 'thumbnail'){
			return $this->supports('thumbnail') ? get_the_post_thumbnail_url($this->id, 'full') : '';
		}elseif($key == 'images'){
			return $this->supports('images') ? array_values(get_post_meta($this->id, 'images', true) ?: []) : [];
		}elseif($key == 'post'){
			return get_post($this->id);
		}elseif($key == 'data'){
			return $this->post->to_array();
		}elseif(!str_starts_with($key, 'post_') && isset($this->post->{'post_'.$key})){
			return $this->post->{'post_'.$key};
		}else{
			return $this->post->$key ?? $this->meta_get($key);
		}
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function __call($method, $args){
		if($method == 'get_type_setting'){
			return $this->type_object->{$args[0]};
		}elseif(in_array($method, ['get_taxonomies', 'supports'])){
			return $this->type_object->$method(...$args);
		}elseif(in_array($method, ['get_content', 'get_excerpt', 'get_first_image_url', 'get_thumbnail_url', 'get_images'])){
			$cb	= 'wpjam_get_post_'.substr($method, 4);

			return $cb($this->post, ...$args);
		}elseif(in_array($method, ['get_terms', 'set_terms', 'in_term'])){
			$cb	= ['get_terms'=>'get_the_terms', 'set_terms'=>'wp_set_post_terms', 'in_term'=>'is_object_in_term'][$method];

			return $cb($this->id, ...$args);
		}elseif(in_array($method, ['in_taxonomy'])){
			$cb	= ['in_taxonomy'=>'is_object_in_taxonomy'][$method];

			return $cb($this->post, ...$args);
		}

		return $this->call_dynamic_method($method, ...$args);
	}

	public function save($data){
		$key	= array_find(['post_status', 'status'], fn($k)=> isset($data[$k]));
		$cb		= $key && $data[$key] == 'publish' ? [$this, 'is_publishable'] : null;
		$result	= $cb && method_exists(...$cb) ? wpjam_catch($cb) : true;

		if(is_wp_error($result) || !$result){
			return $result ?: new WP_Error('cannot_publish', '不可发布');
		}

		return $data ? self::update($this->id, $data, false) : true;
	}

	public function set_status($status){
		return $this->save(['post_status'=>$status]);
	}

	public function publish(){
		return $this->set_status('publish');
	}

	public function unpublish(){
		return $this->set_status('draft');
	}

	public function get_unserialized(){
		return wpjam_unserialize($this->content, fn($fixed)=> $this->save(['content'=>$fixed])) ?: [];
	}

	public function parse_for_json($args=[]){
		if($args && is_string($args) && in_array($args, ['date', 'modified'])){
			$ts		= get_post_timestamp($this->id, $args);
			$prefix	= $args == 'modified' ? $args.'_' : '';
			$result	= [$prefix.'timestamp'=>$ts, $prefix.'time'=>wpjam_human_time_diff($ts), $prefix.'date'=>wpjam_date('Y-m-d', $ts)];

			return $result+($args == 'date' ? ['day'=>wpjam_human_date_diff($result['date'])] : []);
		}

		$args	+= self::get_default_args();
		$query	= $args['query'] ?? null;
		$single	= $query && $query->is_main_query() && ($query->is_single($this->id) || $query->is_page($this->id));
		$filter	= $args['suppress_filter'] ? '' : ($args['list_query'] ? ($args['filter'] ?? '') : 'wpjam_post_json');

		$json	= wpjam_pick($this, ['id', 'type', 'post_type', 'status', 'views']);
		$json	+= ['icon'=>(string)$this->get_type_setting('icon')];
		$json	+= $this->viewable ? ['name'=> urldecode($this->name), 'post_url'=>str_replace(home_url(), '', get_permalink($this->id))] : [];
		$json	+= wpjam_fill(['title', 'excerpt'], fn($field)=> $this->supports($field) ? html_entity_decode(('get_the_'.$field)($this->id)) : '');
		$json	+= ['thumbnail'=>$this->get_thumbnail_url($args['thumbnail_size'])];
		$json	+= $this->supports('images') ? ['images'=>$this->get_images()] : [];
		$json	+= ['user_id'=>(int)$this->author];
		$json	+= $this->supports('author') ? ['author'=>wpjam_get_user($this->author)] : [];
		$json	+= $this->parse_for_json('date')+$this->parse_for_json('modified');
		$json	+= $this->password ? ['password_protected'=>true, 'password_required'=>post_password_required($this->id)] : [];
		$json	+= $this->supports('page-attributes') ? ['menu_order'=>(int)$this->menu_order] : [];
		$json	+= $this->supports('post-formats') ? ['format'=>get_post_format($this->id) ?: ''] : [];

		if(!$args['list_query']){
			$rest	= $single ? 'show_in_rest' : 'show_in_posts_rest';
			$json	+= array_reduce(wpjam_get_post_options($this->type, [$rest=>true]), fn($carry, $option)=> $carry+$option->prepare($this->id), []);
			$json	+= array_reduce($this->get_taxonomies([$rest=>true], 'names'), fn($carry, $tax)=> $carry+[$tax=>wpjam_get_terms(['terms'=>$this->get_terms($tax), 'taxonomy'=>$tax])], []);
		}

		if($single || $args['content_required']){
			if($this->supports('editor')){
				$json	+= ['content'=>$this->get_content(), 'multipage'=>(bool)$GLOBALS['multipage']];
				$json	+= $json['multipage'] ? ['numpages'=>$GLOBALS['numpages'], 'page'=>$GLOBALS['page']] : [];
			}else{
				$json	+= is_serialized($this->content) ? ['content'=>$this->get_unserialized()] : [];
			}
		}

		return $filter ? apply_filters($filter, $json, $this->id, $args) : $json;
	}

	public function value_callback($field){
		if($field == 'tax_input'){
			return wpjam_fill($this->get_taxonomies('names'), fn($tax)=> array_column($this->get_terms($tax), 'term_id'));
		}

		return $this->post->$field ?? $this->meta_get($field);
	}

	// update/insert 方法同时支持 title 和 post_xxx 字段写入 post 中，meta 字段只支持 meta_input
	// update_callback 方法只支持 post_xxx 字段写入 post 中，其他字段都写入 meta_input
	public function update_callback($data, $defaults){
		$result	= $this->save(wpjam_pull($data, [...array_keys($this->data), 'tax_input']));

		return (!is_wp_error($result) && $data) ? $this->meta_input($data, $defaults) : $result;
	}

	public static function get_default_args(){
		return [
			'suppress_filter'	=> false,
			'list_query'		=> false,
			'content_required'	=> false,
			'thumbnail_size'	=> null
		];
	}

	public static function get_instance($post=null, $type=null, $wp_error=false){
		$post	= $post ?: get_post();
		$post	= static::validate($post, $type);

		if(is_wp_error($post)){
			return $wp_error ? $post : null;
		}

		return self::instance($post->ID, fn($id)=> [wpjam_get_post_type_setting(get_post_type($id), 'model') ?: 'WPJAM_Post', 'create_instance']($id));
	}

	public static function validate($value, $type=null){
		$post	= $value ? self::get_post($value) : null;
		$type	??= static::get_current_post_type();

		if(!$post){
			return new WP_Error('invalid_id');
		}

		if(!post_type_exists($post->post_type) || ($type && $type !== 'any' && !in_array($post->post_type, (array)$type))){
			return new WP_Error('invalid_post_type');
		}

		return $post;
	}

	public static function get($post){
		if($data	= self::get_post($post, ARRAY_A)){
			$key	= 'post_content';
			$data	= [$key=>maybe_unserialize($data[$key]), 'id'=>$data['ID']]+$data;
			$data	+= wpjam_array($data, fn($k, $v)=> try_remove_prefix($k, 'post_') ? [$k, $v] : null);
		}

		return $data;
	}

	public static function update($post_id, $data, $validate=true){
		$result	= $validate ? wpjam_catch(fn()=> static::validate($post_id)) : null;

		return is_wp_error($result) ? $result : parent::update($post_id, $data);
	}

	public static function delete($post_id, $force=true){
		return wpjam_catch(fn()=> static::before_delete($post_id) || true ? (wp_delete_post($post_id, $force) ?: wpjam_throw('delete_error', '删除失败')) : null);
	}

	protected static function call_method($method, ...$args){
		if($method == 'get_meta_type'){
			return 'post';
		}elseif($method == 'insert'){
			$data	= $args[0];
			$type	= wpjam_filter([$data['post_type'] ?? '', static::get_current_post_type()]);
			$type	= count($type) == 2 && $type[0] != $type[1] ? '' : ($type ? (post_type_exists($type[0]) ? $type[0] : '') : 'post');

			$data['post_type']		= $type ?: wpjam_throw('invalid_post_type');
			$data['post_status']	??= current_user_can(get_post_type_object($type)->cap->publish_posts) ? 'publish' : 'draft';
			$data['post_author']	??= get_current_user_id();
			$data['post_date']		??= wpjam_date('Y-m-d H:i:s');

			return wpjam_try('wp_insert_post', wp_slash($data), true, true);
		}elseif($method == 'update'){
			return wpjam_try('wp_update_post', wp_slash(array_merge($args[1], ['ID'=>$args[0]])), true, true);
		}
	}

	protected static function sanitize_data($data, $post_id=0){
		$data	+= wpjam_array(get_class_vars('WP_Post'), fn($k, $v)=> try_remove_prefix($k, 'post_') && isset($data[$k]) ? ['post_'.$k, $data[$k]] : null);
		$key	= 'post_content';
		$data	= (is_array($data[$key] ?? '') ? [$key=>serialize($data[$key])] : [])+$data;

		return $data+(!$post_id && method_exists(static::class, 'is_publishable') ? ['post_status'=>'draft'] : []);
	}

	public static function get_by_ids($post_ids){
		return array_map('get_post', array_filter(self::update_caches($post_ids)));
	}

	public static function update_caches($post_ids, $update_term_cache=false, $update_meta_cache=false){
		if($post_ids = array_filter(wp_parse_id_list($post_ids))){
			_prime_post_caches($post_ids, $update_term_cache, $update_meta_cache);

			$posts	= wp_cache_get_multiple($post_ids, 'posts');
			$args	= compact('update_term_cache', 'update_meta_cache');

			do_action('wpjam_deleted_ids', 'post', array_keys(array_filter($posts, fn($v)=> !$v)));

			return wpjam_tap(array_filter($posts), fn($posts)=> do_action('wpjam_update_post_caches', $posts, $args));
		}

		return [];
	}

	public static function get_post($post, $output=OBJECT, $filter='raw'){
		return wpjam_tap(get_post($post, $output, $filter), fn($v)=> $post && is_numeric($post) && !$v && do_action('wpjam_deleted_ids', 'post', $post));
	}

	public static function get_current_post_type(){
		if(static::class !== self::class){
			return wpjam_get_annotation(static::class, 'post_type') ?: ((WPJAM_Post_Type::get(static::class, 'model', self::class) ?: [])['name'] ?? null);
		}
	}

	public static function get_path($args, $item=[]){
		$type	= $item['post_type'];
		$id		= is_array($args) ? (int)($args[$type.'_id'] ?? 0) : $args;

		if($id === 'fields'){
			return get_post_type_object($type) ? [$type.'_id' => self::get_field(['post_type'=>$type, 'required'=>true])] : [];
		}

		if(!$id){
			return new WP_Error('invalid_id', [wpjam_get_post_type_setting($type, 'title')]);
		}

		return $item['platform'] == 'template' ? get_permalink($id) : str_replace('%post_id%', $id, $item['path']);
	}

	public static function get_field($args){
		$args['title'] ??= is_string($args['post_type'] ?? null) ? wpjam_get_post_type_setting($args['post_type'], 'title') : null;

		return $args+[
			'type'			=> 'text',
			'class'			=> 'all-options',
			'data_type'		=> 'post_type',
			'placeholder'	=> '请输入'.$args['title'].'ID或者输入关键字筛选'
		];
	}

	public static function with_field($method, $field, $value){
		$type	= $field->post_type;

		if($method == 'validate'){
			return (is_numeric($value) && wpjam_try(static::class.'::validate', $value, $type)) ? (int)$value : null;
		}elseif($method == 'parse'){
			return ($object = self::get_instance($value, $type)) ? $object->parse_for_json(['thumbnail_size'=>$field->size]) : null;
		}
	}

	public static function query_items($args){
		if(wpjam_pull($args, 'data_type')){
			return wpjam_get_posts($args+[
				's'					=> $args['search'] ?? null,
				'posts_per_page'	=> $args['number'] ?? 10,
				'suppress_filters'	=> false,
			]);
		}

		$args['post_status']	= wpjam_pull($args, 'status') ?: 'any';
		$args['post_type']		??= static::get_current_post_type();
		$args['posts_per_page']	??= $args['number'] ?? 10;

		return [
			'items'	=> $GLOBALS['wp_query']->query($args),
			'total'	=> $GLOBALS['wp_query']->found_posts
		];
	}

	public static function query_calendar($args){
		$args['posts_per_page']	= -1;
		$args['post_status']	= wpjam_pull($args, 'status') ?: 'any';
		$args['post_type']		??= static::get_current_post_type();
		$args['monthnum']		= $args['month'];

		return array_reduce($GLOBALS['wp_query']->query($args), fn($carry, $post)=> wpjam_set($carry, wpjam_at($post->post_date, ' ', 0).'[]', $post), []);
	}

	public static function get_views(){
		if(get_current_screen()->base != 'edit'){
			$counts	= array_filter((array)wp_count_posts(static::get_current_post_type()));
			$views	= ['all'=>['filter'=>['status'=>null, 'show_sticky'=>null], 'label'=>'全部', 'count'=>array_sum($counts)]];

			return wpjam_reduce($counts, fn($c, $v, $k)=> $c+(($object	= get_post_status_object($k)) && $object->show_in_admin_status_list ? [$k=>['filter'=>['status'=>$k], 'label'=>$object->label, 'count'=>$v]] : []), $views);
		}
	}

	public static function filter_fields($fields, $id){
		return ($id && !is_array($id) && !isset($fields['title']) && !isset($fields['post_title']) ? ['title'=>['title'=>wpjam_get_post_type_setting(get_post_type($id), 'title').'标题', 'type'=>'view', 'value'=>get_the_title($id)]] : [])+$fields;
	}

	public static function get_meta($post_id, ...$args){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_get_metadata');
		return wpjam_get_metadata('post', $post_id, ...$args);
	}

	public static function update_meta($post_id, ...$args){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_update_metadata');
		return wpjam_update_metadata('post', $post_id, ...$args);
	}

	public static function update_metas($post_id, $data, $meta_keys=[]){
		return static::update_meta($post_id, $data, $meta_keys);
	}
}

/**
* @config menu_page, admin_load, register_json, init
**/
#[config('menu_page', 'admin_load', 'register_json', 'init')]
class WPJAM_Post_Type extends WPJAM_Register{
	public function __get($key){
		if($key == 'title'){
			return $this->labels ? $this->labels->singular_name : $this->label;
		}elseif($key != 'name' && property_exists('WP_Post_Type', $key)){
			if($object	= get_post_type_object($this->name)){
				return $object->$key;
			}
		}

		$value	= parent::__get($key);

		if($key == 'model'){
			return $value && class_exists($value) ? $value : 'WPJAM_Post';
		}elseif($key == 'permastruct'){
			return ($value	??= $this->call('get_'.$key.'_by_model')) ? trim($value, '/') : $value;
		}else{
			return $value;
		}
	}

	public function __set($key, $value){
		if($key != 'name' && property_exists('WP_Post_Type', $key) && ($object	= get_post_type_object($this->name))){
			$object->$key = $value;
		}

		parent::__set($key, $value);
	}

	protected function preprocess_args($args){
		$args	= parent::preprocess_args($args);
		$args	+= ['plural'=>$args['name'].'s'];

		if(!empty($args['_jam'])){
			if(!is_array(wpjam_get($args, 'taxonomies'))){
				unset($args['taxonomies']);
			}

			$args	+= [
				'public'		=> true,
				'show_ui'		=> true,
				'hierarchical'	=> false,
				'rewrite'		=> true,
				'supports'		=> ['title'],
			];
		}

		return $args;
	}

	public function get_menu_slug(){
		if(is_null($this->menu_slug) && ($menu_page	= $this->get_arg('menu_page')) && is_array($menu_page)){
			$this->menu_slug	= wp_is_numeric_array($menu_page) ? rest($menu_page)['menu_slug'] : $menu_page['menu_slug'];
		}

		return $this->menu_slug;
	}

	public function to_array(){
		$this->filter_args();

		if(!$this->_builtin && $this->permastruct){
			$this->permastruct	= str_replace(['%'.$this->name.'_id%', '%postname%'], ['%post_id%', '%'.$this->name.'%'], $this->permastruct);

			if(strpos($this->permastruct, '%post_id%')){
				if($this->hierarchical){
					$this->permastruct	= false;
				}else{
					$this->query_var	??= false;
				}
			}

			$this->permastruct	&& $this->process_arg('rewrite', fn($v)=> $v ?: true);
		}

		if($this->_jam){
			$this->hierarchical	&& $this->update_arg('supports[]', 'page-attributes');
			$this->rewrite		&& $this->process_arg('rewrite', fn($v)=> (is_array($v) ? $v : [])+['with_front'=>false, 'feeds'=>false]);
			$this->menu_icon	&& $this->process_arg('menu_icon', fn($v)=> (str_starts_with($v, 'dashicons-') ? '' : 'dashicons-').$v);
		}

		return $this->args;
	}

	public function get_fields($id=0, $action_key=''){
		if(in_array($action_key, ['add', 'set'])){
			if($action_key == 'add'){
				$fields['post_type']	= ['type'=>'hidden',	'value'=>$this->name];
				$fields['post_status']	= ['type'=>'hidden',	'value'=>'draft'];
			}

			$fields['post_title']	= ['title'=>'标题',	'type'=>'text',	'required'];

			if($this->supports('excerpt')){
				$fields['post_excerpt']	= ['title'=>'摘要',	'type'=>'textarea'];
			}

			if($this->supports('thumbnail')){
				$fields['_thumbnail_id']	= ['title'=>'头图', 'type'=>'img', 'size'=>'600x0',	'name'=>'meta_input[_thumbnail_id]'];
			}
		}

		if($this->supports('images')){
			$fields['images']	= [
				'title'		=> '头图',
				'name'		=> 'meta_input[images]',
				'type'		=> 'mu-img',
				'item_type'	=> 'url',
				'size'		=> ($this->images_sizes ? $this->images_sizes[0] : ''),
				'max_items'	=> $this->images_max_items
			];
		}

		if($this->supports('video')){
			$fields['video']	= ['title'=>'视频',	'type'=>'url',	'name'=>'meta_input[video]'];
		}

		$parsed	= $this->parse_fields($id, $action_key);

		if($parsed && in_array($action_key, ['add', 'set'])){
			$parsed	= wpjam_map($parsed, fn($field, $key)=> array_merge($field, (empty($field['name']) && !property_exists('WP_Post', $key)) ? ['name'=>'meta_input['.$key.']'] : []));
		}

		return array_merge($fields ?? [], $parsed);
	}

	public function register_option(){
		$name	= $this->name.'_base';

		return wpjam_get_post_option($name) ?: wpjam_register_post_option($name, [
			'post_type'		=> $this->name,
			'title'			=> '基础信息',
			'page_title'	=> '设置'.$this->title,
			'fields'		=> [$this, 'get_fields'],
			'list_table'	=> $this->show_ui,
			'action_name'	=> 'set',
			'row_action'	=> false,
			'order'			=> 99,
		]);
	}

	public function get_support($feature){
		$support	= get_all_post_type_supports($this->name)[$feature] ?? false;

		return (wp_is_numeric_array($support) && count($support) == 1) ? reset($support) : $support;
	}

	public function supports($feature){
		return array_any(wp_parse_list($feature), fn($f)=> post_type_supports($this->name, $f));
	}

	public function get_size($type='thumbnail'){
		return $this->{$type.'_size'} ?: $type;
	}

	public function get_taxonomies(...$args){
		$filter = $args && is_array($args[0]) ? array_shift($args) : [];
		$output	= $args[0] ?? 'objects';
		$data	= get_object_taxonomies($this->name);

		if($filter || $output == 'objects'){
			$objects	= wpjam_fill($data, 'wpjam_get_taxonomy_object');
			$objects	= wpjam_filter($objects, $filter);

			return $output == 'objects' ? $objects : array_keys($objects);
		}

		return $data;
	}

	public function in_taxonomy($taxonomy){
		return is_object_in_taxonomy($this->name, $taxonomy);
	}

	public function is_viewable(){
		return is_post_type_viewable($this->name);
	}

	public function reset_invalid_parent($value=0){
		$wpdb		= $GLOBALS['wpdb'];
		$post_ids	= $wpdb->get_col($wpdb->prepare("SELECT p1.ID FROM {$wpdb->posts} p1 LEFT JOIN {$wpdb->posts} p2 ON p1.post_parent = p2.ID WHERE p1.post_type=%s AND p1.post_parent > 0 AND p2.ID is null", $this->name)) ?: [];

		array_walk($post_ids, fn($id)=> wp_update_post(['ID'=>$id, 'post_parent'=>$value]));

		return count($post_ids);
	}

	public function registered(){
		add_action('registered_post_type_'.$this->name,	function($name, $object){
			if($struct	= $this->permastruct){
				if(str_contains($struct, '%post_id%')){
					remove_rewrite_tag('%'.$name.'%');

					add_filter($name.'_rewrite_rules', fn($rules)=> wpjam_map($rules, fn($v)=> str_replace('?p=', '?post_type='.$name.'&p=', $v)));
				}

				add_permastruct($name, $struct, array_merge($this->rewrite, ['feed'=>$this->rewrite['feeds']]));
			}

			wpjam_call($this->registered_callback, $name, $object);
		}, 10, 2);

		$this->_jam && wpjam_init(function(){
			is_admin() && $this->show_ui && add_filter('post_type_labels_'.$this->name,	function($labels){
				$labels		= (array)$labels;
				$name		= $labels['name'];
				$search		= $this->hierarchical ? ['撰写新', '写文章', '页面', 'page', 'Page'] : ['撰写新', '写文章', '文章', 'post', 'Post'];
				$replace	= ['添加', '添加'.$name, $name, $name, ucfirst($name)];
				$labels		= wpjam_map($labels, fn($v, $k)=> ['all_items'=>'所有'.$name, 'archives'=>$name.'归档'][$k] ?? (($v && $v != $name) ? str_replace($search, $replace, $v) : $v));

				return array_merge($labels, (array)($this->labels ?? []));
			});

			register_post_type($this->name, []);

			wpjam_map($this->options ?: [], fn($option, $name)=> wpjam_register_post_option($name, $option+['post_type'=>$this->name]));
		});
	}

	public static function get_instance($name, $args){
		return ($object	= self::get($name)) ? $object->update_args($args) : self::register($name, $args);
	}

	public static function add_hooks(){
		add_filter('post_type_link', fn($link, $post)=> str_replace('%post_id%', $post->ID, $link), 1, 2);

		add_filter('content_save_pre',	fn($content)=> wpjam_tap($content, fn($content)=> is_serialized($content) && remove_filter('content_save_pre', 'wp_filter_post_kses') && wpjam_add_once_filter('content_save_pre', fn($c)=> wpjam_tap($c, fn()=> add_filter('content_save_pre', 'wp_filter_post_kses')), 11)), 1);

		add_filter('posts_clauses',		function($clauses, $query){
			$orderby	= $query->get('related_query') ? 'related' : $query->get('orderby');
			$order		= $query->get('order') ?: 'DESC';
			$wpdb		= $GLOBALS['wpdb'];

			if($orderby == 'related'){
				if($tt_ids	= $query->get('term_taxonomy_ids')){
					$clauses['join']	.= "INNER JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id";
					$clauses['where']	.= " AND tr.term_taxonomy_id IN (".implode(",", $tt_ids).")";
					$clauses['groupby']	.= " tr.object_id";
					$clauses['orderby']	= " count(tr.object_id) DESC, {$wpdb->posts}.ID DESC";
				}
			}elseif($orderby == 'comment_date'){
				$ct		= $query->get('comment_type') ?: 'comment';
				$str	= $ct == 'comment' ? "'comment', ''" : "'".esc_sql($ct)."'";
				$where	= "ct.comment_type IN ({$str}) AND ct.comment_parent=0 AND ct.comment_approved NOT IN ('spam', 'trash', 'post-trashed')";

				$clauses['join']	= "INNER JOIN {$wpdb->comments} AS ct ON {$wpdb->posts}.ID = ct.comment_post_ID AND {$where}";
				$clauses['groupby']	= "ct.comment_post_ID";
				$clauses['orderby']	= "MAX(ct.comment_ID) {$order}";
			}elseif(in_array($orderby, ['views', 'comment_type'])){
				$meta_key			= $orderby == 'comment_type' ? $query->get('comment_count') : 'views';
				$clauses['join']	.= "LEFT JOIN {$wpdb->postmeta} jam_pm ON {$wpdb->posts}.ID = jam_pm.post_id AND jam_pm.meta_key = '{$meta_key}' ";
				$clauses['orderby']	= "(COALESCE(jam_pm.meta_value, 0)+0) {$order}, " . $clauses['orderby'];
				$clauses['groupby']	= "{$wpdb->posts}.ID";
			}elseif(in_array($orderby, ['', 'date', 'post_date'])){
				$clauses['orderby']	.= ", {$wpdb->posts}.ID {$order}";
			}

			return $clauses;
		}, 1, 2);

		add_filter('posts_results',		function($posts, $query){
			$q	= &$query->query_vars;

			$sticky_posts	= array_diff(wp_parse_id_list(wpjam_pull($q, 'sticky_posts') ?: []), $q['post__not_in']);
			
			if($sticky_posts && ($stickies = get_posts([
				'orderby'					=> 'post__in',
				'post__in'					=> $sticky_posts,
				'post_type'					=> $q['post_type'] ?: 'post',
				'post_status'				=> 'publish',
				'posts_per_page'			=> count($sticky_posts),
				'suppress_filters'			=> $q['suppress_filters'],
				'cache_results'				=> $q['cache_results'],
				'update_post_meta_cache'	=> $q['update_post_meta_cache'],
				'update_post_term_cache'	=> $q['update_post_term_cache'],
				'lazy_load_term_meta'		=> $q['lazy_load_term_meta'],
			]))){
				$q['sticky_posts']	= array_column($stickies, 'ID');

				return array_merge($stickies, array_filter($posts, fn($post)=> !in_array($post->ID, $q['sticky_posts'], true)));
			}

			return $posts;
		}, 1, 2);
	}
}

class WPJAM_Posts{
	public static function query($vars, &$args=[]){
		$vars	= self::parse_query_vars($vars);

		if($args){
			$vars	= array_merge($vars, wpjam_pull($args, ['post_type', 'orderby', 'posts_per_page']));
			$number = wpjam_pull($args, 'number');
			$days	= wpjam_pull($args, 'days');
			$vars	= $number ? ['posts_per_page'=>$number]+$vars : $vars;
			$vars	= $days ? wpjam_set($vars, 'date_query[]', [
				'column'	=> wpjam_pull($args, 'column') ?: 'post_date_gmt',
				'after'		=> wpjam_date('Y-m-d', time()-DAY_IN_SECONDS*$days).' 00:00:00'
			]) : $vars;
		}

		return new WP_Query($vars+['no_found_rows'=>true, 'ignore_sticky_posts'=>true]);
	}

	public static function parse($query, $args=[]){
		$format	= wpjam_pull($args, 'format');
		$query	= is_object($query) ? $query : self::query($query, $args);
		$args	+= ['thumbnail_size'=>wpjam_pull($args, 'size')];
		$args	+= $query->get('related_query') ? ['filter'=>'wpjam_related_post_json'] : [];
		$parsed	= [];

		while($query->have_posts()){
			$query->the_post();

			$json	= wpjam_get_post(get_the_ID(), $args+['query'=>$query]);
			$parsed	= $json ? wpjam_set($parsed, ($format == 'date' ? wpjam_at($json['date'], ' ', 0) : '').'[]', $json) : $parsed;
		}

		$query->is_main_query() || wp_reset_postdata();

		return $parsed;
	}

	public static function render($query, $args=[]){
		$cb		= wpjam_fill(['item_callback', 'wrap_callback'], fn($k)=> $args[$k] ?? [self::class, $k]);
		$query	= is_object($query) ? $query : self::query($query, $args);
		$args	+=['query'=>$query];

		return $cb['wrap_callback'](implode(wpjam_map($query->posts, fn($p, $i)=> $cb['item_callback']($p->ID, $args+['i'=>$i]))), $args);
	}

	public static function item_callback($post_id, $args){
		$args	+= ['title_number'=>'', 'excerpt'=>false, 'thumb'=>true, 'size'=>'thumbnail', 'thumb_class'=>'wp-post-image', 'wrap_tag'=>'li'];
		$title	= get_the_title($post_id);
		$item	= wpjam_wrap($title);	

		$args['title_number'] && $item->before('span', ['title-number'], zeroise($args['i']+1, strlen(count($args['query']->posts))).'. ');
		($args['thumb'] || $args['excerpt']) && $item->wrap('h4');
		$args['thumb'] && $item->before(get_the_post_thumbnail($post_id, $args['size'], ['class'=>$args['thumb_class']]));
		$args['excerpt'] && $item->after(wpautop(get_the_excerpt($post_id)));

		return $item->wrap('a', ['href'=>get_permalink($post_id), 'title'=>strip_tags($title)])->wrap($args['wrap_tag'])->render();
	}

	public static function wrap_callback($output, $args){
		if(!$output){
			return '';
		}

		$args	+= ['title'=>'', 'div_id'=>'', 'class'=>[], 'thumb'=>true, 'wrap_tag'=>'ul'];
		$output	= wpjam_wrap($output);

		$args['wrap_tag']	&& $output->wrap($args['wrap_tag'])->add_class($args['class'])->add_class($args['thumb'] ? 'has-thumb' : '');
		$args['title']		&& $output->before($args['title'], 'h3');
		$args['div_id']		&& $output->wrap('div', ['id'=>$args['div_id']]);

		return $output->render();
	}

	public static function get_related_query($post, &$args=[]){
		$post	= get_post($post);
		$type	= [get_post_type($post)];
		$tt_ids	= [];

		foreach($post ? get_object_taxonomies($type[0]) : [] as $tax){
			$terms		= $tax == 'post_format' ? [] : get_the_terms($post, $tax);
			$type	= array_merge($type, $terms ? get_taxonomy($tax)->object_type : []);
			$tt_ids		= array_merge($tt_ids, $terms ? array_column($terms, 'term_taxonomy_id') : []);
		}

		return $tt_ids ? self::query([
			'related_query'		=> true,
			'post_status'		=> 'publish',
			'post__not_in'		=> [$post->ID],
			'post_type'			=> array_unique($type),
			'term_taxonomy_ids'	=> array_unique(array_filter($tt_ids)),
		], $args) : false;
	}

	public static function parse_query_vars($vars, $param=false){
		if(!empty($vars['post_type']) && is_string($vars['post_type']) && str_contains($vars['post_type'], ',')){
			$vars['post_type'] = wp_parse_list($vars['post_type']);
		}

		$term_ids	= [];

		foreach(get_taxonomies([], 'objects') as $tax => $object){
			if(in_array($tax, ['category', 'post_tag']) || !$object->_builtin){
				$query_key	= wpjam_get_taxonomy_query_key($tax);
				$value		= wpjam_pull($vars, $query_key);

				if($param){
					$value	= wpjam_find($tax == 'category' ? ['category_id', 'cat_id'] : [$query_key], fn($v)=> $v, fn($k)=> (int)wpjam_get_parameter($k)) ?: $value;
				}

				if($value){
					$term_ids[$tax]	= $value;
				}
			}
		}

		if(!empty($vars['taxonomy']) && empty($vars['term'])){
			if($value = wpjam_pull($vars, 'term_id')){
				if(is_numeric($value)){
					$term_ids[wpjam_pull($vars, 'taxonomy')]	= $value;
				}else{
					$vars['term']	= $value;
				}
			}
		}

		foreach(array_filter($term_ids) as $tax => $value){
			if($tax == 'category' && $value != 'none'){
				$vars['cat']	= $value;
			}else{
				$vars['tax_query'][]	= ['taxonomy'=>$tax, 'field'=>'term_id']+($value == 'none' ? ['operator'=>'NOT EXISTS'] : ['terms'=>[$value]]);
			}
		}

		foreach(wpjam_pull($vars, ['include', 'exclude']) as $k => $v){
			if($ids = wp_parse_id_list($v)){
				if($k == 'include'){
					$vars['post__in']		= $ids;
					$vars['posts_per_page']	= count($ids);
				}else{
					$vars['post__not_in']	= $ids;
				}
			}
		}

		foreach(['cursor'=>'before', 'since'=>'after'] as $k => $v){
			$value	= wpjam_pull($vars, $k);
			$value	= (int)($param ? (wpjam_get_parameter($k) ?: $value) : $value);

			if($value){
				$vars['date_query'][]	= [$v => wpjam_date('Y-m-d H:i:s', $value)];

				$vars['ignore_sticky_posts']	= true;
			}
		}

		return $vars;
	}

	public static function parse_json_module($args){
		$action	= wpjam_pull($args, 'action');
		$wp		= $GLOBALS['wp'];

		$wp->query_vars	= wpjam_var('json_query_vars', fn()=> $wp->query_vars);

		if($action == 'list'){
			return self::parse_list_json_module($args);
		}elseif($action == 'calendar'){
			return self::parse_list_json_module($args, 'calendar');
		}elseif($action == 'get'){
			return self::parse_get_json_module($args);
		}elseif($action == 'upload'){
			return self::parse_media_json_module($args, 'media');
		}
	}

	/* 规则：
	** 1. 分成主的查询和子查询（$query_args['sub']=1）
	** 2. 主查询支持 $_GET 参数 和 $_GET 参数 mapping
	** 3. 子查询（sub）只支持 $query_args 参数
	** 4. 主查询返回 next_cursor 和 total_pages，current_page，子查询（sub）没有
	** 5. $_GET 参数只适用于 post.list
	** 6. term.list 只能用 $_GET 参数 mapping 来传递参数
	*/
	public static function parse_list_json_module($args, $format=''){
		$output	= wpjam_pull($args, 'output');
		$sub	= $format == 'calendar' ? false : wpjam_pull($args, 'sub');
		$vars	= array_diff_key($args, WPJAM_Post::get_default_args());

		if($sub){	// 子查询不支持 $_GET 参数，置空之前要把原始的查询参数存起来
			$query	= self::query($vars);
			$parsed	= self::parse($query, $args);
		}else{
			$query	= $GLOBALS['wp_query'];
			$wp		= $GLOBALS['wp'];
			$vars	= array_merge(wpjam_except($wp->query_vars, ['module', 'action']), $vars);

			if($format == 'calendar'){
				$vars	= array_merge($args, [
					'year'		=> (int)wpjam_get_parameter('year') ?: wpjam_date('Y'),
					'monthnum'	=> (int)wpjam_get_parameter('month') ?: wpjam_date('m'),
					'day'		=> (int)wpjam_get_parameter('day')
				]);

				$wp->query_vars	= $vars = self::parse_query_vars($vars, true);
				$wp->query_posts();

				$parsed	= self::parse($query, $args+['format'=>'date']);
			}else{
				$number	= wpjam_find(['number', 'posts_per_page'], fn($v)=> $v, fn($k)=> (int)wpjam_get_parameter($k));
				$vars	+= ($number && $number != -1) ? ['posts_per_page'=> min($number, 100)] : [];
				$vars	+= array_filter(['offset'=>wpjam_get_parameter('offset')]);

				if($post__in = wpjam_get_parameter('post__in')){
					$vars['post__in']		= wp_parse_id_list($post__in);
					$vars['orderby']		??= 'post__in';
					$vars['posts_per_page']	??= -1;
				}

				$wp->query_vars	= $vars	= self::parse_query_vars($vars, true);
				$wp->query_posts();

				$parsed	= self::parse($query, $args);

				$posts_json['total']		= $query->found_posts;
				$posts_json['total_pages']	= $query->get('nopaging') ? ($query->found_posts ? 1 : 0) : $query->max_num_pages;
				$posts_json['current_page']	= $query->get('nopaging') ? 1 : ($query->get('paged') ?: 1);

				if(empty($vars['paged']) 
					&& empty($vars['s']) 
					&& (!isset($vars['orderby']) || (!is_array($vars['orderby']) && in_array($vars['orderby'], ['date', 'post_date'])))
				){
					$posts_json['next_cursor']	= ($parsed && $query->max_num_pages > 1) ? end($parsed)['timestamp'] : 0;
				}

				if(is_front_page()){
					$is	= 'home';
				}elseif(is_author()){
					$is	= 'author';

					$posts_json	+= ['current_author'=>wpjam_get_user($query->get('author'))];
				}elseif(is_category() || is_tag() || is_tax()){
					$is		= is_category() ? 'category' : (is_tag() ? 'tag' : 'tax');
					$term	= $query->get_queried_object();

					$posts_json	+= ['current_taxonomy'=>($term ? $term->taxonomy : null)];
					$posts_json	+= $term ? ['current_term'=>wpjam_get_term($term, $term->taxonomy)] : [];
				}elseif(is_post_type_archive()){
					$is		= 'post_type_archive';
					$object	= $query->get_queried_object();

					$posts_json	+= ['current_post_type'=>($object ? $object->name : null)];
				}elseif(is_search()){
					$is	= 'search';
				}elseif(is_archive()){
					$is	= 'archive';
				}

				$posts_json	+= isset($is) ? ['is'=>$is] : [];
			}

			if(!$output && !empty($vars['post_type']) && is_string($vars['post_type'])){
				$output	= wpjam_get_post_type_setting($vars['post_type'], 'plural') ?: $vars['post_type'].'s';
			}
		}

		$output	= $output ?: 'posts';

		$posts_json[$output]	= $parsed;

		return apply_filters('wpjam_posts_json', $posts_json, $query, $output);
	}

	public static function parse_get_json_module($args){
		$wp		= $GLOBALS['wp'];
		$query	= $GLOBALS['wp_query'];

		$wp->set_query_var('cache_results', true);

		!empty($args['post_status']) && $wp->set_query_var('post_status', $args['post_status']);

		$vars	= $wp->query_vars;
		$type	= ($args['post_type'] ??= '') === 'any' ? '' : $args['post_type'];

		if($type){
			!post_type_exists($type) && wp_die('invalid_post_type');

			$var	= is_post_type_hierarchical($type) ? 'pagename' : 'name';
			$name	= $vars[$var] ?? '';
		}else{
			$map	= wp_list_pluck(get_post_types(['_builtin'=>false, 'query_var'=>true], 'objects'), 'query_var')+['post'=>'name', 'page'=>'pagename'];
			$type	= array_find_key($map, fn($v)=> !empty($vars[$v]));
			$name	= $type ? $vars[$map[$type]] : null;
		}

		if(!$name){
			$id		= ($args['id'] ?? 0) ?: (int)wpjam_get_parameter('id', ['required'=>true]);
			$type	??= get_post_type($id);

			(!$type || (get_post_type($id) != $type)) && wp_die('无效的参数：id', 'invalid_parameter');

			$wp->set_query_var('p', $id);
		}

		$wp->set_query_var('post_type', $type);
		$wp->query_posts();

		if(empty($id) && empty($args['post_status']) && !$query->have_posts()){
			$id	= apply_filters('old_slug_redirect_post_id', null) ?: wp_die('无效的文章 ID', 'invalid_post');

			$wp->set_query_var('post_type', 'any');
			$wp->set_query_var('p', $id);
			$wp->set_query_var('name', '');
			$wp->set_query_var('pagename', '');
			$wp->query_posts();
		}

		$parsed	= $query->have_posts() ? self::parse($query, $args) : wp_die('参数错误', 'invalid_parameter');
		$parsed	= reset($parsed);
		$output	= ($args['output'] ?? '') ?: $parsed['post_type'];

		(is_single($parsed['id']) || is_page($parsed['id'])) && wpjam_update_post_views($parsed['id']);

		return array_merge(wpjam_pull($parsed, ['share_title', 'share_image', 'share_data']), [$output => $parsed]);
	}

	public static function parse_media_json_module($args, $format=''){
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$media	= ($args['media'] ?? '') ?: 'media';
		$output	= ($args['output'] ?? '') ?: 'url';

		isset($_FILES[$media]) || wpjam_throw('invalid_parameter', '无效的参数：「'.$media.'」');

		if($format == 'media'){
			$id		= wpjam_try('media_handle_upload', $media, (int)wpjam_get_post_parameter('post_id',	['default'=>0]));
			$url	= wp_get_attachment_url($id);
			$query	= wpjam_get_image_size($id);
		}else{
			$upload	= wpjam_upload($media);
			$url	= $upload['url'];
			$query	= wpjam_get_image_size($upload['file'], 'file');
		}

		return [$output => $query ? add_query_arg($query, $url) : $url];
	}

	public static function json_modules_callback($action, $args=[]){
		$output	= $args['output'] ?? null;

		[$type, $action]	= explode('.', $action);

		if($type == 'post'){
			$type	= wpjam_get_parameter('post_type');
			$output	??= $action == 'get' ? 'post' : 'posts';
		}

		$args		= ['post_type'=>$type, 'action'=>$action, 'output'=>$output]+array_intersect_key($args, WPJAM_Post::get_default_args());
		$modules[]	= ['type'=>'post_type',	'args'=>array_filter($args, fn($v)=> !is_null($v))];

		if($action == 'list' && $type && is_string($type) && !str_contains($type, ',')){
			foreach(get_object_taxonomies($type) as $tax){
				if(is_taxonomy_hierarchical($tax) && wpjam_get_taxonomy_setting($tax, 'show_in_posts_rest')){
					$modules[]	= ['type'=>'taxonomy',	'args'=>['taxonomy'=>$tax, 'hide_empty'=>0]];
				}
			}
		}

		return $modules;
	}
}