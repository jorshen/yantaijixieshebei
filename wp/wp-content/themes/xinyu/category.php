<?php get_header(); ?>
<?php if(is_category(array(3,4,9,10,11,12,13,14,15))){
	require'category-img.php';
}elseif(is_category(array(5))){
	require'category-article.php';
}elseif(is_category(array(2,6,7,8))){
	require'category-single.php';
} ?>
<?php get_footer(); ?>