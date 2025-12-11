<?php
if(!class_exists('WP_List_Table')){
	include ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

class WPJAM_List_Table extends WP_List_Table{
	use WPJAM_Call_Trait;

	public function __construct($args=[]){
		add_screen_option('list_table', ($GLOBALS['wpjam_list_table']	= wpjam_admin('list_table', $this)));

		wp_doing_ajax() && wpjam_get_post_parameter('action_type') == 'query_items' && ($_REQUEST	= $this->get_data()+$_REQUEST);	// 兼容

		$this->screen	= $screen = get_current_screen();
		$this->_args	= $args+['screen'=>$screen];

		array_map([$this, 'component'], ['action', 'view', 'column']);

		wpjam_admin('style', $this->style);
		wpjam_admin('vars[list_table]', fn()=> $this->get_setting());
		wpjam_admin('vars[page_title_action]', fn()=> $this->get_action('add', ['class'=>'page-title-action']) ?: '');

		add_filter('views_'.$screen->id, [$this, 'filter_views']);
		add_filter('bulk_actions-'.$screen->id, [$this, 'filter_bulk_actions']);
		add_filter('manage_'.$screen->id.'_sortable_columns', [$this, 'filter_sortable_columns']);

		$this->builtin ? $this->page_load() : parent::__construct($this->_args);
	}

	public function __get($name){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name;
		}

		if(isset($this->_args[$name])){
			return $this->_args[$name];
		}

		if(in_array($name, ['primary_key', 'actions', 'views', 'fields', 'filterable_fields', 'searchable_fields'])){
			$value	= wpjam_trap(in_array($name, ['actions', 'views', 'fields']) ? [$this, 'get_'.$name.'_by_model'] : $this->model.'::get_'.$name, []) ?: [];

			if($name == 'primary_key'){
				return $this->$name	= $value ?: 'id';
			}elseif($name == 'fields'){
				return $this->$name = WPJAM_Fields::parse($value, true);
			}elseif($name == 'filterable_fields'){
				$fields	= wpjam_filter($this->fields, ['filterable'=>true]);
				$views	= array_keys(wpjam_filter($fields, ['type'=>'view']));
				$fields	= wpjam_map(wpjam_except($fields, $views), fn($v)=> wpjam_except($v, ['title', 'before', 'after', 'required', 'show_admin_column'])+[($v['type'] === 'select' ? 'show_option_all' : 'placeholder') => $v['title'] ?? '']);

				return $this->$name = $fields+($fields && !$this->builtin && $this->sortable_columns ? [
					'orderby'	=> ['options'=>[''=>'排序']+wpjam_map(array_intersect_key($this->columns, $this->sortable_columns), 'wp_strip_all_tags')],
					'order'		=> ['options'=>['desc'=>'降序','asc'=>'升序']]
				] : [])+array_fill_keys(array_merge($value, $views), []);
			}

			return $value;
		}elseif(in_array($name, ['form_data', 'params', 'left_data'])){
			$left	= $name == 'left_data';
			$fields	= $left ? $this->left_fields : array_filter($this->filterable_fields);
			$data	= ($name == 'form_data' || wp_doing_ajax()) ? wpjam_get_post_parameter($left ? 'params' : $name) : wpjam_get_parameter();
			$value	= $data && $fields ? wpjam_trap([wpjam_fields($fields), 'validate'], wp_parse_args($data), []) : [];

			return $this->$name	= array_filter($value, fn($v)=> isset($v) && $v !== [])+($left ? [] : (wpjam_admin('chart', 'get_data', ['data'=>$data]) ?: []));
		}
	}

	public function __set($name, $value){
		return in_array($name, $this->compat_fields, true) ? ($this->$name	= $value) : ($this->_args[$name]	= $value);
	}

	public function __isset($name){
		return $this->$name !== null;
	}

	public function __call($method, $args){
		if($method == 'get_arg'){
			return wpjam_get($this->_args, ...$args);
		}elseif($method == 'get_data'){
			return wpjam_get_data_parameter(...$args);
		}elseif($method == 'get_date'){
			$type	= array_find(['prev', 'next', 'current'], fn($v)=> in_array($v, $args, true));

			if($type == 'current'){
				[$year, $month]	= array_map('wpjam_date', ['Y', 'm']);
			}else{
				$year	= clamp((int)$this->get_data('year') ?: wpjam_date('Y'), 1970, 2200);
				$month	= clamp((int)$this->get_data('month') ?: wpjam_date('m'), 1, 12);
				$month	+= $type ? ($offset = $type == 'prev' ? -1 : 1) : 0;

				if(in_array($month, [0, 13])){
					$year	+= $offset;
					$month	= abs($month-12);
				}
			}

			return in_array('locale', $args) ? sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($month), $year) : compact('year', 'month');
		}elseif($method == 'add'){
			$this->_args	= wpjam_set($this->_args, array_shift($args).'['.(count($args) >= 2 ? array_shift($args) : '').']', $args[0]);

			return $args[0];
		}elseif(try_remove_suffix($method, '_by_model')){
			return method_exists($this->model, $method) ? $this->catch($method.'_by_model', ...$args) : ($method == 'get_actions' ? ($this->builtin ? [] : WPJAM_Model::get_actions()) : ($method == 'get_views' ? $this->views_by_model() : null));
		}elseif(try_remove_prefix($method, 'filter_')){
			if($method == 'table'){
				return wpjam_preg_replace('#<tr id="'.$this->singular.'-(\d+)"[^>]*>(.+?)</tr>#is', fn($m)=> $this->filter_single_row($m[0], $m[1]), $args[0]);
			}elseif($method == 'single_row'){
				return wpjam_do_shortcode(apply_filters('wpjam_single_row', ...$args), [
					'filter'		=> fn($attr, $title)=> $this->get_filter_link($attr, $title, wpjam_pull($attr, 'class')),
					'row_action'	=> fn($attr, $title)=> $this->get_row_action($args[1], (is_blank($title) ? [] : compact('title'))+$attr)."\n"
				]);
			}elseif($method == 'custom_column'){
				return count($args) == 2 ? wpjam_echo($this->column_default([], ...$args)) : $this->column_default(...$args);
			}

			$value	= $this->$method ?: [];

			if($method == 'columns'){
				return wpjam_except(($args ? wpjam_add_at($args[0], -1, $value) : $value), wpjam_admin('removed_columns[]'));
			}elseif($method == 'row_actions'){
				$args[1]= $this->layout == 'calendar' ? $args[1] : ['id'=>$this->parse_id($args[1])];
				$value	= $args[0]+$this->get_actions(array_diff($value, $this->next_actions ?: []), $args[1]);
				$value	+= $this->builtin ? wpjam_pull($value, ['delete', 'trash', 'spam', 'remove', 'view']) : [];

				return wpjam_except($value+($this->primary_key == 'id' || $this->builtin ? ['id'=>'ID: '.$args[1]['id']] : []), wpjam_admin('removed_actions[]'));
			}

			return array_merge($args[0], $value);
		}

		return parent::__call($method, $args);
	}

	public function __invoke($data){
		$type	= $data['action_type'];
		$action	= $data['list_action'] ?? '';
		$parts	= parse_url(wpjam_get_referer() ?: wp_die('非法请求'));

		if($parts['host'] == $_SERVER['HTTP_HOST']){
			$_SERVER['REQUEST_URI']	= $parts['path'];
		}

		$parse	= function($data) use(&$parse){
			if($data['type'] == 'items'){
				if(isset($data['items'])){
					$data['items']	= wpjam_map($data['items'], fn($item, $id)=> $parse(array_merge($item, ['id'=>$id])));
				}
			}elseif($data['type'] == 'list'){
				if($this->layout == 'left' && !isset($this->get_data()[$this->left_key])){
					$data['left']	= $this->ob_get('col_left');
				}
			}elseif($data['type'] != 'delete'){
				if($this->layout == 'calendar'){
					if(!empty($data['data'])){
						$data['data']	= wpjam_map(($data['data']['dates'] ?? $data['data']), fn($v, $k)=> $this->ob_get('single_date', $v, $k));
					}
				}elseif(!empty($data['bulk'])){
					$this->get_by_ids_by_model($ids	= array_filter($data['ids']));

					$data['data']	= array_map(fn($id)=> ['id'=>$id, 'data'=>$this->ob_get('single_row', $id)], $ids);
				}elseif(!empty($data['id'])){
					$data['data']	= $this->ob_get('single_row', $data['id']);
				}
			}

			return $data;
		};

		if($type == 'query_items'){
			if(count($data = $this->get_data()) == 1 && isset($data['id'])){
				return $parse(['type'=>'add']+$data);
			}

			$result	= ['type'=>'list'];
		}else{
			$result	= ($this->get_action($action) ?: wp_die('无效的操作'))($type);
		}

		if(!in_array($result['type'], ['form', 'append', 'redirect', 'move', 'up', 'down'])){
			$this->prepare_items();

			$result	= $parse($result)+['params'=>$this->params, 'setting'=>$this->get_setting(), 'views'=>$this->ob_get('views'), 'search_box'=>$this->get_search_box()];

			$result	+= $result['type'] == 'list' ? ['table'=>$this->get_table()] : ['tablenav'=>wpjam_fill(['top', 'bottom'], fn($which)=>$this->ob_get('display_tablenav', $which))];
		}

		return $result;
	}

	protected function component($type, ...$args){
		if($args){
			return $this->objects[$type][$args[0]] ?? array_find($this->objects[$type], fn($v)=> $v->name == $args[0]);
		}

		$args	= WPJAM_Data_Type::prepare($this);

		if($type == 'action'){
			if($this->sortable){
				$sortable	= is_array($this->sortable) ? $this->sortable : [];
				$action		= (wpjam_pull($sortable, 'action') ?: [])+wpjam_except($sortable, ['items']);

				$this->sortable	= ['items'=> $sortable['items'] ?? ' >tr'];
				$this->actions	+= wpjam_map([
					'move'	=> ['page_title'=>'拖动',	'dashicon'=>'move'],
					'up'	=> ['page_title'=>'向上移动',	'dashicon'=>'arrow-up-alt'],
					'down'	=> ['page_title'=>'向下移动',	'dashicon'=>'arrow-down-alt'],
				], fn($v)=> $action+$v+['direct'=>true]);
			}

			$name			= 'update_setting';
			$this->actions	+= $this->$name ? [$name => (is_array($this->$name) ? $this->$name : [])+['page_title'=>'全局设置', 'title'=>'设置', 'class'=>'button-primary', $name=>true]] : [];

			$meta_type	= WPJAM_Meta_Type::get(wpjam_admin('meta_type'));
			$meta_type	&& $meta_type->register_actions(wpjam_except($args, 'data_type'));
		}elseif($type == 'column'){
			if($this->layout == 'calendar'){
				$start	= (int)get_option('start_of_week');
				$locale	= $GLOBALS['wp_locale'];

				for($i=$start; $i<$start+7; $i++){
					$this->add('columns', 'day'.($i%7), $locale->get_weekday_abbrev($locale->get_weekday($i%7)));
				}

				return wpjam_map(['year', 'month'], fn($v)=> $this->add('query_args', $v));
			}

			$this->bulk_actions && !$this->builtin && $this->add('columns', 'cb', true);

			$no	= $this->numberable; 
			$no && $this->add('columns', 'no', $no === true ? 'No.' : $no) && wpjam_admin('style', '.column-no{width:42px;}');
		}

		$class	= 'WPJAM_List_Table_'.$type;
		[$class, 'registers']($type == 'column' ? $this->fields : $this->{$type.'s'});

		$args	= array_map(fn($v)=> ['value'=>$v, 'if_null'=>true, 'callable'=>true], $args);
		$args	+= $type == 'action' ? ['calendar'=> $this->layout == 'calendar' ? true : ['compare'=>'!==', 'value'=>'only']] : [];

		foreach($this->add('objects', $type, [$class, 'get_registereds']($args)) as $object){
			$key	= $object->name;

			if($type == 'action'){
				if($object->overall){
					$this->add('overall_actions', $key);
				}else{
					$object->bulk && $object->is_allowed() && $this->add('bulk_actions', $key, $object);
					$object->row_action && $this->add('row_actions', $key);
				}

				$object->next && $this->add('next_actions', $key, $object->next);
			}elseif($type == 'view'){
				$view	= $object->parse();
				$view	&& $this->add('views', $key, is_array($view) ? $this->get_filter_link(...$view) : $view);
			}else{
				$data	= array_filter($object->pick(['description', 'sticky', 'nowrap', 'format', 'precision', 'conditional_styles']));

				$this->add('columns', $key, $object->title.($data ? wpjam_tag('i', ['data'=>$data]) : ''));

				$object->sortable && $this->add('sortable_columns', $key, [$key, true]);

				wpjam_admin('style', $object->style);
			}
		}
	}

	protected function get_setting(){
		$s	= $this->get_data('s');

		return wpjam_pick($this, ['sortable', 'layout', 'left_key'])+[
			'subtitle'	=> $this->get_subtitle_by_model().($s ? sprintf(__('Search results for: %s'), '<strong>'.esc_html($s).'</strong>') : ''),
			'summary'	=> $this->get_summary_by_model(),

			'column_count'		=> $this->get_column_count(),
			'bulk_actions'		=> wpjam_map($this->bulk_actions ?: [], fn($object)=> array_filter($object->generate_data_attr(['bulk'=>true]))),
			'overall_actions'	=> array_values($this->get_actions(array_diff($this->overall_actions ?: [], $this->next_actions ?: []), ['class'=>'button overall-action']))
		];
	}

	protected function get_actions($names, $args=[]){
		return wpjam_fill($names ?: [], fn($k)=> $this->get_action($k, $args));
	}

	public function get_action($name, ...$args){
		return ($object = $this->component('action', $name)) && $args ? $object->render($args[0]) : $object;
	}

	public function get_row_action($id, $args=[]){
		return $this->get_action(...(isset($args['name']) ? [wpjam_pull($args, 'name'), $args+['id'=>$id]] : [$id, $args]));
	}

	public function get_filter_link($filter, $label, $attr=[]){
		$filter	+= $this->get_data($this->query_args ?: []);

		return wpjam_tag('a', $attr, $label)->add_class('list-table-filter')->data('filter', $filter ?: new stdClass());
	}

	public function single_row($item){
		if($this->layout == 'calendar'){
			return wpjam_echo(wpjam_tag('tr')->append(wpjam_map($item, fn($date, $day)=> ['td', ['id'=>'date_'.$date, 'class'=>'column-day'.$day], $this->ob_get('single_date', $this->calendar[$date] ?? [], $date)])));
		}

		$item	= ($item instanceof WPJAM_Register) ? $item->to_array() : (is_array($item) ? $item : wpjam_trap($this->model.'::get', $item, wp_doing_ajax() ? 'throw' : []));

		if(!$item){
			return;
		}

		$raw	= (array)$item;
		$id		= $this->parse_id($item);
		$attr	= $id ? ['id'=>$this->singular.'-'.str_replace('.', '-', $id), 'data'=>['id'=>$id]] : [];

		$item['row_actions']	= $id ? $this->filter_row_actions([], $item) : ($this->row_actions ? ['error'=>'Primary Key「'.$this->primary_key.'」不存在'] : []);

		$this->before_single_row_by_model($raw);

		$method	= array_find(['render_row', 'render_item', 'item_callback'], fn($v)=> method_exists($this->model, $v));
		$item	= $method ? [$this->model, $method]($item, $attr) : $item;
		$attr	+= $method && isset($item['class']) ? wpjam_tap(['class'=>$item['class']], fn()=>trigger_error(var_export($item, true))) : [];

		echo $item ? $this->filter_single_row(wpjam_tag('tr', $attr, $this->ob_get('single_row_columns', $item+($this->numberable ? ['no'=>($this->no += 1)] : [])))->add_class($this->multi_rows ? 'tr-'.$id : ''), $id)."\n" : '';

		$this->after_single_row_by_model($item, $raw);
	}

	public function single_date($item, $date){
		$parts	= explode('-', $date);
		$append	= ($item || $parts[1] == $this->get_date()['month']) ? wpjam_tag('div', ['row-actions', 'alignright'])->append($this->filter_row_actions([], ['id'=>$date, 'wrap'=>'<span class="%s"></span>'])) : '';

		echo wpjam_tag('div', ['date-meta'])->append('span', ['day', $date == wpjam_date('Y-m-d') ? 'today' : ''], (int)$parts[2])->append($append)->after('div', ['date-content'], $this->render_date_by_model($item, $date) ?? (is_string($item) ? $item : ''));
	}

	protected function parse_id($item){
		return wpjam_get($item, $this->primary_key);
	}

	protected function parse_cell($cell, $id){
		if(!is_array($cell)){
			return $cell;
		}

		$wrap	= wpjam_pull($cell, 'wrap');

		if(isset($cell['row_action'])){
			$cell	= $this->get_row_action($id, ['name'=>wpjam_pull($cell, 'row_action')]+$cell);
		}elseif(isset($cell['filter'])){
			$cell	= $this->get_filter_link(wpjam_pull($cell, 'filter'), wpjam_pull($cell, 'label'), $cell);
		}elseif(isset($cell['items'])){
			$items	= $cell['items'];
			$args	= $cell['args'] ?? [];
			$type	= $args['item_type'] ?? 'image';
			$key	= $args[$type.'_key'] ?? $type;
			$data	= ['field'=>'', 'max_items'=>null]+($type == 'image' ? ['width'=>60, 'height'=>60, 'per_row'=>null] : []);
			$data	= wpjam_pick($args, array_keys($data))+$data;
			$cell	= wpjam_tag('div', ['items', $type.'-list'])->data($data)->style($args['style'] ?? '');
			$names	= $args['actions'] ?? ['add_item', 'edit_item', 'del_item'];
			$names	= !empty($args['sortable']) && $cell->add_class('sortable') ? ['move_item', ...$names] : $names;
			$args	= ['id'=>$id,'data'=>['_field'=>$data['field']]];
			$add	= in_array('add_item', $names) && (!$data['max_items'] || count($items) <= $data['max_items']);

			foreach($items as $i => $item){
				$v	= $item[$key] ?: '';

				if($type == 'image'){
					$ar	= wpjam_pick($data, ['width', 'height']);
					$v	= wpjam_tag('img', ['src'=>wpjam_get_thumbnail($v, wpjam_map($ar, fn($s)=> $s*2))]+$ar)->after('span', ['item-title'], $item['title'] ?? '');
				}

				$args['i']	= $args['data']['i']	= $i;

				$cell->append(wpjam_tag('div', ['id'=>'item_'.$i, 'data'=>['i'=>$i], 'class'=>'item'])->append([
					$this->get_action('move_item', $args+['title'=>$v, 'fallback'=>true])->style(wpjam_pick($item, ['color'])),
					wpjam_tag('span', ['row-actions'])->append($this->get_actions(array_diff($names, ['add_item']), $args+['wrap'=>'<span class="%s"></span>', 'item'=>$item]))
				]));
			}

			unset($args['i'], $args['data']['i']);

			$add && $cell->append($this->get_action('add_item', $args+['class'=>'add-item item']+($type == 'image' ? ['dashicon'=>'plus-alt2'] : [])));
		}else{
			$cell	= $cell['text'] ?? '';
		}

		return (string)wpjam_wrap($cell, $wrap);
	}

	public function column_default($item, $name, $id=null){
		$id		??= $this->parse_id($item);
		$object	= $this->component('column', $name);
		$args	= $this->value_callback === false ? [] : array_filter(['meta_type'=>wpjam_admin('meta_type'), 'model'=>$this->model]);
		$value	= $object && $id ? $object->render($args+['data'=>$item, 'id'=>$id]) : (is_array($item) ? ($item[$name] ?? null) : $item);

		return wp_is_numeric_array($value) ? implode(',', array_map(fn($v)=> $this->parse_cell($v, $id), $value)) : $this->parse_cell($value, $id);
	}

	public function column_cb($item){
		if(($id	= $this->parse_id($item)) && wpjam_current_user_can($this->capability, $id)){
			return wpjam_tag('input', ['type'=>'checkbox', 'name'=>'ids[]', 'value'=>$id, 'id'=>'cb-select-'.$id, 'title'=>'选择'.strip_tags($item[$this->get_primary_column_name()] ?? $id)]);
		}
	}

	public function render(){
		$form	= wpjam_tag('form', ['id'=>'list_table_form'])->append([$this->get_search_box(), $this->get_table()])->before($this->ob_get('views'));

		return $this->layout == 'left' ? wpjam_tag('div', ['id'=>'col-container', 'class'=>'wp-clearfix'])->append(wpjam_map([
			'left'	=> wpjam_wrap($this->ob_get('col_left'), 'form'),
			'right'	=> $form
		], fn($v, $k)=> $v->add_class('col-wrap')->wrap('div', ['id'=>'col-'.$k]))) : $form;
	}

	public function get_search_box(){
		$fields = $this->searchable_fields;

		if($this->search ?? $fields){
			$box	= $this->ob_get('search_box', '搜索', 'wpjam') ?: wpjam_tag('p', ['search-box']);
			$field	= wpjam_is_assoc_array($fields) ? wpjam_field(['key'=>'search_columns', 'value'=>$this->get_data('search_columns'), 'type'=>'select', 'show_option_all'=>'默认', 'options'=>$fields]) : '';
		
			return $field ? wpjam_preg_replace('/(<p class="search-box">)/is', '$1'.$field, $box) : $box;
		}
	}

	public function get_table(){
		return $this->ob_get('display');
	}

	public function col_left($action=''){
		$paged	= (int)$this->get_data('left_paged') ?: 1;

		if(method_exists($this->model, 'query_left')){
			static $pages, $items;

			if($action == 'prepare'){
				$number	= $this->left_per_page ?: 10;
				$left	= array_filter($this->get_data([$this->left_key]));
				$items	= $this->try('query_left_by_model', ['number'=>$number, 'offset'=>($paged-1)*$number]+$this->left_data+$left);
				$pages	= $items ? ceil($items['total']/$number) : 0;
				$items	= $items ? $items['items'] : [];

				return $items && !$left && wpjam_default($this->left_key, wpjam_at($items, 0)['id']);
			}

			$this->left_fields && wpjam_echo(wpjam_fields($this->left_fields)->render(['fields_type'=>'', 'data'=>$this->left_data])->wrap('div', ['alignleft', 'actions'])->after('br', ['clear'])->wrap('div', ['class'=>'tablenav']));

			$head	= [[$this->left_title, 'th'], 'tr'];
			$items	= implode(wpjam_map($items ?: ['找不到'.$this->left_title], fn($item)=> wpjam_tag('td')->append(is_array($item) ? [
				['p', ['row-title'], $item['title']],
				['span', ['time'], $item['time']],
				...(isset($item['count']) ? wpjam_map((array)$item['count'], fn($v)=> ['span', ['count', 'wp-ui-highlight'], $v]) : [])
			] : $item)->wrap('tr', is_array($item) ? ['class'=>'left-item', 'id'=>$item['id'], 'data-id'=>$item['id']] : ['no-items'])));

			echo wpjam_tag('table', ['widefat striped'])->append([[$head, 'thead'], [$items, 'tbody'], [$head, 'tfoot']]);
		}else{
			$result	= $this->col_left_by_model();
			$pages	= $result && is_array($result) ? ceil($result['total_items']/$result['per_page']) : 0;
		}

		$pages > 1 && wpjam_echo(wpjam_tag('span', ['left-pagination-links'])->append([
			wpjam_tag('a', ['prev-page'], '&lsaquo;')->attr('title', __('Previous page')),
			wpjam_tag('span', [], $paged.' / '.$pages),
			wpjam_tag('a', ['next-page'], '&rsaquo;')->attr('title', __('Next page')),
			wpjam_tag('input', ['type'=>'number', 'name'=>'left_paged', 'value'=>$paged, 'min'=>1, 'max'=>$pages, 'class'=>'current-page']),
			wpjam_tag('input', ['type'=>'submit', 'class'=>'button', 'value'=>'&#10132;'])
		])->wrap('div', ['tablenav-pages'])->wrap('div', ['tablenav', 'bottom']));
	}

	public function page_load(){
		if(wp_doing_ajax()){
			return wpjam_add_admin_ajax('wpjam-list-table-action',	[
				'callback'		=> $this,
				'nonce_action'	=> fn($data)=> ($object = $this->get_action($data['list_action'] ?? '')) ? $object->parse_nonce_action($data) : null
			]);
		}

		if($action	= wpjam_get_parameter('export_action')){
			return ($object	= $this->get_action($action)) ? $object('export') : wp_die('无效的导出操作');
		}

		wpjam_trap([$this, 'prepare_items'], fn($result)=> wpjam_add_admin_error($result));
	}

	public function prepare_items(){
		$args	= array_filter($this->get_data(['orderby', 'order', 's', 'search_columns']), fn($v)=> isset($v));
		$_GET	= array_merge($_GET, $args);
		$args	+= $this->params+wpjam_array($this->filterable_fields, fn($k, $v)=> [$k, $v ? null : $this->get_data($k)], true);

		if($this->layout == 'calendar'){
			$date	= $this->get_date();
			$start	= (int)get_option('start_of_week');
			$ts		= mktime(0, 0, 0, $date['month'], 1, $date['year']);
			$pad	= calendar_week_mod(date('w', $ts)-$start);
			$days	= date('t', $ts);
			$days	= $days+6-($days%7 ?: 7);
			$items	= wpjam_map(array_chunk(range(0, $days), 7), fn($item)=> wpjam_array($item, fn($k, $v)=> [($v+$start)%7, date('Y-m-d', $ts+($v-$pad)*DAY_IN_SECONDS)]));

			$this->calendar	= wpjam_try($this->model.'::query_calendar', $args+$date);
		}else{
			$this->layout == 'left' && $this->col_left('prepare');

			$number	= is_numeric($this->per_page) ? ($this->per_page ?: 50) : 50;
			$offset	= $number*($this->get_pagenum()-1);
			$cb		= $this->model.'::query_items';
			$params	= wpjam_get_reflection($cb, 'Parameters') ?: [];
			$items	= wpjam_try($cb, ...(count($params) > 1 && $params[0]->name != 'args' ? [$number, $offset] : [compact('number', 'offset')+$args]));

			if(wpjam_is_assoc_array($items) && isset($items['items'])){
				$total	= $items['total'] ?? null;
				$items	= $items['items'];
			}

			$this->set_pagination_args(['total_items'=>$total ?? ($number = count($items)), 'per_page'=>$number]);
		}

		$this->items	= $items;
	}

	protected function get_table_classes(){
		return array_merge(array_diff(parent::get_table_classes(), ($this->fixed ? [] : ['fixed'])), $this->nowrap ? ['nowrap'] : []);
	}

	protected function get_default_primary_column_name(){
		return $this->primary_column;
	}

	protected function handle_row_actions($item, $column, $primary){
		return ($primary === $column && !empty($item['row_actions'])) ? $this->row_actions($item['row_actions'], false) : '';
	}

	public function get_columns(){
		return $this->filter_columns();
	}

	public function extra_tablenav($which='top'){
		if($this->layout == 'calendar'){
			echo wpjam_tag('h2', [], $this->get_date('locale'));
			echo wpjam_tag('span', ['pagination-links'])->append(wpjam_map(['prev'=>'&lsaquo;', 'current'=>'今日', 'next'=>'&rsaquo;'], fn($v, $k)=> "\n".$this->get_filter_link($this->get_date($k), $v, ['class'=>$k.'-month button', 'title'=>$this->get_date($k, 'locale')])))->wrap('div', ['tablenav-pages']);
		}

		if($which == 'top' && ($fields = (wpjam_admin('chart', 'get_fields') ?: [])+array_filter($this->filterable_fields))){
			echo wpjam_fields($fields)->render(['fields_type'=>'', 'data'=>$this->params])->after(get_submit_button('筛选', '', 'filter_action', false))->wrap('div', ['actions']);
		}

		if(!$this->builtin){
			$this->extra_tablenav_by_model($which);

			do_action(wpjam_get_filter_name($this->plural, 'extra_tablenav'), $which);
		}
	}

	public function current_action(){
		return wpjam_get_request_parameter('list_action') ?? parent::current_action();
	}
}

class WPJAM_List_Table_Component extends WPJAM_Register{
	public static function call_group($method, ...$args){
		$group	= static::get_group(['name'=>strtolower(static::class), 'config'=>['orderby'=>'order']]);
		$part	= str_replace('wpjam_list_table_', '', $group->name);

		if(in_array($method, ['add_object', 'remove_object'])){
			$args[0]	= ($name = $args[0]).WPJAM_Data_Type::prepare($args[1], 'key');

			if($method == 'add_object'){
				if($part == 'action'){
					if(!empty($args[1]['update_setting'])){
						$model		= wpjam_admin('list_table', 'model');
						$args[1]	+= ['overall'=>true, 'callback'=>[$model, 'update_setting'], 'value_callback'=>[$model, 'get_setting']];
					}

					if(!empty($args[1]['overall']) && $args[1]['overall'] !== true){
						static::call_group($method, $name.'_all', ['overall'=>true, 'title'=>wpjam_pull($args[1], 'overall')]+$args[1]);
					}
				}elseif($part == 'column'){
					$args[1]['_field']	= wpjam_field(wpjam_pick($args[1], ['name', 'options'])+['type'=>'view', 'wrap_tag'=>'', 'key'=>$name]);
				}

				$args[1]	= new static($name, $args[1]);
			}else{
				if(!static::get($args[0])){
					return wpjam_admin('removed_'.$part.'s[]', $name);
				}
			}
		}

		return [$group, $method](...$args);
	}
}

class WPJAM_List_Table_Action extends WPJAM_List_Table_Component{
	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == 'page_title'){
			return $this->title ? wp_strip_all_tags($this->title.wpjam_admin('list_table', 'title')) : '';
		}elseif($key == 'response'){
			return $this->next ? 'form' : ($this->overall && $this->name != 'add' ? 'list' : $this->name);
		}elseif($key == 'row_action'){
			return ($this->bulk !== 'only' && $this->name != 'add');
		}elseif(in_array($key, ['layout', 'model', 'builtin', 'form_data', 'primary_key', 'data_type', 'capability', 'next_actions']) || ($this->data_type && $this->data_type == $key)){
			return wpjam_admin('list_table', $key);
		}
	}

	public function __toString(){
		return $this->title;
	}

	public function __call($method, $args){
		if(str_contains($method, '_prev')){
			$cb	= [self::get($this->prev ?: array_search($this->name, $this->next_actions ?: [])), str_replace('_prev', '', $method)];

			return $cb[0] ? $cb(...$args) : ($cb[1] == 'render' ? '' : []);
		}elseif(try_remove_prefix($method, 'parse_')){
			$args	= $args[0];

			if($method == 'nonce_action'){
				return wpjam_join('-', $this->name, empty($args['bulk']) ? ($args['id'] ?? '') : '');
			}

			if($this->overall){
				return;
			}

			if($method == 'arg'){
				if(wpjam_is_assoc_array($args)){
					return (int)$args['bulk'] === 2 ? (!empty($args['id']) ? $args['id'] : $args['ids']) : ($args['bulk'] ? $args['ids'] : $args['id']);
				}

				return $args;
			}elseif($method == 'id'){
				return $args['bulk'] ? null : $args['id'];
			}
		}
	}

	public function __invoke($type){
		$data	= wpjam_get_parameter(...($type == 'export' ? ['data'] : ['', ['method'=>'DATA']])) ?: [];
		$data	+= in_array($type, ['direct', 'form']) && $this->overall ? $this->form_data : [];
		$args	= $form_args = ['data'=>$data]+wpjam_map([
			'id'	=> ['default'=>''],
			'bulk'	=> ['sanitize_callback'=>fn($v)=> ['true'=>1, 'false'=>0][$v] ?? $v],
			'ids'	=> ['sanitize_callback'=>'wp_parse_args', 'default'=>[]]
		], fn($v, $k)=> wpjam_get_parameter($k, $v+['method'=>($type == 'export' ? 'get' : 'post')]));

		['id'=>$id, 'bulk'=>&$bulk]	= $args;

		$response	= [
			'list_action'	=> $this->name,
			'page_title'	=> $this->page_title,
			'type'	=> $type == 'form' ? 'form' : $this->response,
			'last'	=> (bool)$this->last,
			'width'	=> (int)$this->width,
			'bulk'	=> &$bulk,
			'id'	=> &$id,
			'ids'	=> $args['ids']
		];

		$submit	= null;
		$button	= [];

		if(in_array($type, ['submit', 'export'])){
			$submit	= wpjam_get_parameter('submit_name', ['default'=>$this->name, 'method'=>($type == 'submit' ? 'POST' : 'GET')]);
			$button	= $submit == 'next' ? [] : $this->get_submit_button($args, $submit);

			if(!empty($button['response'])){
				$response['type'] = $button['response'];
			}
		}

		if(in_array($type, ['submit', 'direct'])
			&& ($this->export || ($type == 'submit' && !empty($button['export'])) || ($this->bulk === 'export' && $args['bulk']))
		){
			$args	+= ['export_action'=>$this->name, '_wpnonce'=>wp_create_nonce($this->parse_nonce_action($args))];
			$args	+= $submit != $this->name ? ['submit_name'=>$submit] : [];

			return ['type'=>'redirect', 'url'=>add_query_arg(array_filter($args), $GLOBALS['current_admin_url'])];
		}

		$this->is_allowed($args) || wp_die('access_denied');

		if($type == 'form'){
			return $response+['form'=>$this->get_form($form_args, $type)];
		}

		$bulk	= (int)$bulk === 2 ? 0 : $bulk;
		$cbs	= ['callback', 'bulk_callback'];
		$args	+= $this->pick($cbs);
		$fields	= $result = null;

		if($type == 'submit'){
			$fields	= $this->get_fields($args, true, 'object');
			$data	= $fields->validate($data);

			$form_args['data']	= $response['type'] == 'form' ? $data : wpjam_get_parameter('', ['method'=>'defaults']);
		}

		if($response['type'] != 'form'){
			$args	= (in_array($type, ['submit', 'export']) ? array_filter(wpjam_pick($button, $cbs)) : [])+$args;
			$result	= $this->callback(['data'=>$data, 'fields'=>$fields, 'submit_name'=>$submit]+$args);
			$errmsg	= is_array($result) ? ($result['errmsg'] ?? '') : '';
			$errmsg	= $errmsg && $errmsg != 'ok' ? $errmsg : ($type == 'submit' ? $button['text'].'成功' : '');

			$response['errmsg'] = $errmsg;	// 第三方接口可能返回 ok
		}

		if(is_array($result)){
			if(array_intersect(array_keys($result), ['type', 'bulk', 'ids', 'id', 'items'])){
				$response	= $result+$response;
				$result		= null;
			}
		}else{
			if(in_array($response['type'], ['add', 'duplicate']) && $this->layout != 'calendar'){
				[$id, $result]	= [$result, null];
			}
		}

		if($response['type'] == 'append'){
			return $response+($result ? ['data'=>$result] : []);
		}elseif($response['type'] == 'redirect'){
			return $response+['target'=>$this->target ?: '_self']+(is_string($result) ? ['url'=>$result] : []);
		}

		if($this->layout == 'calendar'){
			if(is_array($result)){
				$response['data']	= $result;
			}
		}else{
			if(!$response['bulk'] && in_array($response['type'], ['add', 'duplicate'])){
				$form_args['id']	= $response['id'];
			}
		}

		if($result){
			$response['result']	= $result;
		}

		if($type == 'submit'){
			if($this->next){
				$response	= ['next'=>$this->next, 'page_title'=>(self::get($this->next))->page_title]+$response;

				if($response['type'] == 'form'){
					$response['errmsg']	= '';
				}
			}

			if($this->dismiss || !empty($response['dismiss']) || $response['type'] == 'delete' || ($response['type'] == 'items' && array_find($response['items'], fn($item)=> $item['type'] == 'delete'))){
				$response['dismiss']	= true;
			}else{
				$response['form']		= ($type == 'submit' && $this->next ? self::get($this->next) : $this)->get_form($form_args, $type);
			}
		}

		return $response;
	}

	public function callback($args){
		$cb_args	= [$this->parse_arg($args), $args['data']];

		if(in_array($this->name, ['up', 'down'])){
			$cb_args[1]	= ($cb_args[1] ?? [])+[$this->name=>true];
			$this->name	= 'move';
		}

		$cb	= $args[($args['bulk'] ? 'bulk_' : '').'callback'] ?? '';

		if($cb && !$args['bulk']){
			$shift	= $this->overall;

			if(!$shift && ($this->response == 'add' || $this->name == 'add') && !is_null($args['data'])){
				$params	= wpjam_get_reflection($cb, 'Parameters') ?: [];
				$shift	= count($params) <= 1 || $params[0]->name == 'data';
			}

			$shift && array_shift($cb_args);
		}

		if($cb){
			return wpjam_trap($cb, ...[...$cb_args, $this->name, $args['submit_name'], 'throw']) ?? wp_die('「'.$this->title.'」的回调函数无效或没有正确返回');
		}

		if($args['bulk']){
			$cb	= [$this->model, 'bulk_'.$this->name];

			if(method_exists(...$cb)){
				return wpjam_try($cb, ...$cb_args) ?? true;
			}

			$data	= [];

			foreach($args['ids'] as $id){
				$result	= $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]));
				$data	= wpjam_merge($data, is_array($result) ? $result : []);
			}

			return $data ?: true;
		}

		$m	= ($m = $this->name) == 'duplicate' && !$this->direct ? 'insert' : (['add'=>'insert', 'edit'=>'update'][$m] ?? $m);
		$cb	= [$this->model, &$m];

		if($m == 'insert' || $this->response == 'add' || $this->overall){
			array_shift($cb_args);
		}elseif(method_exists(...$cb)){
			$this->direct && is_null($args['data']) && array_pop($cb_args);
		}elseif($this->meta_type || !method_exists($cb[0], '__callStatic')){
			$m	= 'update_callback';
			$cb	= method_exists(...$cb) ? $cb : (($cb_args = [wpjam_admin('meta_type'), ...$cb_args])[0] ? 'wpjam_update_metadata' : wp_die('「'.$cb[0].'->'.$this->name.'」未定义'));

			$cb_args[]	= $args['fields']->get_defaults();
		}

		return wpjam_try($cb, ...$cb_args) ?? true ;
	}

	public function is_allowed($args=[]){
		return $this->capability == 'read' || array_all($args && !$this->overall ? (array)$this->parse_arg($args) : [null], fn($id)=> wpjam_current_user_can($this->capability, $id, $this->name));
	}

	public function get_data($id, $type=''){
		$cb		= $type ? $this->data_callback : null;
		$data	= $cb ? (is_callable($cb) ? wpjam_try($cb, $id, $this->name) : wp_die($this->title.'的 data_callback 无效')) : null;

		if($type == 'prev'){
			return array_merge($this->get_prev_data($id, 'prev'), ($data ?: []));
		}

		if($id && !$cb){
			$data	= $this->try('get_by_model', $id);
			$data	= $data instanceof WPJAM_Register ? $data->to_array() : ($data ?: wp_die('无效的 ID「'.$id.'」'));
		}

		return $data;
	}

	public function get_form($args, $type=''){
		$id		= $this->parse_id($args);
		$data	= $type == 'submit' && $this->response == 'form' ? [] : $this->get_data($id, 'callback');
		$fields	= $this->get_fields(wpjam_merge($args, array_filter(['data'=>$data])), false, 'object');
		$args	= wpjam_merge($args, ['data'=>($id && $type == 'form' ? $this->get_prev_data($id, 'prev') : [])]);

		return $fields->render()->after($this->get_submit_button($args)->prepend($this->render_prev(['class'=>['button'], 'title'=>'上一步']+$args)))->wrap('form', ['novalidate', 'id'=>'list_table_action_form', 'data'=>$this->generate_data_attr($args, 'form')]);
	}

	public function get_fields($args, $prev=false, $output=''){
		$arg	= $this->parse_arg($args);
		$fields	= wpjam_try('maybe_callback', $this->fields, $arg, $this->name) ?: wpjam_try($this->model.'::get_fields', $this->name, $arg);
		$fields	= array_merge(is_array($fields) ? $fields : [], ($prev ? $this->get_prev_fields($arg, true) : []));
		$fields	= method_exists($this->model, 'filter_fields') ? $this->try('filter_fields_by_model', $fields, $arg, $this->name) : $fields;

		if(!in_array($this->name, ['add', 'duplicate']) && isset($fields[$this->primary_key])){
			$fields[$this->primary_key]['type']	= 'view';
		}

		if($output == 'object'){
			$id		= $this->parse_id($args);
			$args	= ['id'=>$id]+$args+($id ? array_filter(['meta_type'=>wpjam_admin('meta_type'), 'model'=>$this->model]) : []);

			return WPJAM_Fields::create($fields, array_filter(['value_callback'=>$this->value_callback])+$args);
		}

		return $fields;
	}

	public function get_submit_button($args, $name=null){
		if(!$name && $this->next){
			$button	= ['next'=>'下一步'];
		}else{
			$button	= maybe_callback($this->submit_text, $this->parse_arg($args), $this->name) ?? (wp_strip_all_tags($this->title) ?: $this->page_title);
			$button	= is_array($button) ? $button : [$this->name => $button];
		}

		return WPJAM_Admin::parse_button($button, $name);
	}

	public function render($args=[]){
		$args	+= ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[]];
		$id		= $args['id'];

		if(is_callable($this->show_if)){
			$show_if	= wpjam_trap($this->show_if, ...(!empty($args['item']) ? [$args['item'], null] : [$id, $this->name, null]));

			if(!$show_if){
				return;
			}elseif(is_array($show_if)){
				$args	+= $show_if;
			}
		}elseif($this->show_if){
			if($id && !wpjam_match((wpjam_get($args, 'item') ?: $this->get_data($id)), wpjam_parse_show_if($this->show_if))){
				return;
			}
		}

		if($this->builtin && $id){
			if($this->data_type == 'post_type'){
				if(!wpjam_compare(get_post_status($id), ...(array_filter([$this->post_status]) ?: ['!=', 'trash']))){
					return;
				}
			}elseif($this->data_type == 'user'){
				if($this->roles && !array_intersect(get_userdata($id)->roles, (array)$this->roles)){
					return;
				}
			}
		}

		if(!$this->is_allowed($args)){
			return wpjam_get($args, (wpjam_get($args, 'fallback') === true ? 'title' : 'fallback'));
		}

		$attr	= wpjam_pick($args, ['class', 'style'])+['title'=>$this->page_title];
		$tag	= wpjam_tag($args['tag'] ?? 'a', $attr)->add_class($this->class)->style($this->style);

		if($this->redirect){
			$href	= maybe_callback($this->redirect, $id, $args);
			$tag	= $href ? $tag->add_class('list-table-redirect')->attr(['href'=>str_replace('%id%', $id, $href), 'target'=>$this->target]) : '';
		}elseif($this->filter || $this->filter === []){
			$filter	= maybe_callback($this->filter, $id) ?? false;
			$tag	= $filter === false ? '' : $tag->add_class('list-table-filter')->data('filter', array_merge(($this->data ?: []), (wpjam_is_assoc_array($filter) ? $filter : ($this->overall ? [] : wpjam_pick((array)$this->get_data($id), (array)$filter))), $args['data']));
		}else{
			$data	= $this->generate_data_attr($args);
			$tag	= $tag->add_class('list-table-'.(in_array($this->response, ['move', 'move_item']) ? 'move-' : '').'action')->data($data);
		}

		$text	= wpjam_icon($args) ?? ($args['title'] ?? null);
		$text	??= (!$tag->has_class('page-title-action') && ($this->layout == 'calendar' || !$this->title)) ? wpjam_icon($this) : null;
		$text	??= $this->title ?: $this->page_title;

		return $tag ? $tag->text($text)->wrap(wpjam_get($args, 'wrap'), $this->name) : null;
	}

	public function generate_data_attr($args=[], $type='button'){
		$data	= wp_parse_args(($args['data'] ?? []), ($this->data ?: []))+($this->layout == 'calendar' ? wpjam_pick($args, ['date']) : []);
		$attr	= ['data'=>$data, 'action'=>$this->name, 'nonce'=>wp_create_nonce($this->parse_nonce_action($args))];
		$attr	+= $this->overall ? [] : ($args['bulk'] ? wpjam_pick($args, ['ids'])+$this->pick(['bulk', 'title']) : wpjam_pick($args, ['id']));

		return $attr+$this->pick($type == 'button' ? ['direct', 'confirm'] : ['next']);
	}

	public static function registers($actions){
		foreach($actions as $key => $args){
			self::register($key, $args+['order'=>10.5]);
		}
	}
}

class WPJAM_List_Table_Column extends WPJAM_List_Table_Component{
	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'style'){
			$value	= $this->column_style ?: $value;
			$value	= ($value && !preg_match('/\{([^\}]*)\}/', $value)) ? 'table.wp-list-table .column-'.$this->name.'{'.$value.'}' : $value;
		}elseif(in_array($key, ['title', 'callback', 'description', 'render'])){
			$value	= $this->{'column_'.$key} ?? $value;
		}elseif(in_array($key, ['sortable', 'sticky'])){
			$value	??= $this->{$key.'_column'};
		}

		return $value;
	}

	public function render($args, $callback=true){
		if($callback){
			$id		= $args['id'];
			$value	= $this->_field->val(null)->value_callback($args) ?? wpjam_value_callback($args, $this->name, $id) ?? $this->default;

			if(wpjam_is_assoc_array($value)){
				return $value;
			}
			
			$cb		= $id !== false && is_callable($this->callback) ? $this->callback : null;
			$value	= $cb ? wpjam_call($cb, $id, $this->name, $value) : $value;

			if(is_callable($this->render)){
				return ($this->render)($value, $args['data'], $this->name, $id);
			}

			if($this->render){
				if($this->type == 'img'){
					$size	= wpjam_parse_size($this->size ?: '600x0', [600, 600]);

					return $value ? '<img src="'.wpjam_get_thumbnail($value, $size).'" '.image_hwstring($size['width']/2,  $size['height']/2).' />' : '';
				}elseif($this->type == 'timestamp'){
					return $value ? wpjam_date('Y-m-d H:i:s', $value) : '';
				}

				return $value;
			}

			if($cb){
				return $value;
			}

			if(is_array($value)){
				return array_map(fn($v)=> $this->render($v, false), $value);
			}
		}else{
			$value	= $args;
		}

		if($value && str_contains($value, '[filter')){
			return $value;
		}

		$filter	= isset(wpjam_admin('list_table', 'filterable_fields')[$this->name]) ? [$this->_field->name => $value] : [];
		$value	= $this->options ? $this->_field->val($value)->render() : $value;

		return $filter ? ['filter'=>$filter, 'label'=>$value] : $value;
	}

	public static function registers($fields){
		foreach($fields as $key => $field){
			$column	= wpjam_pull($field, 'column');

			if($field['show_admin_column'] ?? is_array($column)){
				self::register($key, ($column ?: [])+wpjam_except(WPJAM_Data_Type::except($field), ['style', 'description', 'render'])+['order'=>10.5]);
			}
		}
	}
}

class WPJAM_List_Table_View extends WPJAM_List_Table_Component{
	public function parse(){
		if($this->_view){
			return $this->_view;
		}

		$view	= $this;
		$cb		= $this->callback;

		if($cb && is_callable($cb)){
			$view	= wpjam_trap($cb, $this->name, null);

			if(!is_array($view)){
				return $view;
			}
		}

		if(!empty($view['label'])){
			$filter	= $view['filter'] ?? [];
			$label	= $view['label'].(is_numeric(wpjam_get($view, 'count')) ? wpjam_tag('span', ['count'], '（'.$view['count'].'）') : '');
			$class	= $view['class'] ?? (array_any($filter, fn($v, $k)=> (((($c = wpjam_get_data_parameter($k)) === null) xor ($v === null)) || $c != $v)) ? '' : 'current');

			return [$filter, $label, $class];
		}
	}

	public static function registers($views){
		foreach(array_filter($views) as $name => $view){
			$name	= is_numeric($name) ? 'view_'.$name : $name;
			$view	= is_array($view) ? WPJAM_Data_Type::except($view) : $view;
			$view	= (is_string($view) || is_object($view)) ? ['_view'=>$view] : $view;

			self::register($name, $view);
		}
	}
}

class WPJAM_Builtin_List_Table extends WPJAM_List_Table{
	public function __construct($args){
		$screen		= get_current_screen();
		$data_type	= wpjam_admin('data_type', $args['data_type']);

		if($data_type == 'post_type'){
			$parts	= $screen->id == 'upload' ? ['media', 'media'] : ($args['hierarchical'] ? ['pages', 'page', 'posts'] : ['posts', 'post', 'posts']);
			$args	+= ['builtin'=> $parts[0] == 'media' ? 'WP_Media_List_Table' : 'WP_Posts_List_Table'];
		}elseif($data_type == 'taxonomy'){
			$args	+= ['builtin'=>'WP_Terms_List_Table'];
			$parts	= [$args['taxonomy'], $args['taxonomy']];
		}elseif($data_type == 'user'){
			$args	+= ['builtin'=>'WP_Users_List_Table'];
			$parts	= ['users', 'user', 'users'];
		}elseif($data_type == 'comment'){
			$args	+= ['builtin'=>'WP_Comments_List_Table'];
			$parts	= ['comments', 'comment'];
		}

		wpjam_admin('meta_type', $args['meta_type'] ?? '');

		add_filter('manage_'.$screen->id.'_columns',	[$this, 'filter_columns']);
		add_filter('manage_'.$parts[0].'_custom_column',[$this, 'filter_custom_column'], 10, in_array($data_type, ['post_type', 'comment']) ? 2 : 3);

		add_filter($parts[1].'_row_actions',	[$this, 'filter_row_actions'], 1, 2);

		isset($parts[2]) && add_action('manage_'.$parts[2].'_extra_tablenav', [$this, 'extra_tablenav']);
		in_array($data_type, ['post_type', 'taxonomy']) && add_action('parse_term_query', [$this, 'on_parse_query'], 0);
		wp_is_json_request() || add_filter('wpjam_html', [$this, 'filter_table']);

		parent::__construct($args);
	}

	public function builtin($method, ...$args){
		return [$GLOBALS['wp_list_table'] ??= _get_list_table($this->builtin, ['screen'=>$this->screen]), $method](...$args);
	}

	public function views(){
		$this->screen->id != 'upload' && $this->builtin('views');
	}

	public function display_tablenav($which){
		$this->builtin('display_tablenav', $which);
	}

	public function get_table(){
		return $this->filter_table($this->ob_get('builtin', 'display'));
	}

	public function prepare_items(){
		if(wp_doing_ajax()){
			if($this->screen->base == 'edit'){
				$_GET['post_type']	= $this->post_type;
			}

			$_GET	= array_merge($_GET, $this->get_data());
			$_POST	= array_merge($_POST, $this->get_data());

			$this->builtin('prepare_items');
		}
	}

	public function single_row($item){
		if($this->data_type == 'post_type'){
			global $post, $authordata;

			$post	= is_numeric($item) ? get_post($item) : $item;

			if($post){
				$authordata	= get_userdata($post->post_author);

				if($post->post_type == 'attachment'){
					echo wpjam_tag('tr', ['id'=>'post-'.$post->ID], $this->ob_get('builtin', 'single_row_columns', $post))->add_class(['author-'.((get_current_user_id() == $post->post_author) ? 'self' : 'other'), 'status-'.$post->post_status]);
				}else{
					$args	= [$post];
				}
			}
		}elseif($this->data_type == 'taxonomy'){
			$term	= is_numeric($item) ? get_term($item) : $item;
			$args	= $term ? [$term, get_term_level($term)] : [];
		}elseif($this->data_type == 'user'){
			$user	= is_numeric($item) ? get_userdata($item) : $item;
			$args	= $user ? [$user] : [];
		}elseif($this->data_type == 'comment'){
			$comment	= is_numeric($item) ? get_comment($item) : $item;
			$args		= $comment ? [$comment] : [];
		}

		echo empty($args) ? '' : $this->filter_table($this->ob_get('builtin', 'single_row', ...$args));
	}

	public function on_parse_query($query){
		if(array_any(debug_backtrace(), fn($v)=> wpjam_get($v, 'class') == $this->builtin)){
			$vars	= &$query->query_vars;
			$by		= $vars['orderby'] ?? '';
			$object	= ($by && is_string($by)) ? $this->component('column', $by) : null;
			$type	= $object ? ($object->sortable === true ? 'meta_value' : $object->sortable) : '';
			$vars	= array_merge($vars, ['list_table_query'=>true], in_array($type, ['meta_value_num', 'meta_value']) ? ['orderby'=>$type, 'meta_key'=>$by] : []);
		}
	}

	public static function load($args){
		return new static($args);
	}
}