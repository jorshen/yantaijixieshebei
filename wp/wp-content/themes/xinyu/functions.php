<?php
/* ------------------------------------------------------------------------- *
* 设置文章第一个图片自动为特色图片
/* ------------------------------------------------------------------------- */
// function catch_that_image() {
// 	global $post, $posts;
// 	$first_img = '';
// 	ob_start();
// 	ob_end_clean();
// 	$output = preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post->post_content, $matches);
// 	$first_img = $matches[1][0];
// 	if(empty($first_img)) {
// 		$first_img = "/path/to/default.png";
// 	}
// 	return $first_img;
// }
// Enable the option show in rest
add_filter( 'acf/rest_api/field_settings/show_in_rest', '__return_true' );

// Enable the option edit in rest
add_filter( 'acf/rest_api/field_settings/edit_in_rest', '__return_true' );

//添加主题设置页面
//require_once ( get_stylesheet_directory() . '/theme-options.php' );

//wpjam seo
add_action('after_setup_theme', 'xintheme_setup');
function xintheme_setup() {
	add_theme_support('title-tag');
}

//移除wp_head自带jquery
add_action('wp_enqueue_scripts', 'no_more_jquery');
function no_more_jquery(){
	wp_deregister_script('jquery');
}


//禁用文章修订功能
add_filter( 'wp_revisions_to_keep', 'specs_wp_revisions_to_keep', 10, 2 );
function specs_wp_revisions_to_keep( $num, $post ) {
	return 0;
}

// 文章浏览次数
function get_post_views ($post_id) {
    $count_key = 'views';
    $count = get_post_meta($post_id, $count_key, true);
    if ($count == '') {
        delete_post_meta($post_id, $count_key);
        add_post_meta($post_id, $count_key, '0');
        $count = '0';
    }
    echo number_format_i18n($count);
}
// function set_post_views () {
//     global $post;
//     $post_id = $post -> ID;
//     $count_key = 'views';
//     $count = get_post_meta($post_id, $count_key, true);
//     if (is_single() || is_page()) {
//         if ($count == '') {
//             delete_post_meta($post_id, $count_key);
//             add_post_meta($post_id, $count_key, '0');
//         } else {
//             // update_post_meta($post_id, $count_key, $count + 1); // 修复刷新一次+2
//             update_post_meta($post_id, $count_key, $count);
//         }
//     }
// }
// add_action('get_header', 'set_post_views');

// 调用子分类
function get_category_root_id($cat)
{
  $this_category = get_category($cat); // 取得当前分类
  while ($this_category->category_parent) // 若当前分类有上级分类时，循环
  {
    $this_category = get_category($this_category->category_parent); // 将当前分类设为上级分类（往上爬）
  }
  return $this_category->term_id; // 返回根分类的id号
}
//获得当前分类目录ID
function get_current_category_id() {
	$current_category = single_cat_title('', false);//获得当前分类目录名称
	return get_cat_ID($current_category);//获得当前分类目录ID
}

//为主题添加主题控制面板
if( function_exists('acf_add_options_page') ) {

	acf_add_options_page(array(
		'page_title' 	=> '全局设置',
		'menu_title'	=> '全局设置',
		'menu_slug' 	=> 'theme-general-settings',
		'capability'	=> 'edit_posts',
		'redirect'		=> false
	));

	acf_add_options_sub_page(array(
		'page_title' 	=> '幻灯片',
		'menu_title'	=> 'Slider',
		'parent_slug'	=> 'theme-general-settings'
	));

	acf_add_options_sub_page(array(
		'page_title' 	=> '导航',
		'menu_title'	=> 'Nav',
		'parent_slug'	=> 'theme-general-settings',
	));

}






/**
 * 自定义JPEG图片压缩质量
 * https://www.wpdaxue.com/wp_image_editor-jpeg_quality.html
 */
function ode_jpeg_quality() {
    //根据实际需求，修改下面的数字即可
    return 60;
}
add_filter( 'jpeg_quality', 'ode_jpeg_quality');
/*
add_theme_support($features, $arguments)
函数功能：开启缩略图功能
@参数 string $features, 此参数是告诉wordpress你要开启什么功能
@参数 array $arguments, 此参数是告诉wordpress哪些信息类型想要开启缩略图
第二个参数如果不填写，那么文章信息和页面信息都开启缩略图功能。
*/
add_theme_support('post-thumbnails');


/*
	add_image_size( $name, $width, $height, $crop )
	函数功能：增加一种新尺寸的图片

	特别说明：
	一般情况下，当你上传一张图片时，除了上传的原图外，wordpress还会把原图结成三种尺寸的图片，一个是“缩略图”， 一个是“中等尺寸图”，一个是“大尺寸图片”。
	如果你的网站，需要两种尺寸的缩略图，比如一个是150*150， 一个是150*180。而你在上传图片时，wordpress默认只能生成一种尺寸的。
	而通过此函数，可以让wordpress在原图的基础上修改出两种尺寸的缩略图

	@参数$name, 增加的新尺寸图片的名称。比如,thumbnail代表的是缩略图，medium代表的是中等尺寸图，large代表的是大尺寸图，full代表的是完整尺寸图。那么你新创建的这个尺寸的图片，叫什么名字？你自己命名即可

	@参数$width,	代表的是你设置的新尺寸的宽度是多少？填写数字，不用写单位。因为单位默认为像素即px

	@参数$height, 代表的是你设置的新尺寸的高度是多少？填写数字，不用单位

	@参数$crop, 代表的是压缩模式还是剪切模式。

	范例：
	//当上传图片时，给我新生成一种尺寸的图片。尺寸为300*200, 压缩模式
	add_image_size( 'cat-thumb', 300, 200, false );

	// 当上传图片时，给我新生成一种尺寸的图片。尺寸为220*180, 裁剪模式
	add_image_size( 'hom-thumb', 220, 180, true );
*/
add_image_size( 'odethumb1', 400, 250, true );
add_image_size( 'odethumb2', 400, 400, true );
add_image_size( 'odethumb3', 600, 400, true );


/**
* ode_strimwidth( ) 函数
* 功能：字符串截取，并去除字符串中的html和php标签
* @Param string $str			要截取的原始字符串
* @Param int $len				截取的长度
* @Param string $suffix		字符串结尾的标识
* @Return string					处理后的字符串
*/
function ode_strimwidth( $str, $len, $start = 0, $suffix = '……' ) {
	$str = str_replace(array(' ', '　','&nbsp;', '\r\n'), '', strip_tags( $str ));
	if ( $len>mb_strlen( $str ) ) {
		return mb_substr( $str, $start, $len );
	}
	return mb_substr($str, $start, $len) . $suffix;
}



/**
 * 为WordPress后台的文章、分类等显示ID From wpdaxue.com
 */
// 添加一个新的列 ID
function ssid_column($cols) {
	$cols['ssid'] = 'ID';
	return $cols;
}

// 显示 ID
function ssid_value($column_name, $id) {
	if ($column_name == 'ssid')
		echo $id;
}

function ssid_return_value($value, $column_name, $id) {
	if ($column_name == 'ssid')
		$value = $id;
	return $value;
}

// 为 ID 这列添加css
function ssid_css() {
?>
<style type="text/css">
	#ssid { width: 50px; } /* Simply Show IDs */
</style>
<?php
}

// 通过动作/过滤器输出各种表格和CSS
function ssid_add() {
	add_action('admin_head', 'ssid_css');

	add_filter('manage_posts_columns', 'ssid_column');
	add_action('manage_posts_custom_column', 'ssid_value', 10, 2);

	add_filter('manage_pages_columns', 'ssid_column');
	add_action('manage_pages_custom_column', 'ssid_value', 10, 2);

	add_filter('manage_media_columns', 'ssid_column');
	add_action('manage_media_custom_column', 'ssid_value', 10, 2);

	add_filter('manage_link-manager_columns', 'ssid_column');
	add_action('manage_link_custom_column', 'ssid_value', 10, 2);

	add_action('manage_edit-link-categories_columns', 'ssid_column');
	add_filter('manage_link_categories_custom_column', 'ssid_return_value', 10, 3);

	foreach ( get_taxonomies() as $taxonomy ) {
		add_action("manage_edit-${taxonomy}_columns", 'ssid_column');
		add_filter("manage_${taxonomy}_custom_column", 'ssid_return_value', 10, 3);
	}

	add_action('manage_users_columns', 'ssid_column');
	add_filter('manage_users_custom_column', 'ssid_return_value', 10, 3);

	add_action('manage_edit-comments_columns', 'ssid_column');
	add_action('manage_comments_custom_column', 'ssid_value', 10, 2);
}

add_action('admin_init', 'ssid_add');

//分类描述使用编辑器
// 移除HTML过滤
remove_filter( 'pre_term_description', 'wp_filter_kses' );
remove_filter( 'term_description', 'wp_kses_data' );
// 移除HTML过滤
add_action("category_edit_form_fields", 'add_form_fields_example', 10, 2);
function add_form_fields_example($term, $taxonomy){
?>
<tr valign="top">
<th scope="row"><?php _e('描述','salong'); ?></th>
<td>
<?php wp_editor(html_entity_decode($term->description), 'description', array('media_buttons' => true,'quicktags'=>true)); ?>
<script>
jQuery(window).ready(function(){
jQuery('label[for=description]').parent().parent().remove();
});
</script>
</td>
</tr>
<?php
}


//修改编辑器插入图片默认设置
function default_attachment_display_settings() {
	update_option( 'image_default_align', 'none' );//居中显示
	update_option( 'image_default_link_type', 'none' );//链接到媒体文件本身
	update_option( 'image_default_size', 'full' );//完整尺寸
}
add_action( 'after_setup_theme', 'default_attachment_display_settings' );

//编辑器不过滤html标签
remove_action('init', 'kses_init');
remove_action('set_current_user', 'kses_init');
//编辑器不加p,br标签
//remove_filter (  'the_content' ,  'wpautop'  );

?>
