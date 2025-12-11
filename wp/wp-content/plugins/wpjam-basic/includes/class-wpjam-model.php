<?php
abstract class WPJAM_Instance{
	use WPJAM_Call_Trait;

	protected $id;

	protected function __construct($id){
		$this->id	= $id;
	}

	abstract protected static function call_method($method, ...$args);

	public function meta_get($key){
		return wpjam_get_metadata(static::get_meta_type(), $this->id, $key);
	}

	public function meta_exists($key){
		return metadata_exists(static::get_meta_type(), $this->id, $key);
	}

	public function meta_input(...$args){
		return $args ? wpjam_update_metadata(static::get_meta_type(), $this->id, ...$args) : null;
	}

	public static function get_meta_type(){
		return static::call_method('get_meta_type');
	}

	public static function insert($data){
		return wpjam_catch(fn()=> wpjam_tap(static::call_method('insert', static::prepare_data($data, 0, $meta)), fn($id)=> $meta && wpjam_update_metadata($meta[0], $id, $meta[1])));
	}

	public static function update($id, $data){
		return wpjam_catch(fn()=> wpjam_tap(static::call_method('update', $id, static::prepare_data($data, $id, $meta)), fn()=> $meta && wpjam_update_metadata($meta[0], $id, $meta[1])));
	}

	public static function delete($id){
		return wpjam_catch(fn()=> static::before_delete($id) || true ? static::call_method('delete', $id) : null);
	}

	public static function before_delete($id){
		if(array_all(['is_deletable', 'get_instance'], fn($m)=> method_exists(static::class, $m))){
			wpjam_try(static::class.'->is_deletable', $id) || wpjam_throw('indelible', '不可删除');
		}
	}

	public static function prepare_data($data, $id=0, &$meta=null){
		method_exists(static::class, 'validate_data') && wpjam_try(fn()=> static::validate_data($data, $id));

		$type	= static::get_meta_type();
		$input	= $type ? wpjam_pull($data, 'meta_input') : [];
		$meta	= $input ? [$type, $input] : [];

		return static::sanitize_data($data, $id);
	}

	protected static function sanitize_data($data, $id=0){
		return $data;
	}

	public static function instance(...$args){
		[$key, $cb]	= count($args) == 2 && is_callable($args[1]) ? $args : [($args ? implode(':', $args) : 'singleton'), null];
		$called		= self::get_called();

		return wpjam($called, $key) ?: wpjam_tap(($cb ? $cb($key) : static::create_instance(...$args)), fn($value)=> (!is_wp_error($value) && !is_null($value)) && wpjam($called, $key, $value));
	}

	protected static function create_instance(...$args){
		return new static(...$args);
	}
}

abstract class WPJAM_Model extends WPJAM_Instance implements ArrayAccess, IteratorAggregate{
	protected $_data	= [];

	public function __construct($data=[], $id=null){
		if($id){
			$this->id		= $id;
			$this->_data	= $data ? array_diff_assoc($data, static::get($id)) : [];
		}else{
			$id		= $data[static::get_primary_key()] ?? null;
			$exist	= isset($id) ? static::get($id) : null;

			$exist && ($this->id	= $id);

			$this->_data	= $exist ? array_diff_assoc($data, $exist) : $data;
		}
	}

	public function __get($key){
		return wpjam_exists($this->get_data(), $key) ? $this->get_data()[$key] : $this->meta_get($key);
	}

	public function __isset($key){
		return wpjam_exists($this->get_data(), $key) || $this->meta_exists($key);
	}

	public function __set($key, $value){
		$this->set_data($key, $value);
	}

	public function __unset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return wpjam_exists($this->get_data(), $key);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->get_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->set_data($key, $value);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_data());
	}

	public function get_primary_id(){
		return $this->get_data(static::get_primary_key());
	}

	public function get_data($key=''){
		$data	= is_null($this->id) ? [] : static::get($this->id);
		$data	= array_merge($data, $this->_data);

		return $key ? ($data[$key] ?? null) : $data;
	}

	public function set_data($key, $value){
		if(!is_null($this->id) && static::get_primary_key() == $key){
			trigger_error('不能修改主键的值');
		}else{
			$this->_data[$key]	= $value;
		}

		return $this;
	}

	public function unset_data($key){
		$this->_data[$key]	= null;
	}

	public function reset_data($key=''){
		if($key){
			unset($this->_data[$key]);
		}else{
			$this->_data	= [];
		}
	}

	public function to_array(){
		return $this->get_data();
	}

	public function save($data=[]){
		$data	= array_merge($this->_data, $data);
		$data	= $this->id ? wpjam_except($data, static::get_primary_key()) : $data;

		return wpjam_tap($this->id ? static::update($this->id, $data) : static::insert($data), function($result){
			if(!is_wp_error($result)){
				$this->id	= $this->id ?: $result;

				$this->reset_data();
			}
		});
	}

	public static function find($id){
		return static::get_instance($id);
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'dismiss'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true, 'confirm'=>true,	'bulk'=>true,	'order'=>1],
		];
	}

	public static function get_handler(){
		return wpjam_get_handler(self::get_called()) ?: (property_exists(static::class, 'handler') ? static::$handler : null);
	}

	public static function set_handler($handler){
		return wpjam_register_handler(self::get_called(), $handler);
	}

	public static function delete_multi($ids){
		return wpjam_catch(fn()=> array_walk($ids, fn($id)=> static::before_delete($id)) || true ? static::call_method('delete_multi', $ids) : null);
	}

	public static function insert_multi($data){
		return wpjam_catch(fn()=> static::call_method('insert_multi', array_map(fn($v)=> static::prepare_data($v), $data)));
	}

	public static function get_instance($id){
		return $id ? static::instance($id, fn($id)=> static::get($id) ? new static([], $id) : null) : null;
	}

	public static function call_method($method, ...$args){
		return wpjam_call_handler(static::get_handler(), $method, ...$args);
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['item_callback', 'render_item'])){
			return $args[0];
		}

		return static::call_method(wpjam_remove_suffix($method, '_by_handler'), ...$args);
	}
}

class WPJAM_Handler{
	static $handlers	= [];

	public static function call($name, $method, ...$args){
		$method	= strtolower($method);
		$object	= is_object($name) ? $name : self::get($name);

		if(!$object){
			return new WP_Error('undefined_handler');
		}

		if($object instanceof WPJAM_DB){
			if($method == 'query'){
				if(!$args){
					return $object;
				}
			}elseif($method == 'query_items'){
				if(is_array($args[0])){
					$method		= 'query';
					$args[1]	??= 'array';
				}
			}
		}

		if(in_array($method, [
			'get_primary_key',
			'get_meta_type',
			'get_searchable_fields',
			'get_filterable_fields'
		])){
			return $object->{substr($method, 4)};
		}elseif(in_array($method, [
			'set_searchable_fields',
			'set_filterable_fields'
		])){
			return $object->{substr($method, 4)}	= $args[0];
		}

		if(!method_exists($object, $method) && try_remove_suffix($method, '_multi')){
			return wpjam_catch(fn()=> array_walk($args[0], fn($item)=> wpjam_try([$object, $method], $item)) || true);
		}

		$cb		= [$object, $method];
		$cb[1]	= ['get_ids'=>'get_by_ids', 'get_all'=>'get_results'][$cb[1]] ?? $cb[1];

		return is_callable($cb) ? wpjam_catch($cb, ...$args) : new WP_Error('undefined_method', [$method]);
	}

	public static function get($name, $args=[]){
		if($name){
			if(is_array($name)){
				$args	= $name;
				$name	= wpjam_pull($args, 'name') ?: md5(serialize($args));
			}

			return self::$handlers[$name] ?? ($args ? self::create($name, maybe_closure($args, $name)) : null);
		}
	}

	public static function create($name, $args=[]){
		if(is_array($name)){
			$args	= $name;
			$name	= wpjam_pull($args, 'name');
		}

		if(is_object($args)){
			$object	= $args;
		}elseif(!empty($args['table_name'])){
			$name	= $name ?: $args['table_name'];
			$object	= new WPJAM_DB($args['table_name'], $args);
		}elseif(!empty($args['option_name'])){
			$args	+= array_filter(['setting_name'=>wpjam_pull($args, 'items_field')]);
			$name	= $name ?: wpjam_join(':', wpjam_pick($args, ['option_name', 'setting_name']));
			$object	= new WPJAM_Items($args+['type'=>(!empty($args['setting_name']) ? 'setting' : 'option')]);
		}elseif(!empty($args['items_type']) || array_all(['get_items', 'update_items'], fn($m)=> !empty($args[$m]))){
			$object	= new WPJAM_Items($args+array_filter(['type'=>wpjam_pull($args, 'items_type')]));
		}elseif(!empty($args['items_model'])){	// 不建议
			$object	= new WPJAM_Items(wp_parse_args($args, wpjam_fill(['get_items', 'update_items'], fn($k)=> [$args['items_model'], $k])));
		}elseif(wpjam_pull($args, 'type') == 'option_items'){	// 不建议
			$object	= new WPJAM_Items(wp_parse_args($args, ['type'=>'option', 'option_name'=>$name]));
		}

		if($name && !empty($object)){
			return wpjam_tap($object, fn($v)=> self::$handlers[$name] = $v);
		}
	}
}

class WPJAM_DB extends WPJAM_Args{
	private $query_vars;

	public function __construct($table, $args=[]){
		$pk	= $args['primary_key'] ??= 'id';
		$ck	= $args['cache_key'] ?? '';
		$ck	= $args['cache_key'] = $ck == $pk ? '' : $ck;

		$this->args	= wp_parse_args($args, [
			'table'			=> $table,
			'orderby'		=> $pk,
			'cache'			=> true,
			'cache_group'	=> $table,
			'cache_time'	=> DAY_IN_SECONDS
		]);

		$this->init();

		$this->process_arg('group_cache_key', fn($v)=> array_merge((array)$v, $ck ? [$ck] : []));
	}

	public function __call($method, $args){
		if(try_remove_prefix($method, 'where_')){
			if($method == 'fragment'){
				return $this->where('', ...$args);
			}

			if(in_array($method, ['any', 'all'])){
				$where		= wpjam_map($args[0], fn($v, $k)=> is_numeric($k) ? '('.$v.')' : $this->where($k, $v, 'value'));
				$args[0]	= implode(($method == 'any' ? ' OR ' : ' AND '), array_filter($where));

				return $this->where('', ...$args);
			}

			return wpjam_get_operator($method) ? $this->where(array_shift($args).'__'.$method, ...$args) : $this;
		}elseif(array_key_exists($method, $this->query_vars)){
			$key	= $method;
			$value	= $args ? $args[0] : ($key == 'found_rows' ? true : null);

			if(!is_null($value)){
				$this->query_vars[$key]	= $value;
			}

			return $this;
		}elseif(try_remove_suffix($method, '_by_db')){
			global $wpdb;

			if($method == 'last_error'){
				return new WP_Error($args[0].'_error', $wpdb->$method);
			}

			if(in_array($method, ['insert', 'update', 'delete', 'replace'])){
				if($this->field_types){
					$wpdb->field_types	= array_merge(($types = $wpdb->field_types), $this->field_types);
				}
			}elseif(in_array($method, ['get_results', 'get_ids', 'get_row', 'get_col', 'get_var'])){
				if($method == 'get_ids'){
					$args[0]['fields']	= $this->table.'.'.$this->primary_key;

					$method	= 'get_col';
				}

				if(is_array($args[0])){
					$args[0]	= $this->get_request($args[0]+(in_array($method, ['get_row', 'get_var']) ? ['limits'=> 'LIMIT 1'] : []));
				}

				if(in_array($method, ['get_results', 'get_row'])){
					$args[1]	= ARRAY_A;
				}
			}

			try{
				return $wpdb->$method(...$args);
			}finally{
				isset($types) && ($wpdb->field_types	= $types);
			}
		}elseif(in_array($method, ['get_col', 'get_var', 'get_row', 'get_results'])){
			$clauses	= $this->get_clauses($args[0] ?? []);
			$action		= $method == 'get_results' && in_array($clauses['fields'], ['*', $this->table.'.*']) ? 'get_ids' : $method;
			$items		= [$this, $action.'_by_db']($clauses);
			$total		= $method == 'get_results' && ($args[1] ?? null) ? $this->find_total() : null;
			$items		= $action == 'get_ids' ? array_values($this->get_by_ids($items)) : $items;

			return isset($total) ? ['items'=>$items, 'total'=>$total] : $items;
		}elseif(str_contains($method, '_meta')){
			return ($object	= wpjam_get_meta_type_object($this->meta_type)) ? $object->$method(...$args) : null;
		}elseif(str_starts_with($method, 'cache_')){
			return [WPJAM_Cache::create($this), $method](...$args);
		}elseif(try_remove_suffix($method, '_last_changed')){
			$ck		= $this->group_cache_key;
			$args	= $ck && is_array($args[0] ?? '') ? wpjam_pick($args[0], $ck) : [];
			$key	= 'last_changed'.($args && count($args) == 1 && !is_array(reset($args)) ? ':'.array_key_first($args).':'.reset($args) : '');

			if($method == 'get'){
				return $this->cache_get($key) ?: wpjam_tap(microtime(), fn($value)=> $this->cache_set($key, $value));
			}

			return $method == 'delete' ? $this->cache_delete($key) : null;
		}

		return new WP_Error('undefined_method', [$method]);
	}

	protected function init(){
		$this->meta_query	= null;
		$this->query_vars	= [
			'where'		=> [],
			'limit'		=> 0,
			'offset'	=> 0,
			'orderby'	=> null,
			'order'		=> null,
			'groupby'	=> null,
			'having'	=> null,
			'found_rows'	=> false,
			'search_term'	=> null,
			'search_columns'	=> null
		];
	}

	public function find_by($field, $value, $order='ASC', $output='results'){
		return [$this, 'get_'.$output.'_by_db']([
			'where'		=> 'WHERE '.(is_array($field) ? $this->where_all($field, 'value') : $this->where($field, $value, 'value')),
			'orderby'	=> $order ? 'ORDER BY `'.$this->get_arg('orderby').'` '.$order : ''
		]);
	}

	public function find_one($value, $field='', $order=''){
		return $this->find_by($field ?: $this->primary_key, $value, $order, 'row');
	}

	public function find_total(){
		return $this->get_var_by_db("SELECT FOUND_ROWS();");
	}

	public function get($id){
		return $this->get_by($this->primary_key, $id);
	}

	public function get_by($field, $value=null, $order='ASC'){
		$pk		= $this->primary_key;
		$type	= is_array($field) ? '' : ($field == $pk ? 'primary' : (in_array($field, $this->group_cache_key) ? 'cache' : ''));
		$multi	= is_array($value);

		if($multi){
			$value	= wpjam_filter(array_filter($value), 'unique');

			if(!$value){
				return [];
			}

			if($type == 'primary'){
				$ids	= $value;
				$data	= array_filter($this->cache_get_multiple($ids) ?: [], 'is_array');
				$rest	= array_diff($ids, array_keys($data));

				if($rest){
					if($result = $this->find_by($pk, $rest)){
						$result	= array_column($result, null, $pk);
						$data	+= $result;
						$rest	= array_diff($rest, array_keys($result));

						$this->cache_set_multiple($result);
					}

					$rest && $this->cache_set_multiple(array_fill_keys($rest, []), 5);
				}

				if($data){
					$data	= wpjam_pick($data, $ids);

					$this->lazyload_meta(array_keys($data));

					wpjam_lazyload($this->lazyload_key, $data);
				}

				return $data;
			}elseif($type == 'cache'){
				$data	= $rest = $cache = $ids = [];

				foreach($value as $v){
					$result	= $this->query([$field=>$v, 'order'=>$order], 'cache');

					if($result[0] === false || !isset($result[0]['items'])){
						$rest[$v]	= $result;
					}else{
						$data[$v]	= $ids[] = $result[0]['items'];
					}
				}

				$ids	= array_merge($rest ? $this->query([$field.'__in'=>array_keys($rest), 'order'=>$order], 'ids') : [], ...$ids);
				$result	= array_values($this->get_by_ids($ids));
				$data	= array_map(fn($ids)=> $ids ? array_values($this->get_by_ids($ids)) : [], $data);

				foreach($rest as $v => $r){
					$data[$v]		= wp_list_filter($result, [$field => $v]) ?: [];
					$cache[$r[1]]	= ['data'=>['items'=>array_column($data[$v], $pk)], 'last_changed'=>$r[2]];
				}

				$cache && $this->cache_set_multiple($cache);

				return $data;
			}
		}else{
			if($type && ($queue	= $this->pending_queue)){
				$queue	= is_array($queue) ? ($queue[$field] ?? '') : ($type == 'primary' ? $queue : '');
				$queue	&& wpjam_load_pending($queue, fn($pending)=> $this->get_by($field, $pending, $order));
			}

			if($type == 'primary'){
				$id		= $value;
				$result	= $id ? $this->cache_get($id) : [];

				return $result === false ? wpjam_tap($this->find_one($id), fn($v)=> $this->cache_set($id, $v ?: [], $v ? $this->cache_time : 60)) : $result;
			}elseif($type == 'cache'){
				return $this->query([$field=>$value, 'order'=>$order], 'items');
			}
		}

		return $this->find_by($field, $value, $order);
	}

	public function get_by_values($field, $values, $order='ASC'){
		return $this->get_by($field, $values, $order);
	}

	public function get_by_ids($ids){
		return $this->get_by($this->primary_key, $ids);
	}

	public function get_ids($ids){
		return $this->get_by_ids($ids);
	}

	public function update_caches($values, $primary=false){
		return $this->get_by(($primary ? '' : $this->cache_key) ?: $this->primary_key, $values);
	}

	protected function get_clauses($fields=[]){
		$table		= $this->table;
		$key		= $this->primary_key;
		$vars		= $this->query_vars;
		$meta_query	= $this->meta_query;
		$clauses	= $meta_query ? $meta_query->get_sql($this->meta_type, $table, $key, $this) : ['where'=>''];
		$fields		= $fields ?: ($meta_query ? $table.'.*' : '*');
		$limit		= (int)$vars['limit'];
		$offset		= (int)$vars['offset'];
		$found_rows	= $limit && $vars['found_rows'];
		$groupby	= $this->group_by() ?: ($meta_query ? $table.'.'.$key : '');
		$groupby	.= $groupby && $vars['having'] ? ' HAVING '.$vars['having'] : '';
		$orderby	= $this->order_by();
		$where 		= $this->where();
		$where 		= ($where || $clauses['where']) ? ($where ?: '1=1').wpjam_pull($clauses, 'where') : '';

		return $clauses	+ [
			'found_rows'=> $found_rows ? 'SQL_CALC_FOUND_ROWS' : '',
			'fields'	=> $fields,
			'where'		=> $where	? ' WHERE '.$where : '',
			'groupby'	=> $groupby	? ' GROUP BY '.$groupby : '',
			'orderby'	=> $orderby	? ' ORDER BY '.$orderby : '',
			'limits'	=> $limit	? ' LIMIT '.($offset ?: 0).', '.$limit : ($offset ? ' OFFSET '.$offset : '')
		];
	}

	public function get_request($clauses=null){
		$clauses	??= $this->get_clauses();
		$fields 	= $clauses['fields'] ?? '';
		$fields		= is_array($fields) ? $this->format($fields, 'fields') : $fields;
		$clauses	= ['fields'=>$fields ?: '*']+$clauses;
		$clauses	= array_map(fn($k)=> $clauses[$k] ?? '', ['found_rows', 'distinct', 'fields', 'join', 'where', 'groupby', 'orderby', 'limits']);

		return sprintf("SELECT %s %s %s FROM `{$this->table}` %s %s %s %s %s", ...$clauses);
	}

	public function get_sql($fields=[]){
		return $this->get_request($this->get_clauses($fields));
	}

	public function search(...$args){
		if($args){
			return $this->search_term(...$args);
		}

		$term	= $this->query_vars['search_term'];
		$fields	= $this->query_vars['search_columns'] ?: $this->searchable_fields;

		if($term && $fields){
			$fields	= is_array($fields) ? (wp_is_numeric_array($fields) ? $fields : array_keys($fields)) : wp_parse_list($fields);

			$this->where_any(wpjam_array($fields, fn($i, $k)=> [$k.'__like', '%'.$term.'%']));
		}

		return $this;
	}

	public function group_by(...$args){
		if($args){
			return $this->groupby(...$args);
		}

		return ($by	= $this->query_vars['groupby']) ? (array_any([',', '(',], fn($v)=> str_contains($by, $v)) ? $by : $this->prepare_by_db('%i', $by)) : '';
	}

	public function order_by(...$args){
		if($args){
			return $this->orderby(...$args);
		}

		$parse	= function($by, $order){
			if($by == 'rand'){
				return 'RAND()';
			}elseif(preg_match('/RAND\(([0-9]+)\)/i', $by, $matches)){
				return $by;
			}elseif(str_contains($by, ',')){
				return $by;
			}elseif(str_contains($by, '(') && str_contains($by, ')')){
				return $by;
			}elseif(str_ends_with($by, '__in')){
				return null;
				// $field	= str_replace('__in', '', $by);
			}

			if($this->meta_query && ($clauses = $this->meta_query->get_clauses())){
				$meta_query	= reset($clauses);
				$meta_query	= in_array($by, [$meta_query['key'] ?? '', 'meta_value', 'meta_value_num']) ? $meta_query : ($clauses[$by] ?? null);

				if($meta_query){
					$by	= $meta_query['alias'].".meta_value";

					return $by == 'meta_value_num' ? $by.'+0' : (empty($meta_query['type']) ? $by : "CAST(".$by." AS {$meta_query['cast']})").' '.$order;
				}
			}

			return $this->prepare_by_db('%i', $by).' '.$order;
		};

		$vars	= $this->query_vars;
		$by		= $vars['orderby'];
		$order	= $vars['order'] ?? ($this->get_arg('order') ?: 'DESC');
		$order	= is_string($order) && 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';

		if(isset($by)){
			return $by ? (is_array($by) ? implode(', ', wpjam_array($by, fn($k, $v)=> [null, $parse($k, $v)], true)) : $parse($by, $order)) : '';
		}else{
			return ($by	= $vars['groupby'] ? '' : $this->get_arg('orderby')) ? $by.' '.$order : '';
		}
	}

	public function insert($data){
		$wpdb	= $GLOBALS['wpdb'];
		$multi	= wp_is_numeric_array($data);

		[$data,$id]	= $multi ? [array_filter($data), null] : [array_filter($data, fn($v)=> !is_null($v)), $data[$this->primary_key] ?? null];

		if($multi && !$data){
			return 0;
		}

		$this->clear([], $data);

		if($id || $multi){
			$wpdb->check_current_query = false;

			$data	= $multi ? $data : [$data];
			$fields	= $this->format(array_keys(reset($data)), 'fields');
			$update	= implode(', ', array_map(fn($v)=> $v.' = VALUES('.$v.')', explode(', ', $fields)));
			$values	= implode(', ', array_map(fn($v)=> '('.$this->format($v, 'values').')', $data));
			$result	= $this->query_by_db("INSERT INTO `{$this->table}` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$update}");
		}else{
			$result	= $this->insert_by_db($this->table, $data);
		}

		return $result === false ? $this->last_error_by_db('insert') : ($multi ? $result : wpjam_tap($id ?: $wpdb->insert_id, fn()=> $this->cache_delete($id)));
	}

	public function insert_multi($data){
		return $this->insert(array_values(array_filter($data)));	// 自增的情况可能无法无法删除缓存，请注意
	}

	/*
	update($id, $data);
	update($data, $where);
	update($data);
	*/
	public function update(...$args){
		if(!$args){
			return 0;
		}

		[$id, $data, $where]	= count($args) == 1 ? [null, ...$args, $this->where()] : (is_array($args[0]) ? [null, ...$args] : [$args[0], $args[1], null]);

		if(!$data || (isset($where) && !$where)){
			return 0;
		}

		$this->clear($id, $data, $where);

		if(count($args) >= 2){
			$result	= $this->update_by_db($this->table, $data, $where ?? [$this->primary_key => $id]);
		}else{
			$data	= $this->format($data, 'data');
			$result	= $this->query_by_db("UPDATE `{$this->table}` SET {$data} WHERE {$where}");
		}

		return $result === false ? $this->last_error_by_db('update') : $result;
	}

	/*
	delete($where);
	delete($id);
	delete();
	*/
	public function delete(...$args){
		[$id, $where]	= $args ? (wpjam_is_assoc_array($args[0]) ? [null, $args[0]] : [$args[0], null]) : [null, $this->where()];

		if(isset($where) && !$where){
			return 0;
		}

		$this->clear($id, [], $where);

		$key	= $this->primary_key;
		$where	??= is_array($id) ? $this->where($key, $id, 'value') : [$key => $id];

		if(is_array($where)){
			$result	= $this->delete_by_db($this->table, $where);
		}else{
			$result = $this->query_by_db("DELETE FROM `{$this->table}` WHERE {$where}");
		}

		if($result === false){
			return $this->last_error_by_db('delete');
		}

		if($id){
			wpjam_map((array)$id, [$this, 'delete_meta_by_id']);
		}else{
			$this->delete_orphan_meta($this->table, $key);
		}

		return $result;
	}

	public function delete_by($field, $value){
		return $this->delete([$field => $value]);
	}

	public function delete_multi($ids){
		return $ids ? $this->delete(array_values(array_filter($ids))) : 0;
	}

	protected function clear($ids=[], $data=[], $where=[]){
		$this->delete_last_changed();

		if(($ck = $this->group_cache_key) || $this->cache){
			$ids	= (array)$ids;
			$pk		= $this->primary_key;
			$where	= $where ? [is_array($where) ? $this->where_all($where, 'value') : $where] : [];
			$data	= wp_is_numeric_array($data) ? $data : [$data];
			$ids	= array_merge($ids, array_column($data, $pk));

			$ids && $this->cache_delete_multiple($ids);
			$ids && $ck && ($where[] = $this->where($pk, $ids, 'value'));

			if($where){
				if($result	= $this->get_results_by_db([
					'fields'	=> [$pk, ...$ck],
					'where'		=> 'WHERE '.$this->where_any($where, 'value')
				])){
					$this->cache_delete_multiple(array_column($result, $pk));

					$ck && ($data	= array_merge($data, $result));
				}
			}

			$ck && wpjam_map($ck, fn($k)=> wpjam_map(array_unique(array_column($data, $k)), fn($v)=> $this->delete_last_changed([$k => $v])));
		}
	}

	protected function format($field, ...$args){
		$types	= $this->field_types ?: [];

		if(is_array($field)){
			$type	= $args[0];
			$query	= $args = [];

			if($type == 'fields'){
				$query	= array_fill(0, count($field), '%i');
				$args	= $field;
			}else{
				foreach($field as $k => $v){
					$query[]	= ($type == 'data' ? "%i = " : '').(is_null($v) ? 'NULL' : ($types[$k] ?? '%s'));
					$args		= array_merge($args, ($type == 'data' ? [$k] : []), (is_null($v) ? [] : [$v]));
				}
			}

			$query	= implode(', ', $query);
		}else{
			[$op, $value]	= $args;

			$format	= $types[$field] ?? '%s';
			
			if(in_array($op, ['IN', 'NOT IN'])){
				$value	= is_array($value) ? $value : array_map('trim', explode(',', $value));

				if(count($value) <= 1){
					$value	= $value ? reset($value) : '';
					$op		= $op == 'IN' ? '=' : '!=';
				}else{
					$format	= '('.implode(', ', array_fill(0, count($value), $format)).')';
				}
			}elseif(in_array($op, ['LIKE', 'NOT LIKE'])){
				$left	= try_remove_prefix($value, '%') ? '%' : '';
				$right	= try_remove_suffix($value, '%') ? '%' : '';
				$value	= $left.$this->esc_like_by_db($value).$right;
				$format	= '%s';
			}

			$args	= [$field, ...(array)$value];
			$query	= '%i '.$op.' '.$format;
		}

		return $args ? $this->prepare_by_db($query, $args) : $query;
	}

	public function where(...$args){
		if(!$args){
			return wpjam_tap(implode(' AND ', $this->search()->query_vars['where']), fn()=> $this->init());
		}

		[$field, $value, $output]	= $args+['', null, 'object'];

		if(!$field){
			$value	= $value ? '('.$value.')' : '';
		}elseif(isset($value)){
			if(str_contains($field, '__')){
				$parts	= explode('__', $field);

				if($op	= wpjam_get_operator($parts[1])){
					$field		= $parts[0];
					$compare	= $op;
				}
			}

			$value	= $this->format($field, $compare ?? (is_array($value) ? 'IN' : '='), $value);
		}

		if($output == 'object'){
			if($value){
				$this->query_vars['where'][]	= $value;
			}

			return $this;
		}

		return $value;
	}

	public function query($vars, $output='object'){
		if(in_array($output, ['cache', 'items', 'ids'])){
			$vars	+= ['no_found_rows'=>true, 'suppress_filters'=>true];
		}else{
			$vars	= apply_filters('wpjam_query_vars', $vars, $this);
			$vars	= isset($vars['groupby']) ? ['no_found_rows'=>true]+wpjam_except($vars, ['since', 'cursor']) : $vars;
			$vars	+= empty($vars['no_found_rows']) ? ['number'=>50] : [];
		}

		$pk			= $this->primary_key;
		$qv			= $vars;
		$orderby	= $qv['orderby'] ?? $this->get_arg('orderby');
		$fields		= wpjam_pull($qv, 'fields');
		$cache		= wpjam_pull($qv, 'cache_results', $output != 'ids') || $output == 'cache';
		$suppress	= wpjam_pull($qv, 'suppress_filters');
		$found_rows	= !wpjam_pull($qv, 'no_found_rows');

		if($this->meta_type && ($meta_query = wpjam_pull($qv, ['meta_key', 'meta_value', 'meta_compare', 'meta_compare_key', 'meta_type', 'meta_type_key', 'meta_query']))){
			$this->meta_query	= new WP_Meta_Query();
			$this->meta_query->parse_query_vars($meta_query);
		}

		foreach($qv as $k => $v){
			if(is_null($v)){
				continue;
			}

			if(array_key_exists($k, $this->query_vars)){
				$this->query_vars[$k]	= $v;
			}elseif($k == 'number'){
				if($v == -1){
					$found_rows	= false;
				}else{
					$this->limit($v);
				}
			}elseif(in_array($k, ['s', 'search'])){
				$this->search($v);
			}elseif(in_array($k, ['cursor', 'since'])){
				$v > 0 && $this->where($orderby.'__'.($k == 'cursor' ? 'lt' : 'gt'), $v);
			}elseif(in_array($k, ['exclude', 'include'])){
				$v && is_array($v) && $this->where($pk.'__'.($k == 'include' ? 'in' : 'not_in'), $v);
			}else{
				$this->where($k, $v);
			}
		}

		$found_rows	&& $this->found_rows();

		$clauses	= $this->get_clauses($fields);
		$clauses	= $suppress ? $clauses : apply_filters('wpjam_clauses', $clauses, $this);
		$request	= $this->get_request($clauses);
		$cache		= $cache && !str_contains(strtoupper($orderby), ' RAND(') && in_array($clauses['fields'], ['*', $this->table.'.*']);
		$data		= false;

		if($cache){
			$vars	= map_deep($vars, 'strval');
			$lc		= $this->get_last_changed($vars);
			$ck		= md5(serialize($vars).$this->remove_placeholder_escape_by_db($request));
			$result	= $this->cache_get($ck);
			$data	= $result && is_array($result) && ($result['last_changed'] ?? '') == $lc ? $result['data'] : false;

			if($output == 'cache'){
				return [$data, $ck, $lc];
			}
		}

		if($data === false || !isset($data['items'])){
			$items	= ($cache || $output == 'ids') ? $this->get_ids_by_db($clauses) : $this->get_results_by_db($request);
			$data	= ['items'=>$items]+($found_rows ? ['total'=>$this->find_total()] : []);

			$cache && $this->cache_set($ck, ['data'=>$data, 'last_changed'=>$lc]);
		}

		if($output == 'ids'){
			return $data['items'];
		}

		if($cache){
			$data['items']	= array_values($this->get_by_ids($data['items']));
		}

		if($output == 'items'){
			return $data['items'];
		}

		if($found_rows){
			$data['next_cursor']	= 0;

			if(!empty($qv['number'])){
				$data['max_num_pages']	= ceil($data['total'] / $qv['number']);
				$data['next_cursor']	= $data['items'] && $data['max_num_pages'] > 1 ? (int)(end($data['items'])[$orderby]) : 0;
			}
		}else{
			$data['total']	= count($data['items']);
		}

		$data['datas'] 		= &$data['items'];	// 兼容
		$data['found_rows']	= &$data['total'];	// 兼容
		$data['request']	= $request;

		return $output == 'object' ? (object)$data : $data;
	}

	// 以下函数放弃
	public function query_items($limit, $offset){
		wpjam_map(['orderby'=>'orderby', 'order'=>'order', 's'=>'search_term'], fn($v, $k)=> is_null($this->$v) && $this->$v(wpjam_get_data_parameter($k)));

		wpjam_map($this->filterable_fields ?: [], fn($key)=> $this->where($key, wpjam_get_data_parameter($key)));

		return $this->limit($limit)->offset($offset)->found_rows()->get_results([], true);
	}

	public function get_one_by($field, $value, $order='ASC'){
		return ($items = $this->get_by($field, $value, $order)) ? reset($items) : [];
	}

	public function find($fields=[]){
		trigger_error('find');
		return $this->get_results($fields);
	}

	public function cache_delete_by($field, $value, $order='ASC'){	// del 2026-06-30
		trigger_error('cache_delete_by');

		in_array($field, $this->group_cache_key) && wpjam_map((array)$value, fn($v)=> $this->cache_delete($this->query([$field=>$v, 'order'=>$order], 'cache')[1]));
	}

	public function get_wheres(){
		return $this->where();
	}
}

class WPJAM_Items extends WPJAM_Args{
	public function __construct($args=[]){
		$this->args	= wp_parse_args($args, $this->parse_by_type($args) ?: [])+['item_type'=>'array'];

		if($this->item_type == 'array'){
			$this->primary_key	??= 'id';
		}
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_items')){
			$result	= $this->$method ? $this->call($method.'_by_prop', ...$args) : ($method == 'delete_items' ? $this->update_items([]) : true);

			return $method == 'get_items' ? ($result ?: []) : $result;
		}elseif(str_contains($method, '_setting')){
			if($this->option_name){
				$i		= str_starts_with($method, 'update_') ? 2 : 1;
				$args	= (isset($args[$i]) && !is_numeric($args[$i])) ? array_slice($args, 0, $i) : $args;

				return ('wpjam_'.$method)($this->option_name, ...$args);
			}
		}elseif(in_array($method, ['increment', 'decrement'])){
			if($this->item_type == 'array'){
				return;
			}

			[$k, $v]	= array_pad($args, 2, 1);

			$v	= (int)$this->get($k)+($method == 'increment' ? $v : (0-$v));

			return wpjam_tap($v, fn()=> $this->process(fn($items)=> wpjam_set($items, $k, $v)));
		}elseif(in_array($method, ['insert', 'add', 'update', 'replace', 'set', 'delete', 'remove'])){
			return wpjam_retry($this->retry_times ?: 1, fn()=> $this->retry($method, $args));
		}
	}

	protected function retry($method, $args){
		$items	= $this->get_items();
		$type	= $this->item_type;
		$key	= $this->primary_key;
		$uk 	= $this->unique_key;
		$title	= $this->primary_title ?: 'ID';
		$add	= $method == 'insert' || ($method == 'add' && count($args) <= 1);
		$id		= $add ? null : array_shift($args);

		if(!$add){
			$id || wpjam_throw('empty_'.($key ?: 'id'), ($key ? $title : 'ID').'不能为空');

			$exist	= isset($items[$id]);

			if($method == 'set'){
				if(!$exist){
					$method	= 'add';
				}
			}else{
				if($exist === ($method == 'add')){
					wpjam_throw(($exist ? 'duplicate_' : 'invalid_').($key ?: 'id'), ($key ? $title : 'ID').'-「'.$id.'」'.($exist ? '已存在' : '不存在'));
				}
			}
		}

		if(in_array($method, ['delete', 'remove'])){
			unset($items[$id]);
		}else{
			if(!$args || ($type == 'array' && !is_array($args[0]))){
				wpjam_throw(...($args ? ['invalid_item', '不是数组'] : ['empty_item', '不能为空']));
			}

			$item	= array_shift($args);

			if($type == 'array'){
				if($uk && ($add || isset($item[$uk]))){
					$uv		= $item[$uk] ?? '';
					$blank	= !$uv && !is_numeric($uv);

					if($blank || array_find($items, fn($v, $k)=> ($add || $id != $k) && $v[$uk] == $uv)){
						wpjam_throw(($blank ? 'empty_' : 'duplicate_').$uk, ($this->unique_title ?: $uk).($blank ? '不能为空' : '不能重复'));
					}
				}

				if($add){
					if(in_array($key, ['option_key', 'id'])){
						$id	= $items ? max(array_map(fn($id)=> (int)str_replace('option_key_', '', $id), array_keys($items)))+1 : 1;
						$id	= $key == 'option_key' ? 'option_key_'.$id : $id;
					}else{
						$id	= $item[$key] ?? '';

						(!$id || isset($items[$id])) && wpjam_throw(($id ? 'duplicate_' : 'empty_').$key, $title.($id ? '不能重复' : '不能为空'));
					}
				}

				$item	= [$key=>$id]+$item;
			}else{
				$add && in_array($item, $items) && wpjam_throw('duplicate_item', '不能重复');
			}
		}

		if(in_array($method, ['add', 'insert'])){
			$this->max_items && count($items) >= $this->max_items && wpjam_throw('over_max_items', '最大允许数量：'.$this->max_items);

			$last	= $this->last ?? ($method == 'add');

			if($type == 'array' || $id){
				$item	= $type == 'array' ? array_filter($item, fn($v)=> !is_null($v)) : $item;
				$items	= $last ? array_replace($items, [$id=>$item]) : [$id=>$item]+$items;
			}else{
				$last ? array_push($items, $item) : array_unshift($items, $item);
			}
		}elseif(in_array($method, ['set', 'update', 'replace'])){
			$items[$id]	= $method == 'update' && $type == 'array' ? wp_parse_args($item, $items[$id]) : $item;
		}

		if($type == 'array' && $items && in_array($key, ['option_key','id'])){
			$except	= array_filter([$key, $this->parent_key]);
			$items	= wpjam_map($items, fn($item)=> wpjam_except($item, $except));
		}

		$result	= $this->update_items($items);

		return $result && $method == 'insert' && $type == 'array' ? ['id'=>$id, 'last'=>(bool)$last] : $result;
	}

	public function process($cb){
		return is_closure($cb) ? wpjam_retry($this->retry_times ?: 1, fn()=> $this->update_items($this->call($cb, $this->get_items()))) : null;
	}

	public function query_items($args){
		$items	= $this->parse_items();
		$s		= trim(wpjam_pull($args, 's') ?: '');
		$number	= wpjam_pull($args, 'number') ?: 50;
		$offset	= wpjam_pull($args, 'offset') ?: 0;
		$items	= $args ? array_filter($items, fn($item)=> wpjam_matches($item, $args)) : $items;
		$items	= $s ? array_filter($items, fn($item)=> array_any($item, fn($v)=> str_contains($v, $s))) : $items;
		$items	= array_values($items);

		return ['total'=>count($items), 'items'=>array_slice($items, $offset, $number)];
	}

	public function parse_items($items=null){
		$items	??= $this->get_items();
		$items	= $items && is_array($items) ? wpjam_map($items, [$this, 'parse_item']) : [];

		return wpjam_tap($items, fn($v)=> $v && $this->item_type == 'array' && wpjam_lazyload($this->lazyload_key, $v));
	}

	public function parse_item($item, $id){
		return $this->item_type == 'array' ? [$this->primary_key => $id]+(is_array($item) ? $item : []) : $item;
	}

	public function get_results(){
		return $this->parse_items();
	}

	public function reset(){
		return $this->delete_items();
	}

	public function empty(){
		return wpjam_tap($this->get_items(), fn($v)=> $v && $this->delete_items());
	}

	public function move($id, $data){
		return $this->process(fn($items)=> wpjam_pick($items, wpjam_move(array_keys($items), $id, $data)));
	}

	public function exists($item, $type='unique'){
		$items	= $this->get_items();
		$items	= $this->item_type == 'array' ? ($type == 'unique' && $this->unique_key ? array_column($items, $this->unique_key) : array_keys($items)) : $items;

		return in_array($item, $items);
	}

	public function get($id){
		$items	= $this->get_items();

		return isset($items[$id]) ? $this->parse_item($items[$id], $id) : null;
	}

	protected static function parse_by_type($args){
		$type	= wpjam_pull($args, 'type');

		if($type == 'option'){
			if(!empty($args['option_name'])){
				return [
					'primary_key'	=> 'option_key',
					'get_items'		=> fn()=> get_option($this->option_name),
					'update_items'	=> fn($items)=> update_option($this->option_name, $items),
				];
			}
		}elseif($type == 'setting'){
			if(array_all(['option_name', 'setting_name'], fn($k)=> !empty($args[$k]))){
				return [
					'get_items'		=> fn()=> wpjam_get_setting($this->option_name, $this->setting_name),
					'update_items'	=> fn($items)=> wpjam_update_setting($this->option_name, $this->setting_name, $items),
				];
			}
		}elseif($type == 'meta'){
			if(array_all(['meta_type', 'meta_key', 'object_id'], fn($k)=> !empty($args[$k]))){
				return [
					'parent_key'	=> $args['meta_type'].'_id',
					'get_items'		=> fn()=> get_metadata($this->meta_type, $this->object_id, $this->meta_key, true),
					'delete_items'	=> fn()=> delete_metadata($this->meta_type, $this->object_id, $this->meta_key),
					'update_items'	=> fn($items)=> update_metadata($this->meta_type, $this->object_id, $this->meta_key, $items, $this->get_items()),
				];
			}
		}elseif($type == 'cache'){
			if(!empty($args['cache_key'])){
				return [
					'item_type'		=> '',
					'retry_times'	=> 10,
					'cache_object'	=> wpjam_cache(wp_parse_args($args, ['group'=>'list_cache'])),
					'update_items'	=> fn($items)=> $this->cache_object->cas($this->cas_token, $this->cache_key, $items),
					'get_items'		=> function(){
						$object	= $this->cache_object;
						$key	= $this->cache_key;
						$items	= $object->get_with_cas($key, $token);

						if($items === false){
							$object->set($key, []);

							$items	= $object->get_with_cas($key, $token);
						}

						$this->cas_token	= $token;

						return $items;
					},
					
				];
			}
		}elseif($type == 'transient'){
			if(!empty($args['transient'])){
				return [
					'item_type'		=> '',
					'get_items'		=> fn()=> get_transient($this->transient),
					'update_items'	=> fn($items)=> set_transient($this->transient, $items, DAY_IN_SECONDS),
				];
			}
		}elseif($type == 'post_content'){
			if(!empty($args['post_id'])){
				return [
					'parent_key'	=> 'post_id',
					'object'		=> wpjam_get_post_object($args['post_id']),
					'get_items'		=> fn()=> $this->object->get_unserialized(),
					'update_items'	=> fn($items)=> $this->object->save(['content'=>$items ?: ''])
				];
			}
		}elseif($type){
			if($parser	= wpjam_pull($args, 'parser')){
				return $parser($args, $type);
			}
		}
	}
}